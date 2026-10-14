<?php
/*
 * Entry point for the FPP commands in commands/descriptions.json.
 *
 *   php cli.php <action> [command args...]
 *
 * <action> is one of the values in FppWledEffects\COMMANDS, or "refresh" to
 * re-read every device. fppd waits for this process to exit before moving on,
 * so all WLED requests use short timeouts.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once __DIR__ . '/wled.php';

// PHP's CLI ignores the system timezone (which FPP keeps in step with its
// TimeZone setting); use it so log lines match the ones from the web UI.
if (!ini_get('date.timezone')) {
    $tz = @readlink('/etc/localtime');
    if ($tz && preg_match('#zoneinfo/(.+)$#', $tz, $m) && in_array($m[1], timezone_identifiers_list(), true)) {
        date_default_timezone_set($m[1]);
    }
}

use FppWledEffects\Actions;
use FppWledEffects\Devices;
use FppWledEffects\Log;
use FppWledEffects\Wled;

$action = isset($argv[1]) ? $argv[1] : '';
$args = array_slice($argv, 2);

try {
    if ($action === 'refresh') {
        $devices = empty($args) ? Devices::enabled() : Devices::resolveTargets($args[0]);
        $entries = Wled::refresh($devices);
        foreach ($devices as $d) {
            $e = $entries[$d['id']];
            echo $d['name'] . ': ' . (!empty($e['online']) ? 'online' : 'offline - ' . $e['error']) . "\n";
        }
        exit(0);
    }

    if (!in_array($action, FppWledEffects\COMMANDS, true)) {
        fwrite(STDERR, "Usage: cli.php <" . implode('|', FppWledEffects\COMMANDS) . "|refresh> [args...]\n");
        exit(2);
    }

    $result = Actions::run($action, $args);
    foreach ($result['results'] as $r) {
        echo $r['device'] . ': ' . $r['message'] . "\n";
    }
    exit($result['ok'] ? 0 : 1);
} catch (\Throwable $e) {
    Log::error('Command ' . $action . ' failed: ' . $e->getMessage());
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
