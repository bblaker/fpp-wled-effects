<?php
/*
 * Stand-in for the two FPP API routes discovery reads:
 *   php -S 127.0.0.1:9080 tests/mock_fpp.php
 * MOCK_SYSTEMS / MOCK_OUTPUTS are comma-separated addresses.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');

$systems = array_filter(explode(',', getenv('MOCK_SYSTEMS') ?: ''));
$outputs = array_filter(explode(',', getenv('MOCK_OUTPUTS') ?: ''));

if ($path === '/api/fppd/multiSyncSystems') {
    $list = [
        ['type' => 'Raspberry Pi 4', 'typeId' => 13, 'address' => '127.0.0.1', 'hostname' => 'fpp', 'local' => 1],
        ['type' => 'Falcon F16V5', 'typeId' => 0x87, 'address' => '10.255.255.1', 'hostname' => 'falcon'],
    ];
    foreach ($systems as $a) {
        $list[] = ['type' => 'WLED', 'typeId' => 0xFB, 'address' => $a, 'hostname' => 'wled-' . $a];
    }
    echo json_encode(['systems' => $list, 'status' => 'OK']);
} elseif ($path === '/api/channel/output/universeOutputs') {
    $universes = [['active' => 1, 'address' => '239.255.0.1', 'description' => 'multicast', 'type' => 0]];
    foreach ($outputs as $i => $a) {
        $universes[] = ['active' => 1, 'address' => $a, 'description' => 'Output ' . $i, 'type' => 4];
    }
    echo json_encode(['channelOutputs' => [['type' => 'universes', 'enabled' => 1, 'universes' => $universes]]]);
} else {
    http_response_code(404);
    echo '{}';
}
return true;
