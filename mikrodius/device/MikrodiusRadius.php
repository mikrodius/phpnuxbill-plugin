<?php
// The plugin copies this driver into system/devices/, and Plugin Manager's Delete leaves it
// behind. Settings › Devices includes every driver, so without the plugin this file still
// loads and describes itself; using it fails with a clear message.
if (is_file(dirname(__DIR__) . '/plugin/mikrodius/bootstrap.php')) {
    require_once dirname(__DIR__) . '/plugin/mikrodius/bootstrap.php';
} else {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'Mikrodius\\')) {
            throw new \RuntimeException('The Mikrodius plugin is not installed. Reinstall it, or move plans off the MikrodiusRadius device and delete system/devices/MikrodiusRadius.php.');
        }
    });
}

final class MikrodiusRadius
{
    public function description(): array
    {
        $installed = is_file(dirname(__DIR__) . '/plugin/mikrodius/bootstrap.php');
        return ['title' => 'Mikrodius RADIUS', 'description' => $installed
                ? 'Cloud RADIUS authentication and accounting through the Mikrodius public API.'
                : 'The Mikrodius plugin is not installed. Reinstall it, or move plans off this device and delete this file.',
            'author' => 'Mikrodius', 'url' => ['Website' => 'https://mikrodius.com']];
    }

    public function add_customer($customer, $plan): void
    {
        // Package calls this before persisting the purchase. Access is granted by the
        // completion hook or persisted-record reconciliation, never this early callback.
    }

    public function sync_customer($customer, $plan): void
    {
        \Mikrodius\Bridge::service()->synchronize($this->row($customer), $this->row($plan));
    }

    public function remove_customer($customer, $plan): void
    {
        \Mikrodius\Bridge::service()->action($this->row($customer), $this->row($plan), 'suspend');
    }

    public function change_username($plan, $from, $to): void
    {
        // This callback can run before PHPNuxBill saves the renamed customer. Use its
        // stable customer ID now; reconciliation reads the final persisted credentials.
        $service = \Mikrodius\Bridge::service();
        $customer = $service->store->one('SELECT * FROM tbl_customers WHERE username IN (?,?) OR pppoe_username IN (?,?) LIMIT 1', [$from, $to, $from, $to]);
        if ($customer) {
            $field = ($plan['type'] === 'PPPOE' && !empty($customer['pppoe_username'])) ? 'pppoe_username' : 'username';
            $customer[$field] = $to; $service->synchronize($customer, $this->row($plan));
        }
    }

    public function add_plan($plan): void
    {
        \Mikrodius\Bridge::service()->capturePlan($this->row($plan));
    }

    public function update_plan($old_name, $plan): void { $this->add_plan($plan); }

    public function remove_plan($plan): void
    {
        $service = \Mikrodius\Bridge::service();
        $service->store->queue('remove-plan:' . bin2hex(random_bytes(16)), 'plan:' . $plan['id'], 'remove_plan', ['local_plan_id' => (int) $plan['id']]);
    }

    public function online_customer($customer, $router_name): bool
    {
        $service = \Mikrodius\Bridge::service();
        foreach ($service->store->rows("SELECT * FROM tbl_plans WHERE device='MikrodiusRadius' AND routers=?", [$router_name]) as $plan) {
            $status = $service->status($service->source($this->row($customer), $plan), true);
            if ($status && ($status['online'] ?? false)) { return true; }
        }
        return false;
    }

    public function disconnect_customer($customer, $router_name): void
    {
        $service = \Mikrodius\Bridge::service();
        foreach ($service->store->rows("SELECT * FROM tbl_plans WHERE device='MikrodiusRadius' AND routers=?", [$router_name]) as $plan) {
            $service->action($this->row($customer), $plan, 'disconnect');
        }
    }

    public function connect_customer($customer, $ip, $mac_address, $router_name): void
    {
        throw new \Mikrodius\Problem(422, 'forced_login_not_supported');
    }

    private function row($value): array { return is_array($value) ? $value : $value->as_array(); }
}
