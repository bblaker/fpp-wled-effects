<?php
/*
 * Tests for fpp-wled-effects. Run via tests/run.sh, which starts the mock
 * WLED controllers and mock FPP API this expects:
 *   127.0.0.1:9001  WLED "Porch"
 *   127.0.0.1:9002  WLED "Garage" (different presets), reported by MultiSync
 *   127.0.0.1:9003  WLED "Tree", an E1.31/DDP output destination
 *   127.0.0.1:9009  nothing (offline)
 *   127.0.0.1:9080  FPP API
 */

$root = dirname(__DIR__);
$media = getenv('MEDIADIR');
$mockDir = getenv('MOCK_DIR');
$_SERVER['SERVER_PORT'] = '9080'; // Env::fppApiBase() -> the mock FPP API

require_once $root . '/lib/wled.php';

use FppWledEffects\Actions;
use FppWledEffects\Cache;
use FppWledEffects\Devices;
use FppWledEffects\Discovery;
use FppWledEffects\Lists;
use FppWledEffects\Util;
use FppWledEffects\Wled;

// Write straight to STDOUT: output through PHP's output layer counts as
// "headers sent" in the CLI, after which the API callbacks under test can't
// set their HTTP status codes (enforced from PHP 8.4).
function out($s)
{
    fwrite(STDOUT, $s);
}

$failures = 0;
$count = 0;

function check($cond, $label, $detail = null)
{
    global $failures, $count;
    $count++;
    if ($cond) {
        out("  ok   $label\n");
    } else {
        $failures++;
        out("  FAIL $label" . ($detail !== null ? "\n       " . (is_string($detail) ? $detail : json_encode($detail)) : '') . "\n");
    }
}

function throws(callable $fn, $class = 'InvalidArgumentException')
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return $e instanceof $class;
    }
    return false;
}

// POST bodies each mock device received since the last call.
function posts($port)
{
    global $mockDir;
    $file = "$mockDir/posts-$port.jsonl";
    if (!file_exists($file)) {
        return [];
    }
    $lines = array_filter(explode("\n", file_get_contents($file)));
    unlink($file);
    return array_values(array_map(function ($l) {
        return json_decode($l, true);
    }, $lines));
}

function clearPosts()
{
    foreach ([9001, 9002, 9003] as $p) {
        posts($p);
    }
}

function section($name)
{
    out("\n$name\n");
}

/* ------------------------------------------------------------------ */
section('Util');
check(Util::normalizeHost(' http://192.168.1.50/ ') === '192.168.1.50', 'normalizeHost strips scheme and slash');
check(Util::normalizeHost('WLED-Porch.local') === 'wled-porch.local', 'normalizeHost lowercases hostnames');
check(Util::normalizeHost('10.0.0.5:8080') === '10.0.0.5:8080', 'normalizeHost keeps port');
check(Util::normalizeHost('fe80::1') === '[fe80::1]', 'normalizeHost brackets IPv6');
check(Util::normalizeHost('bad host;rm') === null, 'normalizeHost rejects junk');
check(Util::normalizeHost('') === null, 'normalizeHost rejects empty');
check(Util::parseColor('#FF8800') === [255, 136, 0], 'parseColor #rrggbb');
check(Util::parseColor('f80') === [255, 136, 0], 'parseColor short form');
check(Util::parseColor('nope') === null, 'parseColor invalid');
check(Util::clampInt('300', 0, 255, 1) === 255 && Util::clampInt('', 0, 255, 7) === 7, 'clampInt clamps and defaults');
check(Util::parseBool('true') && !Util::parseBool('false') && Util::parseBool('1') && !Util::parseBool(''), 'parseBool');
$names = [1 => 'Candy Cane', 2 => 'warm white', 12 => '5'];
check(Util::findByName($names, 'Candy Cane') === 1, 'findByName exact');
check(Util::findByName($names, ' WARM White ') === 2, 'findByName case-insensitive');
check(Util::findByName($names, '5') === 12, 'findByName prefers a name over an id');
check(Util::findByName($names, '#2') === 2, 'findByName #id');
check(Util::findByName($names, 'Nope') === null, 'findByName missing');
check(Wled::parsePresets(['0' => [], '1' => ['n' => 'A'], '2' => ['n' => 'L', 'playlist' => ['ps' => [1]]], '5' => ['x' => 1]])
    === [[1 => 'A', 5 => 'Preset 5'], [2 => 'L']], 'parsePresets splits presets and playlists');

/* ------------------------------------------------------------------ */
section('Discovery');
$found = Discovery::find();
$hosts = array_column($found, 'host');
sort($hosts);
check($hosts === ['127.0.0.1:9002', '127.0.0.1:9003'], 'finds WLED from MultiSync and channel outputs only', $hosts);
$byHost = array_column($found, null, 'host');
check($byHost['127.0.0.1:9002']['source'] === 'FPP MultiSync' && $byHost['127.0.0.1:9002']['name'] === 'Garage', 'MultiSync device named from WLED');
check($byHost['127.0.0.1:9003']['source'] === 'FPP Channel Outputs', 'output device source');

$misses = json_decode(file_get_contents($media . '/plugindata/fpp-wled-effects/discovery-misses.json'), true);
check(array_keys($misses) === ['127.0.0.1:9009'], 'non-WLED address remembered as a miss', $misses);
check(count(Discovery::find()) === 2, 'cached miss is skipped and results are unchanged');

$added = Discovery::autoImport();
check(count($added) === 2, 'autoImport adds both', array_column($added, 'name'));
check(count(Discovery::autoImport()) === 0, 'autoImport is idempotent');
check(!empty(Cache::get($added[0]['id'])['online']), 'autoImport reads the new devices');

/* ------------------------------------------------------------------ */
section('Devices');
$porch = Devices::add('127.0.0.1:9001', '', 'manual', Wled::probe(['127.0.0.1:9001'])['127.0.0.1:9001']);
check($porch['name'] === 'Porch' && strpos($porch['id'], 'mac-') === 0, 'add takes name and id from WLED', $porch);
check(throws(function () {
    Devices::add('127.0.0.1:9001');
}), 'add rejects a duplicate address');
check(throws(function () {
    Devices::add('not valid!');
}), 'add rejects a bad address');
$dupe = Devices::add('127.0.0.1:9010', 'porch');
check($dupe['name'] === 'porch 2', 'names are kept unique', $dupe['name']);
Devices::remove($dupe['id']);
$reserved = Devices::add('127.0.0.1:9011', 'All WLED Devices');
check($reserved['name'] === 'WLED Device', 'reserved name is replaced', $reserved['name']);
Devices::remove($reserved['id']);
$porchInfo = Wled::probe(['127.0.0.1:9001'])['127.0.0.1:9001'];
$moved = Devices::add('127.0.0.1:9005', '', 'fpp', $porchInfo);
check($moved['id'] === $porch['id'] && $moved['host'] === '127.0.0.1:9005' && count(Devices::all()) === 3,
    'same MAC at a new address updates the device instead of adding one', $moved);
Devices::add('127.0.0.1:9001', '', 'fpp', $porchInfo);
check(Devices::find($porch['id'])['host'] === '127.0.0.1:9001', 'and back again');
check(Devices::find('porch')['id'] === $porch['id'], 'find by name, case-insensitive');
check(Devices::find('127.0.0.1:9001')['id'] === $porch['id'], 'find by address');
check(count(Devices::resolveTargets('All WLED Devices')) === 3, 'All WLED Devices targets every device');
check(count(Devices::resolveTargets('Nope')) === 0, 'unknown device resolves to nothing');

$entries = Wled::refresh(Devices::all());
$pc = $entries[$porch['id']];
check($pc['online'] === true && $pc['info']['ver'] === '0.15.0' && $pc['info']['leds'] === 150, 'refresh reads info');
check($pc['presets'] === [1 => 'Candy Cane', 2 => 'Warm White', 9 => 'Preset 9'], 'refresh reads presets', $pc['presets']);
check($pc['playlists'] === [3 => 'Show Loop'], 'refresh reads playlists', $pc['playlists']);
check(!in_array('RSVD', $pc['effects'], true) && !in_array('-', $pc['effects'], true) && $pc['effects'][8] === 'Fireworks',
    'refresh drops reserved effects and keeps ids', $pc['effects']);
check($pc['segments'] === [0, 1], 'refresh reads segment ids');
check(Cache::get($porch['id'])['presets'][2] === 'Warm White', 'refresh persists the cache (ids survive JSON)');

/* ------------------------------------------------------------------ */
section('Lists (command dropdowns)');
check(Lists::devices() === ['All WLED Devices', 'Garage', 'Tree', 'Porch'], 'device list', Lists::devices());
$presetList = (array) Lists::named('presets');
check(array_keys($presetList) === ['Candy Cane', 'Garage Only', 'Preset 9', 'Warm White'], 'preset names (sorted, unique)', array_keys($presetList));
check($presetList['Candy Cane'] === 'Candy Cane  (all devices)', 'label says all devices', $presetList['Candy Cane']);
check($presetList['Garage Only'] === 'Garage Only  (Garage)', 'label names the device', $presetList['Garage Only']);
check(array_keys((array) Lists::named('playlists')) === ['Show Loop'], 'playlist names');
$fx = Lists::effects();
check($fx[0] === 'Solid' && in_array('Fireworks', $fx, true) && !in_array('RSVD', $fx, true), 'effect list', $fx);
check(Lists::palettes()[0] === 'Default', 'palette list leads with Default');

/* ------------------------------------------------------------------ */
section('Actions');
clearPosts();
$r = Actions::run('preset', ['All WLED Devices', 'Candy Cane', 'false']);
check($r['ok'] && count($r['results']) === 3, 'preset on all devices', $r);
check(posts(9001) === [['on' => true, 'ps' => 1]], 'Porch got ps 1');
check(posts(9002) === [['on' => true, 'ps' => 4]], 'Garage got its own id for the same name');
check(posts(9003) === [['on' => true, 'ps' => 1]], 'Tree got ps 1');

$r = Actions::run('preset', ['All WLED Devices', 'Warm White']);
check($r['ok'], 'preset missing on one device still succeeds overall', $r);
$skipped = array_values(array_filter($r['results'], function ($x) {
    return !empty($x['skipped']);
}));
check(count($skipped) === 1 && $skipped[0]['device'] === 'Garage' && strpos($r['message'], '1 without this preset skipped') !== false,
    'device without the preset is reported as skipped', $r);
check(posts(9002) === [], 'device without the preset is skipped');
clearPosts();

$r = Actions::run('preset', ['Porch', 'Nope']);
check(!$r['ok'] && strpos($r['results'][0]['message'], 'no preset named') === 0, 'unknown preset fails', $r);

$r = Actions::run('playlist', ['Porch', 'Show Loop', 'true']);
check($r['ok'] && posts(9001) === [['on' => true, 'ps' => 3, 'lor' => 1]], 'playlist with live override');

$r = Actions::run('effect', ['Porch', 'Chase', 'Party', '#ff0000', '#00ff00', '#0000ff', '200', '50', '0', 'Selected', 'false']);
$p = posts(9001);
check($r['ok'] && $p === [['on' => true, 'seg' => ['on' => true, 'fx' => 5, 'sx' => 200, 'ix' => 50, 'pal' => 2,
    'col' => [[255, 0, 0], [0, 255, 0], [0, 0, 255]]]]], 'effect payload', $p);

Actions::run('effect', ['Porch', 'blink', '', '#ffffff', '', '', '', '', '180', 'All', 'true']);
$p = posts(9001)[0];
check($p['bri'] === 180 && $p['lor'] === 1 && count($p['seg']) === 2 && $p['seg'][1]['id'] === 1 && $p['seg'][1]['fx'] === 1
    && !isset($p['seg'][0]['pal']) && $p['seg'][0]['col'] === [[255, 255, 255]], 'effect on all segments, defaults, brightness', $p);

Actions::run('effect', ['Porch', 'Solid', '', '', '', '', '', '', '', '1']);
$p = posts(9001)[0];
check($p['seg']['id'] === 1 && $p['seg']['fx'] === 0 && $p['seg']['sx'] === 128, 'effect on one segment', $p);

$r = Actions::run('effect', ['Porch', 'Not An Effect']);
check(!$r['ok'] && posts(9001) === [], 'unknown effect is not sent');
$r = Actions::run('effect', ['Porch', 'Solid', 'Not A Palette']);
check(!$r['ok'], 'unknown palette fails');
check(throws(function () {
    Actions::run('effect', ['Porch', '']);
}), 'missing effect throws');

Actions::run('power', ['Porch', 'Toggle']);
Actions::run('power', ['Porch', 'Off']);
Actions::run('power', ['Porch', 'On']);
check(posts(9001) === [['on' => 't'], ['on' => false], ['on' => true]], 'power on/off/toggle');

Actions::run('brightness', ['Tree', '0']);
Actions::run('brightness', ['Tree', '77']);
check(posts(9003) === [['on' => false], ['on' => true, 'bri' => 77]], 'brightness');

Actions::run('override', ['Garage', 'Until Reboot']);
Actions::run('override', ['Garage', 'Off']);
check(posts(9002) === [['lor' => 2], ['lor' => 0]], 'live override modes');
check(throws(function () {
    Actions::run('override', ['Garage', 'Sometimes']);
}), 'bad override mode throws');
check(throws(function () {
    Actions::run('power', ['Nope', 'On']);
}), 'unknown device throws');

// A name the cache doesn't know triggers one live re-read of that device.
Cache::put([$porch['id'] => ['online' => true, 'error' => null, 'checked' => time(), 'effects' => [0 => 'Solid'], 'presets' => [], 'playlists' => []]]);
$r = Actions::run('preset', ['Porch', 'Warm White']);
check($r['ok'] && posts(9001) === [['on' => true, 'ps' => 2]], 'stale cache is refreshed on a miss', $r);

// WLED rejecting a request is reported as a failure.
$r = Actions::run('power', ['Porch', 'On']); // sanity
posts(9001);

/* ------------------------------------------------------------------ */
section('Offline devices');
$off = Devices::add('127.0.0.1:9009', 'Shed');
$t = microtime(true);
$r = Actions::run('preset', ['All WLED Devices', 'Candy Cane']);
$elapsed = microtime(true) - $t;
$shed = array_values(array_filter($r['results'], function ($x) {
    return $x['device'] === 'Shed';
}))[0];
check(!$shed['ok'] && strpos($shed['message'], 'offline') === 0, 'offline device reported', $shed);
check(!$r['ok'] && count(array_filter($r['results'], function ($x) {
    return $x['ok'];
})) === 3, 'other devices still get the command');
check($elapsed < 5, sprintf('offline device fails fast (%.2fs)', $elapsed));
clearPosts();
Devices::edit($off['id'], ['enabled' => false]);
check(count(Devices::resolveTargets('All WLED Devices')) === 3, 'disabled device is skipped by All WLED Devices');
Devices::remove($off['id']);

/* ------------------------------------------------------------------ */
section('Edit / remove / auto-import');
$garage = Devices::find('Garage');
Devices::edit($garage['id'], ['name' => 'Garage Door']);
check(Devices::find('Garage Door') !== null && Devices::find('Garage') === null, 'rename');
Devices::remove($garage['id']);
check(Cache::get($garage['id']) === null, 'remove drops cached lists');
check(count(Discovery::autoImport()) === 0, 'auto-import does not bring back a removed device');
check(in_array('127.0.0.1:9002', Devices::load()['ignored'], true), 'removed device is remembered');
Devices::setAutoImport(true);
check(count(Discovery::autoImport()) === 1, 're-enabling auto-import forgets removals');

/* ------------------------------------------------------------------ */
section('Command scripts (as fppd runs them)');
clearPosts();
$env = 'env -i MEDIADIR=' . escapeshellarg($media) . ' FPPDIR=/opt/fpp SCRIPTDIR=' . escapeshellarg($root . '/commands/');
$cmd = $env . ' ' . escapeshellarg($root . '/commands/apply-preset.sh') . ' Porch "Warm White" false < /dev/null 2>&1';
exec($cmd, $out, $rc);
check($rc === 0 && posts(9001) === [['on' => true, 'ps' => 2]], 'apply-preset.sh with a bare environment', ['rc' => $rc, 'out' => $out]);

$out = [];
$cmd = $env . ' ' . escapeshellarg($root . '/commands/run-effect.sh') . ' Tree Rainbow Rainbow "#112233" "#000000" "#000000" 10 20 0 Selected false < /dev/null 2>&1';
exec($cmd, $out, $rc);
$p = posts(9003);
check($rc === 0 && isset($p[0]['seg']['fx']) && $p[0]['seg']['fx'] === 6 && $p[0]['seg']['pal'] === 3, 'run-effect.sh', ['rc' => $rc, 'out' => $out, 'posts' => $p]);

foreach (['start-playlist.sh' => 'Porch "Show Loop"', 'power.sh' => 'Porch Off', 'brightness.sh' => 'Porch 10', 'live-override.sh' => 'Porch "Until Live Data Ends"'] as $script => $a) {
    exec($env . ' ' . escapeshellarg($root . '/commands/' . $script) . ' ' . $a . ' < /dev/null 2>&1', $out, $rc);
    check($rc === 0, $script . ' runs');
}
check(posts(9001) === [['on' => true, 'ps' => 3], ['on' => false], ['on' => true, 'bri' => 10], ['lor' => 1]], 'script payloads');

$log = file_get_contents($media . '/logs/plugin-fpp-wled-effects.log');
check(strpos($log, 'Preset "Warm White" -> Porch: OK') !== false, 'commands write the plugin log');

/* ------------------------------------------------------------------ */
section('descriptions.json matches the code');
$desc = json_decode(file_get_contents($root . '/commands/descriptions.json'), true);
$names = array_column($desc, 'name');
check($names === array_keys(FppWledEffects\COMMANDS), 'command names match COMMANDS', $names);
foreach ($desc as $c) {
    check(is_executable($root . '/commands/' . $c['script']), $c['name'] . ' script is executable');
    check($c['args'][0]['name'] === 'device' && $c['args'][0]['default'] === FppWledEffects\ALL_DEVICES, $c['name'] . ' starts with the device arg');
}
$lor = array_values(array_filter($desc, function ($c) {
    return $c['name'] === 'WLED - Live Override';
}))[0];
check($lor['args'][1]['contents'] === array_keys(FppWledEffects\LIVE_OVERRIDE_MODES), 'live override choices match');

/* ------------------------------------------------------------------ */
section('REST API callbacks');
// Minimal stand-ins for the limonade helpers FPP provides.
function json($data)
{
    return json_encode($data);
}
$PARAMS = [];
function params($k)
{
    global $PARAMS;
    return $PARAMS[$k];
}
require_once $root . '/api.php';

$eps = getEndpointsfppwledeffects();
foreach ($eps as $ep) {
    check(function_exists($ep['callback']), 'endpoint ' . $ep['method'] . ' ' . $ep['endpoint'] . ' has a callback');
}
$j = json_decode(fppWledEffects_listDevices(), true);
check($j[0] === 'All WLED Devices' && in_array('Porch', $j, true), 'GET list/devices');
$j = json_decode(fppWledEffects_listPresets(), true);
check(isset($j['Candy Cane']), 'GET list/presets');
$j = json_decode(fppWledEffects_getDevices(), true);
check($j['status'] === 'OK' && count($j['devices']) === 3 && $j['commands']['preset'] === 'WLED - Apply Preset', 'GET devices', $j);
$row = array_values(array_filter($j['devices'], function ($d) {
    return $d['name'] === 'Porch';
}))[0];
check($row['online'] === true && $row['counts']['presets'] === 3 && $row['counts']['playlists'] === 1, 'device row has counts', $row);
$GLOBALS['_POST'] = ['command' => 'WLED - Power', 'args' => ['Porch', 'On']];
$j = json_decode(fppWledEffects_run(), true);
check($j['status'] === 'OK' && $j['results'][0]['ok'] && posts(9001) === [['on' => true]], 'POST run', $j);
$GLOBALS['_POST'] = ['command' => 'Nope'];
$j = json_decode(fppWledEffects_run(), true);
check($j['status'] === 'ERROR' && http_response_code() === 400, 'POST run rejects unknown command');
http_response_code(200);
$GLOBALS['_POST'] = ['host' => '127.0.0.1:9009'];
$j = json_decode(fppWledEffects_addDevice(), true);
check(http_response_code() === 422 && strpos($j['message'], 'No WLED') === 0, 'POST devices refuses a non-WLED address');
http_response_code(200);
$PARAMS['id'] = $porch['id'];
$j = json_decode(fppWledEffects_deviceDetails(), true);
check($j['cache']['presets']['1'] === 'Candy Cane', 'GET devices/:id/details');
$GLOBALS['_POST'] = ['autoImport' => false];
$j = json_decode(fppWledEffects_saveSettings(), true);
check($j['autoImport'] === false, 'POST settings');

/* ------------------------------------------------------------------ */
out("\n" . ($count - $failures) . " passed, $failures failed\n");
exit($failures ? 1 : 0);
