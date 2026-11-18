<?php
namespace Mikrodius;

/**
 * Keeps `system/devices/MikrodiusRadius.php` in step with the copy shipped inside the plugin.
 * Plugin Manager's Install button only fills `system/plugin/`, so the plugin places its own
 * device driver. Runs on every plugin load: local file work only, never remote calls, and it
 * never throws. A development tree has no bundled copy and is left alone.
 */
final class DeviceInstaller
{
    public const FILE = 'MikrodiusRadius.php';

    private static ?string $status = null;

    /** `null` (in place or nothing to do), `devices_not_writable` or `device_copy_failed`. */
    public static function status(): ?string { return self::$status; }

    public static function ensure(): void
    {
        $source = __DIR__ . '/device/' . self::FILE;
        if (!is_file($source)) { return; }
        $directory = rtrim((string) ($GLOBALS['DEVICE_PATH'] ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'devices'), '/\\');
        $target = $directory . DIRECTORY_SEPARATOR . self::FILE;
        $wanted = @hash_file('sha256', $source);
        if ($wanted === false) { self::$status = 'device_copy_failed'; return; }
        if (is_file($target) && hash_equals($wanted, (string) @hash_file('sha256', $target))) { return; }
        if (!is_dir($directory) || !is_writable($directory)) { self::$status = 'devices_not_writable'; return; }
        // Write beside the target and rename, so PHPNuxBill never includes a half-written driver.
        $temp = @tempnam($directory, 'mikrodius-');
        // tempnam() silently falls back to the system temp folder; a rename from there may cross filesystems.
        if ($temp === false || realpath(dirname($temp)) !== realpath($directory)) {
            if ($temp !== false) { @unlink($temp); }
            self::$status = 'devices_not_writable';
            return;
        }
        $copied = @copy($source, $temp);
        // tempnam() creates 0600; the web server must read a driver that cron may have written.
        @chmod($temp, 0644);
        if (!$copied || !@rename($temp, $target)) {
            @unlink($temp);
            self::$status = 'device_copy_failed';
        }
    }
}
