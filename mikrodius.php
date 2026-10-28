<?php
require_once __DIR__ . '/mikrodius/bootstrap.php';
// Installs from Plugin Manager's list fill only system/plugin/; this places the device driver.
try { \Mikrodius\DeviceInstaller::ensure(); } catch (\Throwable $e) {}

function mikrodius_recharge_finish($args = []): void {
    if (\Mikrodius\Bridge::hookAllowed(__FUNCTION__)) { \Mikrodius\Bridge::purchase(); }
}
function mikrodius_cron($args = []): void {
    if (\Mikrodius\Bridge::hookAllowed(__FUNCTION__)) { \Mikrodius\Bridge::cron(); }
}
function mikrodius_webhook(): void {
    try { \Mikrodius\WebhookReceiver::handleHttp(\Mikrodius\Bridge::service()); }
    catch (\Throwable $e) {
        http_response_code(503); header('Content-Type: application/json');
        echo '{"code":"webhook_storage_unavailable"}';
    }
}
/** Operator pages: `?_route=plugin/mikrodius/<page>`. Controller checks the role and CSRF. */
function mikrodius(): void {
    global $routes;
    \Mikrodius\Controller::dispatch($routes);
}
/** Router list/edit shortcut. Only the first plugin hooked to a view runs, so the NAS page stays the way in. */
function mikrodius_router_shortcut($args = []): void {
    if (\Mikrodius\Bridge::hookAllowed(__FUNCTION__)) { \Mikrodius\Shortcut::routers(); }
}
register_menu('Mikrodius', true, 'mikrodius', 'NETWORK', '', '', 'success', \Mikrodius\Ui::ROLES);
register_hook('recharge_user_finish', 'mikrodius_recharge_finish');
register_hook('cronjob', 'mikrodius_cron');
register_hook('cronjob_end', 'mikrodius_cron');
register_hook('view_list_routers', 'mikrodius_router_shortcut');
register_hook('view_router_edit', 'mikrodius_router_shortcut');

\Mikrodius\RequestObserver::install();
