<?php
namespace Mikrodius;

/**
 * Shared plumbing for the operator pages: authentication, role checks, the plugin's own
 * CSRF token (PHPNuxBill's check can be turned off in its settings), rendering and errors.
 */
final class Ui
{
    public const ROLES = ['SuperAdmin', 'Admin'];
    public const TABS = ['overview' => 'Connection', 'nas' => 'NAS & routers', 'settings' => 'Settings', 'statistics' => 'Statistics', 'jobs' => 'Sync jobs',
        'webhooks' => 'Webhooks'];
    /** Signs up or in, then opens the dashboard's new-key dialog prefilled for PHPNuxBill. */
    public const KEY_URL = 'https://console.mikrodius.com/developer?new=key&name=PHPNuxBill&ref=phpnuxbill';
    private const JSON = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;

    public static function reactRequest(): bool { return ($_SERVER['HTTP_X_MIKRODIUS_UI'] ?? '') === 'react'; }

    /** The signed-in SuperAdmin or Admin; anyone else is turned away before any work. */
    public static function admin(bool $json): array
    {
        $id = \Admin::getID();
        $admin = $id ? \Admin::_info($id) : null;
        $admin = $admin ? (is_array($admin) ? $admin : $admin->as_array()) : null;
        if (!$admin) {
            if ($json) { self::json(['error' => self::error(new Problem(401, 'not_authenticated'))], 401); }
            _admin(); // Redirects to the sign-in page.
            exit;
        }
        if (!in_array($admin['user_type'] ?? '', self::ROLES, true)) {
            if ($json) { self::json(['error' => self::error(new Problem(403, 'forbidden'))], 403); }
            http_response_code(403);
            _alert('You do not have permission to access this page', 'danger', 'dashboard');
            exit;
        }
        return $admin;
    }

    public static function token(): string
    {
        if (empty($_SESSION['mikrodius_csrf']) || !is_string($_SESSION['mikrodius_csrf'])) {
            $_SESSION['mikrodius_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['mikrodius_csrf'];
    }

    /** Every mutation: POST, same-site, with the session's plugin token. */
    public static function verify(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { throw new Problem(405, 'method_not_allowed'); }
        $sent = $_POST['mikrodius_csrf'] ?? ($_SERVER['HTTP_X_MIKRODIUS_CSRF'] ?? '');
        $stored = $_SESSION['mikrodius_csrf'] ?? '';
        if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site' || !is_string($sent) || !is_string($stored) ||
            strlen($stored) !== 64 || !hash_equals($stored, $sent)) {
            throw new Problem(403, 'csrf_failed');
        }
    }

    public static function headers(): void
    {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('Referrer-Policy: same-origin');
    }

    public static function json(array $data, int $status = 200): void
    {
        self::headers();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, self::JSON);
        exit;
    }

    /** JSON for a <script type="application/json"> block: safe inside HTML without escaping. */
    public static function embed($data): string { return json_encode($data, self::JSON); }

    public static function render(string $template, array $admin, array $vars): void
    {
        global $ui;
        self::headers();
        $data = [];
        foreach ($vars as $name => $value) {
            $name = preg_replace('/^mk_/', '', $name);
            if (str_ends_with($name, '_json')) { $name = substr($name, 0, -5); $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR); }
            $data[$name] = $value;
        }
        $boot = ['data' => $data, 'csrf' => self::token(), 'base' => getUrl('plugin/mikrodius'),
            'version' => Version::PLUGIN, 'key_url' => self::KEY_URL, 'device_problem' => DeviceInstaller::status(), 'login_url' => getUrl('admin')];
        if (self::reactRequest()) { self::json($boot); }
        $assets = __DIR__ . '/assets/manifest.json';
        if (is_file($assets)) {
            $manifest = json_decode(file_get_contents($assets), true, 32, JSON_THROW_ON_ERROR);
            $entry = $manifest['src/main.tsx']['file'] ?? null;
            if (!is_string($entry) || !preg_match('/^mikrodius-[A-Za-z0-9_-]+\.js$/D', $entry) || !is_file(__DIR__ . '/assets/' . $entry)) {
                throw new Problem(503, 'ui_assets_missing');
            }
            $ui->assign('mk_app_asset', rtrim(APP_URL, '/') . '/system/plugin/mikrodius/assets/' . $entry);
            $ui->assign('mk_app_boot_json', self::embed($boot));
            $template = 'mikrodius_app.tpl';
        }
        $ui->assign('_title', 'Mikrodius');
        $ui->assign('_system_menu', 'network');
        $ui->assign('_admin', $admin);
        $ui->assign('mk_csrf', self::token());
        $ui->assign('mk_base', getUrl('plugin/mikrodius'));
        $ui->assign('mk_tabs', self::TABS);
        $ui->assign('mk_version', Version::PLUGIN);
        $ui->assign('mk_key_url', self::KEY_URL);
        $ui->assign('mk_device_problem', DeviceInstaller::status());
        foreach ($vars as $name => $value) { $ui->assign($name, $value); }
        $ui->display($template);
    }

    /**
     * Text for PHPNuxBill's flash notice. Its layout prints the notice unescaped inside a
     * quoted script string, so only characters that cannot break out of it are kept.
     */
    public static function notice(string $text): string
    {
        $text = str_replace(["'", '"'], '’', $text);
        return mb_substr(trim(preg_replace('/[^\p{L}\p{N}’ .,:;()\/_+@#=-]+/u', ' ', $text)), 0, 300);
    }

    public static function syncMessage(array $result, bool $admin = true): string
    {
        if (!$admin) { return $result['state'] === 'synced' ? 'Saved and synchronized.' : 'Saved. Access synchronization is pending.'; }
        return ($result['local_saved'] ? 'Saved. ' : '') . ($result['state'] === 'synced' ? 'Synchronization complete.' : 'Synchronization ' . $result['state'] . '.') .
            ' Synced ' . $result['synced'] . ', pending ' . $result['pending'] . ', failed ' . $result['failed'] . '.' .
            ($result['error_code'] ? ' ' . self::message($result['error_code']) : '') . ($result['request_id'] ? ' Request ID: ' . $result['request_id'] : '');
    }
    public static function syncBack(string $page, array $result): void
    {
        $_SESSION['mikrodius_feedback'] = $result;
        self::back($page, $result['state'] === 'synced' ? 's' : 'w', self::syncMessage($result));
    }

    /** Redirects to a plugin page with a sanitized flash notice. */
    public static function back(string $page, string $type, string $text): void
    {
        if (self::reactRequest() || isset($_SERVER['HTTP_X_MIKRODIUS_CSRF'])) {
            self::json(['ok' => true, 'page' => $page, 'message' => self::notice($text), 'feedback' => $_SESSION['mikrodius_feedback'] ?? null]);
        }
        r2(getUrl('plugin/mikrodius/' . $page), $type, self::notice($text));
        exit;
    }

    public static function error(\Throwable $e): array
    {
        if (!$e instanceof Problem) {
            // Unexpected errors may carry SQL or credentials in their message: log only the type.
            error_log('Mikrodius UI error: ' . get_class($e));
            $e = new Problem(500, 'internal_error');
        }
        return ['code' => $e->problemCode, 'message' => self::message($e->problemCode), 'detail' => $e->detail, 'request_id' => $e->requestId];
    }

    public static function status(\Throwable $e): int
    {
        return $e instanceof Problem && $e->status >= 400 && $e->status < 600 ? $e->status : ($e instanceof Problem ? 502 : 500);
    }

    public static function message(string $code): string
    {
        return [
            'onboarding_busy' => 'Setup is already running. Wait a moment and refresh.',
            'workspace_selection_changed' => 'The selected workspace changed. Refresh before continuing.',
            'workspace_creation_uncertain' => 'The workspace may have been created. Select it from the workspace list.',
            'invalid_workspace_name' => 'Enter a workspace name with 1 to 100 characters.',
            'workspace_required' => 'Choose a workspace before preparing routers.',
            'device_disabled' => 'Enable this router in PHPNuxBill before preparing its NAS.',
            'invalid_connection' => 'Choose WireGuard, L2TP or SSTP.',
            'not_authenticated' => 'Sign in again.',
            'forbidden' => 'Only SuperAdmin and Admin accounts can use Mikrodius.',
            'csrf_failed' => 'The form expired or came from another site. Reload the page and try again.',
            'method_not_allowed' => 'This action needs a form submission.',
            'connection_not_configured' => 'Save a Mikrodius API key in Settings first.',
            'invalid_api_key' => 'That is not a Mikrodius API key (mk_live_…).',
            'invalid_api_url' => 'The API address must be https://…/api/v1.',
            'insufficient_access' => 'The API key is read-only. Use a read_write key scoped to the workspace.',
            'transport_error' => 'Mikrodius could not be reached. Check the server\'s internet access and try again.',
            'router_unreachable' => 'The router\'s API did not answer. Check its address, port 8728 and the firewall.',
            'router_login_failed' => 'The router rejected the stored login. Fix it on the router page.',
            'router_command_failed' => 'The router refused a change.',
            'router_permissions_missing' => 'The router login needs the api, read, write, test and sensitive policies.',
            'management_via_tunnel_not_supported' => 'Manage the router over its own address, not a Mikrodius tunnel address.',
            'wireguard_requires_v7' => 'WireGuard needs RouterOS v7. RouterOS v6 routers use L2TP or SSTP.',
            'routeros_v7_uses_wireguard' => 'RouterOS v7 routers connect over WireGuard. L2TP and SSTP are for RouterOS v6.',
            'routeros_unsupported' => 'RouterOS 6.49.10 or later is required.',
            'provisioning_blocked' => 'Resolve or acknowledge the listed items first.',
            'operation_active' => 'An installation for this device is still open. Resume or cancel it.',
            'device_owned_by_other_nas' => 'Mikrodius resources on this router belong to another NAS.',
            'device_changed_since_preview' => 'The router changed after the preview. Cancel and preview again.',
            'setting_changed_since_preview' => 'A setting changed after the preview and was left as is. Cancel and preview again.',
            'tunnel_not_ready' => 'The tunnel did not come up.',
            'tunnel_dns_failed' => 'The tunnel host name did not resolve on the billing server.',
            'nas_creation_uncertain' => 'It is not known whether the NAS was created. Link it or create it again.',
            'nas_created_mapping_failed' => 'The NAS was created but could not be saved here. Link it.',
            'insufficient_balance' => 'The workspace balance does not cover another NAS.',
            'spend_cap_reached' => 'Another NAS would exceed the workspace spend cap.',
            'verification_failed' => 'The router does not read back as configured.',
            'full_install_not_approved' => 'Confirm that the full install may change PPP and every active hotspot profile.',
            'nas_mapping_required' => 'Map this device to a NAS first.',
            'internal_error' => 'Something went wrong. Check the PHP error log.',
            'page_not_found' => 'That Mikrodius page does not exist.',
            'job_not_failed' => 'Only failed jobs can be retried; the others are still being processed.',
            'diagnostic_not_retryable' => 'Recovery clears this check after its cause is fixed.',
            'unsupported_action' => 'That action is not available here.',
            'confirmation_required' => 'Tick the confirmation box to continue.',
            'invalid_billing_timezone' => 'Choose a valid billing timezone, for example Asia/Jakarta.',
            'device_not_found' => 'That router no longer exists in PHPNuxBill.',
            'nas_mapping_conflict' => 'That NAS belongs to another workspace.',
            'nas_mapped_to_other_device' => 'That NAS is already linked to another router.',
            'workspace_mapping_conflict' => 'This item is already mapped to another workspace. Its mapping is kept.',
            'plan_not_configured' => 'Only plans that use the MikrodiusRadius device can be mapped.',
            'plan_mapping_required' => 'Map the plan, or its router, to a workspace first.',
            'plan_mapping_conflict' => 'The plan is already linked to another workspace or Mikrodius plan. Its mapping is kept.',
            'plan_ownership_conflict' => 'More than one Mikrodius plan carries this plan’s tag. Remove the duplicate in Mikrodius.',
            'plan_sync_busy' => 'The plan is being synchronized. Try again shortly.',
            'bandwidth_not_found' => 'The plan’s bandwidth profile is missing in PHPNuxBill.',
            'billing_record_not_saved' => 'PHPNuxBill did not save the billing record. Check the transaction in PHPNuxBill.',
            'saved_assignment_mismatch' => 'The saved recharge router or login type disagrees with the selected plan. Correct the recharge or use a normal purchase to assign the plan.',
            'billing_snapshot_changed' => 'The recharge changed after the transaction was saved. Recovery uses the latest saved recharge.',
            'customer_not_found' => 'The customer no longer exists in PHPNuxBill.',
            'customer_inactive' => 'The customer is disabled in PHPNuxBill, so access was not granted.',
            'entitlement_mapping_missing' => 'The local subscriber mapping is missing.',
            'subscriber_mapping_missing' => 'The subscriber does not exist in Mikrodius yet.',
            'subscriber_ownership_conflict' => 'A Mikrodius subscriber with this username belongs to another billing system or customer.',
            'username_mapping_conflict' => 'Another customer already uses this username in the workspace.',
            'invalid_service_mapping' => 'Choose pppoe, hotspot or any.',
            'invalid_voucher_mode' => 'Choose direct login or customer credit.',
            'voucher_calendar_expiry_required' => 'Month and fixed-date vouchers need an exact expiry before they sync.',
            'voucher_already_used' => 'The voucher is already used; its expiry cannot change.',
            'voucher_mapping_already_saved' => 'The voucher is already synchronized; its expiry is fixed.',
            'voucher_plan_conflict' => 'The voucher’s plan or router changed after it was issued.',
            'voucher_ownership_conflict' => 'The voucher code matches more than one saved voucher or another subscriber.',
            'voucher_direct_redemption_conflict' => 'A direct-login voucher was redeemed into a customer account. Billing kept the recharge; Mikrodius did not grant it.',
            'voucher_customer_account_required' => 'A customer-credit voucher was used without a customer account. Billing kept the record; Mikrodius did not grant it.',
            'voucher_already_claimed' => 'The voucher was already redeemed by another transaction.',
            'voucher_credentials_changed' => 'The voucher code changed after it was issued.',
            'voucher_sync_busy' => 'The voucher is being synchronized. Try again shortly.',
            'invalid_event_timestamp' => 'Enter the expiry as a full date and time with its offset, for example 2026-12-31T23:59:00+07:00.',
            'invalid_webhook_secret' => 'Paste the whsec_… signing secret from the Mikrodius webhook endpoint.',
            'webhook_resource_conflict' => 'The event names a subscriber or NAS that belongs elsewhere.',
            'webhook_workspace_not_mapped' => 'The event’s workspace is not mapped here.',
        ][$code] ?? 'The request failed (' . $code . ').';
    }
}
