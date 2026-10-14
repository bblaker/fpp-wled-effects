<?php
/*
 * REST endpoints for fpp-wled-effects, served by FPP under
 * /api/plugin/fpp-wled-effects/...
 *
 * FPP loads this file on every API request, so it only declares functions;
 * the library is loaded when one of them is actually called.
 */

function getEndpointsfppwledeffects()
{
    $eps = [
        ['GET', 'devices', 'fppWledEffects_getDevices'],
        ['POST', 'devices', 'fppWledEffects_addDevice'],
        ['POST', 'devices/:id', 'fppWledEffects_editDevice'],
        ['DELETE', 'devices/:id', 'fppWledEffects_removeDevice'],
        ['GET', 'devices/:id/details', 'fppWledEffects_deviceDetails'],
        ['POST', 'refresh', 'fppWledEffects_refresh'],
        ['GET', 'discover', 'fppWledEffects_discover'],
        ['POST', 'settings', 'fppWledEffects_saveSettings'],
        ['POST', 'run', 'fppWledEffects_run'],
        ['GET', 'list/devices', 'fppWledEffects_listDevices'],
        ['GET', 'list/presets', 'fppWledEffects_listPresets'],
        ['GET', 'list/playlists', 'fppWledEffects_listPlaylists'],
        ['GET', 'list/effects', 'fppWledEffects_listEffects'],
        ['GET', 'list/palettes', 'fppWledEffects_listPalettes'],
    ];
    $result = [];
    foreach ($eps as $ep) {
        $result[] = ['method' => $ep[0], 'endpoint' => $ep[1], 'callback' => $ep[2]];
    }
    return $result;
}

function fppWledEffects_lib()
{
    require_once __DIR__ . '/lib/wled.php';
}

function fppWledEffects_body()
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (is_array($data)) {
        return $data;
    }
    return isset($GLOBALS['_POST']) && is_array($GLOBALS['_POST']) ? $GLOBALS['_POST'] : [];
}

function fppWledEffects_error($status, $message)
{
    http_response_code($status);
    return json(['status' => 'ERROR', 'message' => $message]);
}

// Run $fn, mapping bad input to 400 and anything else to 500.
function fppWledEffects_wrap(callable $fn)
{
    fppWledEffects_lib();
    try {
        return $fn();
    } catch (\InvalidArgumentException $e) {
        return fppWledEffects_error(400, $e->getMessage());
    } catch (\Throwable $e) {
        \FppWledEffects\Log::error('API error: ' . $e->getMessage());
        return fppWledEffects_error(500, $e->getMessage());
    }
}

// Device list joined with what was last read from each device.
function fppWledEffects_deviceRows()
{
    $data = \FppWledEffects\Devices::load();
    $cache = \FppWledEffects\Cache::all();
    $rows = [];
    foreach ($data['devices'] as $d) {
        $c = isset($cache[$d['id']]) ? $cache[$d['id']] : [];
        $rows[] = array_merge($d, [
            'online' => isset($c['online']) ? (bool) $c['online'] : null,
            'error' => isset($c['error']) ? $c['error'] : null,
            'checked' => isset($c['checked']) ? $c['checked'] : null,
            'updated' => isset($c['updated']) ? $c['updated'] : null,
            'info' => isset($c['info']) ? $c['info'] : null,
            'on' => isset($c['on']) ? $c['on'] : null,
            'bri' => isset($c['bri']) ? $c['bri'] : null,
            'live' => isset($c['live']) ? $c['live'] : null,
            'counts' => [
                'effects' => isset($c['effects']) ? count($c['effects']) : 0,
                'palettes' => isset($c['palettes']) ? count($c['palettes']) : 0,
                'presets' => isset($c['presets']) ? count($c['presets']) : 0,
                'playlists' => isset($c['playlists']) ? count($c['playlists']) : 0,
                'segments' => isset($c['segments']) ? count($c['segments']) : 0,
            ],
        ]);
    }
    return [
        'status' => 'OK',
        'now' => time(),
        'autoImport' => (bool) $data['autoImport'],
        'allDevices' => \FppWledEffects\ALL_DEVICES,
        'commands' => array_flip(\FppWledEffects\COMMANDS),
        'devices' => $rows,
    ];
}

// GET devices[?sync=1] - sync=1 first imports WLED devices FPP knows about
// (when auto-import is on) and refreshes any device never read before.
function fppWledEffects_getDevices()
{
    return fppWledEffects_wrap(function () {
        $added = [];
        if (isset($_GET['sync']) && $_GET['sync'] == '1') {
            $data = \FppWledEffects\Devices::load();
            if ($data['autoImport']) {
                $added = \FppWledEffects\Discovery::autoImport();
            }
            $cache = \FppWledEffects\Cache::all();
            $stale = array_values(array_filter($data['devices'], function ($d) use ($cache) {
                return !isset($cache[$d['id']]);
            }));
            if (!empty($stale)) {
                \FppWledEffects\Wled::refresh($stale);
            }
        }
        $rows = fppWledEffects_deviceRows();
        $rows['imported'] = array_map(function ($d) {
            return $d['name'];
        }, $added);
        return json($rows);
    });
}

// POST devices {host, name?} - add a device after checking it answers as WLED.
function fppWledEffects_addDevice()
{
    return fppWledEffects_wrap(function () {
        $body = fppWledEffects_body();
        $host = \FppWledEffects\Util::normalizeHost(isset($body['host']) ? $body['host'] : '');
        if ($host === null) {
            throw new \InvalidArgumentException('Enter an IP address or hostname');
        }
        $force = !empty($body['force']);
        $found = \FppWledEffects\Wled::probe([$host], 1500, 4000);
        if (!isset($found[$host]) && !$force) {
            return fppWledEffects_error(422, 'No WLED controller answered at ' . $host . '.');
        }
        $info = isset($found[$host]) ? $found[$host] : null;
        $device = \FppWledEffects\Devices::add($host, isset($body['name']) ? $body['name'] : '', 'manual', $info);
        \FppWledEffects\Wled::refresh([$device]);
        $rows = fppWledEffects_deviceRows();
        $rows['added'] = $device;
        return json($rows);
    });
}

// POST devices/:id {name?, host?, enabled?}
function fppWledEffects_editDevice()
{
    return fppWledEffects_wrap(function () {
        $body = fppWledEffects_body();
        $changes = array_intersect_key($body, array_flip(['name', 'host', 'enabled']));
        \FppWledEffects\Devices::edit(params('id'), $changes);
        return json(fppWledEffects_deviceRows());
    });
}

// DELETE devices/:id
function fppWledEffects_removeDevice()
{
    return fppWledEffects_wrap(function () {
        \FppWledEffects\Devices::remove(params('id'));
        return json(fppWledEffects_deviceRows());
    });
}

// GET devices/:id/details - everything cached for one device.
function fppWledEffects_deviceDetails()
{
    return fppWledEffects_wrap(function () {
        $d = \FppWledEffects\Devices::find(params('id'));
        if (!$d) {
            return fppWledEffects_error(404, 'Unknown device');
        }
        $c = \FppWledEffects\Cache::get($d['id']);
        return json(['status' => 'OK', 'device' => $d, 'cache' => $c]);
    });
}

// POST refresh {id?} - re-read one device, or every enabled device.
function fppWledEffects_refresh()
{
    return fppWledEffects_wrap(function () {
        $body = fppWledEffects_body();
        if (!empty($body['id'])) {
            $d = \FppWledEffects\Devices::find($body['id']);
            if (!$d) {
                return fppWledEffects_error(404, 'Unknown device');
            }
            $devices = [$d];
        } else {
            $devices = \FppWledEffects\Devices::enabled();
        }
        \FppWledEffects\Wled::refresh($devices);
        return json(fppWledEffects_deviceRows());
    });
}

// GET discover - WLED controllers FPP knows about (MultiSync + outputs).
function fppWledEffects_discover()
{
    return fppWledEffects_wrap(function () {
        return json(['status' => 'OK', 'candidates' => \FppWledEffects\Discovery::find(true)]);
    });
}

// POST settings {autoImport}
function fppWledEffects_saveSettings()
{
    return fppWledEffects_wrap(function () {
        $body = fppWledEffects_body();
        if (array_key_exists('autoImport', $body)) {
            \FppWledEffects\Devices::setAutoImport(\FppWledEffects\Util::parseBool($body['autoImport'], true));
        }
        return json(fppWledEffects_deviceRows());
    });
}

// POST run {command, args[]} - run a plugin command now, with the same
// positional arguments FPP passes, and report per-device results.
function fppWledEffects_run()
{
    return fppWledEffects_wrap(function () {
        $body = fppWledEffects_body();
        $command = isset($body['command']) ? (string) $body['command'] : '';
        if (!isset(\FppWledEffects\COMMANDS[$command])) {
            throw new \InvalidArgumentException('Unknown command "' . $command . '"');
        }
        $args = isset($body['args']) && is_array($body['args']) ? array_map('strval', $body['args']) : [];
        $result = \FppWledEffects\Actions::run(\FppWledEffects\COMMANDS[$command], $args);
        $result['status'] = $result['ok'] ? 'OK' : 'ERROR';
        return json($result);
    });
}

function fppWledEffects_listDevices()
{
    return fppWledEffects_wrap(function () {
        return json(\FppWledEffects\Lists::devices());
    });
}

function fppWledEffects_listPresets()
{
    return fppWledEffects_wrap(function () {
        return json(\FppWledEffects\Lists::named('presets'));
    });
}

function fppWledEffects_listPlaylists()
{
    return fppWledEffects_wrap(function () {
        return json(\FppWledEffects\Lists::named('playlists'));
    });
}

function fppWledEffects_listEffects()
{
    return fppWledEffects_wrap(function () {
        return json(\FppWledEffects\Lists::effects());
    });
}

function fppWledEffects_listPalettes()
{
    return fppWledEffects_wrap(function () {
        return json(\FppWledEffects\Lists::palettes());
    });
}
