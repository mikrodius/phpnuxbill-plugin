<?php
namespace Mikrodius;

/** Installed at plugin load, outside the first-callback-only hook dispatcher. */
final class RequestObserver
{
    private static bool $installed = false;
    private static bool $finished = false;
    private static ?array $result = null;
    private static array $route = [];
    private static ?array $bandwidth = null;
    public static function install(): void
    {
        if (self::$installed || PHP_SAPI === 'cli') { return; }
        $raw = $_GET['_route'] ?? (!empty($GLOBALS['isApi']) ? ($_GET['r'] ?? '') : null);
        if ($raw === null) {
            $raw = ltrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
            $prefix = defined('APP_URL') ? strlen(ltrim((string) parse_url(APP_URL, PHP_URL_PATH), '/')) : 0;
            if ($prefix > 0) { $raw = ltrim(substr($raw, $prefix), '/'); }
        }
        if (!is_string($raw) || $raw === '' || str_starts_with($raw, 'plugin/')) { return; }
        self::$installed = true; self::$route = explode('/', trim($raw, '/'));
        Bridge::guard(function () {
            $c = Bridge::coordinator(); $r = self::$route;
            $id = (int) ($_POST['id'] ?? $r[2] ?? 0);
            if (($r[0] ?? '') === 'bandwidth' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') { self::$bandwidth = Bridge::service()->store->one('SELECT * FROM tbl_bandwidth WHERE id=?', [$id]); }
            if (($r[0] ?? '') === 'customers') {
                $c->customer($id);
                if (($r[1] ?? '') === 'delete') { $c->deletion('customer', $id); }
            }
            if (($r[0] ?? '') === 'accounts') { $c->customer((int) (\User::getID() ?: 0)); }
            if (($r[0] ?? '') === 'services' && str_contains($r[1] ?? '', 'delete')) { $c->deletion('plan', $id); }
            if (($r[0] ?? '') === 'services' && str_contains($r[1] ?? '', 'edit-post')) { $c->deletion('plan_change', $id); }
            if (($r[0] ?? '') === 'plan' && in_array($r[1] ?? '', ['delete','edit-post'], true)) {
                $recharge = Bridge::service()->store->one('SELECT customer_id FROM tbl_user_recharges WHERE id=?', [$id]);
                if ($recharge) { $c->customer((int) $recharge['customer_id']); }
                if (($r[1] ?? '') === 'delete') { $c->deletion('recharge', $id); }
            }
        });
        // Callback runs before any buffered response leaves PHP, including explicit flush
        // and exit. A shutdown callback also handles redirects with an empty body.
        $buffer = '';
        ob_start(static function (string $body, int $phase) use (&$buffer): string {
            $buffer .= $body;
            if (!($phase & PHP_OUTPUT_HANDLER_FINAL)) { return ''; }
            return self::finish($buffer);
        });
        register_shutdown_function(static function (): void { self::finish(''); });
    }
    public static function finish(string $body): string
    {
        if (!self::$installed) { return $body; }
        try {
            if (!self::$finished) {
            self::$finished = true;
            Bridge::coordinator()->startFinalization();
            $currentBody = $body !== '' ? $body : (string) ob_get_contents();
            $native = json_decode($currentBody, true);
            $success = is_array($native) && array_key_exists('success', $native) ? $native['success'] === true : ($_SESSION['ntype'] ?? '') === 's';
            Bridge::coordinator()->allowUnchanged($success);
            if (self::$bandwidth !== null && self::$bandwidth !== Bridge::service()->store->one('SELECT * FROM tbl_bandwidth WHERE id=?', [self::$bandwidth['id']])) {
                foreach (Bridge::service()->store->rows("SELECT id FROM tbl_plans WHERE id_bw=? AND device='MikrodiusRadius'", [self::$bandwidth['id']]) as $plan) { Bridge::coordinator()->plan((int) $plan['id']); }
            }
            Bridge::purchase();
            foreach (($GLOBALS['newVoucherIds'] ?? []) as $id) { Bridge::coordinator()->voucher((int) $id); }
            self::$result = Bridge::coordinator()->finish();
            }
            $result = self::$result;
            if (!$result) { return $body; }
            if (!$result['local_saved'] && !$result['job_ids'] && !$result['error_code']) { return $body; }
            $admin = isset($GLOBALS['admin']) && in_array($GLOBALS['admin']['user_type'] ?? '', Ui::ROLES, true);
            $message = Ui::syncMessage($result, $admin);
            if (!headers_sent()) { header('X-Mikrodius-Sync: ' . $result['state']); }
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['mikrodius_feedback'] = $admin ? $result : ['state' => $result['state'], 'message' => $message];
                $_SESSION['notify'] = Ui::notice($message); $_SESSION['ntype'] = $result['state'] === 'synced' ? 's' : 'w';
            }
            $decoded = json_decode($body, true);
            if (is_array($decoded)) { $decoded['synchronization'] = $admin ? $result : ['state' => $result['state'], 'message' => $message]; return json_encode($decoded, JSON_THROW_ON_ERROR); }
            if ($body !== '') {
                $notice = '<p role="status">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
                return str_contains(strtolower($body), '</body>') ? str_ireplace('</body>', $notice . '</body>', $body) : $body . $notice;
            }
        } catch (\Throwable $e) {
            error_log('Mikrodius finalization pending: local_storage_error');
            if (!headers_sent()) { header('X-Mikrodius-Sync: pending'); }
            $message = 'Access synchronization is pending.';
            if (session_status() === PHP_SESSION_ACTIVE) { $_SESSION['notify'] = Ui::notice($message); $_SESSION['ntype'] = 'w'; }
            $decoded = json_decode($body, true);
            if (is_array($decoded)) { $decoded['synchronization'] = ['state' => 'pending', 'message' => $message]; return json_encode($decoded); }
            if ($body !== '') { return $body . '<p role="status">' . $message . '</p>'; }
        }
        return $body;
    }
}
