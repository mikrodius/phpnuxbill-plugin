<?php
namespace Mikrodius;

/**
 * Routes `?_route=plugin/mikrodius/<page>/…`. Pages are GET; every change is a POST that
 * passes Ui::verify(). Requests sent by the pages' scripts carry the token in the
 * X-Mikrodius-Csrf header and get JSON; plain form posts redirect back with a notice.
 */
final class Controller
{
    public static function dispatch(array $routes): void
    {
        $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
        $json = $post && isset($_SERVER['HTTP_X_MIKRODIUS_CSRF']);
        $admin = Ui::admin($json);
        $page = (string) ($routes[2] ?? '');
        try {
            if ($post) { Ui::verify(); }
            $service = Bridge::service();
            if ($page === '') { $page = 'overview'; }
            switch ($page) {
                case 'overview': Pages::overview($service, $admin, $post); return;
                case 'nas': self::nas($service, $admin, $post); return;
                case 'provision': self::provision($service, $admin, (int) ($routes[3] ?? 0), $post); return;
                case 'settings': case 'mappings': case 'jobs': case 'subscribers': case 'vouchers': case 'webhooks':
                    Pages::$page($service, $admin, $post); return;
                default: throw new Problem(404, 'page_not_found');
            }
        } catch (\Throwable $e) {
            if ($json) { Ui::json(['error' => Ui::error($e)], Ui::status($e)); }
            if ($post && isset(Ui::TABS[$page])) {
                $error = Ui::error($e);
                Ui::back($page, 'e', $error['message'] . ($error['detail'] ? ' ' . $error['detail'] : '') .
                    ($error['request_id'] ? ' Request ID: ' . $error['request_id'] : ''));
            }
            http_response_code(Ui::status($e));
            Ui::render('mikrodius_error.tpl', $admin, ['mk_page' => $page, 'mk_error' => Ui::error($e)]);
        }
    }

    /** Request input that must be a string; arrays and other shapes are refused. */
    public static function input(string $name, string $default = ''): string
    {
        $value = $_POST[$name] ?? $default;
        if (!is_string($value)) { throw new Problem(422, 'invalid_' . preg_replace('/[^a-z_]/', '', $name)); }
        return trim($value);
    }

    private static function nas(Service $service, array $admin, bool $post): void
    {
        if ($post) {
            if (self::input('action') !== 'refresh') { throw new Problem(422, 'unsupported_action'); }
            foreach ($service->store->rows('SELECT * FROM mikrodius_devices WHERE nas_id IS NOT NULL') as $map) {
                $nas = $service->client()->request('GET', ApiClient::workspace($map['workspace_id']) . '/nas/' . rawurlencode($map['nas_id']));
                if (($nas['id'] ?? null) === $map['nas_id']) { $service->store->transaction(fn() => $service->store->observeNas($map['workspace_id'], $nas, gmdate('c'))); }
            }
            Ui::back('nas', 's', 'NAS status refreshed.');
        }
        Ui::render('mikrodius_nas.tpl', $admin, ['mk_page' => 'nas', 'mk_configured' => $service->settings()['configured'], 'mk_devices' => self::devices($service)]);
    }

    public static function devices(Service $service): array
    {
        $rows = $service->store->rows('SELECT r.id,r.name,r.ip_address,r.description,r.enabled,m.workspace_id,m.nas_id,s.observed,s.observed_at,
                (SELECT COUNT(*) FROM tbl_plans p WHERE p.routers=r.name AND p.device=\'MikrodiusRadius\') AS radius_plans
            FROM tbl_routers r LEFT JOIN mikrodius_devices m ON m.local_id=r.id
            LEFT JOIN mikrodius_nas_status s ON s.workspace_id=m.workspace_id AND s.nas_id=m.nas_id ORDER BY r.name');
        foreach ($rows as &$row) {
            $observed = $row['observed'] ? json_decode($row['observed'], true) : [];
            $row['nas_status'] = $observed['status'] ?? null;
            $row['nas_last_seen_at'] = $observed['last_seen_at'] ?? null;
            $op = $service->store->one('SELECT id,state,updated_at FROM mikrodius_provisioning WHERE local_device_id=? ORDER BY id DESC LIMIT 1', [$row['id']]);
            $row['operation'] = $op;
            unset($row['observed']);
        }
        return $rows;
    }

    private static function provision(Service $service, array $admin, int $deviceId, bool $post): void
    {
        $provisioner = Bridge::provisioner();
        $device = $provisioner->device($deviceId);
        $operation = function () use ($provisioner, $deviceId): int {
            $id = (int) self::input('operation');
            if ($id <= 0 || (int) $provisioner->operation($id)['local_device_id'] !== $deviceId) { throw new Problem(404, 'operation_not_found'); }
            return $id;
        };
        if ($post) {
            $choice = static function (): array {
                $choice = json_decode(self::input('choice', '{}'), true, 8);
                if (!is_array($choice)) { throw new Problem(422, 'invalid_choice'); }
                return $choice;
            };
            $result = match (self::input('action')) {
                'inspect' => ['inspection' => $provisioner->inspect($deviceId)],
                'workspaces' => ['workspaces' => array_map(static fn($w) => ['id' => $w['id'], 'name' => $w['name'] ?? $w['id']], $service->testConnection())],
                'nas_list' => ['nas' => self::unmappedNas($service, self::input('workspace_id'))],
                'preview' => ['preview' => $provisioner->preview($deviceId, $choice())],
                'start' => ['operation' => $provisioner->status($provisioner->start($deviceId, $choice(), (int) $admin['id']))],
                'advance' => ['operation' => $provisioner->advance($operation(), 15)],
                'status' => ['operation' => $provisioner->status($operation())],
                'retry' => (function () use ($provisioner, $operation) { $id = $operation(); $provisioner->retry($id); return ['operation' => $provisioner->status($id)]; })(),
                'cancel' => (function () use ($provisioner, $operation) { $id = $operation(); $provisioner->cancel($id); return ['operation' => $provisioner->status($id)]; })(),
                'candidates' => ['candidates' => $provisioner->candidates($operation())],
                'reconcile' => (function () use ($provisioner, $operation) {
                    $id = $operation(); $action = self::input('resolution');
                    if ($action === 'create_again' && self::input('confirm') !== 'yes') { throw new Problem(422, 'confirmation_required'); }
                    $provisioner->reconcile($id, $action, self::input('nas_id') ?: null);
                    return ['operation' => $provisioner->status($id)];
                })(),
                'install_link' => ['install_link' => $provisioner->installLink($deviceId, self::input('approve_full_install') === 'yes')],
                default => throw new Problem(422, 'unsupported_action'),
            };
            Ui::json($result);
        }
        $latest = $service->store->one('SELECT id FROM mikrodius_provisioning WHERE local_device_id=? ORDER BY id DESC LIMIT 1', [$deviceId]);
        $map = $service->store->one('SELECT workspace_id,nas_id FROM mikrodius_devices WHERE local_id=?', [$deviceId]);
        Ui::render('mikrodius_provision.tpl', $admin, ['mk_page' => 'nas', 'mk_configured' => $service->settings()['configured'],
            'mk_device' => ['id' => (int) $device['id'], 'name' => $device['name'], 'ip_address' => $device['ip_address']],
            'mk_endpoint_json' => Ui::embed(getUrl('plugin/mikrodius/provision/' . (int) $device['id'])), 'mk_csrf_json' => Ui::embed(Ui::token()),
            'mk_state_json' => Ui::embed(['device' => ['id' => (int) $device['id'], 'name' => $device['name']], 'mapping' => $map,
                'operation' => $latest ? $provisioner->status((int) $latest['id']) : null])]);
    }

    private static function unmappedNas(Service $service, string $workspace): array
    {
        $out = [];
        foreach ($service->client()->all(ApiClient::workspace($workspace) . '/nas') as $nas) {
            if (($nas['workspace_id'] ?? null) !== $workspace || $service->store->one('SELECT local_id FROM mikrodius_devices WHERE nas_id=?', [$nas['id']])) { continue; }
            $out[] = array_intersect_key($nas, array_flip(['id', 'name', 'connection', 'status', 'tunnel_ip', 'created_at']));
        }
        return $out;
    }
}
