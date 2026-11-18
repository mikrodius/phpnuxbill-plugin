<?php
namespace Mikrodius;

/** Read-only cloud monitoring. Only allowlisted data enters the local cache. */
final class Statistics
{
    private Service $service;
    private Store $store;
    private string $scope = '';
    private string $workspace = '';

    public function __construct(Service $service) { $this->service = $service; $this->store = $service->store; }

    public function read(array $input): array
    {
        $period = (string) ($input['period'] ?? '30');
        $search = $input['q'] ?? ''; $cursor = $input['cursor'] ?? '';
        if (!in_array($period, ['7','30','90'], true) || !is_string($search) || mb_strlen($search) > 100 ||
            !is_string($cursor) || strlen($cursor) > 4096) { throw new Problem(422, 'invalid_statistics_filter'); }
        $search = trim($search);
        $this->workspace = (new Workspace($this->service, Bridge::provisioner()))->selected() ?? '';
        $empty = ['period' => (int) $period, 'workspace' => null, 'counters' => null, 'rows' => [], 'next_cursor' => null,
            'search' => $search, 'cursor' => $cursor, 'aggregate' => null, 'observed_at' => null, 'stale' => false, 'error' => null];
        if (!$this->service->settings()['configured'] || $this->workspace === '') { return $empty; }
        $config = $this->store->config();
        $baseScope = hash('sha256', json_encode([$this->store->installation(), $config['api_url'],
            hash('sha256', $config['api_key']), $config['timezone'] ?? '', $this->workspace], JSON_THROW_ON_ERROR));
        $oldDeadline = $this->service->deadline;
        $this->service->withDeadline(new Deadline(8));
        $metadata = $this->cached($baseScope, 'metadata');
        try {
            // Recheck public-API authorization before returning formerly accessible cache.
            $workspace = $this->service->client()->request('GET', ApiClient::workspace($this->workspace));
            if (($workspace['id'] ?? '') !== $this->workspace) { throw new Problem(404, 'workspace_not_found'); }
            $zone = $workspace['time_zone'] ?? 'UTC';
            try { new \DateTimeZone($zone); } catch (\Throwable $e) { throw new Problem(502, 'invalid_api_response'); }
            $safeWorkspace = ['id' => $this->workspace, 'name' => (string) ($workspace['name'] ?? $this->workspace), 'time_zone' => $zone];
            $this->scope = hash('sha256', $baseScope . ':' . $zone);
            $this->save($baseScope, 'metadata', ['workspace' => $safeWorkspace, 'scope' => $this->scope]);
            $responseKey = 'response:' . $period . ':' . hash('sha256', $search . "\0" . $cursor);
            $previous = $this->cached($this->scope, $responseKey);
            $result = $empty + [];
            $result['workspace'] = $safeWorkspace;
            $result['observed_at'] = gmdate('c');
            $errors = [];
            try {
                $stats = $this->service->client()->request('GET', ApiClient::workspace($this->workspace) . '/stats');
                $result['counters'] = self::counters($stats);
                $this->save($this->scope, 'counters', $result['counters']);
            } catch (Problem $e) {
                self::authorize($e);
                $result['counters'] = $this->cached($this->scope, 'counters')['data'] ?? null;
                $errors[] = Ui::error($e);
            }
            try {
                $path = ApiClient::workspace($this->workspace) . '/subscribers?limit=50';
                if ($search !== '') { $path .= '&q=' . rawurlencode($search); }
                if ($cursor !== '') { $path .= '&cursor=' . rawurlencode($cursor); }
                $page = $this->service->client()->request('GET', $path);
                if (!is_array($page['data'] ?? null) || (!is_string($page['next_cursor'] ?? null) && ($page['next_cursor'] ?? null) !== null)) { throw new Problem(502, 'invalid_api_response'); }
                $result['rows'] = array_map(fn($row) => $this->subscriber($row), $page['data']);
                $result['next_cursor'] = $page['next_cursor'] ?? null;
                $result['aggregate'] = $this->aggregate((int) $period, $search === '' && $cursor === '' ? $page : null);
                $range = $result['aggregate']['building_range'] ?? $result['aggregate']['range'];
                foreach ($result['rows'] as &$row) {
                    $usage = $this->cached($this->scope, self::usageKey($range, $row['id']));
                    $row['usage'] = $usage['data']['totals'] ?? null;
                    $row['usage_observed_at'] = $usage['at'] ?? null;
                    $row['usage_range'] = $range;
                }
                unset($row);
                $result['table_observed_at'] = gmdate('c');
            } catch (Problem $e) {
                self::authorize($e);
                $errors[] = Ui::error($e);
                if ($previous) {
                    foreach (['rows','next_cursor','aggregate','table_observed_at'] as $field) { $result[$field] = $previous['data'][$field] ?? null; }
                }
            }
            $result['stale'] = (bool) $errors;
            $result['error'] = $errors[0] ?? null;
            if ($result['aggregate'] === null) { $result['aggregate'] = $this->aggregateView((int) $period); }
            if (!$errors) { $this->save($this->scope, $responseKey, $result); }
            return $result;
        } catch (Problem $e) {
            self::authorize($e);
            if (!$metadata) { return array_replace($empty, ['error' => Ui::error($e), 'stale' => true]); }
            $this->scope = $metadata['data']['scope'];
            $old = $this->cached($this->scope, 'response:' . $period . ':' . hash('sha256', $search . "\0" . $cursor));
            return array_replace($old['data'] ?? $empty, ['workspace' => $metadata['data']['workspace'], 'stale' => true, 'error' => Ui::error($e)]);
        } finally { $this->service->withDeadline($oldDeadline); }
    }

    private static function authorize(Problem $e): void
    {
        if (in_array($e->status, [401,403,404], true)) { throw $e; }
    }

    private function subscriber(array $row): array
    {
        if (($row['workspace_id'] ?? '') !== $this->workspace || !is_string($row['id'] ?? null) || !is_string($row['username'] ?? null)) { throw new Problem(502, 'statistics_workspace_conflict'); }
        return ['id' => $row['id'], 'username' => $row['username'], 'online' => is_bool($row['online'] ?? null) ? $row['online'] : null,
            'status' => in_array($row['status'] ?? '', ['active','suspended'], true) ? $row['status'] : null,
            'state' => in_array($row['state'] ?? '', ['allowed','suspended','expired','limit_reached','isolated'], true) ? $row['state'] : null,
            'expires_at' => is_string($row['expires_at'] ?? null) ? $row['expires_at'] : null];
    }

    private static function counters(array $stats): array
    {
        $out = [];
        foreach (['nas_total','nas_online','subscribers_total','subscribers_online','sessions_active'] as $key) {
            $out[$key] = isset($stats[$key]) && is_numeric($stats[$key]) ? max(0, (int) $stats[$key]) : null;
        }
        $out['subscribers_by_state'] = [];
        foreach (['allowed','suspended','expired','limit_reached','isolated'] as $state) {
            if (isset($stats['subscribers_by_state'][$state]) && is_numeric($stats['subscribers_by_state'][$state])) { $out['subscribers_by_state'][$state] = max(0, (int) $stats['subscribers_by_state'][$state]); }
        }
        return $out;
    }

    private function aggregate(int $days, ?array $initialPage): array
    {
        $key = 'aggregate:' . $days;
        $stored = $this->cached($this->scope, $key);
        $state = $stored['data'] ?? null;
        if (!$state || ($state['phase'] === 'complete' && strtotime($stored['at']) + 30 <= time()) || ($state['phase'] === 'restart' && ($state['retry_at'] ?? 0) <= time())) {
            $to = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $state = ['phase' => 'enumerating', 'range' => ['from' => $to->modify('-' . $days . ' days')->format('c'), 'to' => $to->format('c'), 'granularity' => $days === 90 ? 'month' : 'day'],
                'cursor' => null, 'seen_cursors' => [], 'ids' => [], 'position' => 0, 'totals' => ['download_bytes' => 0,'upload_bytes' => 0,'online_seconds' => 0],
                'buckets' => [], 'error' => null, 'retry_at' => 0, 'started_at' => gmdate('c')];
        }
        if ($state['phase'] !== 'complete' && $state['retry_at'] <= time() && $this->store->lock('statistics:' . $this->scope . ':' . $days)) {
            try {
                // Read after acquiring the lock: another PHP worker may have advanced it.
                $current = $this->cached($this->scope, $key);
                if ($current) {
                    $phase = $current['data']['phase'];
                    $stillCurrent = in_array($phase, ['enumerating', 'usage'], true)
                        || ($phase === 'complete' && strtotime($current['at']) + 30 > time())
                        || ($phase === 'restart' && ($current['data']['retry_at'] ?? 0) > time());
                    if ($stillCurrent) { $state = $current['data']; }
                }
                $state['error'] = null;
                for ($pageNumber = 0; $state['retry_at'] <= time() && $state['phase'] === 'enumerating' && $pageNumber < 2 && $this->service->deadline->available(); $pageNumber++) {
                    $path = ApiClient::workspace($this->workspace) . '/subscribers?limit=50';
                    if ($state['cursor'] !== null) { $path .= '&cursor=' . rawurlencode($state['cursor']); }
                    $page = $state['cursor'] === null && $initialPage !== null ? $initialPage : $this->service->client()->request('GET', $path);
                    $initialPage = null;
                    if (!is_array($page['data'] ?? null)) { throw new Problem(502, 'invalid_api_response'); }
                    foreach ($page['data'] as $subscriber) { $safe = $this->subscriber($subscriber); $state['ids'][$safe['id']] = true; }
                    $next = $page['next_cursor'] ?? null;
                    if ($next !== null && (!is_string($next) || isset($state['seen_cursors'][$next]))) { throw new Problem(502, 'invalid_pagination'); }
                    if ($next !== null) { $state['seen_cursors'][$next] = true; }
                    $state['cursor'] = $next;
                    if ($next === null) { $state['phase'] = 'usage'; }
                    $this->save($this->scope, $key, $state);
                }
                $ids = array_keys($state['ids']);
                for ($reads = 0; $state['retry_at'] <= time() && $state['phase'] === 'usage' && $state['position'] < count($ids) && $reads < 100 && $this->service->deadline->available(); $reads++) {
                    $id = $ids[$state['position']]; $usageKey = self::usageKey($state['range'], $id);
                    $usage = $this->cached($this->scope, $usageKey)['data'] ?? null;
                    if ($usage === null) {
                        try {
                            $remote = $this->service->client()->request('GET', ApiClient::workspace($this->workspace) . '/subscribers/' . rawurlencode($id) . '/usage?' . http_build_query($state['range'], '', '&', PHP_QUERY_RFC3986));
                        } catch (Problem $e) { if ($e->status === 404) { $state['phase'] = 'restart'; throw new Problem(503, 'statistics_membership_changed', $e->requestId, 30); } throw $e; }
                        $usage = self::usage($remote, $state['range']);
                        $this->save($this->scope, $usageKey, $usage);
                    }
                    foreach ($state['totals'] as $name => $_) { $state['totals'][$name] += $usage['totals'][$name]; }
                    foreach ($usage['buckets'] as $bucket) {
                        $start = $bucket['start'];
                        $state['buckets'][$start] ??= ['start' => $start,'download_bytes' => 0,'upload_bytes' => 0,'online_seconds' => 0];
                        foreach ($state['totals'] as $name => $_) { $state['buckets'][$start][$name] += $bucket[$name]; }
                    }
                    $state['position']++;
                    $this->save($this->scope, $key, $state);
                }
                if ($state['phase'] === 'usage' && $state['position'] === count($ids)) {
                    $state['phase'] = 'complete'; $state['completed_at'] = gmdate('c');
                    ksort($state['buckets']);
                    $this->save($this->scope, 'complete:' . $days, ['range' => $state['range'], 'totals' => $state['totals'], 'buckets' => array_values($state['buckets']),
                        'subscribers' => count($ids), 'observed_at' => $state['completed_at']]);
                    $this->save($this->scope, $key, $state);
                }
            } catch (Problem $e) {
                if (in_array($e->status, [401,403], true)) { throw $e; }
                $state['error'] = Ui::error($e); $state['retry_at'] = time() + max(5, $e->retryAfter);
                try { $this->save($this->scope, $key, $state); } catch (\Throwable $ignored) { /* Prior checkpoint and per-member usage remain recoverable. */ }
            } finally { $this->store->unlock('statistics:' . $this->scope . ':' . $days); }
        }
        return $this->aggregateView($days, $state);
    }

    private function aggregateView(int $days, ?array $state = null): array
    {
        $state ??= $this->cached($this->scope, 'aggregate:' . $days)['data'] ?? null;
        $complete = $this->cached($this->scope, 'complete:' . $days)['data'] ?? null;
        return ['state' => $state['phase'] ?? 'pending', 'range' => $complete['range'] ?? $state['range'] ?? null,
            'building_range' => $state['range'] ?? null, 'totals' => $complete['totals'] ?? null, 'buckets' => $complete['buckets'] ?? [],
            'subscribers' => $complete['subscribers'] ?? null, 'observed_at' => $complete['observed_at'] ?? null,
            'enumerated' => isset($state['ids']) ? count($state['ids']) : 0, 'processed' => $state['position'] ?? 0,
            'stale' => $complete !== null && ($state['phase'] ?? '') !== 'complete', 'error' => $state['error'] ?? null,
            'next_retry_at' => !empty($state['retry_at']) ? gmdate('c', $state['retry_at']) : null];
    }

    private static function usage(array $remote, array $range): array
    {
        try {
            foreach (['from','to'] as $bound) {
                if (!is_string($remote[$bound] ?? null) || new \DateTimeImmutable($remote[$bound]) != new \DateTimeImmutable($range[$bound])) { throw new Problem(502, 'invalid_api_response'); }
            }
        } catch (\Throwable $e) { throw new Problem(502, 'invalid_api_response'); }
        $totals = static function (array $input): array {
            $out = [];
            foreach (['download_bytes','upload_bytes','online_seconds'] as $key) {
                if (!isset($input[$key]) || !is_numeric($input[$key]) || $input[$key] < 0) { throw new Problem(502, 'invalid_api_response'); }
                $out[$key] = (int) $input[$key];
            }
            return $out;
        };
        if (!is_array($remote['totals'] ?? null) || !is_array($remote['buckets'] ?? null)) { throw new Problem(502, 'invalid_api_response'); }
        $out = ['totals' => $totals($remote['totals']), 'buckets' => []];
        foreach ($remote['buckets'] as $bucket) {
            if (!is_string($bucket['start'] ?? null)) { throw new Problem(502, 'invalid_api_response'); }
            try { new \DateTimeImmutable($bucket['start']); } catch (\Throwable $e) { throw new Problem(502, 'invalid_api_response'); }
            $out['buckets'][] = ['start' => $bucket['start']] + $totals($bucket);
        }
        return $out;
    }

    private static function usageKey(array $range, string $id): string { return 'usage:' . hash('sha256', json_encode([$range,$id], JSON_THROW_ON_ERROR)); }
    private function cached(string $scope, string $key): ?array
    {
        $row = $this->store->one('SELECT payload,updated_at FROM mikrodius_statistics WHERE cache_key=? AND scope_key=?', [hash('sha256', $scope . ':' . $key), $scope]);
        return $row ? ['data' => json_decode($row['payload'], true, 64, JSON_THROW_ON_ERROR), 'at' => str_replace(' ', 'T', $row['updated_at']) . 'Z'] : null;
    }
    private function save(string $scope, string $key, array $data): void
    {
        $this->store->execute('INSERT INTO mikrodius_statistics(cache_key,scope_key,payload,updated_at) VALUES(?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE payload=VALUES(payload),updated_at=VALUES(updated_at)',
            [hash('sha256', $scope . ':' . $key), $scope, json_encode($data, JSON_THROW_ON_ERROR)]);
    }
}
