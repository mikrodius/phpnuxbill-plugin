<?php
namespace Mikrodius;

final class Worker
{
    private Service $service;
    private Store $store;

    public function __construct(Service $service) { $this->service = $service; $this->store = $service->store; }

    public function run(int $limit = 50, int $seconds = 25): int
    {
        $deadline = microtime(true) + $seconds; $done = 0;
        // Limit runnable heads, not targets with followers behind blocked heads.
        // Oldest work gets priority over continuously arriving refresh jobs.
        $candidates = $this->store->rows("SELECT j.target_key FROM mikrodius_jobs j
            WHERE j.state IN ('queued','retry','running') AND j.available_at<=UTC_TIMESTAMP(6)
              AND NOT EXISTS (SELECT 1 FROM mikrodius_jobs earlier WHERE earlier.target_key=j.target_key
                AND earlier.id<j.id AND earlier.state NOT IN ('succeeded','superseded'))
            ORDER BY j.id LIMIT " . max(1, min(500, $limit)));
        foreach ($candidates as $candidate) {
            if ($done >= $limit || microtime(true) >= $deadline) { break; }
            $target = $candidate['target_key'];
            if (!$this->store->lock('worker:' . $target)) { continue; }
            try {
                // Failed/uncertain head jobs prevent later purchases overtaking them.
                $job = $this->store->one("SELECT *,available_at<=UTC_TIMESTAMP(6) AS due FROM mikrodius_jobs
                    WHERE target_key=? AND state NOT IN ('succeeded','superseded') ORDER BY id LIMIT 1", [$target]);
                if (!$job || $job['state'] === 'failed' || !(bool) $job['due']) { continue; }
                $this->store->execute("UPDATE mikrodius_jobs SET state='running',attempts=attempts+1 WHERE id=?", [$job['id']]);
                try {
                    $this->apply($job, $this->store->secrets->open($job['payload']));
                    $this->store->execute("UPDATE mikrodius_jobs SET state='succeeded',finished_at=UTC_TIMESTAMP(6),error_code=NULL,request_id=NULL WHERE id=?", [$job['id']]);
                    $done++;
                } catch (\Throwable $failure) {
                    $problem = $failure instanceof Problem ? $failure : new Problem(500, 'local_storage_error');
                    $retry = $problem->retryable();
                    $delay = max($problem->retryAfter, min(3600, 5 * (2 ** min(9, (int) $job['attempts']))) + random_int(0, 5));
                    $this->store->execute('UPDATE mikrodius_jobs SET state=?,error_code=?,request_id=?,available_at=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)) WHERE id=?',
                        [$retry ? 'retry' : 'failed', $problem->problemCode, $problem->requestId, $delay, $job['id']]);
                }
            } finally { $this->store->unlock('worker:' . $target); }
        }
        return $done;
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
        if ($job['kind'] === 'plan') { $this->service->ensurePlan((int) $payload['local_plan_id']); return; }
        if ($job['kind'] === 'remove_plan') {
            $map = $this->store->one('SELECT * FROM mikrodius_plans WHERE local_id=?', [$payload['local_plan_id']]);
            if ($map && $map['remote_id'] && $map['managed']) {
                try { $this->service->client()->request('DELETE', ApiClient::workspace($map['workspace_id']) . '/plans/' . rawurlencode($map['remote_id'])); }
                catch (Problem $e) { if ($e->status !== 404) { throw $e; } }
            }
            if ($map) {
                $this->store->transaction(function () use ($map) {
                    $this->store->execute('DELETE FROM mikrodius_plans WHERE local_id=?', [$map['local_id']]);
                    $this->store->execute('DELETE FROM mikrodius_settings WHERE name=?', ['plan-sync.' . $map['local_id']]);
                });
            }
            return;
        }
        if (in_array($job['kind'], ['voucher', 'voucher_activation', 'voucher_plan'], true)) {
            (new VoucherSupport($this->service))->apply($job, $payload, $this);
            return;
        }
        $entity = $this->store->entitlement($job['target_key']);
        if (!$entity) { throw new Problem(409, 'entitlement_mapping_missing'); }
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
            $this->service->client()->request('POST', $path . rawurlencode($sub['id']) . '/' . $job['kind'],
                $job['kind'] === 'suspend' ? ['reason' => 'PHPNuxBill'] : null);
            $this->service->status($entity['source_key'], true);
            return;
        }
        $planId = $this->service->ensurePlan((int) $payload['local_plan_id']);
        $identity = $payload['identity'];
        if (preg_match('/^customer:(\d+):/', $entity['source_key'], $match)) {
            // Identity corrections can resolve failed ownership/credential jobs.
            // Billing expiry and invoice reference remain the immutable snapshot.
            $customer = $this->store->one('SELECT * FROM tbl_customers WHERE id=?', [$match[1]]);
            if (!$customer || $customer['status'] !== 'Active') {
                if ($job['kind'] === 'sync') { return; }
                // A purchase discovered before deletion/disable must not grant access.
                try {
                    $inactive = $this->subscriber($entity, null);
                    $this->service->client()->request('POST', $path . rawurlencode($inactive['id']) . '/suspend', ['reason' => 'PHPNuxBill']);
                } catch (Problem $e) { if ($e->problemCode !== 'subscriber_mapping_missing') { throw $e; } }
                throw new Problem(409, 'customer_inactive');
            }
            $identity = Mapper::identity($customer, $this->service->localPlan((int) $payload['local_plan_id']));
        }
        $identity['service_type'] = $this->service->mappedServiceType($entity, $identity['service_type']);
        $create = $job['kind'] === 'purchase' ? array_replace($identity, ['plan_id' => $planId, 'expires_at' => '1970-01-01T00:00:00Z']) : null;
        $sub = $this->subscriber($entity, $create);
        $this->checkUsername($entity, $identity['username']);
        $patch = $identity;
        if ($job['kind'] === 'sync') { $patch['plan_id'] = $planId; }
        $changes = [];
        foreach ($patch as $key => $value) { if (($sub[$key] ?? null) !== $value) { $changes[$key] = $value; } }
        if ($changes) { $sub = $this->service->client()->request('PATCH', $path . rawurlencode($sub['id']), $changes); }
        if ($job['kind'] === 'purchase') {
            $sub = $this->service->client()->request('POST', $path . rawurlencode($sub['id']) . '/renew',
                ['expires_at' => $payload['expires_at'], 'plan_id' => $planId, 'reference' => $payload['reference']]);
        } elseif ($job['kind'] !== 'sync') { throw new Problem(422, 'unsupported_job'); }
        $this->store->execute('UPDATE mikrodius_entitlements SET username=? WHERE id=?', [$identity['username'], $entity['id']]);
        $this->store->observe($entity, $sub);
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
