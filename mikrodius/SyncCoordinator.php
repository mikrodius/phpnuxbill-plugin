<?php
namespace Mikrodius;

/** Captures only durable local facts, then drains just the corresponding targets. */
final class SyncCoordinator
{
    public Deadline $deadline;
    private Service $service;
    private array $customers = [], $plans = [], $purchases = [], $vouchers = [], $targets = [], $intents = [];
    private ?array $result = null;
    private bool $allowUnchanged = false;
    public function allowUnchanged(bool $allowed): void { $this->allowUnchanged = $allowed; }
    private int $saved = 0;
    private array $savedKeys = [];
    public function startFinalization(): void { if ($this->result === null) { $this->deadline = new Deadline(8); } }
    private ?string $error = null;
    public function __construct(Service $service, ?Deadline $deadline = null) { $this->service = $service; $this->deadline = $deadline ?? new Deadline(8); }
    public function target(string $target): void { $this->targets[$target] = true; }
    public function saved(int $count = 1): void { $this->saved += $count; }
    public function customer(int $id): void
    {
        if ($id <= 0 || isset($this->customers[$id])) { return; }
        $this->customers[$id] = $this->customerState($id);
    }
    private function customerState(int $id): array
    {
        return ['customer' => $this->service->store->one('SELECT * FROM tbl_customers WHERE id=?', [$id]),
            'recharges' => $this->service->store->rows('SELECT r.* FROM tbl_user_recharges r JOIN tbl_plans p ON p.id=r.plan_id AND p.device=\'MikrodiusRadius\' WHERE r.customer_id=? ORDER BY r.id', [$id])];
    }
    public function plan(int $id): void { if ($id > 0) { $this->plans[$id] = true; } }
    public function purchase(int $transaction, int $recharge): void { if ($transaction > 0 && $recharge > 0) { $this->purchases[$transaction] = $recharge; } }
    public function voucher(int $id): void { if ($id > 0) { $this->vouchers[$id] = true; } }
    public function deletion(string $kind, int $id): void
    {
        $table = ['plan_change' => 'tbl_plans', 'plan' => 'tbl_plans', 'customer' => 'tbl_customers', 'recharge' => 'tbl_user_recharges'][$kind] ?? null;
        if (!$table || !$this->service->store->one('SELECT id FROM ' . $table . ' WHERE id=?', [$id])) { return; }
        if ($kind === 'plan_change' && !$this->service->store->one("SELECT id FROM tbl_plans WHERE id=? AND device='MikrodiusRadius'", [$id])) { return; }
        if (isset($this->intents[$kind . ':' . $id])) { return; }
        $store = $this->service->store;
        $entities = [];
        if ($kind === 'customer') { $entities = $store->rows('SELECT * FROM mikrodius_entitlements WHERE source_key LIKE ?', ['customer:' . $id . ':%']); }
        if ($kind === 'recharge') {
            $r = $store->one('SELECT * FROM tbl_user_recharges WHERE id=?', [$id]);
            $c = $store->one('SELECT * FROM tbl_customers WHERE id=?', [$r['customer_id']]);
            $p = $store->one('SELECT * FROM tbl_plans WHERE id=?', [$r['plan_id']]);
            if ($c && $p && $p['device'] === 'MikrodiusRadius') { $e = $store->entitlement($this->service->source($c, $p)); if ($e) { $entities[] = $e; } }
        }
        $intent = bin2hex(random_bytes(16));
        $store->execute('INSERT INTO mikrodius_intents (id,kind,local_id,payload,created_at) VALUES (?,?,?,?,UTC_TIMESTAMP(6))', [$intent, $kind, $id, $store->secrets->seal(['entities' => $entities])]);
        $this->intents[$kind . ':' . $id] = $intent;
    }
    public function recoverIntents(bool $final = false): void
    {
        $store = $this->service->store;
        $rows = $final ? array_values($this->intents) : array_column($store->rows("SELECT id FROM mikrodius_intents WHERE state='pending' ORDER BY created_at LIMIT 500"), 'id');
        foreach ($rows as $id) {
            if (!$this->deadline->available()) { break; }
            $intent = $store->one("SELECT * FROM mikrodius_intents WHERE id=? AND state='pending'", [$id]);
            if (!$intent) { continue; }
            $table = ['plan_change' => 'tbl_plans', 'plan' => 'tbl_plans', 'customer' => 'tbl_customers', 'recharge' => 'tbl_user_recharges'][$intent['kind']];
            $localExists = $store->one('SELECT * FROM ' . $table . ' WHERE id=?', [$intent['local_id']]);
            if ($localExists && ($intent['kind'] !== 'plan_change' || $localExists['device'] === 'MikrodiusRadius')) {
                if ($final) { $store->execute("UPDATE mikrodius_intents SET state='cancelled',finished_at=UTC_TIMESTAMP(6) WHERE id=?", [$id]); }
                continue;
            }
            $payload = $store->secrets->open($intent['payload']);
            if ($intent['kind'] === 'recharge') {
                $payload['entities'] = array_values(array_filter($payload['entities'], function ($entity) use ($store) {
                    if (!preg_match('/^customer:(\d+):/', $entity['source_key'], $match)) { return true; }
                    $customer = $store->one('SELECT * FROM tbl_customers WHERE id=?', [$match[1]]);
                    if (!$customer) { return true; }
                    foreach ($store->rows('SELECT p.* FROM tbl_user_recharges r JOIN tbl_plans p ON p.id=r.plan_id WHERE r.customer_id=? AND p.device=\'MikrodiusRadius\'', [$match[1]]) as $plan) {
                        if ($this->service->source($customer, $plan) === $entity['source_key']) { return false; }
                    }
                    return true;
                }));
            }
            $store->transaction(function () use ($store, $intent, $payload, $id) {
                if (in_array($intent['kind'], ['plan','plan_change'], true)) {
                    $target = 'plan:' . $intent['local_id'];
                    // A timed-out create is reconciled by remove_plan using its stable ownership tag.
                    $store->execute("UPDATE mikrodius_jobs SET state='superseded',finished_at=UTC_TIMESTAMP(6) WHERE target_key=? AND kind='plan' AND state='queued' AND attempts=0", [$target]);
                    $store->execute("UPDATE mikrodius_jobs SET state='retry',available_at=UTC_TIMESTAMP(6) WHERE target_key=? AND kind='plan' AND state='failed' AND attempts>0", [$target]);
                    $store->queue('delete:' . $id, $target, $intent['kind'] === 'plan_change' ? 'detach_plan' : 'remove_plan', ['local_plan_id' => (int) $intent['local_id']]);
                    foreach ($store->rows('SELECT * FROM mikrodius_entitlements WHERE local_plan_id=?', [$intent['local_id']]) as $entity) {
                        $store->queue('detach:' . $id . ':' . $entity['id'], $entity['source_key'], 'suspend', [], (int) $entity['source_txn']); $this->target($entity['source_key']);
                    }
                    $this->target($target);
                } else {
                    foreach ($payload['entities'] as $entity) {
                        $store->execute("UPDATE mikrodius_jobs SET state='superseded',finished_at=UTC_TIMESTAMP(6) WHERE target_key=? AND state='queued' AND attempts=0", [$entity['source_key']]);
                        $store->execute("UPDATE mikrodius_jobs SET state='retry',available_at=UTC_TIMESTAMP(6) WHERE target_key=? AND kind IN ('purchase','sync','suspend','activate') AND state='failed' AND attempts>0", [$entity['source_key']]);
                        $store->queue('delete:' . $id . ':' . $entity['id'], $entity['source_key'], 'remove_entitlement', [], (int) $entity['source_txn']);
                        $this->target($entity['source_key']);
                    }
                }
                $store->execute("UPDATE mikrodius_intents SET state='promoted',finished_at=UTC_TIMESTAMP(6) WHERE id=?", [$id]);
            });
            $this->saved++;
        }
    }
    public function finish(): array
    {
        if ($this->result !== null) { return $this->result; }
        $store = $this->service->store; $old = $this->service->deadline; $this->service->withDeadline($this->deadline);
        try {
            foreach ($this->purchases as $transaction => $recharge) {
                if ($txn = $store->one('SELECT user_id FROM tbl_transactions WHERE id=?', [$transaction])) { $this->savedKeys['customer:' . $txn['user_id']] = true; }
                $this->capture(function () use ($transaction, $recharge, $store) {
                    $this->service->capturePurchase($transaction, $recharge);
                    foreach ($store->rows('SELECT target_key FROM mikrodius_jobs WHERE source_txn=?', [$transaction]) as $job) { $this->target($job['target_key']); }
                });
            }
            foreach ($this->plans as $id => $_) {
                $this->target('plan:' . $id); $this->savedKeys['plan:' . $id] = true;
                $this->capture(function () use ($id) { $this->service->capturePlan($this->service->localPlan($id)); foreach ((new VoucherSupport($this->service))->captureProfiles($id) as $target) { $this->target($target); } }, 'plan:' . $id);
            }
            foreach ($this->customers as $id => $before) {
                $after = $this->customerState($id);
                if ($before === $after && !$this->allowUnchanged) { continue; }
                $this->savedKeys['customer:' . $id] = true;
                $this->capture(function () use ($after, $before, $store, $id) {
                    foreach ($after['recharges'] as $recharge) {
                        $customer = $after['customer']; if (!$customer) { continue; }
                        $plan = $this->service->localPlan((int) $recharge['plan_id']);
                        $source = $this->service->source($customer, $plan); $this->target($source);
                        $oldRecharge = array_values(array_filter($before['recharges'], static fn($r) => $r['id'] === $recharge['id']))[0] ?? null;
                        $this->capture(fn() => $this->service->synchronize($customer, $plan, $oldRecharge !== $recharge ? $recharge : null), 'credentials:' . $recharge['id']);
                    }
                    $this->service->captureCustomerState($id);
                    foreach ($store->rows('SELECT source_key FROM mikrodius_entitlements WHERE source_key LIKE ?', ['customer:' . $id . ':%']) as $e) { $this->target($e['source_key']); }
                });
            }
            foreach ($this->vouchers as $id => $_) {
                $this->target('voucher:' . $id); $this->savedKeys['voucher:' . $id] = true;
                $this->capture(function () use ($id) { (new VoucherSupport($this->service))->discover($id); }, 'voucher:' . $id);
            }
            $this->recoverIntents(true);
            $execution = (new Worker($this->service))->runTargets(array_keys($this->targets), $this->deadline);
            $this->result = $this->summarize($execution);
        } catch (\Throwable $e) {
            $this->error = $e instanceof Problem ? $e->problemCode : 'local_storage_error';
            try { $this->result = $this->summarize(['targets' => array_keys($this->targets), 'blockers' => []]); }
            catch (\Throwable $summaryFailure) { $this->result = ['local_saved' => $this->saved + count($this->savedKeys) > 0, 'state' => 'pending', 'synchronization' => 'pending', 'job_ids' => [], 'saved' => $this->saved + count($this->savedKeys), 'synced' => 0, 'pending' => count($this->targets), 'failed' => 0, 'error_code' => $this->error, 'request_id' => null, 'blocker' => ['code' => $this->error], 'next_retry_at' => null]; }
        }
        try { $store->execute('INSERT INTO mikrodius_receipts (id,result,created_at) VALUES (?,?,UTC_TIMESTAMP(6))', [bin2hex(random_bytes(16)), json_encode($this->result, JSON_THROW_ON_ERROR)]); } catch (\Throwable $e) { $this->result['error_code'] = 'receipt_storage_error'; }
        finally { $this->service->withDeadline($old); }
        return $this->result;
    }
    private function capture(callable $fn, ?string $source = null): void
    {
        $target = $source === null ? null : 'diagnostic:' . hash('sha256', $source);
        try {
            $this->deadline->check(); $fn();
            if ($target) { $this->service->store->execute("UPDATE mikrodius_jobs SET state='superseded' WHERE target_key=? AND state IN ('queued','retry','failed')", [$target]); }
        } catch (\Throwable $e) {
            $this->error = $e instanceof Problem ? $e->problemCode : 'local_storage_error';
            if ($target && $this->deadline->available()) {
                try { $this->service->store->queue($target . ':' . $this->error, $target, 'diagnostic', ['source' => $source, 'code' => $this->error]); $this->target($target); } catch (\Throwable $storage) { /* The receipt still reports pending if diagnostic storage is unavailable. */ }
            }
        }
    }
    private function summarize(array $execution): array
    {
        $jobs = [];
        foreach ($execution['targets'] as $target) { array_push($jobs, ...$this->service->store->rows("SELECT id,target_key,state,error_code,request_id,available_at FROM mikrodius_jobs WHERE target_key=? AND state<>'superseded' ORDER BY id", [$target])); }
        $relevant = array_flip($execution['job_ids'] ?? []);
        $jobs = array_values(array_filter($jobs, static fn($j) => $j['state'] !== 'succeeded' || isset($relevant[$j['id']])));
        $states = array_fill_keys(array_keys($this->targets), 'synced');
        foreach ($jobs as $job) {
            if (!isset($states[$job['target_key']])) { continue; }
            if ($job['state'] === 'failed') { $states[$job['target_key']] = 'failed'; }
            elseif ($states[$job['target_key']] !== 'failed' && in_array($job['state'], ['queued','retry','running'], true)) { $states[$job['target_key']] = 'pending'; }
        }
        foreach ($execution['blockers'] as $target => $reason) { if (($states[$target] ?? null) === 'synced') { $states[$target] = 'pending'; } }
        $failed = count(array_filter($states, static fn($s) => $s === 'failed'));
        $pending = count(array_filter($states, static fn($s) => $s === 'pending'));
        $bad = array_values(array_filter($jobs, static fn($j) => !empty($j['error_code'])))[0] ?? [];
        $blocker = array_values($execution['blockers'])[0] ?? null;
        $state = $failed ? 'failed' : (($pending || $this->error || $blocker) ? 'pending' : 'synced');
        return ['local_saved' => $this->saved + count($this->savedKeys) > 0, 'state' => $state, 'synchronization' => $state,
            'job_ids' => array_map('intval', array_column($jobs, 'id')), 'saved' => $this->saved + count($this->savedKeys),
            'synced' => count(array_filter($states, static fn($s) => $s === 'synced')), 'pending' => $pending, 'failed' => $failed,
            'error_code' => $this->error ?? $bad['error_code'] ?? null, 'request_id' => $bad['request_id'] ?? null,
            'blocker' => $blocker, 'next_retry_at' => $blocker['next_retry_at'] ?? ($pending ? ($bad['available_at'] ?? null) : null)];
    }
}
