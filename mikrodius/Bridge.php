<?php
namespace Mikrodius;

final class Bridge
{
    private static ?Service $instance = null;
    private static $routerFactory = null;
    public static function useService(Service $service): void { self::$instance = $service; }
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
            $service = self::service();
            $service->safely('recharge:' . $recharge['id'], fn() => $service->capturePurchase((int) $t['id'], (int) $recharge['id']));
        });
    }

    public static function cron(): void
    {
        self::guard(static function () {
            $service = self::service();
            if (!$service->settings()['configured']) { return; }
            $service->reconcile();
            if (class_exists(VoucherSupport::class)) { (new VoucherSupport($service))->reconcile(); }
            (new WebhookReceiver($service))->process();
            $service->reconcileStatus();
            (new Worker($service))->run();
            self::provisioner()->resume();
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
