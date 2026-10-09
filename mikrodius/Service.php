<?php
namespace Mikrodius;

final class Service
{
    public Store $store;
    private $clientFactory;

    public function __construct(Store $store, ?callable $clientFactory = null)
    {
        $this->store = $store;
        $this->clientFactory = $clientFactory;
    }

    public function client(): ApiClient
    {
        $config = $this->store->config();
        if (empty($config['api_key'])) { throw new Problem(422, 'connection_not_configured'); }
        return $this->clientFactory ? ($this->clientFactory)($config) : new ApiClient($config['api_url'], $config['api_key']);
    }

    public function configure(string $key, string $timezone, string $base = 'https://mikrodius.com/api/v1'): array
    {
        try { new \DateTimeZone($timezone); } catch (\Exception $e) { throw new Problem(422, 'invalid_billing_timezone'); }
        new ApiClient($base, $key);
        $config = array_replace($this->store->config(), ['api_key' => $key, 'api_url' => $base, 'timezone' => $timezone]);
        $client = $this->clientFactory ? ($this->clientFactory)($config) : new ApiClient($base, $key);
        $workspaces = $client->all('/workspaces');
        $this->store->saveConfig($config);
        return $workspaces;
    }

    public function settings(): array
    {
        $config = $this->store->config();
        return ['configured' => !empty($config['api_key']), 'api_url' => $config['api_url'] ?? 'https://mikrodius.com/api/v1',
            'timezone' => $config['timezone'] ?? null, 'installation_id' => $this->store->installation(),
            // Masked: the prefix and last four characters identify the key without revealing it.
            'api_key_hint' => empty($config['api_key']) ? null : 'mk_live_…' . substr($config['api_key'], -4)];
    }

    public function testConnection(): array { return $this->client()->all('/workspaces'); }

    /** Saves settings; an empty key keeps the stored one, which is never sent back to the page. */
    public function reconfigure(string $key, string $timezone, string $base): array
    {
        $key = $key !== '' ? $key : (string) ($this->store->config()['api_key'] ?? '');
        return $this->configure($key, $timezone, $base);
    }

    public function mapDevice(int $localId, string $workspace, ?string $nas = null): void
    {
        if (!$this->store->one('SELECT id FROM tbl_routers WHERE id=?', [$localId])) { throw new Problem(422, 'device_not_found'); }
        $path = ApiClient::workspace($workspace);
        $this->client()->request('GET', $path);
        if ($nas !== null) {
            $result = $this->client()->request('GET', $path . '/nas/' . rawurlencode($nas));
            if (($result['workspace_id'] ?? null) !== $workspace) { throw new Problem(409, 'nas_mapping_conflict'); }
        }
        $old = $this->store->one('SELECT * FROM mikrodius_devices WHERE local_id=?', [$localId]);
        if ($old && $old['workspace_id'] !== $workspace) { throw new Problem(409, 'workspace_mapping_conflict'); }
        if ($nas !== null && $this->store->one('SELECT local_id FROM mikrodius_devices WHERE nas_id=? AND local_id<>?', [$nas, $localId])) {
            throw new Problem(409, 'nas_mapped_to_other_device');
        }
        // An open installation owns the device's NAS link until it ends.
        if ($old && $old['nas_id'] !== $nas && $this->store->one('SELECT id FROM mikrodius_provisioning WHERE active_device=?', [$localId])) {
            throw new Problem(409, 'operation_active');
        }
        $this->store->execute('INSERT INTO mikrodius_devices (local_id,workspace_id,nas_id) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE nas_id=VALUES(nas_id)', [$localId, $workspace, $nas]);
    }

    public function mapPlan(int $localId, string $workspace, ?string $remoteId = null, bool $managed = false): void
    {
        $plan = $this->localPlan($localId);
        $path = ApiClient::workspace($workspace);
        $this->client()->request('GET', $path);
        if ($remoteId !== null) { $this->client()->request('GET', $path . '/plans/' . rawurlencode($remoteId)); }
        $old = $this->store->one('SELECT * FROM mikrodius_plans WHERE local_id=?', [$localId]);
        if ($old && ($old['workspace_id'] !== $workspace || ($old['remote_id'] !== null && $old['remote_id'] !== $remoteId))) {
            throw new Problem(409, 'plan_mapping_conflict');
        }
        $this->store->execute('INSERT INTO mikrodius_plans (local_id,workspace_id,remote_id,managed) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE remote_id=COALESCE(remote_id,VALUES(remote_id)),managed=VALUES(managed)', [$localId, $workspace, $remoteId, $remoteId === null || $managed ? 1 : 0]);
        $this->capturePlan($plan);
    }

    public function mappings(): array
    {
        return ['devices' => $this->store->rows('SELECT * FROM mikrodius_devices ORDER BY local_id'),
            'plans' => $this->store->rows('SELECT * FROM mikrodius_plans ORDER BY local_id'),
            'entitlements' => $this->store->rows('SELECT id,source_key,workspace_id,external_id,username,subscriber_id,local_plan_id,source_txn FROM mikrodius_entitlements ORDER BY source_key')];
    }

    public function localPlan(int $id): array
    {
        $plan = $this->store->one('SELECT * FROM tbl_plans WHERE id=?', [$id]);
        if (!$plan || ($plan['device'] ?? '') !== 'MikrodiusRadius') { throw new Problem(422, 'plan_not_configured'); }
        Mapper::service($plan);
        return $plan;
    }

    public function planMapping(array $plan): array
    {
        $map = $this->store->one('SELECT * FROM mikrodius_plans WHERE local_id=?', [$plan['id']]);
        if ($map) { return $map; }
        $device = $this->store->one('SELECT m.* FROM mikrodius_devices m JOIN tbl_routers r ON r.id=m.local_id WHERE r.name=?', [$plan['routers']]);
        if (!$device) { throw new Problem(422, 'plan_mapping_required'); }
        $this->store->execute('INSERT IGNORE INTO mikrodius_plans (local_id,workspace_id,managed) VALUES (?,?,1)', [$plan['id'], $device['workspace_id']]);
        return $this->store->one('SELECT * FROM mikrodius_plans WHERE local_id=?', [$plan['id']]);
    }

    public function planPayload(array $plan): array
    {
        $bw = $this->store->one('SELECT * FROM tbl_bandwidth WHERE id=?', [$plan['id_bw']]);
        if (!$bw) { throw new Problem(422, 'bandwidth_not_found'); }
        $suffix = ' [mk:' . substr($this->store->installation(), 0, 12) . ':' . $plan['id'] . ']';
        $name = mb_substr($plan['name_plan'], 0, 100 - strlen($suffix)) . $suffix;
        return Mapper::plan($plan, $bw, $name);
    }

    public function capturePlan(array $plan): void
    {
        if (empty($plan['id'])) { return; } // Upstream calls add_plan before saving its row.
        $plan = $this->localPlan((int) $plan['id']);
        $map = $this->planMapping($plan);
        $payload = $this->planPayload($plan);
        $name = 'plan-sync.' . $plan['id'];
        $fingerprint = $this->store->secrets->fingerprint([$map['workspace_id'], $map['remote_id'], (bool) $map['managed'], $payload]);
        $this->store->transaction(function () use ($plan, $map, $payload, $name, $fingerprint) {
            $last = $this->store->one('SELECT value FROM mikrodius_settings WHERE name=? FOR UPDATE', [$name]);
            if ($last && $last['value'] === $fingerprint) { return; }
            $this->store->queue('plan:' . bin2hex(random_bytes(16)), 'plan:' . $plan['id'], 'plan',
                ['local_plan_id' => (int) $plan['id'], 'workspace_id' => $map['workspace_id'], 'plan' => $payload]);
            $this->store->execute('INSERT INTO mikrodius_settings (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [$name, $fingerprint]);
        });
    }

    public function ensurePlan(int $id): string
    {
        if (!$this->store->lock('plan:' . $id, 5)) { throw new Problem(503, 'plan_sync_busy'); }
        try {
            $plan = $this->localPlan($id); $map = $this->planMapping($plan); $payload = $this->planPayload($plan);
            $path = ApiClient::workspace($map['workspace_id']) . '/plans';
            if ($map['remote_id'] === null) {
                $tag = '[mk:' . substr($this->store->installation(), 0, 12) . ':' . $id . ']';
                $matches = array_values(array_filter($this->client()->all($path), static fn($row) => str_ends_with($row['name'], $tag)));
                if (count($matches) > 1) { throw new Problem(409, 'plan_ownership_conflict'); }
                if ($matches) { $remote = $matches[0]; }
                else {
                    try { $remote = $this->client()->request('POST', $path, $payload); }
                    catch (Problem $e) {
                        if ($e->status !== 409) { throw $e; }
                        $matches = array_values(array_filter($this->client()->all($path), static fn($row) => str_ends_with($row['name'], $tag)));
                        if (count($matches) !== 1) { throw new Problem(409, 'plan_ownership_conflict'); }
                        $remote = $matches[0];
                    }
                }
                if (($remote['workspace_id'] ?? null) !== $map['workspace_id']) { throw new Problem(409, 'plan_ownership_conflict'); }
                $this->store->execute('UPDATE mikrodius_plans SET remote_id=?,updated_at=UTC_TIMESTAMP(6) WHERE local_id=?', [$remote['id'], $id]);
                $map['remote_id'] = $remote['id'];
            }
            if ((bool) $map['managed']) {
                $remote = $this->client()->request('GET', $path . '/' . rawurlencode($map['remote_id']));
                $changes = [];
                foreach ($payload as $key => $value) { if (($remote[$key] ?? null) != $value) { $changes[$key] = $value; } }
                if ($changes) { $this->client()->request('PATCH', $path . '/' . rawurlencode($map['remote_id']), $changes); }
                $this->store->execute('UPDATE mikrodius_plans SET updated_at=UTC_TIMESTAMP(6) WHERE local_id=?', [$id]);
            }
            return $map['remote_id'];
        } finally { $this->store->unlock('plan:' . $id); }
    }

    public function source(array $customer, array $plan): string
    {
        if ((int) ($customer['id'] ?? $customer['customer_id'] ?? 0) === 0) {
            $voucher = $this->store->one("SELECT v.id FROM tbl_voucher v JOIN mikrodius_vouchers m ON m.local_id=v.id AND m.mode='direct'
                WHERE BINARY v.code=BINARY ? AND v.id_plan=? AND v.routers=? LIMIT 1", [$customer['username'] ?? '', $plan['id'], $plan['routers']]);
            if ($voucher) { return 'voucher:' . $voucher['id']; }
        }
        $device = $this->store->one('SELECT id FROM tbl_routers WHERE name=?', [$plan['routers']]);
        return 'customer:' . (int) ($customer['id'] ?? $customer['customer_id'] ?? 0) . ':' . Mapper::service($plan) . ':device:' . ($device['id'] ?? 'radius');
    }

    public function capturePurchase(int $transactionId, int $rechargeId): void
    {
        $txn = $this->store->one('SELECT * FROM tbl_transactions WHERE id=?', [$transactionId]);
        $recharge = $this->store->one('SELECT * FROM tbl_user_recharges WHERE id=?', [$rechargeId]);
        if (!$txn || !$recharge) { throw new Problem(422, 'billing_record_not_saved'); }
        foreach (['username', 'routers', 'type', 'expiration', 'time'] as $field) {
            if ($txn[$field] !== $recharge[$field]) { throw new Problem(409, 'billing_snapshot_changed'); }
        }
        if ((int) $txn['user_id'] !== (int) $recharge['customer_id']) { throw new Problem(409, 'billing_snapshot_changed'); }
        $plan = $this->localPlan((int) $recharge['plan_id']); $map = $this->planMapping($plan);
        $customer = $this->store->one('SELECT * FROM tbl_customers WHERE id=?', [$recharge['customer_id']]);
        if (!$customer && (int) $recharge['customer_id'] !== 0) { throw new Problem(409, 'customer_not_found'); }
        if (!$customer) { $customer = ['id' => 0, 'username' => $recharge['username'], 'password' => $recharge['username']]; }
        $this->captureBilling($txn, $recharge, $customer, $plan, $map);
    }

    private function captureBilling(array $txn, array $recharge, array $customer, array $plan, array $map): void
    {
        if (str_starts_with($txn['method'], 'Voucher - ')) {
            (new VoucherSupport($this))->captureActivation($txn, $recharge, $customer, $plan);
            return;
        }
        $this->captureCustomerPurchase($txn, $customer, $plan, $map);
    }

    public function captureCustomerPurchase(array $txn, array $customer, array $plan, array $map): void
    {
        $identity = Mapper::identity($customer, $plan);
        $expiry = Mapper::expiry($txn['expiration'], $txn['time'], $this->store->config()['timezone'] ?? '');
        $source = $this->source($customer, $plan);
        $capture = function () use ($source, $map, $identity, $plan, $txn, $expiry) {
            $entity = $this->store->ensureEntitlement($source, $map['workspace_id'], $identity['username'], (int) $plan['id']);
            $entity = $this->store->one('SELECT * FROM mikrodius_entitlements WHERE id=? FOR UPDATE', [$entity['id']]);
            if ((int) $entity['source_txn'] > (int) $txn['id']) { return; }
            $this->store->execute('UPDATE mikrodius_entitlements SET source_txn=?,local_plan_id=?,username=? WHERE id=?', [$txn['id'], $plan['id'], $identity['username'], $entity['id']]);
            // Never supersede attempted work: a timeout may already have committed.
            $this->store->execute("UPDATE mikrodius_jobs SET state='superseded',finished_at=UTC_TIMESTAMP(6)
                WHERE target_key=? AND kind='purchase' AND source_txn<? AND state='queued' AND attempts=0", [$source, $txn['id']]);
            $reference = 'phpnuxbill:' . $this->store->installation() . ':invoice:' . $txn['id'];
            $this->store->queue($reference, $source, 'purchase', ['identity' => $identity, 'expires_at' => $expiry,
                'local_plan_id' => (int) $plan['id'], 'reference' => $reference, 'invoice' => $txn['invoice']], (int) $txn['id']);
        };
        // Voucher claims and their purchase job commit together in the same transaction.
        if ($this->store->db->inTransaction()) { $capture(); }
        else { $this->store->transaction($capture); }
    }

    public function reconcile(): void
    {
        foreach ($this->store->rows("SELECT * FROM tbl_plans WHERE device='MikrodiusRadius'") as $plan) {
            $this->safely('plan:' . $plan['id'], fn() => $this->capturePlan($plan));
        }
        // The recharge is the authoritative current entitlement. Its saved timestamps
        // identify the latest associated transaction without recomputing any expiry.
        // Customer renames leave transaction usernames historical; keep those
        // recharges in the scan so existing credentials still synchronize.
        foreach ($this->store->rows("SELECT r.id AS recharge_id, MAX(t.id) AS transaction_id FROM tbl_user_recharges r
            JOIN tbl_plans p ON p.id=r.plan_id AND p.device='MikrodiusRadius'
            LEFT JOIN tbl_transactions t ON t.user_id=r.customer_id AND BINARY t.username=BINARY r.username
                AND t.routers=r.routers AND t.type=r.type AND t.expiration=r.expiration AND t.time=r.time
            GROUP BY r.id") as $row) {
            if ($row['transaction_id'] !== null) {
                $this->safely('recharge:' . $row['recharge_id'], fn() => $this->capturePurchase((int) $row['transaction_id'], (int) $row['recharge_id']));
            }
            $recharge = $this->store->one('SELECT * FROM tbl_user_recharges WHERE id=?', [$row['recharge_id']]);
            $customer = $this->store->one('SELECT * FROM tbl_customers WHERE id=?', [$recharge['customer_id']]);
            if ($customer) {
                $this->safely('credentials:' . $row['recharge_id'], fn() => $this->synchronize($customer, $this->localPlan((int) $recharge['plan_id'])));
            }
        }
        foreach ($this->store->rows("SELECT * FROM mikrodius_entitlements WHERE source_key LIKE 'customer:%'") as $entity) {
            $this->safely('customer-state:' . $entity['id'], function () use ($entity) {
                if (!preg_match('/^customer:(\d+):/', $entity['source_key'], $match)) { return; }
                $customer = $this->store->one('SELECT status FROM tbl_customers WHERE id=?', [$match[1]]);
                $state = $customer && $customer['status'] === 'Active' ? 'active' : 'suspended';
                $key = 'state.' . $entity['id'];
                $previous = $this->store->one('SELECT value FROM mikrodius_settings WHERE name=?', [$key]);
                if ($previous && $previous['value'] === $state) { return; }
                $this->store->transaction(function () use ($entity, $state, $previous, $key) {
                    if ($previous || $state === 'suspended') {
                        $this->store->queue('customer-state:' . bin2hex(random_bytes(16)), $entity['source_key'],
                            $state === 'active' ? 'activate' : 'suspend', [], (int) $entity['source_txn']);
                    }
                    $this->store->execute('INSERT INTO mikrodius_settings (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [$key, $state]);
                });
            });
        }
    }

    public function safely(string $source, callable $fn): void
    {
        $target = 'diagnostic:' . hash('sha256', $source);
        try {
            $fn();
            $this->store->execute("UPDATE mikrodius_jobs SET state='superseded' WHERE target_key=? AND state IN ('queued','retry','failed')", [$target]);
        } catch (\Throwable $e) {
            $code = $e instanceof Problem ? $e->problemCode : 'local_storage_error';
            $this->store->queue($target . ':' . $code, $target, 'diagnostic', ['source' => $source, 'code' => $code]);
        }
    }

    public function synchronize(array $customer, array $plan): void
    {
        $plan = $this->localPlan((int) $plan['id']); $source = $this->source($customer, $plan);
        if (str_starts_with($source, 'voucher:')) {
            // Issued code credentials and first-use profile are immutable. A generic
            // customer sync must not replace that profile with a paid billing plan.
            (new VoucherSupport($this))->discover((int) substr($source, strlen('voucher:')), true);
            return;
        }
        $entity = $this->store->entitlement($source);
        if (!$entity) { return; }
        $payload = ['identity' => Mapper::identity($customer, $plan), 'local_plan_id' => (int) $plan['id']];
        // Passwords never enter the public status cache. Use a keyed fingerprint to
        // discover persisted credential changes without retaining plaintext diagnostics.
        $fingerprint = $this->store->secrets->fingerprint($payload);
        $last = $this->store->one('SELECT value FROM mikrodius_settings WHERE name=?', ['sync.' . $entity['id']]);
        if ($last && $last['value'] === $fingerprint) { return; }
        $this->store->transaction(function () use ($source, $entity, $payload, $fingerprint) {
            $this->store->execute("UPDATE mikrodius_jobs SET state='superseded',finished_at=UTC_TIMESTAMP(6)
                WHERE target_key=? AND kind='sync' AND state='queued' AND attempts=0", [$source]);
            $this->store->queue('sync:' . bin2hex(random_bytes(16)), $source, 'sync', $payload, (int) $entity['source_txn']);
            $this->store->execute('INSERT INTO mikrodius_settings (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', ['sync.' . $entity['id'], $fingerprint]);
        });
    }

    public function action(array $customer, array $plan, string $action): void
    {
        if (!in_array($action, ['suspend', 'activate', 'disconnect'], true)) { throw new Problem(422, 'unsupported_action'); }
        $source = $this->source($customer, $plan); $entity = $this->store->entitlement($source);
        if (!$entity) { return; }
        $this->store->queue('action:' . bin2hex(random_bytes(16)), $source, $action, [], (int) $entity['source_txn']);
    }

    public function setServiceType(string $source, string $type): void
    {
        $entity = $this->store->entitlement($source);
        if (!$entity || !in_array($type, ['pppoe', 'hotspot', 'any'], true)) { throw new Problem(422, 'invalid_service_mapping'); }
        $this->store->transaction(function () use ($entity, $source, $type) {
            $this->store->execute('INSERT INTO mikrodius_settings (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', ['service.' . $entity['id'], $type]);
            $this->store->queue('service:' . bin2hex(random_bytes(16)), $source, 'service_type', ['service_type' => $type], (int) $entity['source_txn']);
        });
    }

    public function mappedServiceType(array $entity, string $fallback): string
    {
        $setting = $this->store->one('SELECT value FROM mikrodius_settings WHERE name=?', ['service.' . $entity['id']]);
        return $setting['value'] ?? $fallback;
    }

    public function sessions(string $source): array
    {
        $entity = $this->store->entitlement($source);
        if (!$entity || !$entity['subscriber_id']) { return []; }
        return $this->client()->all(ApiClient::workspace($entity['workspace_id']) . '/sessions?subscriber=' . rawurlencode($entity['subscriber_id']));
    }

    public function reconcileStatus(): void
    {
        // Bounded periodic GET jobs recover missed access/session/NAS events. They
        // share the entitlement queue and cannot overtake an uncertain purchase.
        $slot = (int) floor(time() / 300);
        foreach ($this->store->rows("SELECT * FROM mikrodius_entitlements WHERE subscriber_id IS NOT NULL AND source_key NOT LIKE 'voucher:%'") as $entity) {
            $this->store->queue('status:' . $entity['id'] . ':' . $slot, $entity['source_key'], 'refresh', [], (int) $entity['source_txn']);
        }
        foreach ($this->store->rows('SELECT local_id FROM mikrodius_devices WHERE nas_id IS NOT NULL') as $device) {
            $this->store->queue('nas-status:' . $device['local_id'] . ':' . $slot, 'nas:' . $device['local_id'], 'nas_refresh', ['local_id' => (int) $device['local_id']]);
        }
    }

    public function status(string $source, bool $refresh = false): ?array
    {
        $entity = $this->store->entitlement($source);
        if (!$entity) { return null; }
        if ($refresh && $entity['subscriber_id'] !== null) {
            $subscriber = $this->client()->request('GET', ApiClient::workspace($entity['workspace_id']) . '/subscribers/' . rawurlencode($entity['subscriber_id']));
            $this->store->observe($entity, $subscriber);
            $entity = $this->store->entitlement($source);
        }
        return $entity['observed'] ? json_decode($entity['observed'], true) : null;
    }
}
