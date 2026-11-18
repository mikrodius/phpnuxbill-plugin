<?php
namespace Mikrodius;

/** Workspace onboarding. Prepares cloud records without writing to a router or billing row. */
final class Workspace
{
    public function __construct(private Service $service, private Provisioner $provisioner) {}

    public function selected(): ?string
    {
        $saved = $this->service->store->one("SELECT value FROM mikrodius_settings WHERE name='workspace'");
        if ($saved) { return $saved['value']; }
        // Existing installations with one workspace keep working after an upgrade.
        $ids = $this->service->store->rows('SELECT workspace_id FROM mikrodius_devices UNION SELECT workspace_id FROM mikrodius_plans');
        return count($ids) === 1 ? $ids[0]['workspace_id'] : null;
    }

    private function record(string $name, array $value): void
    {
        $this->service->store->execute('INSERT INTO mikrodius_settings (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
            [$name, json_encode($value, JSON_THROW_ON_ERROR)]);
    }

    public function preparation(int $id): array
    {
        $row = $this->service->store->one('SELECT value FROM mikrodius_settings WHERE name=?', ['prepare.' . $id]);
        return $row ? json_decode($row['value'], true, 16, JSON_THROW_ON_ERROR) : [];
    }

    public function select(string $id): void
    {
        $this->service->client()->request('GET', ApiClient::workspace($id));
        $this->service->store->execute("INSERT INTO mikrodius_settings (name,value) VALUES ('workspace',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$id]);
        $row = $this->service->store->one("SELECT value FROM mikrodius_settings WHERE name='workspace-create'");
        if ($row && (json_decode($row['value'], true)['state'] ?? '') === 'creating') {
            $this->record('workspace-create', ['state' => 'resolved']);
        }
    }

    public function create(string $name): string
    {
        if ($name === '' || mb_strlen($name) > 100) { throw new Problem(422, 'invalid_workspace_name'); }
        $store = $this->service->store;
        if (!$store->lock('onboarding-workspace')) { throw new Problem(409, 'onboarding_busy'); }
        try {
            $row = $store->one("SELECT value FROM mikrodius_settings WHERE name='workspace-create'");
            $previous = $row ? json_decode($row['value'], true) : [];
            if (($previous['state'] ?? '') === 'creating') {
                throw new Problem(409, 'workspace_creation_uncertain', null, 0, 'Select the workspace from the list before creating another.');
            }
            if (($previous['name'] ?? '') === $name && !empty($previous['id'])) {
                $this->select($previous['id']); return $previous['id'];
            }
            $this->record('workspace-create', ['name' => $name, 'state' => 'creating']);
            try {
                $ws = $this->service->client()->request('POST', '/workspaces', ['name' => $name,
                    'time_zone' => $this->service->settings()['timezone'] ?? 'Asia/Jakarta']);
            } catch (Problem $e) {
                if ($e->status >= 400 && $e->status < 500 && $e->status !== 424) {
                    $this->record('workspace-create', ['name' => $name, 'state' => 'rejected']);
                }
                throw $e;
            }
            $this->record('workspace-create', ['name' => $name, 'state' => 'created', 'id' => $ws['id']]);
            $this->select($ws['id']);
            return $ws['id'];
        } finally { $store->unlock('onboarding-workspace'); }
    }

    /** One request per router, with a durable journal before every non-idempotent POST. */
    public function prepare(int $id, string $workspace, ?string $manualConnection = null, bool $createAgain = false): array
    {
        if ($workspace !== $this->selected()) { throw new Problem(409, 'workspace_selection_changed'); }
        $store = $this->service->store;
        if (!$store->lock('prepare-nas:' . $id)) { throw new Problem(409, 'onboarding_busy'); }
        try {
            $router = $this->provisioner->device($id);
            if (!$router['enabled']) { throw new Problem(422, 'device_disabled'); }
            $map = $store->one('SELECT * FROM mikrodius_devices WHERE local_id=?', [$id]);
            if ($map && $map['workspace_id'] !== $workspace) { throw new Problem(409, 'workspace_mapping_conflict'); }
            if (!empty($map['nas_id'])) { return ['nas_id' => $map['nas_id'], 'state' => 'ready']; }
            if ($store->one('SELECT id FROM mikrodius_provisioning WHERE active_device=?', [$id])) { throw new Problem(409, 'operation_active'); }
            $journal = $this->preparation($id);
            if ($journal && $journal['workspace_id'] !== $workspace) { throw new Problem(409, 'workspace_mapping_conflict'); }
            if (!empty($journal['nas_id'])) {
                $this->service->mapDevice($id, $workspace, $journal['nas_id']);
                return ['nas_id' => $journal['nas_id'], 'state' => 'ready'];
            }
            if (in_array($journal['state'] ?? '', ['creating', 'uncertain'], true)) {
                if (!$createAgain) { throw new Problem(409, 'nas_creation_uncertain', null, 0, 'Review existing NAS and link the one created for this router.'); }
                $manualConnection = $journal['connection'];
            }
            if ($manualConnection !== null && !in_array($manualConnection, ['wireguard', 'l2tp', 'sstp'], true)) { throw new Problem(422, 'invalid_connection'); }
            try {
                if ($manualConnection === null) {
                    $inspection = $this->provisioner->inspect($id);
                    if (!empty($inspection['owners'])) { throw new Problem(409, 'device_owned_by_other_nas'); }
                    $manualConnection = $inspection['routeros']['major'] >= 7 ? 'wireguard' : 'sstp';
                    $unsupported = RouterState::unsupported($inspection['routeros'], $manualConnection);
                    if ($unsupported) { throw new Problem(422, $unsupported); }
                }
            } catch (\Throwable $e) {
                $error = Ui::error($e);
                $this->record('prepare.' . $id, ['workspace_id' => $workspace, 'state' => 'needs_check', 'error_code' => $error['code']]);
                throw $e;
            }
            $journal = ['workspace_id' => $workspace, 'name' => mb_substr($router['name'], 0, 100), 'connection' => $manualConnection, 'state' => 'creating'];
            $this->record('prepare.' . $id, $journal);
            try {
                $nas = $this->service->client()->request('POST', ApiClient::workspace($workspace) . '/nas',
                    ['name' => $journal['name'], 'vendor' => 'mikrotik', 'connection' => $manualConnection]);
                // Keep the returned ID even if mapping fails or this worker stops immediately afterwards.
                $journal['nas_id'] = $nas['id']; $journal['state'] = 'created';
                $this->record('prepare.' . $id, $journal);
                $this->service->mapDevice($id, $workspace, $nas['id']);
                $store->transaction(fn() => $store->observeNas($workspace, $nas, gmdate('c')));
                return ['nas_id' => $nas['id'], 'state' => 'ready'];
            } catch (\Throwable $e) {
                $journal['state'] = $e instanceof Problem && $e->status >= 400 && $e->status < 500 && $e->status !== 424 ? 'rejected' : 'uncertain';
                $journal['error_code'] = Ui::error($e)['code'];
                $this->record('prepare.' . $id, $journal);
                throw $e;
            }
        } finally { $store->unlock('prepare-nas:' . $id); }
    }

    public function link(int $id, string $nas): void
    {
        $store = $this->service->store;
        if (!$store->lock('prepare-nas:' . $id)) { throw new Problem(409, 'onboarding_busy'); }
        try {
            $journal = $this->preparation($id);
            $journal = $journal ?: ['workspace_id' => $this->selected()];
            if (!$journal['workspace_id'] || $journal['workspace_id'] !== $this->selected()) { throw new Problem(409, 'workspace_selection_changed'); }
            $map = $store->one('SELECT nas_id FROM mikrodius_devices WHERE local_id=?', [$id]);
            if (!empty($map['nas_id']) && $map['nas_id'] !== $nas) { throw new Problem(409, 'device_mapped_to_nas'); }
            $this->service->mapDevice($id, $journal['workspace_id'], $nas);
            $journal['nas_id'] = $nas; $journal['state'] = 'created';
            $this->record('prepare.' . $id, $journal);
        } finally { $store->unlock('prepare-nas:' . $id); }
    }
}
