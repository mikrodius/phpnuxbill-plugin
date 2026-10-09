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
            if ($action === 'save') {
                $count = count($service->reconfigure(Controller::input('api_key'), Controller::input('timezone'),
                    Controller::input('api_url', 'https://mikrodius.com/api/v1')));
                Ui::back('settings', 's', 'Settings saved. The key reaches ' . $count . ' workspace(s).');
            }
            if ($action !== 'test') { throw new Problem(422, 'unsupported_action'); }
            $workspaces = array_map(static fn($w) => ['id' => $w['id'], 'name' => $w['name'] ?? ''], $service->testConnection());
        }
        $settings = $service->settings();
        Ui::render('mikrodius_settings.tpl', $admin, ['mk_page' => 'settings', 'mk_settings' => $settings,
            'mk_timezone' => $settings['timezone'] ?? ($config['timezone'] ?? 'UTC'), 'mk_workspaces' => $workspaces]);
    }

    public static function mappings(Service $service, array $admin, bool $post): void
    {
        if ($post) {
            $action = Controller::input('action');
            $workspace = Controller::input('workspace_id');
            if ($action === 'device') {
                $service->mapDevice((int) Controller::input('device_id'), $workspace, Controller::input('nas_id') ?: null);
                Ui::back('mappings', 's', 'Router mapping saved.');
            }
            if ($action === 'plan') {
                $service->mapPlan((int) Controller::input('plan_id'), $workspace, Controller::input('remote_id') ?: null, Controller::input('managed') === 'yes');
                Ui::back('mappings', 's', 'Plan mapping saved. Cron synchronizes it.');
            }
            throw new Problem(422, 'unsupported_action');
        }
        $store = $service->store;
        Ui::render('mikrodius_mappings.tpl', $admin, ['mk_page' => 'mappings', 'mk_configured' => $service->settings()['configured'],
            'mk_devices' => $store->rows('SELECT r.id,r.name,r.ip_address,m.workspace_id,m.nas_id FROM tbl_routers r
                LEFT JOIN mikrodius_devices m ON m.local_id=r.id ORDER BY r.name'),
            'mk_plans' => $store->rows("SELECT p.id,p.name_plan,p.type,p.routers,p.enabled,m.workspace_id,m.remote_id,m.managed,m.updated_at
                FROM tbl_plans p LEFT JOIN mikrodius_plans m ON m.local_id=p.id WHERE p.device='MikrodiusRadius' ORDER BY p.name_plan"),
            'mk_workspaces' => array_column($store->rows('SELECT workspace_id FROM mikrodius_devices UNION SELECT workspace_id FROM mikrodius_plans'), 'workspace_id')]);
    }

    public static function jobs(Service $service, array $admin, bool $post): void
    {
        $store = $service->store;
        if ($post) {
            if (Controller::input('action') !== 'retry') { throw new Problem(422, 'unsupported_action'); }
            $job = $store->one('SELECT id,kind,state FROM mikrodius_jobs WHERE id=?', [(int) Controller::input('job')]);
            if (!$job || $job['state'] !== 'failed') { throw new Problem(409, 'job_not_failed'); }
            if ($job['kind'] === 'diagnostic') { throw new Problem(409, 'diagnostic_not_retryable'); }
            // Requeues only the synchronization. Billing rows and payments are never touched.
            $store->retry((int) $job['id']);
            Ui::back('jobs', 's', 'Job ' . $job['id'] . ' queued again. The next cron run processes it.');
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
            'retryable' => $job['state'] === 'failed' && $job['kind'] !== 'diagnostic'];
    }

    public static function subscribers(Service $service, array $admin, bool $post): void
    {
        $store = $service->store;
        if ($post) {
            $source = Controller::input('source');
            if (!$store->entitlement($source)) { throw new Problem(404, 'entitlement_mapping_missing'); }
            $action = Controller::input('action');
            if ($action === 'refresh') {
                $service->status($source, true);
                Ui::back('subscribers', 's', 'Subscriber status refreshed from Mikrodius.');
            }
            if ($action === 'service_type') {
                if (Controller::input('confirm') !== 'yes') { throw new Problem(422, 'confirmation_required'); }
                $service->setServiceType($source, Controller::input('service_type'));
                Ui::back('subscribers', 's', 'Login type change queued.');
            }
            throw new Problem(422, 'unsupported_action');
        }
        $search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
        $rows = $store->rows('SELECT e.id,e.source_key,e.workspace_id,e.username,e.subscriber_id,e.local_plan_id,e.source_txn,e.observed,e.observed_at,
                p.name_plan, s.value AS service_type
            FROM mikrodius_entitlements e LEFT JOIN tbl_plans p ON p.id=e.local_plan_id
            LEFT JOIN mikrodius_settings s ON s.name=CONCAT(\'service.\',e.id)
            WHERE ?=\'\' OR e.username LIKE ? ORDER BY e.observed_at IS NULL, e.observed_at DESC LIMIT ' . self::LIMIT,
            [$search, '%' . addcslashes($search, '%_\\') . '%']);
        foreach ($rows as &$row) {
            $observed = $row['observed'] ? json_decode($row['observed'], true) : [];
            $row['status'] = $observed['status'] ?? null;
            $row['state'] = $observed['state'] ?? null;
            $row['online'] = $observed['online'] ?? null;
            $row['expires_at'] = $observed['expires_at'] ?? null;
            $row['first_login_at'] = $observed['first_login_at'] ?? null;
            unset($row['observed']);
            $row['kind'] = str_starts_with($row['source_key'], 'voucher:') ? 'voucher' : 'customer';
            $row['customer_id'] = preg_match('/^customer:(\d+):/', $row['source_key'], $m) ? (int) $m[1] : null;
            $row['customer_url'] = $row['customer_id'] ? getUrl('customers/view/' . $row['customer_id']) : null;
            $row['last_job'] = $store->one('SELECT id,kind,state,error_code,request_id,finished_at,created_at FROM mikrodius_jobs
                WHERE target_key=? AND state<>\'superseded\' ORDER BY id DESC LIMIT 1', [$row['source_key']]);
            if ($row['last_job'] && $row['last_job']['error_code']) { $row['last_job']['message'] = Ui::message($row['last_job']['error_code']); }
        }
        Ui::render('mikrodius_subscribers.tpl', $admin, ['mk_page' => 'subscribers', 'mk_rows' => $rows, 'mk_search' => $search]);
    }

    public static function vouchers(Service $service, array $admin, bool $post): void
    {
        $store = $service->store;
        $vouchers = new VoucherSupport($service);
        $zone = $service->settings()['timezone'] ?? 'UTC';
        if ($post) {
            $action = Controller::input('action');
            if ($action === 'mode') {
                $vouchers->setPlanMode((int) Controller::input('plan_id'), Controller::input('mode'));
                Ui::back('vouchers', 's', 'Voucher mode saved. It applies to codes not yet synchronized; issued codes keep theirs.');
            }
            if ($action === 'expiry') {
                $local = Controller::input('expires_at');
                $at = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, new \DateTimeZone($zone));
                if (!$at || $at->format('Y-m-d\TH:i') !== $local) { throw new Problem(422, 'invalid_event_timestamp'); }
                $vouchers->setExactExpiry((int) Controller::input('voucher_id'), $at->format(\DateTimeInterface::ATOM));
                Ui::back('vouchers', 's', 'Exact expiry saved. Cron synchronizes the code.');
            }
            throw new Problem(422, 'unsupported_action');
        }
        $plans = $store->rows("SELECT p.id,p.name_plan,p.type,p.routers,p.validity,p.validity_unit,s.value AS mode,
                (SELECT COUNT(*) FROM tbl_voucher v WHERE v.id_plan=p.id) AS codes,
                (SELECT COUNT(*) FROM mikrodius_vouchers mv WHERE mv.local_plan_id=p.id AND mv.mode='direct') AS direct_codes,
                (SELECT COUNT(*) FROM mikrodius_vouchers mv WHERE mv.local_plan_id=p.id AND mv.mode='customer_credit') AS credit_codes
            FROM tbl_plans p LEFT JOIN mikrodius_settings s ON s.name=CONCAT('voucher-mode.',p.id)
            WHERE p.device='MikrodiusRadius' ORDER BY p.name_plan");
        $filter = ($_GET['filter'] ?? '') === 'attention' ? 'attention' : 'all';
        $rows = $store->rows("SELECT v.id,v.code,v.status,v.used_date,v.user,v.id_plan,p.name_plan,p.validity_unit,
                mv.mode,mv.validity_seconds,mv.expires_at,mv.first_login_at,mv.claimed_transaction,
                COALESCE(sm.value,'direct') AS plan_mode, se.value AS exact_expiry, e.observed_at
            FROM tbl_voucher v JOIN tbl_plans p ON p.id=v.id_plan AND p.device='MikrodiusRadius'
            LEFT JOIN mikrodius_vouchers mv ON mv.local_id=v.id
            LEFT JOIN mikrodius_settings sm ON sm.name=CONCAT('voucher-mode.',p.id)
            LEFT JOIN mikrodius_settings se ON se.name=CONCAT('voucher-expiry.',v.id)
            LEFT JOIN mikrodius_entitlements e ON e.source_key=CONCAT('voucher:',v.id)
            ORDER BY v.id DESC LIMIT " . self::LIMIT);
        $out = [];
        foreach ($rows as $row) {
            $row['needs_expiry'] = $row['mode'] === null && $row['status'] === '0' && $row['plan_mode'] === 'direct'
                && $row['exact_expiry'] === null && !in_array($row['validity_unit'], ['Mins', 'Hrs', 'Days'], true);
            $job = $store->one("SELECT state,error_code,request_id FROM mikrodius_jobs WHERE target_key IN (?,?) AND state<>'superseded' ORDER BY id DESC LIMIT 1",
                ['voucher:' . $row['id'], 'diagnostic:' . hash('sha256', 'voucher:' . $row['id'])]);
            $row['job_state'] = $job['state'] ?? null;
            $row['job_error'] = $job && $job['error_code'] ? Ui::message($job['error_code']) : null;
            $row['job_request_id'] = $job['request_id'] ?? null;
            if ($filter === 'attention' && !$row['needs_expiry'] && !in_array($row['job_state'], ['failed', 'retry'], true)) { continue; }
            $out[] = $row;
        }
        Ui::render('mikrodius_vouchers.tpl', $admin, ['mk_page' => 'vouchers', 'mk_plans' => $plans, 'mk_vouchers' => $out,
            'mk_filter' => $filter, 'mk_timezone' => $zone,
            'mk_filters' => ['all' => getUrl('plugin/mikrodius/vouchers'), 'attention' => getUrl('plugin/mikrodius/vouchers&filter=attention')]]);
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
                Ui::back('webhooks', 's', 'Event queued for processing again.');
            }
            if ($action === 'process') {
                $done = $receiver->process();
                Ui::back('webhooks', 's', $done . ' pending event(s) processed.');
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
