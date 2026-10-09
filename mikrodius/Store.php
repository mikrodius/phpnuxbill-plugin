<?php
namespace Mikrodius;

final class Store
{
    public \PDO $db;
    public SecretBox $secrets;

    public function __construct(\PDO $db, SecretBox $secrets)
    {
        $this->db = $db; $this->secrets = $secrets;
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function execute(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql); $stmt->execute($params); return $stmt;
    }

    public function one(string $sql, array $params = []): ?array
    {
        return $this->execute($sql, $params)->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public function rows(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function transaction(callable $fn)
    {
        $this->db->beginTransaction();
        try { $value = $fn(); $this->db->commit(); return $value; }
        catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function migrate(): void
    {
        if (!$this->lock('migrations', 10)) { throw new Problem(503, 'migration_busy'); }
        try {
            // MySQL DDL auto-commits. Every statement is independently repeatable.
            foreach ([
                "CREATE TABLE IF NOT EXISTS mikrodius_settings (name VARCHAR(80) PRIMARY KEY, value MEDIUMTEXT NOT NULL) ENGINE=InnoDB",
                "CREATE TABLE IF NOT EXISTS mikrodius_devices (
                    local_id BIGINT PRIMARY KEY, workspace_id VARCHAR(64) NOT NULL, nas_id VARCHAR(64) NULL
                ) ENGINE=InnoDB",
                "CREATE TABLE IF NOT EXISTS mikrodius_plans (
                    local_id BIGINT PRIMARY KEY, workspace_id VARCHAR(64) NOT NULL, remote_id VARCHAR(64) NULL,
                    managed BOOLEAN NOT NULL DEFAULT TRUE, updated_at DATETIME(6) NULL
                ) ENGINE=InnoDB",
                "CREATE TABLE IF NOT EXISTS mikrodius_entitlements (
                    id CHAR(32) PRIMARY KEY, source_key VARCHAR(190) COLLATE utf8mb4_bin NOT NULL UNIQUE,
                    workspace_id VARCHAR(64) NOT NULL, external_id VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
                    username VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, subscriber_id VARCHAR(64) NULL,
                    local_plan_id BIGINT NOT NULL, source_txn BIGINT NOT NULL DEFAULT 0,
                    observed MEDIUMTEXT NULL, observed_at DATETIME(6) NULL,
                    UNIQUE KEY external_identity (workspace_id, external_id), KEY network_identity (workspace_id, username)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS mikrodius_jobs (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY, operation_key CHAR(64) NOT NULL UNIQUE,
                    target_key VARCHAR(190) COLLATE utf8mb4_bin NOT NULL, kind VARCHAR(32) NOT NULL,
                    payload MEDIUMTEXT NOT NULL, source_txn BIGINT NOT NULL DEFAULT 0,
                    state VARCHAR(16) NOT NULL DEFAULT 'queued', attempts INT NOT NULL DEFAULT 0,
                    available_at DATETIME(6) NOT NULL, created_at DATETIME(6) NOT NULL,
                    finished_at DATETIME(6) NULL, error_code VARCHAR(80) NULL, request_id VARCHAR(128) NULL,
                    KEY ready (state, available_at), KEY target_order (target_key, id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS mikrodius_vouchers (
                    local_id BIGINT PRIMARY KEY, workspace_id VARCHAR(64) NOT NULL,
                    mode VARCHAR(16) NOT NULL, local_plan_id BIGINT NOT NULL,
                    validity_seconds BIGINT NULL, expires_at VARCHAR(40) NULL,
                    claimed_transaction BIGINT NULL, first_login_at DATETIME(6) NULL
                ) ENGINE=InnoDB",
                "CREATE TABLE IF NOT EXISTS mikrodius_voucher_plans (
                    profile_key CHAR(64) PRIMARY KEY, workspace_id VARCHAR(64) NOT NULL,
                    local_plan_id BIGINT NOT NULL, validity_seconds BIGINT NULL, remote_id VARCHAR(64) NULL
                ) ENGINE=InnoDB",
                "CREATE TABLE IF NOT EXISTS mikrodius_events (
                    id VARCHAR(128) COLLATE utf8mb4_bin PRIMARY KEY, workspace_id VARCHAR(64) NOT NULL,
                    event_type VARCHAR(80) NOT NULL, event_at DATETIME(6) NOT NULL,
                    body_hash CHAR(64) NOT NULL, payload MEDIUMTEXT NOT NULL,
                    state VARCHAR(16) NOT NULL DEFAULT 'pending', error_code VARCHAR(80) NULL,
                    received_at DATETIME(6) NOT NULL, processed_at DATETIME(6) NULL,
                    KEY pending_events (state,event_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS mikrodius_nas_status (
                    workspace_id VARCHAR(64) NOT NULL, nas_id VARCHAR(64) NOT NULL,
                    observed MEDIUMTEXT NOT NULL, observed_at DATETIME(6) NOT NULL,
                    PRIMARY KEY(workspace_id,nas_id)
                ) ENGINE=InnoDB",
                // One active operation per device: active_device is NULL once it ends.
                "CREATE TABLE IF NOT EXISTS mikrodius_provisioning (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY, local_device_id BIGINT NOT NULL,
                    active_device BIGINT NULL UNIQUE, workspace_id VARCHAR(64) NOT NULL, nas_id VARCHAR(64) NULL,
                    intent MEDIUMTEXT NOT NULL, state VARCHAR(24) NOT NULL, created_by BIGINT NULL,
                    created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, finished_at DATETIME(6) NULL,
                    KEY device_operations (local_device_id,id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS mikrodius_provisioning_steps (
                    operation_id BIGINT NOT NULL, position INT NOT NULL, step VARCHAR(32) NOT NULL,
                    state VARCHAR(16) NOT NULL DEFAULT 'pending', attempts INT NOT NULL DEFAULT 0,
                    available_at DATETIME(6) NULL, first_started_at DATETIME(6) NULL,
                    started_at DATETIME(6) NULL, finished_at DATETIME(6) NULL,
                    evidence MEDIUMTEXT NULL, error_code VARCHAR(80) NULL, error_detail VARCHAR(255) NULL,
                    request_id VARCHAR(128) NULL, PRIMARY KEY (operation_id,position)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            ] as $sql) { $this->db->exec($sql); }
            $this->execute("INSERT IGNORE INTO mikrodius_settings (name,value) VALUES ('installation',?)", [bin2hex(random_bytes(16))]);
            $this->execute("INSERT INTO mikrodius_settings (name,value) VALUES ('schema_version','3') ON DUPLICATE KEY UPDATE value=GREATEST(CAST(value AS UNSIGNED),3)");
        } finally { $this->unlock('migrations'); }
    }

    public function installation(): string
    {
        return $this->one("SELECT value FROM mikrodius_settings WHERE name='installation'")['value'];
    }

    public function config(): array
    {
        $row = $this->one("SELECT value FROM mikrodius_settings WHERE name='connection'");
        return $row ? $this->secrets->open($row['value']) : [];
    }

    public function saveConfig(array $config): void
    {
        $this->execute("INSERT INTO mikrodius_settings (name,value) VALUES ('connection',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$this->secrets->seal($config)]);
    }

    public function entitlement(string $source): ?array
    {
        return $this->one('SELECT * FROM mikrodius_entitlements WHERE source_key=?', [$source]);
    }

    public function ensureEntitlement(string $source, string $workspace, string $username, int $planId, ?string $external = null): array
    {
        $id = bin2hex(random_bytes(16));
        $external = $external ?? 'phpnuxbill:' . $this->installation() . ':entitlement:' . $id;
        $this->execute('INSERT INTO mikrodius_entitlements (id,source_key,workspace_id,external_id,username,local_plan_id)
            VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE source_key=source_key', [$id, $source, $workspace, $external, $username, $planId]);
        $row = $this->entitlement($source);
        if (!$row || $row['workspace_id'] !== $workspace) { throw new Problem(409, 'workspace_mapping_conflict'); }
        return $row;
    }

    public function queue(string $operation, string $target, string $kind, array $payload, int $sourceTxn = 0): void
    {
        $this->execute('INSERT INTO mikrodius_jobs (operation_key,target_key,kind,payload,source_txn,available_at,created_at)
            VALUES (?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE operation_key=operation_key',
            [hash('sha256', $operation), $target, $kind, $this->secrets->seal($payload), $sourceTxn]);
    }

    public function jobs(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        // Operator-facing list deliberately excludes encrypted payloads and secret data.
        return $this->rows('SELECT id,target_key,kind,source_txn,state,attempts,available_at,created_at,finished_at,error_code,request_id
            FROM mikrodius_jobs ORDER BY id DESC LIMIT ' . $limit);
    }

    public function retry(int $id): void
    {
        $this->execute("UPDATE mikrodius_jobs SET state='retry',available_at=UTC_TIMESTAMP(6),error_code=NULL,request_id=NULL
            WHERE id=? AND state='failed'", [$id]);
    }

    public function lock(string $target, int $wait = 0): bool
    {
        // Advisory locks survive transaction commits, serialize network calls, and are
        // released by MySQL when a crashed worker's connection closes. No expiring lease
        // allows a second worker to overtake a still-running HTTP request.
        $name = 'mk:' . substr(hash('sha256', $this->installationScope() . ':' . $target), 0, 60);
        return (int) $this->one('SELECT GET_LOCK(?,?) AS locked', [$name, $wait])['locked'] === 1;
    }

    private function installationScope(): string
    {
        return (string) $this->one('SELECT DATABASE() AS name')['name'];
    }

    public function unlock(string $target): void
    {
        $name = 'mk:' . substr(hash('sha256', $this->installationScope() . ':' . $target), 0, 60);
        $this->execute('SELECT RELEASE_LOCK(?)', [$name]);
    }

    public function observe(array $entity, array $subscriber, ?string $timestamp = null): void
    {
        if (($subscriber['workspace_id'] ?? null) !== $entity['workspace_id'] || ($subscriber['external_id'] ?? null) !== $entity['external_id']) {
            throw new Problem(409, 'subscriber_ownership_conflict');
        }
        unset($subscriber['password']);
        $timestamp = $timestamp ?? $subscriber['updated_at'] ?? gmdate('c');
        $at = (new \DateTimeImmutable($timestamp))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $this->execute('UPDATE mikrodius_entitlements SET observed=?,observed_at=? WHERE id=? AND (observed_at IS NULL OR observed_at<=?)',
            [json_encode($subscriber, JSON_THROW_ON_ERROR), $at, $entity['id'], $at]);
    }

    public function observeNas(string $workspace, array $nas, string $timestamp): void
    {
        if (($nas['workspace_id'] ?? null) !== $workspace) { throw new Problem(409, 'nas_mapping_conflict'); }
        if (!$this->one('SELECT local_id FROM mikrodius_devices WHERE workspace_id=? AND nas_id=? LIMIT 1', [$workspace, $nas['id'] ?? ''])) { return; }
        $at = WebhookReceiver::timestamp($timestamp);
        $safe = array_intersect_key($nas, array_flip(['id', 'workspace_id', 'name', 'status', 'last_seen_at', 'updated_at']));
        $old = $this->one('SELECT observed_at FROM mikrodius_nas_status WHERE workspace_id=? AND nas_id=? FOR UPDATE', [$workspace, $nas['id']]);
        if (!$old || $old['observed_at'] <= $at) {
            $this->execute('INSERT INTO mikrodius_nas_status (workspace_id,nas_id,observed,observed_at) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE observed=VALUES(observed),observed_at=VALUES(observed_at)', [$workspace, $nas['id'], json_encode($safe, JSON_THROW_ON_ERROR), $at]);
        }
    }
}
