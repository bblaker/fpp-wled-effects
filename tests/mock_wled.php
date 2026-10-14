<?php
/*
 * Minimal WLED JSON API stand-in for tests:
 *   MOCK_NAME=Porch MOCK_DIR=/tmp/x php -S 127.0.0.1:9001 tests/mock_wled.php
 * Every POST to /json/state is appended to $MOCK_DIR/posts-<port>.jsonl.
 * MOCK_PRESETS=alt serves a different preset set.
 */

$name = getenv('MOCK_NAME') ?: 'Mock WLED';
$dir = getenv('MOCK_DIR') ?: sys_get_temp_dir();
$port = $_SERVER['SERVER_PORT'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$effects = ['Solid', 'Blink', 'Breathe', 'Wipe', 'RSVD', 'Chase', 'Rainbow', '-', 'Fireworks'];
$palettes = ['Default', '* Random Cycle', 'Party', 'Rainbow', 'Candy Cane'];
if (getenv('MOCK_PRESETS') === 'alt') {
    $presets = [
        '0' => new stdClass(),
        '4' => ['n' => 'Candy Cane', 'on' => true],
        '7' => ['n' => 'Garage Only'],
    ];
} else {
    $presets = [
        '0' => new stdClass(),
        '1' => ['n' => 'Candy Cane', 'on' => true, 'seg' => [['fx' => 5]]],
        '2' => ['n' => 'Warm White', 'on' => true],
        '3' => ['n' => 'Show Loop', 'playlist' => ['ps' => [1, 2], 'dur' => [100, 100], 'transition' => [7, 7], 'repeat' => 0, 'end' => 0]],
        '9' => ['ql' => 'X'],
    ];
}
$info = [
    'ver' => '0.15.0',
    'name' => $name,
    'brand' => 'WLED',
    'arch' => 'esp32',
    'mac' => substr(md5($name), 0, 12),
    'leds' => ['count' => 150],
    'live' => false,
];
$state = ['on' => true, 'bri' => 128, 'ps' => -1, 'pl' => -1, 'seg' => [['id' => 0, 'fx' => 0], ['id' => 1, 'fx' => 0]]];

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($path === '/json/state' || $path === '/json')) {
    $body = file_get_contents('php://input');
    file_put_contents("$dir/posts-$port.jsonl", $body . "\n", FILE_APPEND | LOCK_EX);
    if (getenv('MOCK_REJECT')) {
        echo json_encode(['error' => 9]);
    } else {
        echo json_encode(['success' => true]);
    }
    return true;
}

switch ($path) {
    case '/':
        header('Content-Type: text/html');
        echo '<html><head><title>WLED</title></head><body>WLED UI</body></html>';
        break;
    case '/json':
        echo json_encode(['state' => $state, 'info' => $info, 'effects' => $effects, 'palettes' => $palettes]);
        break;
    case '/json/info':
        echo json_encode($info);
        break;
    case '/json/state':
        echo json_encode($state);
        break;
    case '/json/eff':
        echo json_encode($effects);
        break;
    case '/json/pal':
        echo json_encode($palettes);
        break;
    case '/presets.json':
        echo json_encode($presets);
        break;
    default:
        http_response_code(404);
        echo json_encode(['error' => 'not found']);
}
return true;
