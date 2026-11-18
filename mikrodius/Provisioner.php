<?php
namespace Mikrodius;

/**
 * Selective MikroTik provisioning through PHPNuxBill's stored router login.
 *
 * Every write is an idempotent "ensure" that reads the device first, so a retry after an
 * interruption converges instead of duplicating. Operations and steps are durable; the
 * NAS configuration (its secrets) is fetched per run and never stored or returned.
 * Resource names, ownership comments and readiness checks follow core/api/routeros/generator.go.
 */
final class Provisioner
{
    public const STEPS = ['inspect', 'nas', 'preflight', 'certificate', 'tunnel', 'reachability', 'radius', 'services', 'verify'];
    public const READY_SECONDS = 180;
    private const FOLLOW = 'mikrodius-follow';
    private const TERMINAL = ['succeeded', 'cancelled'];

    private Service $service;
    private Store $store;
    private $routerFactory;
    /** Ready deadline; tests shorten it. */
    public int $readySeconds = self::READY_SECONDS;

    public function __construct(Service $service, ?callable $routerFactory = null)
    {
        $this->service = $service; $this->store = $service->store; $this->routerFactory = $routerFactory;
    }

    public function device(int $id): array
    {
        $router = $this->store->one('SELECT * FROM tbl_routers WHERE id=?', [$id]);
        if (!$router) { throw new Problem(422, 'device_not_found'); }
        return $router;
    }

    private function connect(array $router): RouterApi
    {
        // The NAS tunnel carries RADIUS only; management goes over the operator's own path.
        $host = preg_replace('/:\d+$/', '', trim((string) $router['ip_address']));
        if (preg_match('/^10\.(8|9)\.\d{1,3}\.\d{1,3}$/D', $host)) { throw new Problem(422, 'management_via_tunnel_not_supported'); }
        return $this->routerFactory ? ($this->routerFactory)($router) : PearRouterApi::connect($router);
    }

    // ---------------------------------------------------------------- inspection and preview

    /** Sanitized device state for the operator: no passwords, secrets or keys. */
    public function inspect(int $deviceId): array
    {
        $router = $this->device($deviceId);
        return $this->describe($router, RouterState::read($this->connect($router), $router));
    }

    private function describe(array $router, array $state): array
    {
        $servers = [];
        foreach ($state['hotspots'] as $hs) { $servers[$hs['profile'] ?? 'default'][] = $hs['name'] ?? ''; }
        $profiles = [];
        foreach ($state['profiles'] as $p) {
            $profiles[] = ['name' => $p['name'], 'default' => RouterState::truthy($p['default'] ?? 'false'),
                'use_radius' => RouterState::truthy($p['use-radius'] ?? 'false'), 'html_directory' => $p['html-directory'] ?? null,
                'servers' => $servers[$p['name']] ?? []];
        }
        $radius = [];
        foreach ($state['menus']['/radius'] as $r) {
            $radius[] = ['id' => $r['.id'], 'address' => $r['address'] ?? '', 'service' => $r['service'] ?? '',
                'disabled' => RouterState::truthy($r['disabled'] ?? 'false'), 'comment' => $r['comment'] ?? '',
                'owner' => RouterState::owner($r['comment'] ?? null)];
        }
        $tunnels = [];
        foreach ($state['menus']['/interface'] as $i) {
            if (in_array($i['type'] ?? '', ['wg', 'wireguard', 'l2tp-out', 'sstp-out'], true)) {
                $tunnels[] = ['name' => $i['name'], 'type' => $i['type'], 'running' => RouterState::truthy($i['running'] ?? 'false'),
                    'disabled' => RouterState::truthy($i['disabled'] ?? 'false'), 'comment' => $i['comment'] ?? ''];
            }
        }
        $localPpp = array_values(array_filter($state['ppp_secrets'], static fn($s) => !RouterState::truthy($s['disabled'] ?? 'false')));
        $localHs = array_values(array_filter($state['hotspot_users'], static fn($u) => !RouterState::truthy($u['disabled'] ?? 'false') && ($u['name'] ?? '') !== 'default-trial'));
        $filters = array_values(array_filter($state['menus']['/ip/firewall/filter'], static fn($r) => ($r['chain'] ?? '') === 'input'));
        return ['device' => ['id' => (int) $router['id'], 'name' => $router['name']],
            'routeros' => $state['version'], 'access' => $state['access'],
            'owners' => RouterState::owners($state), 'tunnels' => $tunnels, 'radius' => $radius,
            'radius_incoming' => ['accept' => RouterState::truthy($state['incoming']['accept'] ?? 'false'), 'port' => $state['incoming']['port'] ?? null],
            'ppp' => ['aaa' => RouterState::safe($state['aaa']), 'servers' => $state['ppp_servers'],
                'local_users' => count($localPpp), 'local_names' => array_slice(array_column($localPpp, 'name'), 0, 50)],
            'hotspot' => ['servers' => array_map(static fn($h) => ['name' => $h['name'] ?? '', 'interface' => $h['interface'] ?? '',
                'profile' => $h['profile'] ?? '', 'disabled' => RouterState::truthy($h['disabled'] ?? 'false')], $state['hotspots']),
                'profiles' => $profiles, 'local_users' => count($localHs), 'local_names' => array_slice(array_column($localHs, 'name'), 0, 50)],
            'firewall' => ['input_rules' => count($filters)]];
    }

    /**
     * What an operation with this choice would change, its consequences and what blocks it.
     *
     * choice: workspace_id, nas {mode: mapped|create|link, nas_id, name, connection}, ppp,
     * hotspot_profiles [names], acknowledge {shared_profiles, provider_cutover, local_users}.
     */
    public function preview(int $deviceId, array $choice): array
    {
        $router = $this->device($deviceId);
        $state = RouterState::read($this->connect($router), $router);
        return $this->plan($router, $state, $choice);
    }

    /** Resources another NAS (or an untagged Mikrodius install) owns, and reserved names it holds. */
    private function collisions(array $state, ?string $nas): array
    {
        $found = [];
        foreach (RouterState::owners($state) as $owner => $items) {
            if ($owner !== $nas) { foreach ($items as $item) { $found[] = $item['menu'] . ' ' . $item['comment']; } }
        }
        foreach ($state['menus']['/interface'] as $i) {
            if (in_array($i['name'] ?? '', ['wg-mikrodius', 'l2tp-mikrodius', 'sstp-mikrodius'], true) && ($nas === null || RouterState::owner($i['comment'] ?? null) !== $nas)) {
                $found[] = '/interface ' . $i['name'];
            }
        }
        foreach ($state['menus']['/system/scheduler'] as $s) {
            if (($s['name'] ?? '') === self::FOLLOW && ($nas === null || ($s['comment'] ?? '') !== self::comment('Follow', $nas))) { $found[] = '/system/scheduler ' . self::FOLLOW; }
        }
        return array_values(array_unique($found));
    }

    private function plan(array $router, array $state, array $choice): array
    {
        $blockers = []; $impacts = []; $settings = [];
        $block = static function (string $code, string $message, ?string $ack = null, array $items = []) use (&$blockers) {
            $blockers[] = ['code' => $code, 'message' => $message, 'acknowledge' => $ack, 'items' => array_values($items)];
        };
        $ack = array_map('boolval', (array) ($choice['acknowledge'] ?? []));
        $mapping = $this->store->one('SELECT * FROM mikrodius_devices WHERE local_id=?', [$router['id']]);
        $workspace = $mapping['workspace_id'] ?? (string) ($choice['workspace_id'] ?? '');
        ApiClient::workspace($workspace);
        if ($mapping && !empty($choice['workspace_id']) && $choice['workspace_id'] !== $mapping['workspace_id']) {
            $block('workspace_mapping_conflict', 'This device is mapped to another workspace.');
        }
        $nas = $this->nasChoice($router, $mapping, $workspace, (array) ($choice['nas'] ?? []), $block);
        if ($this->store->one("SELECT id FROM mikrodius_provisioning WHERE active_device=?", [$router['id']])) {
            $block('operation_active', 'Another installation for this device has not finished.');
        }

        $version = $state['version'];
        $unsupported = $nas['connection'] ? RouterState::unsupported($version, $nas['connection']) : null;
        if ($unsupported) {
            $block($unsupported, ['wireguard_requires_v7' => 'WireGuard needs RouterOS v7.',
                'routeros_v7_uses_wireguard' => 'RouterOS v7 connects over WireGuard. L2TP and SSTP are for RouterOS v6.',
                'routeros_unsupported' => 'RouterOS 6.49.10 or later is required.'][$unsupported], null, [$version['text']]);
        }
        if ($state['access']['missing']) {
            $block('router_permissions_missing', 'The stored router login lacks required policies.', null, $state['access']['missing']);
        }
        if ($collisions = $this->collisions($state, $nas['nas_id'])) {
            $block('device_owned_by_other_nas', 'Mikrodius resources on this device belong to another NAS or an untagged install. Link that NAS, or remove them first.', null, $collisions);
        }

        $ppp = (bool) ($choice['ppp'] ?? false);
        $selected = array_values(array_unique(array_map('strval', (array) ($choice['hotspot_profiles'] ?? []))));
        if (!$ppp && !$selected) { $block('nothing_selected', 'Choose PPP, one or more hotspot profiles, or both.'); }
        $profiles = []; $servers = [];
        foreach ($state['profiles'] as $p) { $profiles[$p['name']] = $p; }
        foreach ($state['hotspots'] as $hs) { $servers[$hs['profile'] ?? 'default'][] = $hs['name'] ?? ''; }
        foreach ($selected as $name) {
            if (!isset($profiles[$name])) { $block('hotspot_profile_not_found', 'Hotspot profile not found.', null, [$name]); continue; }
            $p = $profiles[$name];
            $settings['hotspot_profile:' . $name] = ['label' => 'Hotspot profile ' . $name,
                'before' => ['use-radius' => $p['use-radius'] ?? null, 'radius-accounting' => $p['radius-accounting'] ?? null, 'radius-interim-update' => $p['radius-interim-update'] ?? null],
                'after' => ['use-radius' => 'true', 'radius-accounting' => 'true', 'radius-interim-update' => '2m']];
            if (count($servers[$name] ?? []) > 1) {
                $block('shared_hotspot_profile', 'Profile ' . $name . ' is used by several hotspot servers; all of them change.', 'shared_profiles', $servers[$name]);
            }
        }
        if ($selected) {
            // RADIUS entries are not per profile: every profile with use-radius=yes asks them.
            $others = [];
            foreach ($profiles as $name => $p) {
                if (!in_array($name, $selected, true) && RouterState::truthy($p['use-radius'] ?? 'false')) { $others[] = $name; }
            }
            if ($others) { $block('shared_radius_profiles', 'These unselected profiles already use RADIUS and will authenticate against Mikrodius too.', 'shared_profiles', $others); }
            $impacts[] = ['code' => 'hotspot_servers', 'message' => 'Hotspot servers using the selected profiles.',
                'items' => array_merge(...array_map(static fn($n) => $servers[$n] ?? [], $selected ?: [''])) ];
        }
        if ($ppp) {
            $settings['ppp_aaa'] = ['label' => 'PPP AAA (global)',
                'before' => ['use-radius' => $state['aaa']['use-radius'] ?? null, 'accounting' => $state['aaa']['accounting'] ?? null, 'interim-update' => $state['aaa']['interim-update'] ?? null],
                'after' => ['use-radius' => 'true', 'accounting' => 'true', 'interim-update' => '2m']];
            $impacts[] = ['code' => 'ppp_global', 'message' => 'PPP AAA is global: every PPP server on this device uses RADIUS.',
                'items' => array_map(static fn($s) => $s['kind'] . ': ' . $s['name'], $state['ppp_servers'])];
        }
        $services = array_merge($ppp ? ['ppp'] : [], $selected ? ['hotspot'] : []);
        $providers = [];
        foreach ($state['menus']['/radius'] as $r) {
            if (RouterState::owner($r['comment'] ?? null) !== null || RouterState::truthy($r['disabled'] ?? 'false')) { continue; }
            if (array_intersect($services, explode(',', (string) ($r['service'] ?? '')))) { $providers[] = $r; }
        }
        if ($providers) {
            $block('existing_radius_provider', 'Another RADIUS server answers these services first. Cutting over disables these entries for all of their services.',
                'provider_cutover', array_map(static fn($r) => ($r['address'] ?? '') . ' (' . ($r['service'] ?? '') . ')', $providers));
            if (!empty($ack['provider_cutover'])) {
                foreach ($providers as $r) {
                    $settings['radius_provider:' . $r['.id']] = ['label' => 'Disable RADIUS ' . ($r['address'] ?? '') . ' (' . ($r['service'] ?? '') . ')',
                        'match' => ['address' => $r['address'] ?? '', 'service' => $r['service'] ?? ''],
                        'before' => ['disabled' => 'false'], 'after' => ['disabled' => 'true']];
                }
            }
        }
        $settings['radius_incoming'] = ['label' => 'RADIUS incoming (CoA/Disconnect)',
            'before' => ['accept' => $state['incoming']['accept'] ?? null, 'port' => $state['incoming']['port'] ?? null],
            'after' => ['accept' => 'true', 'port' => '3799']];
        $shadow = [];
        if ($ppp) { foreach ($state['ppp_secrets'] as $s) { if (!RouterState::truthy($s['disabled'] ?? 'false')) { $shadow[] = 'ppp: ' . $s['name']; } } }
        if ($selected) {
            foreach ($state['hotspot_users'] as $u) {
                if (!RouterState::truthy($u['disabled'] ?? 'false') && ($u['name'] ?? '') !== 'default-trial') { $shadow[] = 'hotspot: ' . $u['name']; }
            }
        }
        if ($shadow) {
            $block('local_users_shadow', 'Local users are checked before RADIUS. Migrate or disable them yourself; they are never deleted.', 'local_users', array_slice($shadow, 0, 50));
        }
        foreach ($settings as $key => $setting) {
            $changed = false;
            foreach ($setting['after'] as $field => $value) { $changed = $changed || !RouterState::same($field, $setting['before'][$field] ?? null, $value); }
            $settings[$key]['changes'] = $changed;
        }
        $impacts[] = ['code' => 'owned_resources', 'message' => 'Mikrodius adds or updates only its own tagged tunnel, RADIUS entry, CoA firewall rule and DNS follower.', 'items' => []];
        $services = ['ppp' => $ppp, 'hotspot_profiles' => $selected];
        $services['radius'] = self::radiusServices($services, $state['menus']['/radius'], $nas['nas_id']);
        $impacts[] = ['code' => 'radius_services', 'message' => 'RADIUS services that remain enabled on this NAS; existing services are preserved.', 'items' => $services['radius']];
        $open = array_values(array_filter($blockers, static fn($b) => $b['acknowledge'] === null || empty($ack[$b['acknowledge']])));
        return ['device_id' => (int) $router['id'], 'workspace_id' => $workspace, 'nas' => $nas,
            'services' => $services, 'routeros' => $version,
            'settings' => $settings, 'impacts' => $impacts, 'blockers' => $blockers, 'open_blockers' => $open, 'ready' => !$open];
    }

    private function nasChoice(array $router, ?array $mapping, string $workspace, array $choice, callable $block): array
    {
        $path = ApiClient::workspace($workspace) . '/nas/';
        $mode = $choice['mode'] ?? ($mapping['nas_id'] ?? null ? 'mapped' : 'create');
        if (!empty($mapping['nas_id'])) {
            if ($mode !== 'mapped') { $block('device_mapped_to_nas', 'This device already has a NAS; it is reused, never replaced.'); }
            $nas = $this->service->client()->request('GET', $path . rawurlencode($mapping['nas_id']));
            return ['mode' => 'mapped', 'nas_id' => $nas['id'], 'name' => $nas['name'] ?? '', 'connection' => $nas['connection'] ?? null, 'tunnel_ip' => $nas['tunnel_ip'] ?? null];
        }
        if ($mode === 'link') {
            $id = (string) ($choice['nas_id'] ?? '');
            if (!preg_match('/^nas_[A-Za-z0-9]+$/D', $id)) { throw new Problem(422, 'invalid_nas'); }
            $nas = $this->service->client()->request('GET', $path . rawurlencode($id));
            if (($nas['workspace_id'] ?? null) !== $workspace) { throw new Problem(409, 'nas_mapping_conflict'); }
            $other = $this->store->one('SELECT local_id FROM mikrodius_devices WHERE nas_id=? AND local_id<>?', [$id, $router['id']]);
            if ($other) { $block('nas_mapped_to_other_device', 'That NAS is linked to another PHPNuxBill device.'); }
            return ['mode' => 'link', 'nas_id' => $id, 'name' => $nas['name'] ?? '', 'connection' => $nas['connection'] ?? null, 'tunnel_ip' => $nas['tunnel_ip'] ?? null];
        }
        if ($mode !== 'create') { throw new Problem(422, 'invalid_nas_mode'); }
        $this->service->client()->request('GET', ApiClient::workspace($workspace));
        $connection = (string) ($choice['connection'] ?? 'wireguard');
        if (!in_array($connection, ['wireguard', 'l2tp', 'sstp'], true)) { throw new Problem(422, 'invalid_connection'); }
        $name = trim((string) ($choice['name'] ?? $router['name']));
        if ($name === '' || mb_strlen($name) > 100) { throw new Problem(422, 'invalid_nas_name'); }
        return ['mode' => 'create', 'nas_id' => null, 'name' => $name, 'connection' => $connection, 'tunnel_ip' => null];
    }

    // ---------------------------------------------------------------- operations

    public function start(int $deviceId, array $choice, ?int $adminId = null): int
    {
        if (!$this->store->lock('prepare-nas:' . $deviceId)) { throw new Problem(409, 'onboarding_busy'); }
        try {
            $journal = (new Workspace($this->service, $this))->preparation($deviceId);
            $map = $this->store->one('SELECT nas_id FROM mikrodius_devices WHERE local_id=?', [$deviceId]);
            if (empty($map['nas_id']) && in_array($journal['state'] ?? '', ['creating', 'uncertain', 'created'], true)
                && ($choice['nas']['mode'] ?? 'create') === 'create') {
                throw new Problem(409, 'nas_creation_uncertain');
            }
            return $this->startPrepared($deviceId, $choice, $adminId);
        } finally { $this->store->unlock('prepare-nas:' . $deviceId); }
    }

    private function startPrepared(int $deviceId, array $choice, ?int $adminId): int
    {
        $router = $this->device($deviceId);
        $preview = $this->plan($router, RouterState::read($this->connect($router), $router), $choice);
        if (!$preview['ready']) {
            throw new Problem(409, 'provisioning_blocked', null, 0, implode(', ', array_column($preview['open_blockers'], 'code')));
        }
        $intent = ['choice' => $choice, 'preview' => $preview];
        return $this->store->transaction(function () use ($router, $preview, $intent, $adminId) {
            try {
                $this->store->execute('INSERT INTO mikrodius_provisioning (local_device_id,active_device,workspace_id,nas_id,intent,state,created_by,created_at,updated_at)
                    VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))', [$router['id'], $router['id'], $preview['workspace_id'],
                    $preview['nas']['nas_id'], json_encode($intent, JSON_THROW_ON_ERROR), 'running', $adminId]);
            } catch (\PDOException $e) {
                if ($e->getCode() === '23000') { throw new Problem(409, 'operation_active'); }
                throw $e;
            }
            $id = (int) $this->store->db->lastInsertId();
            foreach (self::STEPS as $position => $step) {
                $this->store->execute('INSERT INTO mikrodius_provisioning_steps (operation_id,position,step) VALUES (?,?,?)', [$id, $position, $step]);
            }
            return $id;
        });
    }

    public function operation(int $id): array
    {
        $op = $this->store->one('SELECT * FROM mikrodius_provisioning WHERE id=?', [$id]);
        if (!$op) { throw new Problem(404, 'operation_not_found'); }
        return $op;
    }

    /** Operator-facing state. Contains no NAS secrets, router login or API key. */
    public function status(int $id): array
    {
        $op = $this->operation($id); $intent = json_decode($op['intent'], true);
        $steps = [];
        foreach ($this->steps($id) as $s) {
            $steps[] = ['step' => $s['step'], 'state' => $s['state'], 'attempts' => (int) $s['attempts'],
                'started_at' => $s['first_started_at'], 'finished_at' => $s['finished_at'],
                'evidence' => $s['evidence'] ? json_decode($s['evidence'], true) : null,
                'error_code' => $s['error_code'], 'error_detail' => $s['error_detail'], 'request_id' => $s['request_id']];
        }
        $router = $this->store->one('SELECT id,name FROM tbl_routers WHERE id=?', [$op['local_device_id']]);
        return ['id' => (int) $op['id'], 'device' => ['id' => (int) $op['local_device_id'], 'name' => $router['name'] ?? null],
            'workspace_id' => $op['workspace_id'], 'nas_id' => $op['nas_id'], 'state' => $op['state'],
            'created_at' => $op['created_at'], 'updated_at' => $op['updated_at'], 'finished_at' => $op['finished_at'],
            'services' => $intent['preview']['services'], 'nas' => $intent['preview']['nas'],
            'settings' => array_map(static fn($s) => ['label' => $s['label'], 'before' => $s['before'], 'after' => $s['after']], $intent['preview']['settings']),
            'steps' => $steps];
    }

    public function operations(?int $deviceId = null, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT id,local_device_id,workspace_id,nas_id,state,created_at,updated_at,finished_at FROM mikrodius_provisioning';
        return $deviceId === null ? $this->store->rows($sql . ' ORDER BY id DESC LIMIT ' . $limit)
            : $this->store->rows($sql . ' WHERE local_device_id=? ORDER BY id DESC LIMIT ' . $limit, [$deviceId]);
    }

    private function steps(int $id): array
    {
        return $this->store->rows('SELECT * FROM mikrodius_provisioning_steps WHERE operation_id=? ORDER BY position', [$id]);
    }

    /** Runs steps until one waits, fails or the time budget ends. Returns status(). */
    public function advance(int $id, int $seconds = 20): array
    {
        $op = $this->operation($id);
        if (in_array($op['state'], self::TERMINAL, true)) { return $this->status($id); }
        $router = $this->device((int) $op['local_device_id']);
        $host = 'provision:host:' . strtolower(preg_replace('/:\d+$/', '', trim((string) $router['ip_address'])));
        if (!$this->store->lock('provision:device:' . $router['id'])) { return ['busy' => true] + $this->status($id); }
        try {
            if (!$this->store->lock($host)) { return ['busy' => true] + $this->status($id); }
            try { $this->run($id, $router, microtime(true) + $seconds); }
            finally { $this->store->unlock($host); }
        } finally { $this->store->unlock('provision:device:' . $router['id']); }
        return $this->status($id);
    }

    private function run(int $id, array $router, float $deadline): void
    {
        $ctx = ['router' => $router];
        while (microtime(true) < $deadline) {
            $op = $this->operation($id);
            if (!in_array($op['state'], ['running'], true)) { return; }
            $step = null;
            foreach ($this->steps($id) as $s) { if (!in_array($s['state'], ['succeeded', 'skipped'], true)) { $step = $s; break; } }
            if (!$step) { $this->finish($id, 'succeeded'); return; }
            if ($step['state'] === 'running' && $step['step'] === 'nas' && ($op['nas_id'] === null)) {
                // A worker died during NAS creation: whether a billed NAS exists is unknown.
                $this->mark($id, $step, 'uncertain', null, new Problem(0, 'nas_creation_uncertain'));
                $this->setState($id, 'needs_reconciliation'); return;
            }
            if ($step['state'] === 'waiting' && $step['available_at'] !== null && strtotime($step['available_at'] . ' UTC') > time()) { return; }
            if (in_array($step['state'], ['failed', 'uncertain'], true)) { return; }
            $this->store->execute("UPDATE mikrodius_provisioning_steps SET state='running',attempts=attempts+1,started_at=UTC_TIMESTAMP(6),
                first_started_at=COALESCE(first_started_at,UTC_TIMESTAMP(6)),error_code=NULL,error_detail=NULL,request_id=NULL
                WHERE operation_id=? AND position=?", [$id, $step['position']]);
            $this->store->execute('UPDATE mikrodius_provisioning SET updated_at=UTC_TIMESTAMP(6) WHERE id=?', [$id]);
            $step = $this->store->one('SELECT * FROM mikrodius_provisioning_steps WHERE operation_id=? AND position=?', [$id, $step['position']]);
            try {
                $op = $this->operation($id);
                // A completed preflight cannot authorize a changed device after a
                // retry, wait or dead worker. Check before every phase that can write.
                if (in_array($step['step'], ['certificate', 'tunnel', 'reachability', 'radius', 'services'], true)) {
                    $this->stepPreflight($op, $step, $ctx);
                }
                $result = $this->{'step' . ucfirst($step['step'])}($op, $step, $ctx);
                if (($result['wait'] ?? null) !== null) {
                    $this->store->execute("UPDATE mikrodius_provisioning_steps SET state='waiting',available_at=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP(6)),evidence=? WHERE operation_id=? AND position=?",
                        [(int) $result['wait'], json_encode($result['evidence'] ?? null, JSON_THROW_ON_ERROR), $id, $step['position']]);
                    continue;
                }
                $this->mark($id, $step, ($result['skipped'] ?? false) ? 'skipped' : 'succeeded', $result['evidence'] ?? null);
            } catch (\Throwable $e) {
                $problem = $e instanceof Problem ? $e : new Problem(500, 'local_storage_error');
                // Only a creation request can leave a billed NAS behind without telling us.
                if ($step['step'] === 'nas' && $this->operation($id)['nas_id'] === null && ($problem->status === 0 || $problem->status >= 500 || $problem->status === 424)) {
                    $this->mark($id, $step, 'uncertain', null, $problem);
                    $this->setState($id, 'needs_reconciliation'); return;
                }
                $this->mark($id, $step, 'failed', null, $problem);
                $this->setState($id, 'failed'); return;
            }
        }
    }

    private function mark(int $id, array $step, string $state, ?array $evidence, ?Problem $problem = null): void
    {
        $this->store->execute('UPDATE mikrodius_provisioning_steps SET state=?,finished_at=UTC_TIMESTAMP(6),evidence=COALESCE(?,evidence),
            error_code=?,error_detail=?,request_id=? WHERE operation_id=? AND position=?',
            [$state, $evidence === null ? null : json_encode($evidence, JSON_THROW_ON_ERROR), $problem?->problemCode,
                $problem?->detail === null ? null : mb_substr($problem->detail, 0, 250), $problem?->requestId, $id, $step['position']]);
    }

    private function setState(int $id, string $state): void
    {
        $this->store->execute('UPDATE mikrodius_provisioning SET state=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?', [$state, $id]);
    }

    private function finish(int $id, string $state): void
    {
        $this->store->execute('UPDATE mikrodius_provisioning SET state=?,active_device=NULL,updated_at=UTC_TIMESTAMP(6),finished_at=UTC_TIMESTAMP(6) WHERE id=?', [$state, $id]);
    }

    /** Re-queues the failed step. It re-reads the device before changing anything. */
    public function retry(int $id): void
    {
        $op = $this->operation($id);
        if ($op['state'] !== 'failed') { throw new Problem(409, 'operation_not_failed'); }
        $this->store->transaction(function () use ($id) {
            $this->store->execute("UPDATE mikrodius_provisioning_steps SET state='pending',available_at=NULL,
                first_started_at=IF(step='reachability',NULL,first_started_at) WHERE operation_id=? AND state='failed'", [$id]);
            $this->setState($id, 'running');
        });
    }

    /** Stops an unfinished operation. Changes already applied stay; previous values remain in its evidence. */
    public function cancel(int $id): void
    {
        $op = $this->operation($id);
        if (in_array($op['state'], self::TERMINAL, true)) { return; }
        if (!$this->store->lock('provision:device:' . $op['local_device_id'], 5)) { throw new Problem(409, 'operation_busy'); }
        try { $this->finish($id, 'cancelled'); } finally { $this->store->unlock('provision:device:' . $op['local_device_id']); }
    }

    /** NAS in the workspace that an uncertain creation may have produced, for explicit linking. */
    public function candidates(int $id): array
    {
        $op = $this->operation($id); $intent = json_decode($op['intent'], true);
        $name = $intent['preview']['nas']['name'];
        $out = [];
        foreach ($this->service->client()->all(ApiClient::workspace($op['workspace_id']) . '/nas') as $nas) {
            if (($nas['workspace_id'] ?? null) !== $op['workspace_id'] || $this->store->one('SELECT local_id FROM mikrodius_devices WHERE nas_id=?', [$nas['id']])) { continue; }
            $out[] = ['id' => $nas['id'], 'name' => $nas['name'] ?? '', 'connection' => $nas['connection'] ?? null,
                'status' => $nas['status'] ?? null, 'created_at' => $nas['created_at'] ?? null, 'same_name' => ($nas['name'] ?? null) === $name];
        }
        return $out;
    }

    /**
     * Resolves an uncertain NAS creation: link a NAS the operator identified, or create
     * again after the operator confirmed none was created (a duplicate may be billed).
     */
    public function reconcile(int $id, string $action, ?string $nasId = null): void
    {
        $op = $this->operation($id);
        if ($op['state'] !== 'needs_reconciliation') { throw new Problem(409, 'operation_not_uncertain'); }
        if ($action === 'link') {
            if (!preg_match('/^nas_[A-Za-z0-9]+$/D', (string) $nasId)) { throw new Problem(422, 'invalid_nas'); }
            $nas = $this->service->client()->request('GET', ApiClient::workspace($op['workspace_id']) . '/nas/' . rawurlencode($nasId));
            if (($nas['workspace_id'] ?? null) !== $op['workspace_id']) { throw new Problem(409, 'nas_mapping_conflict'); }
            $this->link($op, $nas, 'linked by operator after uncertain creation');
            $this->setState($id, 'running');
            return;
        }
        if ($action !== 'create_again') { throw new Problem(422, 'invalid_reconciliation'); }
        $this->store->transaction(function () use ($id) {
            $this->store->execute("UPDATE mikrodius_provisioning_steps SET state='pending',error_code=NULL,error_detail=NULL WHERE operation_id=? AND step='nas'", [$id]);
            $this->setState($id, 'running');
        });
    }

    private function link(array $op, array $nas, string $how): void
    {
        $this->store->transaction(function () use ($op, $nas, $how) {
            $other = $this->store->one('SELECT local_id FROM mikrodius_devices WHERE nas_id=? AND local_id<>?', [$nas['id'], $op['local_device_id']]);
            if ($other) { throw new Problem(409, 'nas_mapped_to_other_device'); }
            $map = $this->store->one('SELECT * FROM mikrodius_devices WHERE local_id=? FOR UPDATE', [$op['local_device_id']]);
            if ($map && ($map['workspace_id'] !== $op['workspace_id'] || ($map['nas_id'] !== null && $map['nas_id'] !== $nas['id']))) {
                throw new Problem(409, 'device_mapping_conflict');
            }
            $this->store->execute('INSERT INTO mikrodius_devices (local_id,workspace_id,nas_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE nas_id=VALUES(nas_id)',
                [$op['local_device_id'], $op['workspace_id'], $nas['id']]);
            $this->store->execute('UPDATE mikrodius_provisioning SET nas_id=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?', [$nas['id'], $op['id']]);
            $this->store->execute("UPDATE mikrodius_provisioning_steps SET state='succeeded',finished_at=UTC_TIMESTAMP(6),error_code=NULL,error_detail=NULL,evidence=?
                WHERE operation_id=? AND step='nas'", [json_encode(['nas_id' => $nas['id'], 'name' => $nas['name'] ?? '', 'connection' => $nas['connection'] ?? null, 'how' => $how], JSON_THROW_ON_ERROR), $op['id']]);
        });
    }

    /** Continues unattended operations from cron; failed or uncertain ones wait for the operator. */
    public function resume(int $seconds = 20): void
    {
        $deadline = microtime(true) + $seconds;
        foreach ($this->store->rows("SELECT id FROM mikrodius_provisioning WHERE state='running' ORDER BY id LIMIT 20") as $row) {
            if (microtime(true) >= $deadline) { return; }
            try { $this->advance((int) $row['id'], (int) max(1, $deadline - microtime(true))); }
            catch (\Throwable $e) { error_log('Mikrodius provisioning resume: ' . ($e instanceof Problem ? $e->problemCode : 'local_error')); }
        }
    }

    /**
     * The full-install fallback: a fresh one-line install link for the mapped NAS. It enables
     * PPP RADIUS and RADIUS on the default and all active hotspot profiles, so the operator
     * must approve that explicitly. Earlier links stop working; credentials are unchanged.
     */
    public function installLink(int $deviceId, bool $approvedFullInstall): array
    {
        if (!$approvedFullInstall) { throw new Problem(422, 'full_install_not_approved'); }
        $map = $this->store->one('SELECT * FROM mikrodius_devices WHERE local_id=?', [$deviceId]);
        if (!$map || !$map['nas_id']) { throw new Problem(422, 'nas_mapping_required'); }
        $link = $this->service->client()->request('POST', ApiClient::workspace($map['workspace_id']) . '/nas/' . rawurlencode($map['nas_id']) . '/install-link');
        if (!is_string($link['command'] ?? null)) { throw new Problem(0, 'invalid_api_response'); }
        return ['command' => $link['command'], 'expires_at' => $link['expires_at'] ?? null];
    }

    // ---------------------------------------------------------------- steps

    private function intent(array $op): array { return json_decode($op['intent'], true); }

    private function api(array &$ctx): RouterApi
    {
        return $ctx['api'] ??= $this->connect($ctx['router']);
    }

    private function state(array &$ctx): array
    {
        return RouterState::read($this->api($ctx), $ctx['router']);
    }

    private function stepInspect(array $op, array $step, array &$ctx): array
    {
        $intent = $this->intent($op);
        $plan = $this->plan($ctx['router'], $this->state($ctx), $intent['choice']);
        $open = array_values(array_filter($plan['open_blockers'], static fn($b) => $b['code'] !== 'operation_active'));
        if ($open) { throw new Problem(409, 'provisioning_blocked', null, 0, implode(', ', array_column($open, 'code'))); }
        foreach ($intent['preview']['settings'] as $key => $setting) {
            $now = $plan['settings'][$key]['before'] ?? null;
            foreach ($setting['before'] as $field => $value) {
                if ($now === null || !RouterState::same($field, $now[$field] ?? null, $value)) {
                    throw new Problem(409, 'device_changed_since_preview', null, 0, $setting['label']);
                }
            }
        }
        return ['evidence' => ['routeros' => $plan['routeros']['text'], 'checked' => array_keys($intent['preview']['settings'])]];
    }

    private function stepNas(array $op, array $step, array &$ctx): array
    {
        $intent = $this->intent($op); $nas = $intent['preview']['nas'];
        $path = ApiClient::workspace($op['workspace_id']) . '/nas';
        if ($op['nas_id'] !== null) {
            $remote = $this->service->client()->request('GET', $path . '/' . rawurlencode($op['nas_id']));
            if (($remote['workspace_id'] ?? null) !== $op['workspace_id']) { throw new Problem(409, 'nas_mapping_conflict'); }
            $this->link($op, $remote, $nas['mode'] === 'create' ? 'created' : $nas['mode']);
            return ['evidence' => ['nas_id' => $remote['id'], 'name' => $remote['name'] ?? '', 'connection' => $remote['connection'] ?? null, 'how' => $nas['mode']]];
        }
        // Mikrodius generates every credential; the body names only what the operator chose.
        $remote = $this->service->client()->request('POST', $path, ['name' => $nas['name'], 'vendor' => 'mikrotik', 'connection' => $nas['connection']]);
        if (($remote['workspace_id'] ?? null) !== $op['workspace_id'] || !is_string($remote['id'] ?? null)) { throw new Problem(0, 'invalid_api_response'); }
        try { $this->link($op, $remote, 'created'); }
        catch (\Throwable $e) {
            // The NAS exists but is not recorded: never create another one on retry.
            throw new Problem(500, 'nas_created_mapping_failed', null, 0, $remote['id']);
        }
        return ['evidence' => ['nas_id' => $remote['id'], 'name' => $remote['name'] ?? '', 'connection' => $remote['connection'] ?? null, 'how' => 'created']];
    }

    /** Fetches the NAS and its JSON config. Secrets stay in memory for this run only. */
    private function config(array $op, array &$ctx): array
    {
        if (isset($ctx['config'])) { return $ctx['config']; }
        $path = ApiClient::workspace($op['workspace_id']) . '/nas/' . rawurlencode((string) $op['nas_id']);
        $nas = $this->service->client()->request('GET', $path);
        $config = $this->service->client()->request('GET', $path . '/config?format=json');
        $connection = $config['connection'] ?? null;
        if (($nas['id'] ?? null) !== $op['nas_id'] || $connection !== ($nas['connection'] ?? null)) { throw new Problem(409, 'nas_config_mismatch'); }
        $radius = $config['radius'] ?? [];
        $server = $connection === 'wireguard' ? '10.8.0.1' : '10.9.0.1';
        if (($radius['server'] ?? null) !== $server || (int) ($radius['auth_port'] ?? 0) !== 1812 || (int) ($radius['acct_port'] ?? 0) !== 1813 ||
            (int) ($radius['coa_port'] ?? 0) !== 3799 || !self::credential($radius['shared_secret'] ?? null)) {
            throw new Problem(422, 'invalid_nas_config', null, 0, 'radius');
        }
        $ip = (string) ($nas['tunnel_ip'] ?? '');
        $prefix = $connection === 'wireguard' ? '10.8.' : '10.9.';
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !str_starts_with($ip, $prefix) || in_array($ip, [$prefix . '0.0', $prefix . '0.1', $prefix . '255.255'], true)) {
            throw new Problem(422, 'invalid_nas_config', null, 0, 'tunnel_ip');
        }
        $out = ['nas_id' => $nas['id'], 'connection' => $connection, 'tunnel_ip' => $ip, 'server' => $server, 'secret' => $radius['shared_secret']];
        if ($connection === 'wireguard') {
            $wg = $config['wireguard'] ?? [];
            foreach (['private_key', 'server_public_key', 'preshared_key'] as $key) {
                $raw = base64_decode((string) ($wg[$key] ?? ''), true);
                if ($raw === false || strlen($raw) !== 32 || trim($raw, "\0") === '') { throw new Problem(422, 'invalid_nas_config', null, 0, 'wireguard ' . $key); }
            }
            if (!preg_match('/^(.+):(\d{1,5})$/D', (string) ($wg['endpoint'] ?? ''), $m)) { throw new Problem(422, 'invalid_nas_config', null, 0, 'wireguard endpoint'); }
            if (strtok((string) ($wg['address'] ?? ''), '/') !== $ip || ($wg['allowed_ips'] ?? '') !== '10.8.0.1/32') { throw new Problem(422, 'invalid_nas_config', null, 0, 'wireguard address'); }
            $out += ['host' => $m[1], 'port' => (int) $m[2], 'private_key' => $wg['private_key'], 'server_public_key' => $wg['server_public_key'], 'preshared_key' => $wg['preshared_key']];
        } elseif ($connection === 'l2tp' || $connection === 'sstp') {
            $ppp = $config[$connection] ?? [];
            if (!self::credential($ppp['username'] ?? null) || !self::credential($ppp['password'] ?? null) || !self::host($ppp['server'] ?? '')) {
                throw new Problem(422, 'invalid_nas_config', null, 0, $connection);
            }
            $out += ['host' => $ppp['server'], 'user' => $ppp['username'], 'password' => $ppp['password']];
            if ($connection === 'sstp') {
                if ((int) ($ppp['port'] ?? 0) !== 4443) { throw new Problem(422, 'invalid_nas_config', null, 0, 'sstp port'); }
                $out['fingerprint'] = self::caFingerprint((string) ($ppp['ca_certificate'] ?? ''));
                $out['ca'] = trim($ppp['ca_certificate']);
            }
        } else { throw new Problem(422, 'invalid_nas_config', null, 0, 'connection'); }
        if (!self::host($out['host'])) { throw new Problem(422, 'invalid_nas_config', null, 0, 'endpoint host'); }
        $api = $this->api($ctx);
        if ($api instanceof PearRouterApi) {
            $api->redact(...array_filter([$out['secret'], $out['private_key'] ?? null, $out['preshared_key'] ?? null, $out['password'] ?? null]));
        }
        return $ctx['config'] = $out;
    }

    private static function credential($value): bool
    {
        return is_string($value) && trim($value) !== '' && !preg_match('/[<>\r\n\x00]/', $value) && !str_contains(strtolower($value), 'placeholder');
    }

    private static function host(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) { return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4); }
        return (bool) preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$/D', $host) && !str_ends_with(strtolower($host), '.local');
    }

    /** SHA-256 of a self-signed, currently valid CA certificate, as RouterOS prints it. */
    public static function caFingerprint(string $pem): string
    {
        $pem = trim($pem);
        if (!preg_match('/^-----BEGIN CERTIFICATE-----\s+([A-Za-z0-9+\/=\s]+)\s+-----END CERTIFICATE-----$/D', $pem, $m)) {
            throw new Problem(422, 'invalid_nas_config', null, 0, 'sstp ca_certificate');
        }
        $cert = openssl_x509_read($pem); $info = $cert ? openssl_x509_parse($cert) : false;
        $der = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
        if (!$info || $der === false || !str_contains(strtoupper($info['extensions']['basicConstraints'] ?? ''), 'CA:TRUE') ||
            $info['subject'] != $info['issuer'] || openssl_x509_verify($cert, openssl_pkey_get_public($cert)) !== 1 ||
            time() < $info['validFrom_time_t'] || time() > $info['validTo_time_t']) {
            throw new Problem(422, 'invalid_nas_config', null, 0, 'sstp ca_certificate');
        }
        return hash('sha256', $der);
    }

    private function names(string $connection): array
    {
        return $connection === 'wireguard' ? ['iface' => 'wg-mikrodius', 'menu' => '/interface/wireguard']
            : ['iface' => $connection . '-mikrodius', 'menu' => '/interface/' . $connection . '-client'];
    }

    private static function comment(string $role, string $nas): string { return 'Mikrodius ' . $role . ' [NAS ' . $nas . ']'; }

    /** Selected services add to this NAS's existing shared RADIUS destination. */
    private static function radiusServices(array $services, array $rows, ?string $nas): array
    {
        $all = array_merge($services['radius'] ?? [], $services['ppp'] ? ['ppp'] : [], $services['hotspot_profiles'] ? ['hotspot'] : []);
        foreach ($rows as $row) {
            if ($nas !== null && ($row['comment'] ?? '') === self::comment('Cloud RADIUS', $nas)) {
                $all = array_merge($all, explode(',', (string) ($row['service'] ?? '')));
            }
        }
        return array_values(array_unique(array_filter(array_map('trim', $all))));
    }

    private function stepPreflight(array $op, array $step, array &$ctx): array
    {
        $config = $this->config($op, $ctx); $state = $this->state($ctx);
        if ($unsupported = RouterState::unsupported($state['version'], $config['connection'])) {
            throw new Problem(422, $unsupported, null, 0, $state['version']['text']);
        }
        if ($state['access']['missing']) { throw new Problem(422, 'router_permissions_missing', null, 0, implode(',', $state['access']['missing'])); }
        // Reserved names and tagged resources are claimed only when they carry this NAS's tag.
        if ($collisions = $this->collisions($state, $config['nas_id'])) {
            throw new Problem(409, 'device_owned_by_other_nas', null, 0, implode('; ', array_slice($collisions, 0, 5)));
        }
        return ['evidence' => ['nas_id' => $config['nas_id'], 'connection' => $config['connection'], 'tunnel_ip' => $config['tunnel_ip'],
            'radius_server' => $config['server'], 'endpoint_host' => $config['host'], 'routeros' => $state['version']['text']]];
    }

    private function stepCertificate(array $op, array $step, array &$ctx): array
    {
        $config = $this->config($op, $ctx);
        if ($config['connection'] !== 'sstp') { return ['skipped' => true, 'evidence' => ['reason' => 'only SSTP verifies the Mikrodius CA']]; }
        $api = $this->api($ctx);
        $find = static fn() => array_values(array_filter($api->rows('/certificate'), static fn($c) => strtolower($c['fingerprint'] ?? '') === $config['fingerprint']));
        $found = $find(); $imported = false;
        if (!$found) {
            $file = 'mikrodius-ca-' . $config['nas_id'] . '.txt';
            $cleanup = static function () use ($api, $file) {
                foreach ($api->rows('/file') as $f) { if (($f['name'] ?? '') === $file) { $api->remove('/file', $f['.id']); } }
            };
            // A leftover file under this NAS-specific name is from an interrupted attempt of ours.
            $cleanup();
            try { $api->writeFile($file, $config['ca']); $api->importCertificate($file); }
            finally { $cleanup(); }
            $found = $find(); $imported = true;
            if (!$found) { throw new Problem(422, 'ca_fingerprint_mismatch'); }
        }
        foreach ($found as $cert) {
            if (!RouterState::truthy($cert['trusted'] ?? 'false')) { $api->set('/certificate', $cert['.id'], ['trusted' => 'yes']); }
        }
        return ['evidence' => ['fingerprint' => $config['fingerprint'], 'imported' => $imported, 'trusted' => true]];
    }

    /** Resolves the endpoint host on the billing server; the follow scheduler refreshes it on the router. */
    private function endpoint(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { return $host; }
        $ips = gethostbynamel($host);
        if (!$ips) { throw new Problem(0, 'tunnel_dns_failed', null, 0, $host); }
        return $ips[0];
    }

    /**
     * Adds a missing resource or sets the properties that differ. A resource that matches
     * the selector but is not this NAS's stops the step before anything changes.
     */
    private function ensure(RouterApi $api, string $menu, callable $match, string $comment, array $props, array &$log, string $label, array $add = []): string
    {
        $rows = array_values(array_filter($api->rows($menu), $match));
        if (count($rows) > 1) { throw new Problem(409, 'duplicate_managed_resource', null, 0, $label); }
        $props['comment'] = $comment;
        if (!$rows) { $id = $api->add($menu, $add + $props); $log[$label] = 'added'; return $id; }
        $row = $rows[0];
        if (($row['comment'] ?? '') !== $comment) { throw new Problem(409, 'resource_owned_elsewhere', null, 0, $label); }
        $changes = [];
        foreach ($props as $field => $value) { if (!RouterState::same($field, $row[$field] ?? null, $value)) { $changes[$field] = $value; } }
        if ($changes) { $api->set($menu, $row['.id'], $changes); }
        $log[$label] = $changes ? 'updated: ' . implode(',', array_map(static fn($f) => RouterState::isSecretField($f) ? $f . ' (secret)' : $f, array_keys($changes))) : 'unchanged';
        return $row['.id'];
    }

    private function stepTunnel(array $op, array $step, array &$ctx): array
    {
        $c = $this->config($op, $ctx); $api = $this->api($ctx); $n = $this->names($c['connection']); $log = [];
        $endpoint = $this->endpoint($c['host']); $nas = $c['nas_id'];
        if ($c['connection'] === 'wireguard') {
            $this->ensure($api, $n['menu'], static fn($r) => ($r['name'] ?? '') === $n['iface'], self::comment('Cloud Tunnel', $nas),
                ['name' => $n['iface'], 'disabled' => 'no', 'listen-port' => '13231', 'private-key' => $c['private_key']], $log, 'tunnel ' . $n['iface']);
            $this->ensure($api, '/ip/address', static fn($r) => ($r['comment'] ?? '') === self::comment('Tunnel IP', $nas), self::comment('Tunnel IP', $nas),
                ['address' => $c['tunnel_ip'] . '/16', 'interface' => $n['iface'], 'network' => '10.8.0.0', 'disabled' => 'no'], $log, 'tunnel address');
            $this->ensure($api, '/interface/wireguard/peers', static fn($r) => ($r['comment'] ?? '') === self::comment('Server Peer', $nas), self::comment('Server Peer', $nas),
                ['interface' => $n['iface'], 'public-key' => $c['server_public_key'], 'endpoint-address' => $endpoint, 'endpoint-port' => (string) $c['port'],
                    'allowed-address' => '10.8.0.1/32', 'preshared-key' => $c['preshared_key'], 'persistent-keepalive' => '25s', 'disabled' => 'no'], $log, 'server peer');
        } else {
            $other = $c['connection'] === 'l2tp' ? 'sstp' : 'l2tp';
            $previous = array_values(array_filter($api->rows('/interface/' . $other . '-client'), static fn($r) => ($r['name'] ?? '') === $other . '-mikrodius'));
            foreach ($previous as $old) {
                if (($old['comment'] ?? '') !== self::comment('Cloud Tunnel', $nas)) { throw new Problem(409, 'resource_owned_elsewhere', null, 0, $other . '-mikrodius'); }
            }
            $prepare = $previous && !RouterState::truthy($previous[0]['disabled'] ?? 'false');
            $props = ['name' => $n['iface'], 'disabled' => $prepare ? 'yes' : 'no', 'connect-to' => $c['connection'] === 'sstp' ? $endpoint . ':4443' : $endpoint,
                'user' => $c['user'], 'password' => $c['password'], 'profile' => 'default-encryption', 'add-default-route' => 'no'];
            $props += $c['connection'] === 'l2tp' ? ['use-ipsec' => 'no'] : ['verify-server-certificate' => 'yes', 'verify-server-address-from-certificate' => 'no'];
            $this->ensure($api, $n['menu'], static fn($r) => ($r['name'] ?? '') === $n['iface'], self::comment('Cloud Tunnel', $nas), $props, $log, 'tunnel ' . $n['iface']);
            if ($prepare) {
                // Switch protocols the way the script does: prepare, disable the old, enable the new.
                foreach ($api->rows('/interface/' . $other . '-client') as $old) {
                    if (($old['name'] ?? '') !== $other . '-mikrodius') { continue; }
                    if (($old['comment'] ?? '') !== self::comment('Cloud Tunnel', $nas)) { throw new Problem(409, 'resource_owned_elsewhere', null, 0, $other . '-mikrodius'); }
                    $api->set('/interface/' . $other . '-client', $old['.id'], ['disabled' => 'yes']);
                }
                foreach ($api->rows($n['menu']) as $r) { if (($r['name'] ?? '') === $n['iface']) { $api->set($n['menu'], $r['.id'], ['disabled' => 'no']); } }
                $log['previous ' . $other . '-mikrodius'] = 'disabled; removed once the new tunnel is ready';
            }
        }
        $follow = array_values(array_filter($api->rows('/system/scheduler'), static fn($r) => ($r['name'] ?? '') === self::FOLLOW));
        if (!filter_var($c['host'], FILTER_VALIDATE_IP)) {
            $this->ensure($api, '/system/scheduler', static fn($r) => ($r['name'] ?? '') === self::FOLLOW, self::comment('Follow', $nas),
                ['name' => self::FOLLOW, 'interval' => '5m', 'start-time' => 'startup', 'disabled' => 'no',
                    'on-event' => self::followEvent($c['host'], $c['connection'], self::comment('Server Peer', $nas), self::comment('Cloud Tunnel', $nas))], $log, 'DNS follower');
        } elseif ($follow && !RouterState::truthy($follow[0]['disabled'] ?? 'false')) {
            if (($follow[0]['comment'] ?? '') !== self::comment('Follow', $nas)) { throw new Problem(409, 'resource_owned_elsewhere', null, 0, self::FOLLOW); }
            $api->set('/system/scheduler', $follow[0]['.id'], ['disabled' => 'yes']); $log['DNS follower'] = 'disabled (IP endpoint)';
        }
        return ['evidence' => ['resources' => $log, 'endpoint' => $endpoint]];
    }

    private function stepReachability(array $op, array $step, array &$ctx): array
    {
        $c = $this->config($op, $ctx); $api = $this->api($ctx); $n = $this->names($c['connection']);
        $waited = $step['first_started_at'] ? max(0, time() - strtotime($step['first_started_at'] . ' UTC')) : 0;
        $ready = false; $evidence = ['waited_seconds' => $waited];
        if ($c['connection'] === 'wireguard') {
            foreach ($api->rows('/interface/wireguard/peers') as $peer) {
                if (($peer['comment'] ?? '') === self::comment('Server Peer', $c['nas_id'])) {
                    $age = RouterState::seconds((string) ($peer['last-handshake'] ?? ''));
                    $evidence['last_handshake_seconds'] = $age;
                    $ready = $age !== null && $age < 120;
                }
            }
        } else {
            foreach ($api->rows('/interface') as $i) {
                if (($i['name'] ?? '') === $n['iface']) { $ready = RouterState::truthy($i['running'] ?? 'false'); $evidence['running'] = $ready; }
            }
        }
        if ($ready) {
            $replies = $api->ping($c['server'], 2);
            $received = 0;
            foreach ($replies as $r) { $received = max($received, (int) ($r['received'] ?? 0), isset($r['time']) && !isset($r['status']) ? 1 : 0); }
            $evidence['ping_received'] = $received;
            $ready = $received > 0;
        }
        if (!$ready) {
            if ($waited >= $this->readySeconds) {
                throw new Problem(504, 'tunnel_not_ready', null, 0, 'No reply from ' . $c['server'] . ' through the tunnel after ' . $waited . ' s. Check DNS, the firewall towards ' . $c['host'] . ' and the router clock.');
            }
            return ['wait' => 3, 'evidence' => $evidence];
        }
        if ($c['connection'] !== 'wireguard') {
            $other = $c['connection'] === 'l2tp' ? 'sstp' : 'l2tp';
            foreach ($api->rows('/interface/' . $other . '-client') as $r) {
                if (($r['name'] ?? '') === $other . '-mikrodius' && ($r['comment'] ?? '') === self::comment('Cloud Tunnel', $c['nas_id'])) {
                    $api->remove('/interface/' . $other . '-client', $r['.id']); $evidence['removed_previous'] = $other . '-mikrodius';
                }
            }
        }
        return ['evidence' => $evidence + ['note' => 'The RADIUS address answers through the tunnel. This does not prove a subscriber login.']];
    }

    /** Applies a previewed setting only where it still has its previewed value; reports operator edits. */
    private function applySetting(RouterApi $api, array $setting, ?array $row, string $menu, ?string $id, array &$log, string $key): void
    {
        if ($row === null) { $log[$key] = 'not found; skipped'; return; }
        $changes = [];
        foreach ($setting['after'] as $field => $value) {
            $current = $row[$field] ?? null;
            if (RouterState::same($field, $current, $value)) { continue; }
            if (!RouterState::same($field, $current, $setting['before'][$field] ?? null)) {
                throw new Problem(409, 'setting_changed_since_preview', null, 0, $setting['label'] . ': ' . $field);
            }
            $changes[$field] = in_array($value, ['true', 'false'], true) ? ($value === 'true' ? 'yes' : 'no') : $value;
        }
        if ($changes) { $id === null ? $api->setGlobal($menu, $changes) : $api->set($menu, $id, $changes); }
        $log[$key] = $changes ? 'set ' . implode(',', array_keys($changes)) : 'unchanged';
    }

    private function stepRadius(array $op, array $step, array &$ctx): array
    {
        $c = $this->config($op, $ctx); $api = $this->api($ctx); $n = $this->names($c['connection']);
        $intent = $this->intent($op); $log = [];
        foreach ($intent['preview']['settings'] as $key => $setting) {
            if (!str_starts_with($key, 'radius_provider:')) { continue; }
            $row = null;
            foreach ($api->rows('/radius') as $r) {
                if ($r['.id'] === substr($key, 16) && ($r['address'] ?? '') === $setting['match']['address'] && ($r['service'] ?? '') === $setting['match']['service']) { $row = $r; }
            }
            $this->applySetting($api, $setting, $row, '/radius', $row['.id'] ?? null, $log, $setting['label']);
        }
        $services = self::radiusServices($intent['preview']['services'], $api->rows('/radius'), $c['nas_id']);
        $this->ensure($api, '/radius', static fn($r) => ($r['comment'] ?? '') === self::comment('Cloud RADIUS', $c['nas_id']), self::comment('Cloud RADIUS', $c['nas_id']),
            ['service' => implode(',', $services), 'address' => $c['server'], 'secret' => $c['secret'], 'authentication-port' => '1812',
                'accounting-port' => '1813', 'timeout' => '3s', 'disabled' => 'no'], $log, 'RADIUS entry');
        $filters = $api->rows('/ip/firewall/filter');
        $first = $filters[0]['.id'] ?? null;
        $this->ensure($api, '/ip/firewall/filter', static fn($r) => ($r['comment'] ?? '') === self::comment('Allow CoA', $c['nas_id']), self::comment('Allow CoA', $c['nas_id']),
            ['chain' => 'input', 'action' => 'accept', 'protocol' => 'udp', 'dst-port' => '3799', 'src-address' => $c['server'], 'in-interface' => $n['iface'], 'disabled' => 'no'],
            $log, 'CoA firewall rule', $first === null ? [] : ['place-before' => $first]);
        $this->applySetting($api, $intent['preview']['settings']['radius_incoming'], $api->rows('/radius/incoming')[0] ?? null, '/radius/incoming', null, $log, 'RADIUS incoming');
        return ['evidence' => ['resources' => $log, 'services' => $services]];
    }

    private function stepServices(array $op, array $step, array &$ctx): array
    {
        $api = $this->api($ctx); $intent = $this->intent($op); $log = [];
        if (isset($intent['preview']['settings']['ppp_aaa'])) {
            $this->applySetting($api, $intent['preview']['settings']['ppp_aaa'], $api->rows('/ppp/aaa')[0] ?? null, '/ppp/aaa', null, $log, 'PPP AAA');
        }
        $profiles = [];
        foreach ($api->rows('/ip/hotspot/profile') as $p) { $profiles[$p['name']] = $p; }
        foreach ($intent['preview']['services']['hotspot_profiles'] as $name) {
            if (!isset($profiles[$name])) { throw new Problem(409, 'hotspot_profile_missing', null, 0, $name); }
            $this->applySetting($api, $intent['preview']['settings']['hotspot_profile:' . $name], $profiles[$name], '/ip/hotspot/profile', $profiles[$name]['.id'], $log, 'hotspot profile ' . $name);
        }
        return ['evidence' => ['settings' => $log]];
    }

    private function stepVerify(array $op, array $step, array &$ctx): array
    {
        $c = $this->config($op, $ctx); $state = $this->state($ctx); $intent = $this->intent($op); $n = $this->names($c['connection']);
        $nas = $c['nas_id']; $problems = []; $checked = [];
        $one = static function (array $rows, callable $match) { $m = array_values(array_filter($rows, $match)); return count($m) === 1 ? $m[0] : null; };
        $expect = static function (string $label, ?array $row, array $props) use (&$problems, &$checked) {
            if (!$row) { $problems[] = $label . ' missing'; return; }
            foreach ($props as $field => $value) {
                // Secrets are compared only when the login may read them; never reported.
                if (!array_key_exists($field, $row) && RouterState::isSecretField($field)) { continue; }
                if (!RouterState::same($field, $row[$field] ?? null, $value)) { $problems[] = $label . ' ' . $field; }
            }
            $checked[] = $label;
        };
        $tunnelRows = $c['connection'] === 'wireguard' ? $state['wireguard'] : $state[$c['connection']];
        $expect('tunnel', $one($tunnelRows, static fn($r) => ($r['name'] ?? '') === $n['iface'] && ($r['comment'] ?? '') === self::comment('Cloud Tunnel', $nas)), ['disabled' => 'false']);
        if ($c['connection'] === 'wireguard') {
            $expect('tunnel address', $one($state['menus']['/ip/address'], static fn($r) => ($r['comment'] ?? '') === self::comment('Tunnel IP', $nas)), ['address' => $c['tunnel_ip'] . '/16', 'interface' => $n['iface']]);
            $expect('server peer', $one($state['peers'], static fn($r) => ($r['comment'] ?? '') === self::comment('Server Peer', $nas)),
                ['public-key' => $c['server_public_key'], 'allowed-address' => '10.8.0.1/32', 'preshared-key' => $c['preshared_key']]);
        }
        // Verify the complete set actually applied, including preserved services.
        $radiusStep = $this->store->one("SELECT evidence FROM mikrodius_provisioning_steps WHERE operation_id=? AND step='radius'", [$op['id']]);
        $radiusEvidence = json_decode($radiusStep['evidence'] ?? 'null', true);
        $services = $radiusEvidence['services'] ?? self::radiusServices($intent['preview']['services'], $state['menus']['/radius'], $nas);
        $expect('RADIUS entry', $one($state['menus']['/radius'], static fn($r) => ($r['comment'] ?? '') === self::comment('Cloud RADIUS', $nas)),
            ['service' => implode(',', $services), 'address' => $c['server'], 'secret' => $c['secret'], 'authentication-port' => '1812', 'accounting-port' => '1813', 'disabled' => 'false']);
        $expect('CoA firewall rule', $one($state['menus']['/ip/firewall/filter'], static fn($r) => ($r['comment'] ?? '') === self::comment('Allow CoA', $nas)),
            ['chain' => 'input', 'action' => 'accept', 'protocol' => 'udp', 'dst-port' => '3799', 'src-address' => $c['server'], 'disabled' => 'false']);
        $expect('RADIUS incoming', $state['incoming'], ['accept' => 'true', 'port' => '3799']);
        if ($intent['preview']['services']['ppp']) { $expect('PPP AAA', $state['aaa'], ['use-radius' => 'true', 'accounting' => 'true', 'interim-update' => '2m']); }
        foreach ($intent['preview']['services']['hotspot_profiles'] as $name) {
            $expect('hotspot profile ' . $name, $one($state['profiles'], static fn($p) => ($p['name'] ?? '') === $name),
                ['use-radius' => 'true', 'radius-accounting' => 'true', 'radius-interim-update' => '2m']);
        }
        foreach (RouterState::owners($state) as $owner => $items) { if ($owner !== $nas) { $problems[] = 'resources of another NAS appeared'; } }
        if ($problems) { throw new Problem(409, 'verification_failed', null, 0, implode('; ', array_slice($problems, 0, 8))); }
        $remote = $this->service->client()->request('GET', ApiClient::workspace($op['workspace_id']) . '/nas/' . rawurlencode($nas));
        if (($remote['id'] ?? null) === $nas) {
            $this->store->transaction(fn() => $this->store->observeNas($op['workspace_id'], $remote, gmdate('c')));
        }
        return ['evidence' => ['verified' => $checked, 'nas_status' => $remote['status'] ?? null, 'nas_last_seen_at' => $remote['last_seen_at'] ?? null,
            'note' => 'Device configuration read back as expected. A subscriber login has not been tested.']];
    }

    // ---------------------------------------------------------------- generator.go parity

    private static function quote(string $s): string
    {
        return '"' . strtr($s, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$', "\r" => '\\r', "\n" => '\\n']) . '"';
    }

    /** The follow scheduler's on-event script, as generated by followEvent() in generator.go. */
    public static function followEvent(string $host, string $tunnel, string $peerComment, string $tunnelComment): string
    {
        $path = '/interface ' . $tunnel . '-client'; $selector = 'name=' . self::quote($tunnel . '-mikrodius'); $prop = 'connect-to';
        $endpoint = $tunnel === 'sstp' ? '($ip . ":4443")' : '$ip';
        $owner = $tunnelComment;
        if ($tunnel === 'wireguard') {
            $path = '/interface wireguard peers'; $selector = 'comment=' . self::quote($peerComment); $prop = 'endpoint-address'; $owner = $peerComment;
        }
        return sprintf(':global mkFollowBusy; :if ($mkFollowBusy = true) do={ :error "Mikrodius: DNS follower already running" }; :set mkFollowBusy true; :do { :local ip [:resolve %s]; :local t [%s find where %s]; :if ([:len $t] = 1) do={ :if ([%s get $t comment] = %s && [:tostr [%s get $t %s]] != [:tostr %s]) do={ %s set $t %s=%s } } } on-error={ :log warning "Mikrodius: DNS follower failed" }; :set mkFollowBusy false',
            self::quote($host), $path, $selector, $path, self::quote($owner), $path, $prop, $endpoint, $path, $prop, $endpoint);
    }
}
