<?php
namespace Mikrodius;

final class Bridge
{
    private static ?Service $instance = null;
    private static ?SyncCoordinator $coordinator = null;
    public static function coordinator(): SyncCoordinator { return self::$coordinator ??= new SyncCoordinator(self::service()); }
    private static $routerFactory = null;
    public static function useService(Service $service): void { self::$instance = $service; self::$coordinator = null; }
    /** Tests substitute the RouterOS connection; production uses the stored router login. */
    public static function useRouterFactory(?callable $factory): void { self::$routerFactory = $factory; }
    public static function provisioner(): Provisioner { return new Provisioner(self::service(), self::$routerFactory); }

    public static function hookAllowed(string $name): bool
    {
        global $routes;
        // PHPNuxBill's plugin controller can call any global function by name.
        // Hook callbacks are internal entry points, not public operator routes.
        if (($routes[0] ?? null) === 'plugin' && ($routes[1] ?? null) === $name) {
            http_response_code(404);
            return false;
        }
        return true;
    }

    public static function service(): Service
    {
        if (self::$instance) { return self::$instance; }
        global $PLUGIN_PATH;
        $store = new Store(\ORM::get_db(), SecretBox::fromFile($PLUGIN_PATH . '/mikrodius/private/key.php'));
        $store->migrate();
        return self::$instance = new Service($store);
    }

    public static function purchase(): void
    {
        global $t, $d, $b, $p;
        // Package saves an existing recharge in $b, and a new one in $d.
        // Prefer the current lookup over a $d left by an earlier callback.
        $recharge = $b ?: $d;
        if (!$p || ($p['device'] ?? '') !== 'MikrodiusRadius' || !$t || !$recharge) { return; }
        self::guard(static function () use ($t, $recharge) {
            self::coordinator()->purchase((int) $t['id'], (int) $recharge['id']);
            if (!str_starts_with($t['method'] ?? '', 'Voucher - ')) { self::service()->capturePurchase((int) $t['id'], (int) $recharge['id']); }
        });
    }

    public static function recover(?Deadline $deadline = null): array
    {
        $deadline ??= new Deadline(30); $service = self::service(); $previous = $service->deadline;
        $service->withDeadline($deadline); $store = $service->store;
        if (!$store->lock('recovery')) { $service->withDeadline($previous); return ['state' => 'pending', 'blocker' => 'recovery_busy']; }
        $result = ['state' => 'synced'];
        try {
            $store->execute("INSERT INTO mikrodius_settings (name,value) VALUES ('recovery.started_at',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [gmdate('c')]);
            if (!$service->settings()['configured']) { $result = ['state' => 'idle', 'blocker' => 'connection_not_configured']; return $result; }
            if ($deadline->available()) { (new Worker($service))->run(); }
            $coordinator = new SyncCoordinator($service, $deadline); $coordinator->recoverIntents();
            $service->reconcile();
            if ($deadline->available()) { (new VoucherSupport($service))->reconcile(); }
            if ($deadline->available()) { (new WebhookReceiver($service))->process(); }
            if ($deadline->available()) { $service->reconcileStatus(); }
            if ($deadline->available()) { $result['completed'] = (new Worker($service))->run(); }
            $counts = $store->one("SELECT COALESCE(SUM(state='failed'),0) AS failed,COALESCE(SUM(state IN ('queued','retry','running')),0) AS pending FROM mikrodius_jobs");
            $result += array_map('intval', $counts);
            $result['state'] = $result['failed'] ? 'failed' : ($result['pending'] ? 'pending' : 'synced');
            if ($deadline->available()) { $store->execute('DELETE FROM mikrodius_statistics WHERE updated_at<TIMESTAMPADD(DAY,-2,UTC_TIMESTAMP(6)) LIMIT 500'); }
        } catch (\Throwable $e) { $result = ['state' => 'pending', 'error_code' => $e instanceof Problem ? $e->problemCode : 'local_storage_error']; }
        finally {
            try { $store->execute("INSERT INTO mikrodius_settings (name,value) VALUES ('recovery.heartbeat',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [json_encode(['at' => gmdate('c')] + $result)]); }
            catch (\Throwable $e) { $result['heartbeat_error'] = 'heartbeat_storage_error'; }
            finally { $store->unlock('recovery'); $service->withDeadline($previous); }
        }
        return $result;
    }
    public static function cron(): void
    {
        self::guard(static fn() => self::recover(new Deadline(30)));
        // Keep the existing router-installation recovery separate from billing sync.
        // Its RouterOS steps have their own budget and never create purchases.
        self::guard(static function () {
            if (self::service()->settings()['configured']) { self::provisioner()->resume(); }
        });
    }

    public static function guard(callable $callback): void
    {
        try { $callback(); }
        catch (\Throwable $e) {
            // Billing is already saved. Cron reconciliation recovers the missed enqueue.
            error_log('Mikrodius provisioning pending: ' . ($e instanceof Problem ? $e->problemCode : 'local_storage_error'));
        }
    }
}
