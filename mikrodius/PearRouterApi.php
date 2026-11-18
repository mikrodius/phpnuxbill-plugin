<?php
namespace Mikrodius;

use PEAR2\Net\RouterOS;

/** RouterOS API access through PHPNuxBill's bundled PEAR2 client and stored router login. */
final class PearRouterApi implements RouterApi
{
    private RouterOS\Client $client;
    private array $secrets = [];

    private function __construct(RouterOS\Client $client) { $this->client = $client; }

    public static function connect(array $router, int $timeout = 10): self
    {
        if (!class_exists(RouterOS\Client::class)) { throw new Problem(500, 'routeros_library_missing'); }
        $address = trim((string) ($router['ip_address'] ?? ''));
        $port = 8728;
        if (preg_match('/^(.+):(\d{1,5})$/D', $address, $m) && !str_contains($m[1], ':')) { $address = $m[1]; $port = (int) $m[2]; }
        if ($address === '' || $port < 1 || $port > 65535) { throw new Problem(422, 'router_address_invalid'); }
        try {
            return new self(new RouterOS\Client($address, (string) $router['username'], (string) $router['password'], $port, false, $timeout));
        } catch (RouterOS\DataFlowException $e) {
            if ($e->getCode() === RouterOS\DataFlowException::CODE_INVALID_CREDENTIALS) { throw new Problem(422, 'router_login_failed'); }
            throw new Problem(0, 'router_unreachable');
        } catch (\Throwable $e) {
            // Library messages can contain the management address and login; keep them out.
            throw new Problem(0, 'router_unreachable');
        }
    }

    /** Values that must never appear in an operator-visible router error. */
    public function redact(string ...$values): void
    {
        foreach ($values as $value) { if (strlen($value) >= 4) { $this->secrets[] = $value; } }
    }

    private function send(string $command, array $args = []): array
    {
        $request = new RouterOS\Request($command);
        foreach ($args as $name => $value) { $request->setArgument($name, (string) $value); }
        try { $responses = $this->client->sendSync($request); }
        catch (\Throwable $e) { throw new Problem(0, 'router_unreachable'); }
        $rows = [];
        foreach ($responses as $response) {
            $type = $response->getType();
            if ($type === RouterOS\Response::TYPE_ERROR || $type === RouterOS\Response::TYPE_FATAL) {
                throw new Problem(422, 'router_command_failed', null, 0, $command . ': ' . $this->clean((string) $response->getProperty('message')));
            }
            if ($type === RouterOS\Response::TYPE_DATA) {
                $row = [];
                foreach ($response as $name => $value) { $row[$name] = is_string($value) ? $value : (string) stream_get_contents($value); }
                $rows[] = $row;
            } elseif ($type === RouterOS\Response::TYPE_FINAL && $response->getProperty('ret') !== null) {
                $rows[] = ['ret' => (string) $response->getProperty('ret')];
            }
        }
        return $rows;
    }

    private function clean(string $message): string
    {
        foreach ($this->secrets as $secret) { $message = str_replace($secret, '[redacted]', $message); }
        return mb_substr(preg_replace('/[^\P{C}]+/u', ' ', $message), 0, 200);
    }

    public function rows(string $menu): array { return $this->send($menu . '/print'); }

    public function add(string $menu, array $props): string
    {
        $rows = $this->send($menu . '/add', $props);
        $id = $rows[0]['ret'] ?? null;
        if (!is_string($id) || $id === '') { throw new Problem(502, 'router_command_failed', null, 0, $menu . '/add returned no ID'); }
        return $id;
    }

    public function set(string $menu, string $id, array $props): void { $this->send($menu . '/set', ['.id' => $id] + $props); }

    public function setGlobal(string $menu, array $props): void { $this->send($menu . '/set', $props); }

    public function remove(string $menu, string $id): void { $this->send($menu . '/remove', ['.id' => $id]); }

    public function ping(string $address, int $count): array
    {
        return $this->send('/ping', ['address' => $address, 'count' => max(1, min(5, $count)), 'interval' => '500ms']);
    }

    public function writeFile(string $name, string $contents): void
    {
        $this->send('/file/print', ['file' => preg_replace('/\.txt$/D', '', $name)]);
        $id = null;
        for ($i = 0; $i < 5 && $id === null; $i++) {
            foreach ($this->rows('/file') as $file) { if (($file['name'] ?? '') === $name) { $id = $file['.id']; } }
            if ($id === null) { usleep(500000); }
        }
        if ($id === null) { throw new Problem(502, 'router_file_unavailable'); }
        $this->set('/file', $id, ['contents' => $contents]);
    }

    public function importCertificate(string $file): void
    {
        $this->send('/certificate/import', ['file-name' => $file, 'passphrase' => '']);
    }
}
