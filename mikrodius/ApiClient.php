<?php
namespace Mikrodius;

final class ApiClient
{
    private string $base;
    private ?Deadline $deadline = null;
    public function withDeadline(?Deadline $deadline): self { $this->deadline = $deadline; return $this; }
    private string $key;
    /** Transport injection is for tests; production always uses TLS-verified cURL. */
    private $transport;

    public function __construct(string $base, string $key, ?callable $transport = null)
    {
        $parts = parse_url($base);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['query']) || isset($parts['fragment']) || ($parts['path'] ?? '') !== '/api/v1') {
            throw new Problem(422, 'invalid_api_url');
        }
        if (!preg_match('/^mk_live_[A-Za-z0-9_-]+$/D', $key)) {
            throw new Problem(422, 'invalid_api_key');
        }
        $this->base = rtrim($base, '/');
        $this->key = $key;
        $this->transport = $transport;
    }

    public static function workspace(string $id): string
    {
        if (!preg_match('/^ws_[A-Za-z0-9]+$/D', $id)) { throw new Problem(422, 'invalid_workspace'); }
        return '/workspaces/' . rawurlencode($id);
    }

    public function request(string $method, string $path, ?array $body = null): array
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '://') ||
            str_contains($path, '\\') || preg_match('~(?:^|/)\.\.(?:/|$)~', $path)) {
            throw new Problem(422, 'invalid_api_path');
        }
        $this->deadline?->check();
        $headers = ['Authorization: Bearer ' . $this->key, 'Accept: application/json'];
        $encoded = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);
        if ($encoded !== null) { $headers[] = 'Content-Type: application/json'; }
        if ($this->transport) {
            [$status, $response, $responseHeaders] = ($this->transport)($method, $path, $body);
        } else {
            $responseHeaders = [];
            $ch = curl_init($this->base . $path);
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => min(5000, $this->deadline?->remainingMs() ?? 20000),
                CURLOPT_TIMEOUT_MS => min(20000, $this->deadline?->remainingMs() ?? 20000),
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$responseHeaders) {
                    $pair = explode(':', $line, 2);
                    if (count($pair) === 2) { $responseHeaders[strtolower(trim($pair[0]))] = trim($pair[1]); }
                    return strlen($line);
                }]);
            if ($encoded !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded); }
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $failed = $response === false;
            curl_close($ch);
            if ($failed) { throw new Problem(0, 'transport_error'); }
        }
        try { $decoded = json_decode($response, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { $decoded = null; }
        if ($status < 200 || $status >= 300) {
            $code = is_array($decoded) && is_string($decoded['code'] ?? null) ? $decoded['code'] : 'http_error';
            if (!preg_match('/^[a-z0-9_]{1,80}$/D', $code)) { $code = 'http_error'; }
            $requestId = $responseHeaders['x-request-id'] ?? $decoded['request_id'] ?? null;
            if (!is_string($requestId) || !preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $requestId)) { $requestId = null; }
            $retry = $responseHeaders['retry-after'] ?? '0';
            $delay = ctype_digit((string) $retry) ? (int) $retry : max(0, (strtotime($retry) ?: time()) - time());
            throw new Problem($status, $code, $requestId, min(86400, $delay));
        }
        if ($status === 204) { return []; }
        if (!is_array($decoded)) { throw new Problem(0, 'invalid_api_response'); }
        return $decoded;
    }

    public function all(string $path): array
    {
        $items = []; $cursor = null; $seen = [];
        do {
            $page = $this->request('GET', $path . ($cursor === null ? '' : (str_contains($path, '?') ? '&' : '?') . 'cursor=' . rawurlencode($cursor)));
            if (!is_array($page['data'] ?? null)) { throw new Problem(0, 'invalid_api_response'); }
            array_push($items, ...$page['data']);
            $cursor = $page['next_cursor'] ?? null;
            if ($cursor !== null) {
                if (!is_string($cursor) || isset($seen[$cursor])) { throw new Problem(0, 'invalid_pagination'); }
                $seen[$cursor] = true;
            }
        } while ($cursor !== null);
        return $items;
    }
}
