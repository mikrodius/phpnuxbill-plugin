<?php
namespace Mikrodius;

final class SecretBox
{
    private string $key;

    public function __construct(string $key)
    {
        if (strlen($key) !== 32) { throw new \InvalidArgumentException('A 32-byte storage key is required.'); }
        $this->key = $key;
    }

    public static function fromFile(string $path): self
    {
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new Problem(500, 'secret_storage_unavailable');
        }
        // A PHP guard prevents HTTP disclosure even when deployed below a document root.
        $handle = fopen($path, 'c+');
        if (!$handle || !flock($handle, LOCK_EX)) { throw new Problem(500, 'secret_storage_unavailable'); }
        try {
            $contents = stream_get_contents($handle);
            if ($contents === '') {
                $contents = "<?php /* Mikrodius storage key: preserve on upgrade. */ return '" . base64_encode(random_bytes(32)) . "';\n";
                if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) { throw new Problem(500, 'secret_storage_unavailable'); }
                chmod($path, 0600);
            }
            if (!preg_match("/return '([A-Za-z0-9+\/=]+)';/", $contents, $match)) { throw new Problem(500, 'secret_storage_invalid'); }
            return new self(base64_decode($match[1], true));
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    public function seal(array $value): string
    {
        $iv = random_bytes(12); $tag = '';
        $encrypted = openssl_encrypt(json_encode($value, JSON_THROW_ON_ERROR), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, 'mikrodius-v1');
        if ($encrypted === false) { throw new Problem(500, 'secret_storage_unavailable'); }
        return base64_encode($iv . $tag . $encrypted);
    }

    public function fingerprint(array $value): string
    {
        return hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), $this->key);
    }

    public function open(string $value): array
    {
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 28) { throw new Problem(500, 'secret_storage_invalid'); }
        $decoded = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'mikrodius-v1');
        if ($decoded === false) { throw new Problem(500, 'secret_storage_invalid'); }
        return json_decode($decoded, true, 64, JSON_THROW_ON_ERROR);
    }
}
