<?php
namespace Mikrodius;

final class WebhookReceiver
{
    private Service $service;
    private Store $store;

    public function __construct(Service $service) { $this->service = $service; $this->store = $service->store; }

    public function configureSecret(string $secret, ?string $previous = null, ?int $previousUntil = null): void
    {
        if (!preg_match('/^whsec_[A-Za-z0-9_-]{20,}$/D', $secret) ||
            ($previous !== null && (!preg_match('/^whsec_[A-Za-z0-9_-]{20,}$/D', $previous) ||
                $previousUntil === null || $previousUntil > time() + 86400))) {
            throw new Problem(422, 'invalid_webhook_secret');
        }
        $config = array_replace($this->store->config(), ['webhook_secret' => $secret,
            'webhook_previous' => $previous, 'webhook_previous_until' => $previousUntil]);
        $this->store->saveConfig($config);
    }

    public static function timestamp(string $raw): string
    {
        if (!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|[+-]\d\d:\d\d)$/D', $raw)) {
            throw new Problem(422, 'invalid_event_timestamp');
        }
        try { $dt = new \DateTimeImmutable($raw); }
        catch (\Exception $e) { throw new Problem(422, 'invalid_event_timestamp'); }
        if (\DateTimeImmutable::getLastErrors()) { throw new Problem(422, 'invalid_event_timestamp'); }
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    public function accept(string $body, array $headers, ?int $now = null): bool
    {
        $now = $now ?? time();
        if (strlen($body) > 1048576) { throw new Problem(413, 'webhook_too_large'); }
        $headers = array_change_key_case($headers, CASE_LOWER);
        $signature = $headers['mikrodius-signature'] ?? '';
        $times = []; $signatures = [];
        foreach (explode(',', $signature) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 't=')) { $times[] = substr($part, 2); }
            if (preg_match('/^v1=([a-fA-F0-9]{64})$/D', $part, $m)) { $signatures[] = strtolower($m[1]); }
        }
        if (count($times) !== 1 || !preg_match('/^[0-9]{1,12}$/D', $times[0]) || abs($now - (int) $times[0]) > 300) {
            throw new Problem(401, 'invalid_webhook_signature');
        }
        $config = $this->store->config(); $secrets = [];
        if (!empty($config['webhook_secret'])) { $secrets[] = $config['webhook_secret']; }
        if (!empty($config['webhook_previous']) && ($config['webhook_previous_until'] ?? 0) >= $now) { $secrets[] = $config['webhook_previous']; }
        $valid = false;
        foreach ($secrets as $secret) {
            $expected = hash_hmac('sha256', $times[0] . '.' . $body, $secret);
            foreach ($signatures as $received) { $valid = hash_equals($expected, $received) || $valid; }
        }
        if (!$valid) { throw new Problem(401, 'invalid_webhook_signature'); }
        try { $event = json_decode($body, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new Problem(400, 'invalid_webhook_event'); }
        foreach (['id' => 128, 'type' => 80, 'workspace_id' => 64, 'created_at' => 40] as $field => $max) {
            if (!is_array($event) || !isset($event[$field]) || !is_string($event[$field]) || $event[$field] === '' || strlen($event[$field]) > $max) {
                throw new Problem(400, 'invalid_webhook_event');
            }
        }
        if (!is_array($event['data'] ?? null) || ($headers['mikrodius-event-id'] ?? '') !== $event['id'] ||
            ($headers['mikrodius-event-type'] ?? '') !== $event['type']) { throw new Problem(400, 'invalid_webhook_event'); }
        $at = self::timestamp($event['created_at']);
        $workspace = $event['workspace_id'];
        if (!$this->store->one('SELECT workspace_id FROM mikrodius_plans WHERE workspace_id=? LIMIT 1', [$workspace]) &&
            !$this->store->one('SELECT workspace_id FROM mikrodius_devices WHERE workspace_id=? LIMIT 1', [$workspace])) {
            throw new Problem(403, 'webhook_workspace_not_mapped');
        }
        $this->validateResources($event);
        // Acknowledge only after durable insertion. Retries with the same event ID
        // cannot replace a payload; a changed body is an actionable conflict.
        return $this->store->transaction(function () use ($event, $body, $at) {
            $this->store->execute('INSERT IGNORE INTO mikrodius_events (id,workspace_id,event_type,event_at,body_hash,payload,received_at)
                VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6))', [$event['id'], $event['workspace_id'], $event['type'], $at,
                hash('sha256', $body), $this->store->secrets->seal($event)]);
            $stored = $this->store->one('SELECT body_hash FROM mikrodius_events WHERE id=? FOR UPDATE', [$event['id']]);
            if (!$stored || !hash_equals($stored['body_hash'], hash('sha256', $body))) { throw new Problem(409, 'webhook_event_conflict'); }
            return true;
        });
    }

    private function resource(array $event)
    {
        $data = $event['data'];
        return $data['subscriber'] ?? (is_array($data['session'] ?? null) ? ($data['session']['subscriber'] ?? null) : null);
    }

    private function entity(array $event): ?array
    {
        $sub = $this->resource($event);
        if (!is_array($sub)) { return null; }
        $entity = $this->store->one('SELECT * FROM mikrodius_entitlements WHERE workspace_id=? AND external_id=?',
            [$event['workspace_id'], $sub['external_id'] ?? '']);
        $byId = $this->store->one('SELECT * FROM mikrodius_entitlements WHERE subscriber_id=?', [$sub['id'] ?? '']);
        if ($byId && (!$entity || $byId['id'] !== $entity['id'])) { throw new Problem(409, 'webhook_resource_conflict'); }
        if ($entity && (empty($sub['id']) || (isset($sub['workspace_id']) && $sub['workspace_id'] !== $entity['workspace_id']) ||
            ($entity['subscriber_id'] !== null && $entity['subscriber_id'] !== $sub['id']))) {
            throw new Problem(409, 'webhook_resource_conflict');
        }
        return $entity;
    }

    private function validateResources(array $event): void
    {
        $sub = $this->resource($event);
        if ($sub !== null && !is_array($sub)) { throw new Problem(400, 'invalid_webhook_event'); }
        if (isset($sub['workspace_id']) && $sub['workspace_id'] !== $event['workspace_id']) { throw new Problem(409, 'webhook_resource_conflict'); }
        $nas = $event['data']['nas'] ?? $event['data']['session']['nas'] ?? null;
        if ($nas !== null && (!is_array($nas) || (isset($nas['workspace_id']) && $nas['workspace_id'] !== $event['workspace_id']))) {
            throw new Problem(409, 'webhook_resource_conflict');
        }
        $this->entity($event);
    }

    public function process(int $limit = 100): int
    {
        $done = 0;
        foreach ($this->store->rows("SELECT id FROM mikrodius_events WHERE state='pending' ORDER BY event_at,id LIMIT " . max(1, min(500, $limit))) as $candidate) {
            try {
                $this->store->transaction(function () use ($candidate) {
                    $row = $this->store->one('SELECT * FROM mikrodius_events WHERE id=? FOR UPDATE', [$candidate['id']]);
                    if (!$row || $row['state'] !== 'pending') { return; }
                    $event = $this->store->secrets->open($row['payload']);
                    $this->validateResources($event); $entity = $this->entity($event);
                    if ($entity) {
                        $sub = $this->resource($event);
                        if (str_starts_with($event['type'], 'subscriber.') && isset($sub['status'], $sub['state'], $sub['usage'])) {
                            $this->store->observe($entity, $sub, $event['created_at']);
                            (new VoucherSupport($this->service))->observeFirstLogin($entity, $sub);
                        } else {
                            $this->store->queue('webhook-refresh:' . $event['id'], $entity['source_key'], 'refresh', [], (int) $entity['source_txn']);
                        }
                    }
                    $nas = $event['data']['nas'] ?? null;
                    if ($nas && str_starts_with($event['type'], 'nas.')) { $this->store->observeNas($event['workspace_id'], $nas, $event['created_at']); }
                    $this->store->execute("UPDATE mikrodius_events SET state='processed',processed_at=UTC_TIMESTAMP(6),error_code=NULL WHERE id=?", [$candidate['id']]);
                });
                $done++;
            } catch (Problem $e) {
                $this->store->execute("UPDATE mikrodius_events SET state='failed',error_code=? WHERE id=?", [$e->problemCode, $candidate['id']]);
            }
            // Storage failures leave the event pending and propagate for a later cron run.
        }
        return $done;
    }

    public function events(int $limit = 100): array
    {
        return $this->store->rows('SELECT id,workspace_id,event_type,event_at,state,error_code,received_at,processed_at FROM mikrodius_events ORDER BY received_at DESC LIMIT ' . max(1, min(500, $limit)));
    }

    public function retry(string $id): void { $this->store->execute("UPDATE mikrodius_events SET state='pending',error_code=NULL WHERE id=? AND state='failed'", [$id]); }

    public static function handleHttp(Service $service): void
    {
        header('Content-Type: application/json');
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); throw new Problem(405, 'method_not_allowed'); }
            $stream = fopen('php://input', 'rb'); $raw = stream_get_contents($stream, 1048577); fclose($stream);
            (new self($service))->accept($raw, [
                'Mikrodius-Signature' => $_SERVER['HTTP_MIKRODIUS_SIGNATURE'] ?? '',
                'Mikrodius-Event-Id' => $_SERVER['HTTP_MIKRODIUS_EVENT_ID'] ?? '',
                'Mikrodius-Event-Type' => $_SERVER['HTTP_MIKRODIUS_EVENT_TYPE'] ?? '']);
            http_response_code(202); echo '{"accepted":true}';
        } catch (\Throwable $e) {
            $problem = $e instanceof Problem ? $e : new Problem(503, 'webhook_storage_unavailable');
            http_response_code($problem->status > 0 ? $problem->status : 503);
            echo json_encode(['code' => $problem->problemCode]);
        }
    }
}
