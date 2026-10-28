<?php
namespace Mikrodius;

final class Worker
{
    private Service $service;
    private Store $store;

    public function __construct(Service $service) { $this->service = $service; $this->store = $service->store; }

    public function run(int $limit = 50, int $seconds = 25): int
    {
        $deadline = $this->service->deadline ?? new Deadline($seconds);
        $targets = array_column($this->store->rows("SELECT j.target_key,j.id FROM mikrodius_jobs j WHERE j.state IN ('queued','retry','running') AND j.available_at<=UTC_TIMESTAMP(6) AND NOT EXISTS (SELECT 1 FROM mikrodius_jobs earlier WHERE earlier.target_key=j.target_key AND earlier.id<j.id AND earlier.state NOT IN ('succeeded','superseded')) ORDER BY j.id LIMIT " . max(1, min(500, $limit))), 'target_key');
        return $this->runTargets($targets, $deadline, $limit, false)['completed'];
    }

    /** Only these roots and their plan dependencies may execute. Each head owns its advisory lock. */
    public function runTargets(array $targets, Deadline $deadline, int $limit = 500, bool $successive = true): array
    {
        $previous = $this->service->deadline;
        $this->service->withDeadline($deadline);
        $done = 0; $blockers = []; $visited = []; $jobIds = [];
        $run = function (string $target) use (&$run, &$done, &$blockers, &$visited, &$jobIds, $deadline, $limit, $successive): void {
            if (isset($visited[$target])) { return; }
            $visited[$target] = true;
            if (!$deadline->available()) { $blockers[$target] = ['code' => 'sync_deadline']; return; }
            $entity = $this->store->entitlement($target);
            $head = $this->store->one("SELECT kind FROM mikrodius_jobs WHERE target_key=? AND state NOT IN ('succeeded','superseded') ORDER BY id LIMIT 1", [$target]);
            if ($entity && in_array($head['kind'] ?? '', ['purchase','sync','saved_entitlement','voucher','voucher_activation'], true)) {
                $dependency = 'plan:' . $entity['local_plan_id'];
                $run($dependency);
                if (isset($blockers[$dependency])) { $blockers[$target] = ['code' => 'dependency_blocked', 'target' => $dependency]; return; }
                if (str_starts_with($target, 'voucher:')) {
                    foreach ($this->store->rows("SELECT p.profile_key FROM mikrodius_voucher_plans p JOIN mikrodius_vouchers v ON v.workspace_id=p.workspace_id AND v.local_plan_id=p.local_plan_id AND v.validity_seconds <=> p.validity_seconds WHERE v.local_id=?", [(int) substr($target, 8)]) as $profile) {
                        $dependency = 'voucher-plan:' . $profile['profile_key']; $run($dependency);
                        if (isset($blockers[$dependency])) { $blockers[$target] = ['code' => 'dependency_blocked', 'target' => $dependency]; return; }
                    }
                }
            }
            if (!$this->store->lock('worker:' . $target)) { $blockers[$target] = ['code' => 'sync_busy']; return; }
            try {
                while ($done < $limit) {
                    $job = $this->store->one("SELECT *,available_at<=UTC_TIMESTAMP(6) AS due FROM mikrodius_jobs WHERE target_key=? AND state NOT IN ('succeeded','superseded') ORDER BY id LIMIT 1", [$target]);
                    if (!$job) { break; }
                    $jobIds[] = (int) $job['id'];
                    if (!$deadline->available()) { $blockers[$target] = ['code' => 'sync_deadline', 'job_id' => (int) $job['id']]; break; }
                    if ($job['state'] === 'failed' || !(bool) $job['due']) {
                        $blockers[$target] = ['code' => $job['error_code'] ?: ($job['state'] === 'failed' ? 'job_failed' : 'scheduled'), 'job_id' => (int) $job['id'], 'next_retry_at' => $job['state'] === 'failed' ? null : $job['available_at']]; break;
                    }
                    $this->store->execute("UPDATE mikrodius_jobs SET state='running',attempts=attempts+1 WHERE id=?", [$job['id']]);
                    try {
                        $this->apply($job, $this->store->secrets->open($job['payload']));
                        $this->store->execute("UPDATE mikrodius_jobs SET state='succeeded',finished_at=UTC_TIMESTAMP(6),error_code=NULL,request_id=NULL WHERE id=?", [$job['id']]);
                        $done++;
                        if (!$successive) { break; }
                    } catch (\Throwable $failure) {
                        $problem = $failure instanceof Problem ? $failure : new Problem(500, 'local_storage_error');
                        $delay = max($problem->retryAfter, min(3600, 5 * (2 ** min(9, (int) $job['attempts']))) + random_int(0, 5));
                        $this->store->execute('UPDATE mikrodius_jobs SET state=?,error_code=?,request_id=?,available_at=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)) WHERE id=?', [$problem->retryable() ? 'retry' : 'failed', $problem->problemCode, $problem->requestId, $delay, $job['id']]);
                        $blockers[$target] = ['code' => $problem->problemCode, 'job_id' => (int) $job['id'], 'request_id' => $problem->requestId]; break;
                    }
                }
            } finally { $this->store->unlock('worker:' . $target); }
        };
        try { foreach (array_unique($targets) as $target) { $run($target); } }
        finally { $this->service->withDeadline($previous); }
        return ['job_ids' => array_values(array_unique($jobIds)), 'completed' => $done, 'targets' => array_keys($visited), 'blockers' => $blockers];
    }

    private function apply(array $job, array $payload): void
    {
        if ($job['kind'] === 'diagnostic') { throw new Problem(422, $payload['code']); }
        if ($job['kind'] === 'nas_refresh') {
            $map = $this->store->one('SELECT * FROM mikrodius_devices WHERE local_id=?', [$payload['local_id']]);
            if (!$map || !$map['nas_id']) { return; }
            $nas = $this->service->client()->request('GET', ApiClient::workspace($map['workspace_id']) . '/nas/' . rawurlencode($map['nas_id']));
            if (($nas['id'] ?? null) !== $map['nas_id']) { throw new Problem(409, 'nas_mapping_conflict'); }
            $this->store->transaction(fn() => $this->store->observeNas($map['workspace_id'], $nas, gmdate('c')));
            return;
        }
        if ($job['kind'] === 'plan') {
            if (!$this->store->one("SELECT id FROM tbl_plans WHERE id=? AND device='MikrodiusRadius'", [$payload['local_plan_id']])) { $this->removePlan((int) $payload['local_plan_id'], true); return; }
            $this->service->ensurePlan((int) $payload['local_plan_id']); return;
        }
        if (in_array($job['kind'], ['remove_plan','detach_plan'], true)) { $this->removePlan((int) $payload['local_plan_id'], $job['kind'] === 'detach_plan'); return; }
        if (in_array($job['kind'], ['voucher', 'voucher_activation', 'voucher_plan'], true)) {
            (new VoucherSupport($this->service))->apply($job, $payload, $this);
            return;
        }
        $entity = $this->store->entitlement($job['target_key']);
        if (!$entity) { throw new Problem(409, 'entitlement_mapping_missing'); }
        if ($job['kind'] === 'remove_entitlement') {
            if ((int) $job['source_txn'] !== (int) $entity['source_txn']) { return; }
            try { $sub = $this->subscriber($entity, null); $this->service->client()->request('DELETE', ApiClient::workspace($entity['workspace_id']) . '/subscribers/' . rawurlencode($sub['id'])); }
            catch (Problem $e) { if ($e->status !== 404 && $e->problemCode !== 'subscriber_mapping_missing') { throw $e; } }
            return;
        }
        if ($job['kind'] !== 'purchase' && (int) $job['source_txn'] !== (int) $entity['source_txn']) { return; }
        $path = ApiClient::workspace($entity['workspace_id']) . '/subscribers/';
        if ($job['kind'] === 'refresh') {
            $sub = $this->subscriber($entity, null);
            $this->store->transaction(function () use ($entity, $sub) {
                $this->store->observe($entity, $sub);
                (new VoucherSupport($this->service))->observeFirstLogin($entity, $sub);
            });
            return;
        }
        if ($job['kind'] === 'service_type') {
            $sub = $this->subscriber($entity, null);
            $sub = $this->service->client()->request('PATCH', $path . rawurlencode($sub['id']), ['service_type' => $payload['service_type']]);
            $this->store->observe($entity, $sub);
            return;
        }
        if (in_array($job['kind'], ['suspend', 'activate', 'disconnect'], true)) {
            $sub = $this->subscriber($entity, null);
            $delivery = $this->service->client()->request('POST', $path . rawurlencode($sub['id']) . '/' . $job['kind'],
                $job['kind'] === 'suspend' ? ['reason' => 'PHPNuxBill'] : null);
            if ($job['kind'] === 'disconnect' && array_filter($delivery['sessions'] ?? [], static fn($r) => ($r['result'] ?? null) === 'failed')) { throw new Problem(503, 'disconnect_partial_failure'); }
            $this->service->status($entity['source_key'], true);
            return;
        }
        $identity = $payload['identity'];
        if (preg_match('/^customer:(\d+):/', $entity['source_key'], $match)) {
            // Identity corrections can resolve failed ownership/credential jobs.
            // Billing expiry and invoice reference remain the immutable snapshot.
            $customer = $this->store->one('SELECT * FROM tbl_customers WHERE id=?', [$match[1]]);
            $current = $this->service->currentRecharge($entity);
            if ($current && ($current['device'] !== 'MikrodiusRadius' || $current['type'] !== $current['assigned_type'] || $current['routers'] !== $current['assigned_router'])) { $current = null; }
            if (!$customer || $customer['status'] !== 'Active' || !$current) {
                if ($job['kind'] === 'sync') { return; }
                // A purchase discovered before deletion/disable must not grant access.
                try {
                    $inactive = $this->subscriber($entity, null);
                    $this->service->client()->request('POST', $path . rawurlencode($inactive['id']) . '/suspend', ['reason' => 'PHPNuxBill']);
                } catch (Problem $e) { if ($e->problemCode !== 'subscriber_mapping_missing') { throw $e; } }
                if (!$customer || !$current) { return; }
                throw new Problem(409, 'customer_inactive');
            }
            $identity = Mapper::identity($customer, $this->service->localPlan((int) $payload['local_plan_id']));
        }
        $planId = $this->service->ensurePlan((int) $payload['local_plan_id']);
        $identity['service_type'] = $this->service->mappedServiceType($entity, $identity['service_type']);
        $create = $job['kind'] === 'purchase' ? array_replace($identity, ['plan_id' => $planId, 'expires_at' => '1970-01-01T00:00:00Z']) : ($job['kind'] === 'saved_entitlement' ? array_replace($identity, ['plan_id' => $planId, 'expires_at' => $payload['expires_at']]) : null);
        $sub = $this->subscriber($entity, $create);
        $this->checkUsername($entity, $identity['username']);
        $patch = $identity;
        if (in_array($job['kind'], ['sync','saved_entitlement'], true)) { $patch['plan_id'] = $planId; if (array_key_exists('expires_at', $payload)) { $patch['expires_at'] = $payload['expires_at']; } }
        $changes = [];
        foreach ($patch as $key => $value) { if (($sub[$key] ?? null) !== $value) { $changes[$key] = $value; } }
        if ($changes) { $sub = $this->service->client()->request('PATCH', $path . rawurlencode($sub['id']), $changes); }
        if ($job['kind'] === 'purchase') {
            $sub = $this->service->client()->request('POST', $path . rawurlencode($sub['id']) . '/renew',
                ['expires_at' => $payload['expires_at'], 'plan_id' => $planId, 'reference' => $payload['reference']]);
        } elseif (!in_array($job['kind'], ['sync','saved_entitlement'], true)) { throw new Problem(422, 'unsupported_job'); }
        $this->store->execute('UPDATE mikrodius_entitlements SET username=? WHERE id=?', [$identity['username'], $entity['id']]);
        $this->store->observe($entity, $sub);
    }

    private function removePlan(int $id, bool $detached = false): void
    {
        $local = $this->store->one('SELECT * FROM tbl_plans WHERE id=?', [$id]);
        if ($local && (!$detached || $local['device'] === 'MikrodiusRadius')) { throw new Problem(409, 'local_delete_not_confirmed'); }
        $map = $this->store->one('SELECT * FROM mikrodius_plans WHERE local_id=?', [$id]);
        if (!$map) { return; }
        if ($map['managed']) {
            $path = ApiClient::workspace($map['workspace_id']) . '/plans';
            $tag = '[mk:' . substr($this->store->installation(), 0, 12) . ':' . $id . ']';
            $matches = array_values(array_filter($this->service->client()->all($path), static fn($p) => str_ends_with($p['name'] ?? '', $tag)));
            if (count($matches) > 1) { throw new Problem(409, 'plan_ownership_conflict'); }
            if ($matches) {
                $remote = $matches[0];
                if (($remote['workspace_id'] ?? null) !== $map['workspace_id'] || ($map['remote_id'] && $remote['id'] !== $map['remote_id'])) { throw new Problem(409, 'plan_ownership_conflict'); }
                try { $this->service->client()->request('DELETE', $path . '/' . rawurlencode($remote['id'])); } catch (Problem $e) { if ($e->status !== 404) { throw $e; } }
            } elseif ($map['remote_id']) {
                try { $this->service->client()->request('GET', $path . '/' . rawurlencode($map['remote_id'])); throw new Problem(409, 'plan_ownership_conflict'); }
                catch (Problem $e) { if ($e->status !== 404) { throw $e; } }
            }
        }
        $this->store->transaction(function () use ($id) {
            $this->store->execute('DELETE FROM mikrodius_plans WHERE local_id=?', [$id]);
            $this->store->execute('DELETE FROM mikrodius_settings WHERE name=?', ['plan-sync.' . $id]);
        });
    }

    public function checkUsername(array $entity, string $username): void
    {
        $other = $this->store->one('SELECT id FROM mikrodius_entitlements WHERE workspace_id=? AND BINARY username=BINARY ? AND id<>? AND subscriber_id IS NOT NULL LIMIT 1', [$entity['workspace_id'], $username, $entity['id']]);
        if ($other) { throw new Problem(409, 'username_mapping_conflict'); }
        try {
            $remote = $this->service->client()->request('GET', ApiClient::workspace($entity['workspace_id']) . '/subscribers/username:' . rawurlencode($username));
            $this->verify($entity, $remote);
        } catch (Problem $e) { if ($e->status !== 404) { throw $e; } }
    }

    private function verify(array $entity, array $remote): void
    {
        if (($remote['external_id'] ?? null) !== $entity['external_id'] || ($remote['workspace_id'] ?? null) !== $entity['workspace_id']) {
            throw new Problem(409, 'subscriber_ownership_conflict');
        }
    }

    public function subscriber(array $entity, ?array $create): array
    {
        $path = ApiClient::workspace($entity['workspace_id']) . '/subscribers';
        $ref = 'external_id:' . rawurlencode($entity['external_id']);
        try { $sub = $this->service->client()->request('GET', $path . '/' . $ref); }
        catch (Problem $e) {
            if ($e->status !== 404) { throw $e; }
            if ($create === null) { throw new Problem(409, 'subscriber_mapping_missing'); }
            $this->checkUsername($entity, $create['username']);
            try { $sub = $this->service->client()->request('POST', $path, array_replace($create, ['external_id' => $entity['external_id']])); }
            catch (Problem $conflict) {
                if ($conflict->status !== 409) { throw $conflict; }
                // A username collision is recoverable only if our exact external ID exists.
                try { $sub = $this->service->client()->request('GET', $path . '/' . $ref); }
                catch (Problem $missing) {
                    if ($missing->status === 404) { throw new Problem(409, 'subscriber_ownership_conflict'); }
                    throw $missing;
                }
            }
        }
        $this->verify($entity, $sub);
        $this->store->execute('UPDATE mikrodius_entitlements SET subscriber_id=? WHERE id=?', [$sub['id'], $entity['id']]);
        return $sub;
    }
}
