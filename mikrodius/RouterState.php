<?php
namespace Mikrodius;

/** Reads the RouterOS state provisioning depends on and compares RouterOS values. */
final class RouterState
{
    public const REQUIRED_POLICIES = ['api', 'read', 'write', 'test', 'sensitive'];
    /** Menus whose `Mikrodius …` comments are checked for NAS ownership, as in generator.go. */
    public const OWNED_MENUS = ['/interface', '/radius', '/ip/address', '/ip/firewall/filter', '/system/scheduler'];
    private const SECRET_FIELDS = ['secret', 'password', 'private-key', 'preshared-key', 'contents'];

    public static function read(RouterApi $api, array $router): array
    {
        $optional = static function (string $menu) use ($api): ?array {
            // Menus that a package or RouterOS major version lacks answer with a trap.
            try { return $api->rows($menu); } catch (Problem $e) { if ($e->problemCode === 'router_command_failed') { return null; } throw $e; }
        };
        $resource = $api->rows('/system/resource')[0] ?? [];
        $state = ['version' => self::version((string) ($resource['version'] ?? '')), 'resource_version' => (string) ($resource['version'] ?? '')];
        $users = $api->rows('/user'); $groups = $api->rows('/user/group');
        $user = null;
        foreach ($users as $row) { if (($row['name'] ?? null) === (string) $router['username']) { $user = $row; } }
        $policies = [];
        foreach ($groups as $group) {
            if ($user && ($group['name'] ?? null) === ($user['group'] ?? '')) {
                foreach (explode(',', (string) ($group['policy'] ?? '')) as $policy) {
                    if ($policy !== '' && $policy[0] !== '!') { $policies[] = $policy; }
                }
            }
        }
        $state['access'] = ['user' => $user['name'] ?? null, 'group' => $user['group'] ?? null, 'policies' => $policies,
            'missing' => array_values(array_diff(self::REQUIRED_POLICIES, $policies))];
        foreach (self::OWNED_MENUS as $menu) { $state['menus'][$menu] = $api->rows($menu); }
        $state['wireguard'] = $state['version']['major'] >= 7 ? ($optional('/interface/wireguard') ?? []) : [];
        $state['peers'] = $state['version']['major'] >= 7 ? ($optional('/interface/wireguard/peers') ?? []) : [];
        $state['l2tp'] = $optional('/interface/l2tp-client') ?? [];
        $state['sstp'] = $optional('/interface/sstp-client') ?? [];
        $state['incoming'] = $api->rows('/radius/incoming')[0] ?? [];
        $state['aaa'] = $api->rows('/ppp/aaa')[0] ?? [];
        $state['ppp_secrets'] = $optional('/ppp/secret') ?? [];
        $state['ppp_servers'] = [];
        foreach (['pppoe' => '/interface/pppoe-server/server', 'l2tp' => '/interface/l2tp-server/server',
            'sstp' => '/interface/sstp-server/server', 'pptp' => '/interface/pptp-server/server', 'ovpn' => '/interface/ovpn-server/server'] as $kind => $menu) {
            foreach ($optional($menu) ?? [] as $row) {
                // pppoe lists server entries; the others have one global row with `enabled`.
                $enabled = $kind === 'pppoe' ? !self::truthy($row['disabled'] ?? 'false') : self::truthy($row['enabled'] ?? 'false');
                if ($enabled) { $state['ppp_servers'][] = ['kind' => $kind, 'name' => $row['service-name'] ?? $row['name'] ?? $kind, 'interface' => $row['interface'] ?? null]; }
            }
        }
        $state['hotspots'] = $optional('/ip/hotspot') ?? [];
        $state['profiles'] = $optional('/ip/hotspot/profile') ?? [];
        $state['hotspot_users'] = $optional('/ip/hotspot/user') ?? [];
        $state['certificates'] = $optional('/certificate') ?? [];
        return $state;
    }

    public static function version(string $raw): array
    {
        if (!preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $raw, $m)) { return ['major' => 0, 'minor' => 0, 'patch' => 0, 'text' => $raw]; }
        return ['major' => (int) $m[1], 'minor' => (int) $m[2], 'patch' => (int) ($m[3] ?? 0), 'text' => trim($raw)];
    }

    /**
     * Why a router cannot use a connection, or null: RouterOS 7 connects over WireGuard only,
     * RouterOS 6.49.10 and later over L2TP or SSTP only.
     */
    public static function unsupported(array $version, string $connection): ?string
    {
        $v = [$version['major'], $version['minor'], $version['patch']];
        if ($connection === 'wireguard') { return $v[0] === 7 ? null : 'wireguard_requires_v7'; }
        if ($v[0] === 7) { return 'routeros_v7_uses_wireguard'; }
        return $v[0] === 6 && $v >= [6, 49, 10] ? null : 'routeros_unsupported';
    }

    public static function supported(array $version, string $connection): bool { return self::unsupported($version, $connection) === null; }

    /** The NAS a `Mikrodius … [NAS id]` comment names, '' for an untagged Mikrodius comment, null otherwise. */
    public static function owner(?string $comment): ?string
    {
        $comment = (string) $comment;
        if (!str_starts_with($comment, 'Mikrodius ')) { return null; }
        return preg_match('/\[NAS ([A-Za-z0-9_-]+)\]/', $comment, $m) ? $m[1] : '';
    }

    /** Owned resources on the device, per NAS; '' collects untagged Mikrodius comments. */
    public static function owners(array $state): array
    {
        $owners = [];
        foreach ($state['menus'] as $menu => $rows) {
            foreach ($rows as $row) {
                $owner = self::owner($row['comment'] ?? null);
                if ($owner !== null) { $owners[$owner][] = ['menu' => $menu, 'name' => $row['name'] ?? $row['address'] ?? ($row['.id'] ?? ''), 'comment' => $row['comment']]; }
            }
        }
        return $owners;
    }

    public static function truthy($value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['true', 'yes'], true);
    }

    public static function seconds(string $value): ?int
    {
        $value = strtolower(trim($value));
        if ($value === '' || $value === 'never') { return null; }
        if (preg_match('/^(\d+):(\d\d):(\d\d)$/D', $value, $m)) { return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3]; }
        if (!preg_match('/^(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m(?!s))?(?:(\d+)s)?(?:(\d+)ms)?$/D', $value, $m)) { return null; }
        return (int) ($m[1] ?? 0) * 604800 + (int) ($m[2] ?? 0) * 86400 + (int) ($m[3] ?? 0) * 3600 + (int) ($m[4] ?? 0) * 60 + (int) ($m[5] ?? 0);
    }

    /** Whether a RouterOS value printed by the API equals the value we set. */
    public static function same(string $field, $current, $desired): bool
    {
        if ($current === null || $desired === null) { return $current === $desired; }
        $a = (string) $current; $b = (string) $desired;
        if ($a === $b) { return true; }
        if (in_array($field, ['disabled', 'accept', 'use-radius', 'accounting', 'radius-accounting', 'trusted',
            'verify-server-certificate', 'verify-server-address-from-certificate', 'add-default-route', 'use-ipsec'], true)) {
            $bool = ['yes' => 'true', 'no' => 'false'];
            $a = strtr(strtolower(trim($a)), $bool); $b = strtr(strtolower(trim($b)), $bool);
            return in_array($b, ['true', 'false'], true) && $a === $b;
        }
        if ($field === 'service') {
            $set = static function (string $v) { $p = array_unique(array_filter(array_map('trim', explode(',', strtolower($v))))); sort($p); return $p; };
            return $set($a) === $set($b);
        }
        if (preg_match('/address$/', $field) || $field === 'connect-to') {
            return preg_replace('~/32$~', '', strtolower(trim($a))) === preg_replace('~/32$~', '', strtolower(trim($b)));
        }
        if (in_array($field, ['timeout', 'interval', 'persistent-keepalive', 'interim-update', 'radius-interim-update'], true)) {
            $sa = self::seconds($a); $sb = self::seconds($b);
            return $sa !== null && $sb !== null && $sa === $sb;
        }
        // Credentials, ownership, names and scripts are case- and whitespace-sensitive.
        return false;
    }

    /** Copy without secret fields, for evidence and previews. */
    public static function safe(array $row): array
    {
        return array_diff_key($row, array_flip(self::SECRET_FIELDS));
    }

    public static function isSecretField(string $field): bool { return in_array($field, self::SECRET_FIELDS, true); }
}
