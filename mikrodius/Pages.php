<?php
namespace Mikrodius;

/**
 * The operator pages besides NAS & routers. Controller has already checked the role and,
 * for a POST, the plugin CSRF token. Every change is a form POST that redirects back with a
 * notice; nothing here returns the API key, webhook secrets or job payloads.
 */
final class Pages
{
    private const LIMIT = 200;

    public static function overview(Service $service, array $admin, bool $post): void
    {
        global $config;
        $workspace = new Workspace($service, Bridge::provisioner());
        if ($post) {
            $action = Controller::input('action');
            $area = ['connect' => 'key', 'select' => 'workspace', 'create' => 'create', 'prepare_all' => 'routers',
                'prepare' => 'device', 'prepare_again' => 'device', 'link' => 'device'][$action] ?? null;
            if ($area === null) { throw new Problem(422, 'unsupported_action'); }
            // Form posts answer next to the form that sent them, with its non-secret input kept.
            // The API key is never kept. Requests from the page's script still get JSON.
            $values = ['workspace_id' => Controller::input('workspace_id'), 'name' => Controller::input('name'),
                'device_id' => (int) Controller::input('device_id')];
            try {
                if ($action === 'connect') {
                    $settings = $service->settings();
                    $service->reconfigure(Controller::input('api_key'), $settings['timezone'] ?? ($config['timezone'] ?? 'Asia/Jakarta'), $settings['api_url']);
                    if ($settings['configured'] && Controller::input('api_key') === '') {
                        self::answer('overview', $area, 'Connection checked. Your saved key still works.');
                    }
                    self::answer('overview&choose=1', 'workspace', 'Connected. Now choose your workspace.');
                }
                if ($action === 'select' || $action === 'create') {
                    if ($action === 'select') { $workspace->select($values['workspace_id']); }
                    else { $workspace->create($values['name']); }
                    $_SESSION['mikrodius_prepare_workspace'] = $workspace->selected();
                    self::answer('overview&prepare=1', 'workspace', 'Workspace saved. Preparing NAS for your routers. Router settings are not changed.');
                }
                if ($action === 'prepare_all') {
                    if (!$workspace->selected()) { throw new Problem(422, 'workspace_required'); }
                    $_SESSION['mikrodius_prepare_workspace'] = $workspace->selected();
                    self::answer('overview&prepare=1', $area, 'Checking new routers and preparing their NAS.');
                }
                if ($action === 'prepare' || $action === 'prepare_again') {
                    if ($action === 'prepare_again' && Controller::input('confirm') !== 'yes') { throw new Problem(422, 'confirmation_required'); }
                    $prepared = $workspace->prepare($values['device_id'], $values['workspace_id'], Controller::input('connection') ?: null, $action === 'prepare_again');
                    if (isset($_SERVER['HTTP_X_MIKRODIUS_CSRF'])) { Ui::json(['preparation' => $prepared]); }
                    self::answer('overview', $area, 'NAS ready. Choose Install when you are ready to configure this router.', $values);
                }
                $workspace->link($values['device_id'], Controller::input('nas_id'));
                self::answer('overview', $area, 'NAS linked. The router is ready to install.', $values);
            } catch (\Throwable $e) {
                if (isset($_SERVER['HTTP_X_MIKRODIUS_CSRF'])) { throw $e; }
                $error = Ui::error($e);
                $_SESSION['mikrodius_feedback'] = ['area' => $area, 'type' => 'error', 'message' => $error['message'],
                    'detail' => $error['detail'], 'request_id' => $error['request_id'], 'next' => self::nextStep($error['code'], $area),
                    'values' => $values];
                Ui::back('overview', 'e', '');
            }
        }
        $feedback = $_SESSION['mikrodius_feedback'] ?? null;
        unset($_SESSION['mikrodius_feedback']);
        $settings = $service->settings(); $id = $workspace->selected();
        $workspaces = []; $detail = null; $stats = null; $nasList = []; $error = null; $connectionError = null;
        if ($settings['configured']) {
            try {
                $workspaces = array_map(static fn($w) => ['id' => $w['id'], 'name' => $w['name'] ?? $w['id']], $service->testConnection());
            } catch (\Throwable $e) { $connectionError = Ui::error($e); $connectionError['next'] = self::nextStep($connectionError['code'], 'key'); }
            try {
                if ($id && !$connectionError) {
                    $path = ApiClient::workspace($id);
                    $detail = $service->client()->request('GET', $path);
                    $nasList = $service->client()->all($path . '/nas');
                    foreach ($nasList as $nas) {
                        if (($nas['workspace_id'] ?? null) === $id) { $service->store->transaction(fn() => $service->store->observeNas($id, $nas, gmdate('c'))); }
                    }
                    $stats = $service->client()->request('GET', $path . '/stats');
                }
            } catch (\Throwable $e) { $error = Ui::error($e); }
        }
        $devices = Controller::devices($service);
        $prepareIds = [];
        foreach ($devices as &$device) {
            $device['preparation'] = $workspace->preparation((int) $device['id']);
            $device['prepare_error'] = isset($device['preparation']['error_code']) ? Ui::message($device['preparation']['error_code']) : null;
            $device['connection'] = null;
            foreach ($nasList as $nas) { if ($nas['id'] === $device['nas_id']) { $device['connection'] = $nas['connection'] ?? null; } }
            $device['other_workspace'] = $device['workspace_id'] && $device['workspace_id'] !== $id;
            $device['uncertain'] = in_array($device['preparation']['state'] ?? '', ['creating', 'uncertain'], true);
            $device['can_prepare'] = $settings['configured'] && $id && $device['enabled'] && !$device['nas_id'] && !$device['other_workspace'] && !$device['operation'] && !$device['uncertain'];
            if ($device['can_prepare']) { $prepareIds[] = (int) $device['id']; }
        }
        unset($device);
        $unmapped = array_values(array_filter($nasList, static fn($nas) => !array_filter($devices, static fn($d) => $d['nas_id'] === $nas['id'])));
        $counts = $service->store->one("SELECT SUM(state='failed') AS failed, SUM(state IN ('queued','retry','running')) AS pending FROM mikrodius_jobs");
        // Only a CSRF-checked setup POST can arm automatic preparation. A GET link cannot.
        $autoPrepare = $settings['configured'] && $id && !isset($_GET['choose']) && !$error && !$connectionError && ($_SESSION['mikrodius_prepare_workspace'] ?? null) === $id;
        if ($autoPrepare) { unset($_SESSION['mikrodius_prepare_workspace']); }
        // Routers that still need a step from the operator, shown on the collapsed details.
        $todo = count(array_filter($devices, static fn($d) => $d['enabled'] && !$d['other_workspace'] && $d['nas_status'] !== 'online'));
        Ui::render('mikrodius_overview.tpl', $admin, ['mk_page' => 'overview', 'mk_settings' => $settings,
            'mk_workspace_id' => $id, 'mk_workspace' => $detail, 'mk_stats' => $stats, 'mk_error' => $error,
            'mk_connection_error' => $connectionError, 'mk_feedback' => $feedback,
            'mk_router_todo' => $todo, 'mk_preparable' => count($prepareIds),
            'mk_workspaces' => $workspaces, 'mk_choose' => !$id || isset($_GET['choose']), 'mk_devices' => $devices,
            'mk_onboarding' => !$settings['configured'] || !$id || isset($_GET['choose']),
            'mk_add_router_url' => getUrl('routers/add'),
            'mk_unmapped' => $unmapped, 'mk_counts' => array_map('intval', $counts),
            'mk_prepare_json' => Ui::embed($autoPrepare ? $prepareIds : []),
            'mk_workspace_json' => Ui::embed($id), 'mk_endpoint_json' => Ui::embed(getUrl('plugin/mikrodius/overview')),
            'mk_csrf_json' => Ui::embed(Ui::token())]);
    }

    /** Redirects after a successful overview form, with the confirmation shown beside that form. */
    private static function answer(string $page, string $area, string $message, array $values = []): void
    {
        $_SESSION['mikrodius_feedback'] = ['area' => $area, 'type' => 'success', 'message' => $message, 'detail' => null,
            'request_id' => null, 'next' => null, 'values' => $values + ['workspace_id' => '', 'name' => '', 'device_id' => 0]];
        Ui::back($page, 's', '');
    }

    /** One plain next step for an overview error, when the message alone does not say what to do. */
    private static function nextStep(string $code, string $area): ?string
    {
        if ($code === 'transport_error') { return 'Check this server’s internet connection, then try again.'; }
        if ($code === 'insufficient_balance' || $code === 'spend_cap_reached') { return 'Top up or raise the spend cap in your Mikrodius dashboard, then try again.'; }
        if ($area === 'key' && !in_array($code, ['csrf_failed', 'onboarding_busy'], true)) {
            return 'Copy the whole key from your Mikrodius dashboard and paste it again. A saved key stays in use until a new one works.';
        }
        return null;
    }

    public static function settings(Service $service, array $admin, bool $post): void
    {
        global $config;
        $workspaces = null;
        if ($post) {
            $action = Controller::input('action');
            $coordinator = Bridge::coordinator(); $vouchers = new VoucherSupport($service);
            if ($action === 'voucher_mode') {
                $id = (int) Controller::input('plan_id'); $vouchers->setPlanMode($id, Controller::input('mode')); $coordinator->saved();
                Ui::syncBack('settings', $coordinator->finish());
            }
            if ($action === 'voucher_expiry') {
                $local = Controller::input('expires_at'); $zone = $service->settings()['timezone'] ?? 'UTC';
                $at = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, new \DateTimeZone($zone));
                if (!$at || $at->format('Y-m-d\TH:i') !== $local) { throw new Problem(422, 'invalid_event_timestamp'); }
                $id = (int) Controller::input('voucher_id'); $vouchers->setExactExpiry($id, $at->format(\DateTimeInterface::ATOM));
                $coordinator->voucher($id); Ui::syncBack('settings', $coordinator->finish());
            }
            if ($action === 'service_type') {
                if (Controller::input('confirm') !== 'yes') { throw new Problem(422, 'confirmation_required'); }
                $source = Controller::input('source');
                if (!str_starts_with($source, 'customer:')) { throw new Problem(422, 'invalid_service_mapping'); }
                $service->setServiceType($source, Controller::input('service_type'));
                $coordinator->saved(); $coordinator->target($source); Ui::syncBack('settings', $coordinator->finish());
            }
            if ($action === 'save') {
                $count = count($service->reconfigure(Controller::input('api_key'), Controller::input('timezone'),
                    Controller::input('api_url', 'https://mikrodius.com/api/v1')));
                Ui::back('settings', 's', 'Settings saved. The key reaches ' . $count . ' workspace(s).');
            }
            if ($action !== 'test') { throw new Problem(422, 'unsupported_action'); }
            $workspaces = array_map(static fn($w) => ['id' => $w['id'], 'name' => $w['name'] ?? ''], $service->testConnection());
            if (Ui::reactRequest()) { Ui::json(['ok' => true, 'message' => 'Connection checked. The key reaches ' . count($workspaces) . ' workspace(s).', 'workspaces' => $workspaces]); }
        }
        $settings = $service->settings();
        Ui::render('mikrodius_settings.tpl', $admin, ['mk_page' => 'settings', 'mk_settings' => $settings,
            'mk_timezone' => $settings['timezone'] ?? ($config['timezone'] ?? 'UTC'), 'mk_workspaces' => $workspaces,
            'mk_voucher_plans' => $service->store->rows("SELECT p.id,p.name_plan,p.type,p.validity_unit,COALESCE(s.value,'direct') AS mode FROM tbl_plans p LEFT JOIN mikrodius_settings s ON s.name=CONCAT('voucher-mode.',p.id) WHERE p.device='MikrodiusRadius' ORDER BY p.id"),
            'mk_expiry_vouchers' => $service->store->rows("SELECT v.id,v.code,p.name_plan,s.value AS expires_at FROM tbl_voucher v JOIN tbl_plans p ON p.id=v.id_plan LEFT JOIN mikrodius_vouchers m ON m.local_id=v.id LEFT JOIN mikrodius_settings s ON s.name=CONCAT('voucher-expiry.',v.id) LEFT JOIN mikrodius_settings mode ON mode.name=CONCAT('voucher-mode.',p.id) WHERE p.device='MikrodiusRadius' AND v.status='0' AND m.local_id IS NULL AND p.validity_unit NOT IN ('Mins','Hrs','Days') AND COALESCE(mode.value,'direct')='direct' ORDER BY v.id LIMIT 500"),
            'mk_service_overrides' => $service->store->rows("SELECT e.source_key,e.username,e.workspace_id,e.local_plan_id,COALESCE(s.value,CASE p.type WHEN 'PPPOE' THEN 'pppoe' ELSE 'hotspot' END) AS service_type FROM mikrodius_entitlements e JOIN tbl_plans p ON p.id=e.local_plan_id LEFT JOIN mikrodius_settings s ON s.name=CONCAT('service.',e.id) WHERE e.source_key LIKE 'customer:%' ORDER BY e.username LIMIT 500")]);
    }

    public static function jobs(Service $service, array $admin, bool $post): void
    {
        $store = $service->store;
        if ($post) {
            if (Controller::input('action') !== 'retry') { throw new Problem(422, 'unsupported_action'); }
            $job = $store->one('SELECT id,kind,state,target_key FROM mikrodius_jobs WHERE id=?', [(int) Controller::input('job')]);
            if (!$job || in_array($job['state'], ['succeeded','superseded'], true)) { throw new Problem(409, 'job_not_failed'); }
            if ($job['kind'] === 'diagnostic') { throw new Problem(409, 'diagnostic_not_retryable'); }
            // Requeues only the synchronization. Billing rows and payments are never touched.
            $store->retry((int) $job['id']);
            $coordinator = Bridge::coordinator(); $coordinator->target($job['target_key']); Ui::syncBack('jobs', $coordinator->finish());
        }
        $filter = in_array($_GET['state'] ?? '', ['open', 'failed'], true) ? $_GET['state'] : 'all';
        $where = ['all' => '', 'open' => " WHERE state IN ('queued','retry','running','failed')", 'failed' => " WHERE state='failed'"][$filter];
        $rows = $store->rows('SELECT id,target_key,kind,source_txn,state,attempts,available_at,created_at,finished_at,error_code,request_id
            FROM mikrodius_jobs' . $where . ' ORDER BY id DESC LIMIT ' . self::LIMIT);
        foreach ($rows as &$row) {
            if ($row['kind'] === 'diagnostic') {
                // A diagnostic's payload holds only the billing source and the problem code.
                $payload = $store->one('SELECT payload FROM mikrodius_jobs WHERE id=?', [$row['id']])['payload'];
                $row['target_key'] = (string) ($store->secrets->open($payload)['source'] ?? '');
            }
            $row += self::describe($row);
        }
        $counts = $store->one("SELECT SUM(state='failed') AS failed, SUM(state IN ('queued','retry','running')) AS pending,
            SUM(kind='purchase' AND state NOT IN ('succeeded','superseded')) AS paid_pending FROM mikrodius_jobs");
        Ui::render('mikrodius_jobs.tpl', $admin, ['mk_page' => 'jobs', 'mk_jobs' => $rows, 'mk_filter' => $filter,
            'mk_filters' => ['all' => getUrl('plugin/mikrodius/jobs'), 'open' => getUrl('plugin/mikrodius/jobs&state=open'),
                'failed' => getUrl('plugin/mikrodius/jobs&state=failed')],
            'mk_counts' => array_map('intval', $counts)]);
    }

    /** What a job means to the operator: billing is PHPNuxBill's, so a stuck purchase is "paid, access pending". */
    private static function describe(array $job): array
    {
        $open = !in_array($job['state'], ['succeeded', 'superseded'], true);
        if ($job['kind'] === 'purchase') {
            $category = $open ? 'paid_pending' : 'paid';
        } elseif ($job['kind'] === 'diagnostic' && in_array($job['error_code'], ['billing_record_not_saved', 'billing_snapshot_changed', 'customer_not_found'], true)) {
            $category = 'billing';
        } else { $category = 'sync'; }
        $subject = null; $link = null;
        if (preg_match('/^(?:recharge|credentials):(\d+)$/', $job['target_key'], $m)) { $subject = 'Recharge ' . $m[1]; }
        elseif (preg_match('/^customer:(\d+):/', $job['target_key'], $m)) { $subject = 'Customer ' . $m[1]; $link = getUrl('customers/view/' . $m[1]); }
        elseif (preg_match('/^voucher(?:-status)?:(\d+)$/', $job['target_key'], $m)) { $subject = 'Voucher ' . $m[1]; }
        elseif (preg_match('/^plan:(\d+)$/', $job['target_key'], $m)) { $subject = 'Plan ' . $m[1]; }
        elseif (preg_match('/^nas:(\d+)$/', $job['target_key'], $m)) { $subject = 'Router ' . $m[1]; $link = getUrl('plugin/mikrodius/provision/' . $m[1]); }
        return ['category' => $category, 'subject' => $subject ?? $job['target_key'], 'link' => $link,
            'message' => $job['error_code'] ? Ui::message($job['error_code']) : null,
            'retryable' => in_array($job['state'], ['queued','retry','running','failed'], true) && $job['kind'] !== 'diagnostic'];
    }

    public static function webhooks(Service $service, array $admin, bool $post): void
    {
        $receiver = new WebhookReceiver($service);
        if ($post) {
            $action = Controller::input('action');
            if ($action === 'secret') {
                $hours = (int) Controller::input('keep_previous_hours', '0');
                if ($hours < 0 || $hours > 24) { throw new Problem(422, 'invalid_webhook_secret'); }
                $current = $service->store->config()['webhook_secret'] ?? null;
                $keep = $hours > 0 && $current !== null;
                $receiver->configureSecret(Controller::input('secret'), $keep ? $current : null, $keep ? time() + $hours * 3600 : null);
                Ui::back('webhooks', 's', 'Signing secret saved.' . ($keep ? ' The previous one stays valid for ' . $hours . ' hour(s).' : ''));
            }
            if ($action === 'retry') {
                $receiver->retry(Controller::input('event'));
                $done = $receiver->process(500, Controller::input('event'), Bridge::coordinator()->deadline);
                Ui::syncBack('webhooks', $receiver->outcome() ?? Bridge::coordinator()->finish());
            }
            if ($action === 'process') {
                $done = $receiver->process();
                Ui::syncBack('webhooks', $receiver->outcome() ?? Bridge::coordinator()->finish());
            }
            throw new Problem(422, 'unsupported_action');
        }
        $config = $service->store->config();
        $events = $receiver->events(self::LIMIT);
        foreach ($events as &$event) { $event['message'] = $event['error_code'] ? Ui::message($event['error_code']) : null; }
        $until = $config['webhook_previous_until'] ?? null;
        Ui::render('mikrodius_webhooks.tpl', $admin, ['mk_page' => 'webhooks', 'mk_endpoint' => getUrl('plugin/mikrodius_webhook'),
            'mk_secret_set' => !empty($config['webhook_secret']),
            'mk_previous_until' => !empty($config['webhook_previous']) && $until >= time() ? gmdate('Y-m-d H:i', $until) . ' UTC' : null,
            'mk_events' => $events, 'mk_counts' => array_map('intval', $service->store->one("SELECT SUM(state='pending') AS pending,
                SUM(state='failed') AS failed, SUM(state='processed') AS processed FROM mikrodius_events"))]);
    }
}
