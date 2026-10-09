<?php
namespace Mikrodius;

final class VoucherSupport
{
    private Service $service;
    private Store $store;
    public function __construct(Service $service) { $this->service = $service; $this->store = $service->store; }

    public function setPlanMode(int $planId, string $mode): void
    {
        $this->service->localPlan($planId);
        if (!in_array($mode, ['direct', 'customer_credit'], true)) { throw new Problem(422, 'invalid_voucher_mode'); }
        $this->store->execute('INSERT INTO mikrodius_settings (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', ['voucher-mode.' . $planId, $mode]);
    }

    public function setExactExpiry(int $voucherId, string $expiry): void
    {
        $at = WebhookReceiver::timestamp($expiry);
        $utc = str_replace(' ', 'T', $at) . 'Z';
        if (!$this->store->lock('voucher:' . $voucherId, 5)) { throw new Problem(503, 'voucher_sync_busy'); }
        try {
            $saved = $this->store->one('SELECT * FROM tbl_voucher WHERE id=?', [$voucherId]);
            if (!$saved || $saved['status'] !== '0') { throw new Problem(409, 'voucher_already_used'); }
            $this->service->localPlan((int) $saved['id_plan']);
            if ($this->store->one('SELECT local_id FROM mikrodius_vouchers WHERE local_id=?', [$voucherId])) {
                throw new Problem(409, 'voucher_mapping_already_saved');
            }
            $this->store->execute('INSERT INTO mikrodius_settings (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', ['voucher-expiry.' . $voucherId, $utc]);
        } finally { $this->store->unlock('voucher:' . $voucherId); }
    }

    private function snapshot(array $saved, array $plan): array
    {
        $row = $this->store->one('SELECT * FROM mikrodius_vouchers WHERE local_id=?', [$saved['id']]);
        if ($row) {
            if ((int) $row['local_plan_id'] !== (int) $plan['id']) { throw new Problem(409, 'voucher_plan_conflict'); }
            return $row;
        }
        $map = $this->service->planMapping($plan);
        $setting = $this->store->one('SELECT value FROM mikrodius_settings WHERE name=?', ['voucher-mode.' . $plan['id']]);
        $mode = $setting['value'] ?? 'direct';
        $expiry = $this->store->one('SELECT value FROM mikrodius_settings WHERE name=?', ['voucher-expiry.' . $saved['id']])['value'] ?? null;
        $seconds = null;
        if ($mode === 'direct' && $expiry === null) {
            $factor = match ($plan['validity_unit']) {
                'Mins' => 60, 'Hrs' => 3600, 'Days' => 86400,
                default => throw new Problem(422, 'voucher_calendar_expiry_required')
            };
            $seconds = Mapper::positive($plan['validity'], 'voucher_validity', $factor);
        }
        $this->store->execute('INSERT IGNORE INTO mikrodius_vouchers (local_id,workspace_id,mode,local_plan_id,validity_seconds,expires_at) VALUES (?,?,?,?,?,?)',
            [$saved['id'], $map['workspace_id'], $mode, $plan['id'], $seconds, $expiry]);
        return $this->store->one('SELECT * FROM mikrodius_vouchers WHERE local_id=?', [$saved['id']]);
    }

    public function discover(int $id, bool $activation = false): void
    {
        if (!$this->store->lock('voucher:' . $id, 5)) { throw new Problem(503, 'voucher_sync_busy'); }
        try {
            $saved = $this->store->one('SELECT * FROM tbl_voucher WHERE id=?', [$id]);
            if (!$saved) { throw new Problem(422, 'voucher_not_saved'); }
            if ($saved['status'] !== '0' && !$activation && !$this->store->one('SELECT local_id FROM mikrodius_vouchers WHERE local_id=?', [$id])) { return; }
            $plan = $this->service->localPlan((int) $saved['id_plan']);
            if ($saved['type'] !== $plan['type'] || $saved['routers'] !== $plan['routers']) { throw new Problem(409, 'voucher_plan_conflict'); }
            $identity = Mapper::identity(['username' => $saved['code'], 'password' => $saved['code']], $plan);
            $this->store->transaction(function () use ($saved, $plan, $identity) {
                $row = $this->snapshot($saved, $plan);
                if ($row['mode'] !== 'direct') { return; }
                $source = 'voucher:' . $saved['id'];
                $external = 'phpnuxbill:' . $this->store->installation() . ':voucher:' . $saved['id'];
                $entity = $this->store->ensureEntitlement($source, $row['workspace_id'], $identity['username'], (int) $plan['id'], $external);
                if ($entity['username'] !== $identity['username']) { throw new Problem(409, 'voucher_credentials_changed'); }
                $this->store->queue('voucher:' . $saved['id'], $source, 'voucher', ['voucher_id' => (int) $saved['id'], 'identity' => $identity]);
            });
        } finally { $this->store->unlock('voucher:' . $id); }
    }

    public function captureActivation(array $txn, array $recharge, array $customer, array $plan): void
    {
        $code = substr($txn['method'], strlen('Voucher - '));
        $matches = $this->store->rows('SELECT * FROM tbl_voucher WHERE BINARY code=BINARY ? AND id_plan=? AND routers=?', [$code, $plan['id'], $txn['routers']]);
        if (count($matches) !== 1) { throw new Problem(409, 'voucher_ownership_conflict'); }
        $saved = $matches[0]; $this->discover((int) $saved['id'], true);
        $this->store->transaction(function () use ($saved, $txn, $recharge, $customer, $plan) {
            $row = $this->store->one('SELECT * FROM mikrodius_vouchers WHERE local_id=? FOR UPDATE', [$saved['id']]);
            if ($row['mode'] === 'direct') {
                if ((int) $txn['user_id'] !== 0 || $recharge['username'] !== $saved['code']) { throw new Problem(409, 'voucher_direct_redemption_conflict'); }
                // Activation may happen before the first cron scan. Reuse the saved-code
                // job and mapping; never renew, reset usage or restart first-use expiry.
                $this->store->queue('voucher-activation:' . $txn['id'], 'voucher:' . $saved['id'], 'voucher_activation', ['voucher_id' => (int) $saved['id']]);
                return;
            }
            if ((int) $txn['user_id'] <= 0) { throw new Problem(409, 'voucher_customer_account_required'); }
            if ($row['claimed_transaction'] !== null && (int) $row['claimed_transaction'] !== (int) $txn['id']) { throw new Problem(409, 'voucher_already_claimed'); }
            $this->service->captureCustomerPurchase($txn, $customer, $plan, $this->service->planMapping($plan));
            $this->store->execute('UPDATE mikrodius_vouchers SET claimed_transaction=? WHERE local_id=?', [$txn['id'], $saved['id']]);
        });
    }

    private function ensurePlan(array $voucher): string
    {
        $key = hash('sha256', $voucher['workspace_id'] . ':' . $voucher['local_plan_id'] . ':' . ($voucher['validity_seconds'] ?? 'exact'));
        if (!$this->store->lock('voucher-plan:' . $key, 5)) { throw new Problem(503, 'voucher_plan_sync_busy'); }
        try {
            $this->store->execute('INSERT IGNORE INTO mikrodius_voucher_plans (profile_key,workspace_id,local_plan_id,validity_seconds) VALUES (?,?,?,?)',
                [$key, $voucher['workspace_id'], $voucher['local_plan_id'], $voucher['validity_seconds']]);
            $mapping = $this->store->one('SELECT * FROM mikrodius_voucher_plans WHERE profile_key=?', [$key]);
            $plan = $this->service->localPlan((int) $voucher['local_plan_id']);
            $payload = $this->service->planPayload($plan);
            $tag = '[mkv:' . substr($this->store->installation(), 0, 12) . ':' . substr($key, 0, 24) . ']';
            $payload['name'] = mb_substr($plan['name_plan'], 0, 99 - strlen($tag)) . ' ' . $tag;
            $payload['validity_seconds'] = $voucher['validity_seconds'] === null ? null : (int) $voucher['validity_seconds'];
            $path = ApiClient::workspace($voucher['workspace_id']) . '/plans';
            if (!$mapping['remote_id']) {
                $matches = array_values(array_filter($this->service->client()->all($path), static fn($p) => str_ends_with($p['name'], $tag)));
                if (count($matches) > 1) { throw new Problem(409, 'voucher_plan_ownership_conflict'); }
                if ($matches) { $remote = $matches[0]; }
                else {
                    try { $remote = $this->service->client()->request('POST', $path, $payload); }
                    catch (Problem $e) {
                        if ($e->status !== 409) { throw $e; }
                        $matches = array_values(array_filter($this->service->client()->all($path), static fn($p) => str_ends_with($p['name'], $tag)));
                        if (count($matches) !== 1) { throw new Problem(409, 'voucher_plan_ownership_conflict'); }
                        $remote = $matches[0];
                    }
                }
                if (($remote['workspace_id'] ?? '') !== $voucher['workspace_id']) { throw new Problem(409, 'voucher_plan_ownership_conflict'); }
                $mapping['remote_id'] = $remote['id'];
                $this->store->execute('UPDATE mikrodius_voucher_plans SET remote_id=? WHERE profile_key=?', [$remote['id'], $key]);
            }
            $remote = $this->service->client()->request('GET', $path . '/' . rawurlencode($mapping['remote_id']));
            $changes = [];
            foreach ($payload as $field => $value) { if (($remote[$field] ?? null) != $value) { $changes[$field] = $value; } }
            if ($changes) { $this->service->client()->request('PATCH', $path . '/' . rawurlencode($mapping['remote_id']), $changes); }
            return $mapping['remote_id'];
        } finally { $this->store->unlock('voucher-plan:' . $key); }
    }

    public function apply(array $job, array $payload, Worker $worker): void
    {
        if ($job['kind'] === 'voucher_plan') {
            $profile = $this->store->one('SELECT * FROM mikrodius_voucher_plans WHERE profile_key=?', [$payload['profile_key']]);
            if (!$profile) { throw new Problem(409, 'voucher_plan_mapping_missing'); }
            $this->ensurePlan($profile); return;
        }
        $voucher = $this->store->one('SELECT * FROM mikrodius_vouchers WHERE local_id=?', [$payload['voucher_id']]);
        $entity = $this->store->entitlement($job['target_key']);
        if (!$voucher || !$entity || $voucher['mode'] !== 'direct' || $entity['workspace_id'] !== $voucher['workspace_id']) { throw new Problem(409, 'voucher_mapping_missing'); }
        if ($job['kind'] === 'voucher') {
            $planId = $this->ensurePlan($voucher);
            $sub = $worker->subscriber($entity, array_replace($payload['identity'], ['plan_id' => $planId, 'expires_at' => $voucher['expires_at']]));
            $identity = $payload['identity'];
            $identity['service_type'] = $this->service->mappedServiceType($entity, $identity['service_type']);
            $changes = [];
            foreach ($identity as $field => $value) { if (($sub[$field] ?? null) !== $value) { $changes[$field] = $value; } }
            if ($changes) {
                $worker->checkUsername($entity, $identity['username']);
                $sub = $this->service->client()->request('PATCH', ApiClient::workspace($entity['workspace_id']) . '/subscribers/' . rawurlencode($sub['id']), $changes);
            }
        } else { $sub = $worker->subscriber($entity, null); }
        $this->store->transaction(function () use ($entity, $sub) {
            $this->store->observe($entity, $sub); $this->observeFirstLogin($entity, $sub);
        });
    }

    public function observeFirstLogin(array $entity, array $sub): void
    {
        if (!str_starts_with($entity['source_key'], 'voucher:') || empty($sub['first_login_at'])) { return; }
        if (($sub['external_id'] ?? null) !== $entity['external_id'] || ($sub['workspace_id'] ?? null) !== $entity['workspace_id']) { throw new Problem(409, 'voucher_ownership_conflict'); }
        $id = (int) substr($entity['source_key'], strlen('voucher:'));
        $at = WebhookReceiver::timestamp($sub['first_login_at']);
        $changed = $this->store->execute('UPDATE mikrodius_vouchers SET first_login_at=? WHERE local_id=? AND workspace_id=? AND first_login_at IS NULL', [$at, $id, $entity['workspace_id']])->rowCount();
        if (!$changed) { return; }
        $zone = new \DateTimeZone($this->store->config()['timezone']);
        $local = (new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d H:i:s');
        $this->store->execute("UPDATE tbl_voucher SET status='1',used_date=COALESCE(used_date,?),user=IF(user='0' OR user='',?,user) WHERE id=?", [$local, $entity['username'], $id]);
    }

    public function reconcile(int $limit = 50, int $seconds = 2): void
    {
        if (!$this->store->lock('voucher-reconcile')) { return; }
        try {
            // create_voucher fires before INSERTs. Scan saved rows in bounded passes,
            // continuing after the last ID across cron runs and wrapping at the end.
            $deadline = microtime(true) + max(1, $seconds);
            $limit = max(1, min(500, $limit));
            $name = 'voucher-reconcile.cursor';
            $cursor = (int) ($this->store->one('SELECT value FROM mikrodius_settings WHERE name=?', [$name])['value'] ?? 0);
            $sql = "SELECT v.id FROM tbl_voucher v JOIN tbl_plans p ON p.id=v.id_plan
                WHERE p.device='MikrodiusRadius' AND v.id>? ORDER BY v.id LIMIT " . $limit;
            $rows = $this->store->rows($sql, [$cursor]);
            if (!$rows) { $rows = $this->store->rows($sql, [0]); }
            foreach ($rows as $row) {
                if (microtime(true) >= $deadline) { break; }
                $id = (int) $row['id'];
                $this->service->safely('voucher:' . $id, fn() => $this->discover($id));
                // Discovery is idempotent if a crash happens before progression saves.
                // Failed codes return next sweep instead of starving later codes.
                $this->store->execute('INSERT INTO mikrodius_settings (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [$name, (string) $id]);
            }
            // Refresh mapped codes independently of discovery: removing a local
            // code or changing its plan must not stop observing its remote status.
            $deadline = microtime(true) + max(1, $seconds);
            $name = 'voucher-status.cursor';
            $cursor = (int) ($this->store->one('SELECT value FROM mikrodius_settings WHERE name=?', [$name])['value'] ?? 0);
            $sql = "SELECT e.*,m.local_id FROM mikrodius_vouchers m
                JOIN mikrodius_entitlements e ON e.source_key=CONCAT('voucher:',m.local_id)
                WHERE m.mode='direct' AND e.subscriber_id IS NOT NULL AND m.local_id>? ORDER BY m.local_id LIMIT " . $limit;
            $rows = $this->store->rows($sql, [$cursor]);
            if (!$rows) { $rows = $this->store->rows($sql, [0]); }
            $slot = (int) floor(time() / 300);
            foreach ($rows as $entity) {
                if (microtime(true) >= $deadline) { break; }
                $this->store->transaction(function () use ($entity, $slot, $name) {
                    // The existing bounded worker checks ownership, retries failed
                    // reads and observes first login without an HTTP sweep in cron.
                    $this->store->queue('status:' . $entity['id'] . ':' . $slot, $entity['source_key'], 'refresh', [], (int) $entity['source_txn']);
                    // Commit progression with enqueue, so a crash cannot skip work.
                    $this->store->execute('INSERT INTO mikrodius_settings (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [$name, (string) $entity['local_id']]);
                });
            }
        } finally { $this->store->unlock('voucher-reconcile'); }
        foreach ($this->store->rows('SELECT * FROM mikrodius_voucher_plans') as $profile) {
            $this->service->safely('voucher-plan:' . $profile['profile_key'], function () use ($profile) {
                $payload = $this->service->planPayload($this->service->localPlan((int) $profile['local_plan_id']));
                $name = 'vp.' . $profile['profile_key']; $hash = $this->store->secrets->fingerprint($payload);
                $this->store->transaction(function () use ($profile, $name, $hash) {
                    $old = $this->store->one('SELECT value FROM mikrodius_settings WHERE name=? FOR UPDATE', [$name]);
                    if ($old && $old['value'] === $hash) { return; }
                    $this->store->queue('voucher-plan:' . bin2hex(random_bytes(16)),
                        'voucher-plan:' . $profile['profile_key'], 'voucher_plan', ['profile_key' => $profile['profile_key']]);
                    $this->store->execute('INSERT INTO mikrodius_settings (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [$name, $hash]);
                });
            });
        }
    }

    public function mappings(): array { return $this->store->rows('SELECT * FROM mikrodius_vouchers ORDER BY local_id'); }
}
