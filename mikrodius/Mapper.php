<?php
namespace Mikrodius;

final class Mapper
{
    public static function positive($value, string $field, int $factor = 1): int
    {
        if (!is_numeric($value) || (float) $value <= 0 || (float) $value * $factor > PHP_INT_MAX || floor((float) $value * $factor) !== (float) $value * $factor) {
            throw new Problem(422, 'invalid_' . $field);
        }
        return (int) ((float) $value * $factor);
    }

    public static function service(array $plan): string
    {
        return match ($plan['type'] ?? '') { 'PPPOE' => 'pppoe', 'Hotspot' => 'hotspot', default => throw new Problem(422, 'unsupported_service') };
    }

    public static function identity(array $customer, array $plan): array
    {
        $ppp = self::service($plan) === 'pppoe';
        $username = $ppp && !empty($customer['pppoe_username']) ? $customer['pppoe_username'] : ($customer['username'] ?? '');
        $password = $ppp && !empty($customer['pppoe_password']) ? $customer['pppoe_password'] : ($customer['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._@+-]{1,64}$/D', $username)) { throw new Problem(422, 'invalid_username'); }
        if (strlen($password) < 4 || strlen($password) > 128) { throw new Problem(422, 'invalid_password'); }
        return ['username' => $username, 'password' => $password, 'service_type' => self::service($plan),
            'static_ipv4' => $ppp && !empty($customer['pppoe_ip']) ? $customer['pppoe_ip'] : null];
    }

    public static function expiry(string $date, string $time, string $zone): string
    {
        try { $tz = new \DateTimeZone($zone); }
        catch (\Exception $e) { throw new Problem(422, 'invalid_billing_timezone'); }
        $raw = $date . ' ' . $time;
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $raw, $tz);
        if (!$dt || $dt->format('Y-m-d H:i:s') !== $raw) { throw new Problem(422, 'invalid_billing_expiry'); }
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public static function plan(array $plan, array $bw, string $name): array
    {
        $result = ['name' => $name, 'bandwidth' => null, 'data_limit_bytes' => null, 'time_limit_seconds' => null,
            'validity_seconds' => null, 'max_sessions' => null, 'address_pool' => trim($plan['pool'] ?? '') ?: null];
        if (!empty($plan['on_login']) || !empty($plan['on_logout'])) { throw new Problem(422, 'router_scripts_not_supported'); }
        $speed = static function ($n, $unit): int {
            return self::positive($n, 'bandwidth', match ($unit) { 'Kbps' => 1, 'Mbps' => 1000, default => throw new Problem(422, 'invalid_bandwidth_unit') });
        };
        if ($bw) {
            $result['bandwidth'] = ['download_kbps' => $speed($bw['rate_down'], $bw['rate_down_unit']),
                'upload_kbps' => $speed($bw['rate_up'], $bw['rate_up_unit'])];
            if (trim($bw['burst'] ?? '') !== '') { $result['bandwidth'] = array_replace($result['bandwidth'], self::burst($bw['burst'])); }
        }
        if (!empty($plan['shared_users'])) { $result['max_sessions'] = self::positive($plan['shared_users'], 'max_sessions'); }
        if (($plan['typebp'] ?? 'Unlimited') === 'Limited') {
            $kind = $plan['limit_type'] ?? '';
            if (!in_array($kind, ['Data_Limit', 'Time_Limit', 'Both_Limit'], true)) { throw new Problem(422, 'invalid_limit_type'); }
            if ($kind !== 'Time_Limit') {
                $factor = match ($plan['data_unit'] ?? '') { 'MB' => 1000000, 'GB' => 1000000000, default => throw new Problem(422, 'invalid_data_unit') };
                $result['data_limit_bytes'] = self::positive($plan['data_limit'], 'data_limit', $factor);
            }
            if ($kind !== 'Data_Limit') {
                $factor = match ($plan['time_unit'] ?? '') { 'Mins' => 60, 'Hrs' => 3600, default => throw new Problem(422, 'invalid_time_unit') };
                $result['time_limit_seconds'] = self::positive($plan['time_limit'], 'time_limit', $factor);
            }
        }
        return $result;
    }

    private static function burst(string $raw): array
    {
        // RouterOS rate order is upload/download, while API names use subscriber direction.
        $parts = preg_split('/\s+/', trim($raw));
        if (count($parts) < 3 || count($parts) > 5) { throw new Problem(422, 'unsupported_burst'); }
        $pairs = [];
        foreach (array_slice($parts, 0, 3) as $i => $part) {
            if (!preg_match('/^(\d+(?:\.\d+)?)([kKmMgG]?)\/(\d+(?:\.\d+)?)([kKmMgG]?)$/D', $part, $m)) { throw new Problem(422, 'invalid_burst'); }
            $convert = static function ($n, $unit) use ($i) {
                if ($i === 2 && $unit !== '') { throw new Problem(422, 'invalid_burst_time'); }
                $factor = match (strtolower($unit)) { '' => $i === 2 ? 1 : 0.001, 'k' => 1, 'm' => 1000, 'g' => 1000000 };
                return self::positive((float) $n * $factor, 'burst');
            };
            $pairs[] = [$convert($m[1], $m[2]), $convert($m[3], $m[4])];
        }
        if ($pairs[2][0] !== $pairs[2][1] || $pairs[2][0] > 3600) { throw new Problem(422, 'unsupported_burst_time'); }
        $result = ['burst' => ['upload_kbps' => $pairs[0][0], 'download_kbps' => $pairs[0][1],
            'threshold_upload_kbps' => $pairs[1][0], 'threshold_download_kbps' => $pairs[1][1],
            'time_seconds' => $pairs[2][0]]];
        if (isset($parts[3])) {
            $priority = self::positive($parts[3], 'priority');
            if ($priority > 8) { throw new Problem(422, 'invalid_priority'); }
            $result['priority'] = $priority;
        }
        if (isset($parts[4])) {
            if ($parts[4] === '0/0') { $result['guaranteed'] = null; }
            else {
                if (!preg_match('/^(\d+)([kKmMgG])\/(\d+)([kKmMgG])$/D', $parts[4], $m)) { throw new Problem(422, 'unsupported_guaranteed_rate'); }
                $factor = static fn($unit) => match (strtolower($unit)) { 'k' => 1, 'm' => 1000, 'g' => 1000000 };
                $result['guaranteed'] = ['upload_kbps' => self::positive($m[1], 'guaranteed_rate', $factor($m[2])),
                    'download_kbps' => self::positive($m[3], 'guaranteed_rate', $factor($m[4]))];
            }
        }
        return $result;
    }
}
