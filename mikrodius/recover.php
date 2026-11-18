<?php
/** Dedicated plugin recovery. Does not invoke PHPNuxBill billing cron. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/bootstrap.php';
$mkDeadline = new \Mikrodius\Deadline(30);
try {
    $mkRoot = dirname(__DIR__, 3);
    chdir($mkRoot);
    require $mkRoot . '/config.php';
    $mkDb = new PDO('mysql:host=' . $db_host . ';dbname=' . $db_name . ';charset=utf8mb4', $db_user,
        !empty($db_pass) ? $db_pass : ($db_password ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    $mkStore = new \Mikrodius\Store($mkDb, \Mikrodius\SecretBox::fromFile(__DIR__ . '/private/key.php'));
    $mkService = new \Mikrodius\Service($mkStore);
    $mkService->withDeadline($mkDeadline);
    $mkStore->migrate();
    \Mikrodius\Bridge::useService($mkService);
    $mkResult = \Mikrodius\Bridge::recover($mkDeadline);
    $mkResult['elapsed_seconds'] = 30 - $mkDeadline->remainingMs(false) / 1000;
    echo json_encode($mkResult, JSON_THROW_ON_ERROR), "\n";
    exit(!empty($mkResult['error_code']) || !empty($mkResult['heartbeat_error']) ? 1 : 0);
} catch (Throwable $mkError) {
    echo json_encode(['state' => 'pending', 'error_code' => $mkError instanceof \Mikrodius\Problem ? $mkError->problemCode : 'recovery_storage_unavailable']), "\n";
    exit(1);
}
