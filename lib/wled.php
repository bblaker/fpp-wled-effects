<?php
/*
 * fpp-wled-effects core library.
 *
 * Shared by the FPP command scripts (lib/cli.php), the plugin's REST API
 * (api.php) and the plugin page (wled.php). Everything that talks to a WLED
 * controller or reads/writes the plugin's data lives here so the three entry
 * points behave identically.
 *
 * Nothing in this file produces output when it is included.
 */

namespace FppWledEffects;

const PLUGIN = 'fpp-wled-effects';

// Value of the "Device" command argument that targets every enabled device.
const ALL_DEVICES = 'All WLED Devices';

// FPP Command name => internal action. Must match commands/descriptions.json.
const COMMANDS = [
    'WLED - Apply Preset' => 'preset',
    'WLED - Start Playlist' => 'playlist',
    'WLED - Run Effect' => 'effect',
    'WLED - Power' => 'power',
    'WLED - Brightness' => 'brightness',
    'WLED - Live Override' => 'override',
];

// MultiSync system type FPP assigns to WLED controllers (kSysTypeWLED).
const FPP_SYSTYPE_WLED = 0xFB;

// Timeouts (milliseconds). Commands run synchronously inside fppd's playlist
// handling, so they must fail fast when a controller is offline.
const CMD_CONNECT_MS = 1000;
const CMD_TIMEOUT_MS = 3000;
const FETCH_CONNECT_MS = 1500;
const FETCH_TIMEOUT_MS = 6000;

const LIVE_OVERRIDE_MODES = [
    'Off' => 0,
    'Until Live Data Ends' => 1,
    'Until Reboot' => 2,
];

class Env
{
    public static function mediaDir()
    {
        global $settings;
        if (isset($settings['mediaDirectory']) && $settings['mediaDirectory'] !== '') {
            return rtrim($settings['mediaDirectory'], '/');
        }
        $env = getenv('MEDIADIR');
        if ($env !== false && $env !== '') {
            return rtrim($env, '/');
        }
        return '/home/fpp/media';
    }

    public static function logDir()
    {
        global $settings;
        if (isset($settings['logDirectory']) && $settings['logDirectory'] !== '') {
            return rtrim($settings['logDirectory'], '/');
        }
        $env = getenv('LOGDIR');
        if ($env !== false && $env !== '') {
            return rtrim($env, '/');
        }
        return self::mediaDir() . '/logs';
    }

    public static function dataDir()
    {
        return self::mediaDir() . '/plugindata/' . PLUGIN;
    }

    // Base URL of the local FPP web server, used to read FPP's own API.
    public static function fppApiBase()
    {
        $port = 80;
        if (isset($_SERVER['SERVER_PORT']) && is_numeric($_SERVER['SERVER_PORT'])) {
            $port = (int) $_SERVER['SERVER_PORT'];
        }
        return 'http://127.0.0.1' . ($port == 80 ? '' : ':' . $port) . '/api';
    }
}

class Log
{
    public static function write($level, $msg)
    {
        $file = Env::logDir() . '/plugin-' . PLUGIN . '.log';
        $isNew = !file_exists($file);
        $line = date('Y-m-d H:i:s') . ' [' . $level . '] ' . $msg . "\n";
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== false && $isNew) {
            // fppd runs commands as root while the web UI runs as the FPP user;
            // keep the log owned like its directory so both can append.
            Store::matchOwner($file);
        }
    }

    public static function info($msg)
    {
        self::write('INFO', $msg);
    }

    public static function error($msg)
    {
        self::write('ERROR', $msg);
    }
}

/*
 * JSON files under <mediadir>/plugindata/fpp-wled-effects/:
 *   devices.json - configured devices and plugin options (user data)
 *   cache.json   - what was last read from each device (effects, presets...)
 */
class Store
{
    public static function path($name)
    {
        return Env::dataDir() . '/' . $name;
    }

    public static function ensureDir()
    {
        $dir = Env::dataDir();
        if (!is_dir($dir)) {
            $parent = dirname($dir);
            $parentIsNew = !is_dir($parent);
            @mkdir($dir, 0755, true);
            if ($parentIsNew) {
                self::matchOwner($parent, Env::mediaDir());
            }
            self::matchOwner($dir, Env::mediaDir());
        }
        return is_dir($dir);
    }

    // Give a file the owner/group of a reference directory (default: the
    // file's own directory). A no-op unless running as root.
    public static function matchOwner($file, $refDir = null)
    {
        if ($refDir === null) {
            $refDir = dirname($file);
        }
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            return;
        }
        $owner = @fileowner($refDir);
        $group = @filegroup($refDir);
        if ($owner !== false) {
            @chown($file, $owner);
        }
        if ($group !== false) {
            @chgrp($file, $group);
        }
    }

    public static function read($name, $default)
    {
        $file = self::path($name);
        if (!file_exists($file)) {
            return $default;
        }
        $fp = @fopen($file, 'r');
        if (!$fp) {
            return $default;
        }
        flock($fp, LOCK_SH);
        $data = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $json = json_decode($data, true);
        return is_array($json) ? $json : $default;
    }

    // Read-modify-write under an exclusive lock. $fn receives the current
    // value and returns the new one.
    public static function update($name, $default, callable $fn)
    {
        if (!self::ensureDir()) {
            throw new \RuntimeException('Cannot create data directory ' . Env::dataDir());
        }
        $file = self::path($name);
        $isNew = !file_exists($file);
        $fp = @fopen($file, 'c+');
        if (!$fp) {
            throw new \RuntimeException('Cannot open ' . $file);
        }
        try {
            flock($fp, LOCK_EX);
            $data = stream_get_contents($fp);
            $current = json_decode($data, true);
            if (!is_array($current)) {
                $current = $default;
            }
            $next = $fn($current);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
        if ($isNew) {
            self::matchOwner($file);
        }
        return $next;
    }
}

class Http
{
    /*
     * Run requests concurrently. $requests is [key => [
     *   'url' => ..., 'method' => 'GET'|'POST', 'body' => array|null,
     *   'connectMs' => int, 'timeoutMs' => int ]].
     * Returns [key => ['ok' => bool, 'status' => int, 'json' => mixed,
     *   'body' => string, 'error' => string|null]].
     */
    public static function multi(array $requests)
    {
        $results = [];
        if (empty($requests)) {
            return $results;
        }
        $mh = curl_multi_init();
        $handles = [];
        foreach ($requests as $key => $req) {
            $ch = curl_init($req['url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, isset($req['connectMs']) ? $req['connectMs'] : FETCH_CONNECT_MS);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, isset($req['timeoutMs']) ? $req['timeoutMs'] : FETCH_TIMEOUT_MS);
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
            $headers = ['Accept: application/json'];
            $method = isset($req['method']) ? $req['method'] : 'GET';
            if ($method === 'POST') {
                $body = isset($req['body']) ? $req['body'] : [];
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
                $headers[] = 'Content-Type: application/json';
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running && curl_multi_select($mh, 0.1) === -1) {
                usleep(10000);
            }
        } while ($running && $status == CURLM_OK);

        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            $json = null;
            if (is_string($body) && $body !== '') {
                $json = json_decode($body, true);
            }
            $ok = ($err === '' && $code >= 200 && $code < 300);
            $results[$key] = [
                'ok' => $ok,
                'status' => $code,
                'json' => $json,
                'body' => is_string($body) ? $body : '',
                'error' => $ok ? null : ($err !== '' ? $err : 'HTTP ' . $code),
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $results;
    }

    public static function get($url, $connectMs = FETCH_CONNECT_MS, $timeoutMs = FETCH_TIMEOUT_MS)
    {
        $r = self::multi(['r' => ['url' => $url, 'connectMs' => $connectMs, 'timeoutMs' => $timeoutMs]]);
        return $r['r'];
    }
}

class Util
{
    // Accepts an IPv4 address, hostname or bracketed IPv6 address with an
    // optional :port. Returns the normalized host or null.
    public static function normalizeHost($host)
    {
        $host = trim((string) $host);
        $host = preg_replace('#^https?://#i', '', $host);
        $host = rtrim($host, '/');
        if ($host === '') {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return '[' . $host . ']';
        }
        if (preg_match('/^\[[0-9A-Fa-f:.]+\](:\d{1,5})?$/', $host)) {
            return $host;
        }
        if (preg_match('/^[A-Za-z0-9]([A-Za-z0-9.\-]{0,252})(:\d{1,5})?$/', $host)) {
            return strtolower($host);
        }
        return null;
    }

    public static function baseUrl($host)
    {
        return 'http://' . $host;
    }

    public static function slug($s)
    {
        $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', (string) $s));
        return trim($s, '-');
    }

    // "#ff8800" / "ff8800" / "#f80" -> [255, 136, 0]; null if invalid.
    public static function parseColor($s)
    {
        $s = ltrim(trim((string) $s), '#');
        if (preg_match('/^[0-9A-Fa-f]{3}$/', $s)) {
            $s = $s[0] . $s[0] . $s[1] . $s[1] . $s[2] . $s[2];
        }
        if (!preg_match('/^[0-9A-Fa-f]{6}$/', $s)) {
            return null;
        }
        return [hexdec(substr($s, 0, 2)), hexdec(substr($s, 2, 2)), hexdec(substr($s, 4, 2))];
    }

    public static function clampInt($v, $min, $max, $default)
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return $default;
        }
        return max($min, min($max, (int) round((float) $v)));
    }

    public static function parseBool($v, $default = false)
    {
        if ($v === null || $v === '') {
            return $default;
        }
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
    }

    // Find $name in a list of [id => name] entries: exact match first, then
    // case/whitespace-insensitive, then a literal "#id" or bare number.
    public static function findByName(array $idToName, $name)
    {
        $name = (string) $name;
        foreach ($idToName as $id => $n) {
            if ($n === $name) {
                return (int) $id;
            }
        }
        $norm = strtolower(trim($name));
        foreach ($idToName as $id => $n) {
            if (strtolower(trim((string) $n)) === $norm) {
                return (int) $id;
            }
        }
        if (preg_match('/^#?(\d+)$/', trim($name), $m) && array_key_exists((int) $m[1], $idToName)) {
            return (int) $m[1];
        }
        return null;
    }
}

class Devices
{
    const FILE = 'devices.json';

    public static function defaults()
    {
        return ['version' => 1, 'autoImport' => true, 'devices' => [], 'ignored' => []];
    }

    public static function load()
    {
        $data = Store::read(self::FILE, self::defaults());
        return array_merge(self::defaults(), $data);
    }

    public static function all()
    {
        return self::load()['devices'];
    }

    public static function enabled()
    {
        return array_values(array_filter(self::all(), function ($d) {
            return !isset($d['enabled']) || $d['enabled'];
        }));
    }

    public static function update(callable $fn)
    {
        return Store::update(self::FILE, self::defaults(), function ($data) use ($fn) {
            return $fn(array_merge(self::defaults(), $data));
        });
    }

    public static function find($key)
    {
        $key = trim((string) $key);
        $lower = strtolower($key);
        $host = Util::normalizeHost($key);
        foreach (self::all() as $d) {
            if ($d['id'] === $key || strtolower($d['name']) === $lower || ($host !== null && $d['host'] === $host)) {
                return $d;
            }
        }
        return null;
    }

    // Resolve the "Device" command argument to a list of devices.
    public static function resolveTargets($deviceArg)
    {
        $deviceArg = trim((string) $deviceArg);
        if ($deviceArg === '' || strcasecmp($deviceArg, ALL_DEVICES) === 0) {
            return self::enabled();
        }
        $d = self::find($deviceArg);
        return $d ? [$d] : [];
    }

    private static function uniqueName(array $devices, $name, $exceptId = null)
    {
        $base = trim($name) !== '' ? trim($name) : 'WLED';
        // Reserved: would be ambiguous with the all-devices target.
        if (strcasecmp($base, ALL_DEVICES) === 0) {
            $base = 'WLED Device';
        }
        $candidate = $base;
        $n = 2;
        while (true) {
            $clash = false;
            foreach ($devices as $d) {
                if ($d['id'] !== $exceptId && strcasecmp($d['name'], $candidate) === 0) {
                    $clash = true;
                    break;
                }
            }
            if (!$clash) {
                return $candidate;
            }
            $candidate = $base . ' ' . $n++;
        }
    }

    public static function idForMac($mac)
    {
        $mac = strtolower(preg_replace('/[^0-9A-Fa-f]/', '', (string) $mac));
        return $mac !== '' ? 'mac-' . $mac : null;
    }

    /*
     * Add a device. $info is the controller's /json/info if already known.
     * Returns the stored device. Throws if the host is invalid or a duplicate.
     * A controller that is already configured under another address (same
     * MAC, new DHCP lease) keeps its entry and just gets the new address.
     */
    public static function add($host, $name = '', $source = 'manual', $info = null)
    {
        $host = Util::normalizeHost($host);
        if ($host === null) {
            throw new \InvalidArgumentException('Invalid address');
        }
        $added = null;
        $moved = null;
        self::update(function ($data) use ($host, $name, $source, $info, &$added, &$moved) {
            foreach ($data['devices'] as $d) {
                if ($d['host'] === $host) {
                    throw new \InvalidArgumentException('A device with address ' . $host . ' is already configured');
                }
            }
            $macId = self::idForMac(is_array($info) && isset($info['mac']) ? $info['mac'] : '');
            if ($macId !== null) {
                foreach ($data['devices'] as &$d) {
                    if ($d['id'] === $macId) {
                        $moved = ['from' => $d['host']];
                        $d['host'] = $host;
                        $added = $d;
                        break;
                    }
                }
                unset($d);
                if ($added !== null) {
                    return $data;
                }
            }
            if (trim((string) $name) === '' && is_array($info) && !empty($info['name'])) {
                $name = $info['name'];
            }
            if (trim((string) $name) === '') {
                $name = $host;
            }
            $id = $macId !== null ? $macId : 'host-' . Util::slug($host);
            foreach ($data['devices'] as $d) {
                if ($d['id'] === $id) {
                    $id .= '-' . substr(md5($host . microtime()), 0, 6);
                }
            }
            $added = [
                'id' => $id,
                'name' => self::uniqueName($data['devices'], $name),
                'host' => $host,
                'enabled' => true,
                'source' => $source,
                'added' => time(),
            ];
            $data['devices'][] = $added;
            $data['ignored'] = array_values(array_diff($data['ignored'], [$host]));
            return $data;
        });
        if ($moved !== null) {
            Log::info('Device ' . $added['name'] . ' moved from ' . $moved['from'] . ' to ' . $added['host']);
        } else {
            Log::info('Added device ' . $added['name'] . ' (' . $added['host'] . ')');
        }
        return $added;
    }

    public static function edit($id, array $changes)
    {
        $result = null;
        self::update(function ($data) use ($id, $changes, &$result) {
            foreach ($data['devices'] as &$d) {
                if ($d['id'] !== $id) {
                    continue;
                }
                if (isset($changes['name'])) {
                    $d['name'] = self::uniqueName($data['devices'], $changes['name'], $id);
                }
                if (isset($changes['host'])) {
                    $host = Util::normalizeHost($changes['host']);
                    if ($host === null) {
                        throw new \InvalidArgumentException('Invalid address');
                    }
                    $d['host'] = $host;
                }
                if (isset($changes['enabled'])) {
                    $d['enabled'] = Util::parseBool($changes['enabled'], true);
                }
                $result = $d;
            }
            unset($d);
            if ($result === null) {
                throw new \InvalidArgumentException('Unknown device');
            }
            return $data;
        });
        return $result;
    }

    public static function remove($id)
    {
        $removed = null;
        self::update(function ($data) use ($id, &$removed) {
            $keep = [];
            foreach ($data['devices'] as $d) {
                if ($d['id'] === $id) {
                    $removed = $d;
                } else {
                    $keep[] = $d;
                }
            }
            if ($removed === null) {
                throw new \InvalidArgumentException('Unknown device');
            }
            $data['devices'] = $keep;
            // Don't let auto-import bring back a device the user removed.
            if (!in_array($removed['host'], $data['ignored'], true)) {
                $data['ignored'][] = $removed['host'];
            }
            return $data;
        });
        Cache::forget($id);
        Log::info('Removed device ' . $removed['name'] . ' (' . $removed['host'] . ')');
        return $removed;
    }

    public static function setAutoImport($on)
    {
        return self::update(function ($data) use ($on) {
            $data['autoImport'] = (bool) $on;
            if ($on) {
                $data['ignored'] = [];
            }
            return $data;
        });
    }
}

/*
 * What was last read from each controller, keyed by device id:
 *   updated, online, error, info{name,ver,arch,leds,mac,brand},
 *   effects{id:name}, palettes{id:name}, presets{id:name},
 *   playlists{id:name}, segments[ids]
 */
class Cache
{
    const FILE = 'cache.json';

    public static function all()
    {
        return Store::read(self::FILE, []);
    }

    public static function get($id)
    {
        $all = self::all();
        return isset($all[$id]) ? $all[$id] : null;
    }

    public static function put(array $entries)
    {
        Store::update(self::FILE, [], function ($data) use ($entries) {
            foreach ($entries as $id => $entry) {
                if ($entry === null) {
                    unset($data[$id]);
                } elseif (isset($data[$id]) && empty($entry['online'])) {
                    // Keep the last good lists when a device is offline, so
                    // command dropdowns still work while it is unplugged.
                    $data[$id]['online'] = false;
                    $data[$id]['error'] = $entry['error'];
                    $data[$id]['checked'] = $entry['checked'];
                } else {
                    $data[$id] = $entry;
                }
            }
            return $data;
        });
    }

    public static function forget($id)
    {
        self::put([$id => null]);
    }
}

class Wled
{
    // Names WLED uses for unused effect slots.
    private static function isReservedEffect($name)
    {
        return $name === '' || $name === '-' || $name === 'RSVD' || stripos($name, 'reserved') === 0;
    }

    private static function mapNames($list, $skipReserved)
    {
        $out = [];
        if (!is_array($list)) {
            return $out;
        }
        foreach ($list as $id => $name) {
            if (!is_string($name)) {
                continue;
            }
            if ($skipReserved && self::isReservedEffect($name)) {
                continue;
            }
            $out[(int) $id] = $name;
        }
        return $out;
    }

    // Split presets.json into presets and playlists ({id: name}).
    public static function parsePresets($json)
    {
        $presets = [];
        $playlists = [];
        if (!is_array($json)) {
            return [$presets, $playlists];
        }
        foreach ($json as $id => $p) {
            $id = (int) $id;
            if ($id <= 0 || !is_array($p) || empty($p)) {
                continue;
            }
            $name = isset($p['n']) && trim((string) $p['n']) !== '' ? (string) $p['n'] : 'Preset ' . $id;
            if (isset($p['playlist']) && is_array($p['playlist'])) {
                $playlists[$id] = $name;
            } else {
                $presets[$id] = $name;
            }
        }
        ksort($presets);
        ksort($playlists);
        return [$presets, $playlists];
    }

    public static function summarizeInfo($info)
    {
        if (!is_array($info)) {
            return null;
        }
        return [
            'name' => isset($info['name']) ? (string) $info['name'] : '',
            'ver' => isset($info['ver']) ? (string) $info['ver'] : '',
            'arch' => isset($info['arch']) ? (string) $info['arch'] : '',
            'brand' => isset($info['brand']) ? (string) $info['brand'] : '',
            'mac' => isset($info['mac']) ? (string) $info['mac'] : '',
            'leds' => isset($info['leds']['count']) ? (int) $info['leds']['count'] : 0,
        ];
    }

    public static function isWledInfo($info)
    {
        return is_array($info) && isset($info['brand']) && stripos((string) $info['brand'], 'WLED') !== false;
    }

    /*
     * Read everything the plugin needs from each device and store it in the
     * cache. One request at a time per device (ESP boards handle few
     * concurrent connections), all devices in parallel.
     * Returns [id => cache entry].
     */
    public static function refresh(array $devices, $connectMs = FETCH_CONNECT_MS, $timeoutMs = FETCH_TIMEOUT_MS)
    {
        $entries = [];
        if (empty($devices)) {
            return $entries;
        }
        $byId = [];
        $reqs = [];
        foreach ($devices as $d) {
            $byId[$d['id']] = $d;
            $reqs[$d['id']] = ['url' => Util::baseUrl($d['host']) . '/json', 'connectMs' => $connectMs, 'timeoutMs' => $timeoutMs];
        }
        $full = Http::multi($reqs);

        $reqs = [];
        foreach ($full as $id => $r) {
            if ($r['ok'] && is_array($r['json']) && isset($r['json']['info'])) {
                $reqs[$id] = ['url' => Util::baseUrl($byId[$id]['host']) . '/presets.json', 'connectMs' => $connectMs, 'timeoutMs' => $timeoutMs];
            }
        }
        $presetResults = Http::multi($reqs);

        $now = time();
        foreach ($full as $id => $r) {
            $d = $byId[$id];
            if (!$r['ok'] || !is_array($r['json']) || !isset($r['json']['info'])) {
                $err = $r['ok'] ? 'Not a WLED device (no /json/info)' : $r['error'];
                $entries[$id] = ['online' => false, 'error' => $err, 'checked' => $now];
                Log::error('Refresh of ' . $d['name'] . ' (' . $d['host'] . ') failed: ' . $err);
                continue;
            }
            $j = $r['json'];
            $presets = [];
            $playlists = [];
            $presetError = null;
            if (isset($presetResults[$id])) {
                $pr = $presetResults[$id];
                if ($pr['ok'] && is_array($pr['json'])) {
                    list($presets, $playlists) = self::parsePresets($pr['json']);
                } elseif ($pr['status'] != 404) {
                    $presetError = 'Could not read presets: ' . $pr['error'];
                }
            }
            $segments = [];
            if (isset($j['state']['seg']) && is_array($j['state']['seg'])) {
                foreach ($j['state']['seg'] as $i => $seg) {
                    $segments[] = isset($seg['id']) ? (int) $seg['id'] : (int) $i;
                }
            }
            $entries[$id] = [
                'online' => true,
                'error' => $presetError,
                'checked' => $now,
                'updated' => $now,
                'info' => self::summarizeInfo($j['info']),
                'effects' => self::mapNames(isset($j['effects']) ? $j['effects'] : [], true),
                'palettes' => self::mapNames(isset($j['palettes']) ? $j['palettes'] : [], false),
                'presets' => $presets,
                'playlists' => $playlists,
                'segments' => $segments,
                'on' => isset($j['state']['on']) ? (bool) $j['state']['on'] : null,
                'bri' => isset($j['state']['bri']) ? (int) $j['state']['bri'] : null,
                'live' => isset($j['info']['live']) ? (bool) $j['info']['live'] : null,
            ];
        }
        Cache::put($entries);
        return $entries;
    }

    // Probe hosts for WLED. Returns [host => summarized info] for the ones
    // that answer as WLED.
    public static function probe(array $hosts, $connectMs = 800, $timeoutMs = 2000)
    {
        $reqs = [];
        foreach (array_unique($hosts) as $h) {
            $reqs[$h] = ['url' => Util::baseUrl($h) . '/json/info', 'connectMs' => $connectMs, 'timeoutMs' => $timeoutMs];
        }
        $found = [];
        foreach (Http::multi($reqs) as $h => $r) {
            if ($r['ok'] && self::isWledInfo($r['json'])) {
                $found[$h] = self::summarizeInfo($r['json']);
            }
        }
        return $found;
    }

    // POST a state object to each device. $payloads is [id => state].
    public static function sendState(array $devicesById, array $payloads)
    {
        $reqs = [];
        foreach ($payloads as $id => $payload) {
            $reqs[$id] = [
                'url' => Util::baseUrl($devicesById[$id]['host']) . '/json/state',
                'method' => 'POST',
                'body' => $payload,
                'connectMs' => CMD_CONNECT_MS,
                'timeoutMs' => CMD_TIMEOUT_MS,
            ];
        }
        return Http::multi($reqs);
    }
}

class Discovery
{
    private static function fppGet($path)
    {
        $r = Http::get(Env::fppApiBase() . $path, 1000, 4000);
        return $r['ok'] ? $r['json'] : null;
    }

    // WLED controllers FPP's MultiSync has already identified.
    public static function fromMultiSync()
    {
        $out = [];
        $json = self::fppGet('/fppd/multiSyncSystems');
        if (!is_array($json) || !isset($json['systems']) || !is_array($json['systems'])) {
            return $out;
        }
        foreach ($json['systems'] as $s) {
            $isWled = (isset($s['typeId']) && (int) $s['typeId'] === FPP_SYSTYPE_WLED)
                || (isset($s['type']) && stripos((string) $s['type'], 'WLED') !== false);
            if (!$isWled || empty($s['address'])) {
                continue;
            }
            $host = Util::normalizeHost($s['address']);
            if ($host !== null) {
                $out[$host] = isset($s['hostname']) ? (string) $s['hostname'] : '';
            }
        }
        return $out;
    }

    // Unicast destinations of FPP's E1.31/ArtNet/DDP outputs - candidates
    // that still need probing to see which of them run WLED.
    public static function fromChannelOutputs()
    {
        $out = [];
        $json = self::fppGet('/channel/output/universeOutputs');
        if (!is_array($json) || !isset($json['channelOutputs']) || !is_array($json['channelOutputs'])) {
            return $out;
        }
        foreach ($json['channelOutputs'] as $co) {
            if (!isset($co['universes']) || !is_array($co['universes'])) {
                continue;
            }
            foreach ($co['universes'] as $u) {
                if (empty($u['address'])) {
                    continue;
                }
                $addr = trim((string) $u['address']);
                if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $first = (int) explode('.', $addr)[0];
                    if ($first >= 224) {
                        continue; // multicast/broadcast
                    }
                }
                $host = Util::normalizeHost($addr);
                if ($host !== null) {
                    $out[$host] = isset($u['description']) ? (string) $u['description'] : '';
                }
            }
        }
        return $out;
    }

    const MISS_FILE = 'discovery-misses.json';
    const MISS_TTL = 600;

    /*
     * WLED controllers known to FPP. Returns a list of
     * [host, name, source, info, configured, ignored, existing].
     * Addresses that didn't answer as WLED are not probed again for
     * MISS_TTL seconds unless $force is set, so page loads don't keep
     * poking every non-WLED controller in the channel outputs.
     */
    public static function find($force = false)
    {
        $data = Devices::load();
        $configured = [];
        $byId = [];
        foreach ($data['devices'] as $d) {
            $configured[$d['host']] = true;
            $byId[$d['id']] = $d['name'];
        }

        $sources = [];
        foreach (self::fromMultiSync() as $host => $label) {
            $sources[$host] = ['source' => 'FPP MultiSync', 'label' => $label];
        }
        foreach (self::fromChannelOutputs() as $host => $label) {
            if (!isset($sources[$host])) {
                $sources[$host] = ['source' => 'FPP Channel Outputs', 'label' => $label];
            }
        }

        // Only probe hosts that aren't configured yet.
        $now = time();
        $misses = $force ? [] : Store::read(self::MISS_FILE, []);
        $toProbe = array_values(array_filter(array_keys($sources), function ($h) use ($configured, $misses, $now) {
            return !isset($configured[$h]) && !(isset($misses[$h]) && $now - $misses[$h] < self::MISS_TTL);
        }));
        $infos = Wled::probe($toProbe);
        $newMisses = array_diff($toProbe, array_keys($infos));
        if (!empty($newMisses) || $force) {
            Store::update(self::MISS_FILE, [], function ($m) use ($newMisses, $infos, $now, $force) {
                if ($force) {
                    $m = [];
                }
                foreach ($m as $h => $t) {
                    if ($now - $t >= self::MISS_TTL || isset($infos[$h])) {
                        unset($m[$h]);
                    }
                }
                foreach ($newMisses as $h) {
                    $m[$h] = $now;
                }
                return $m;
            });
        }

        $out = [];
        foreach ($sources as $host => $s) {
            $isConfigured = isset($configured[$host]);
            if (!$isConfigured && !isset($infos[$host])) {
                continue; // not reachable, or not a WLED controller
            }
            $info = isset($infos[$host]) ? $infos[$host] : null;
            $name = $info && $info['name'] !== '' ? $info['name'] : ($s['label'] !== '' ? $s['label'] : $host);
            $macId = $info ? Devices::idForMac($info['mac']) : null;
            $out[] = [
                'host' => $host,
                'name' => $name,
                'source' => $s['source'],
                'info' => $info,
                'configured' => $isConfigured,
                'ignored' => in_array($host, $data['ignored'], true),
                // Already configured under a different address.
                'existing' => (!$isConfigured && $macId !== null && isset($byId[$macId])) ? $byId[$macId] : null,
            ];
        }
        return $out;
    }

    // Add every discovered, not-yet-configured, not-ignored device (and
    // follow configured ones to a new address).
    public static function autoImport()
    {
        $added = [];
        foreach (self::find() as $c) {
            if ($c['configured'] || $c['ignored'] || !$c['info']) {
                continue;
            }
            try {
                $added[] = Devices::add($c['host'], $c['info']['name'], 'fpp', $c['info']);
            } catch (\InvalidArgumentException $e) {
                // Raced with another add; fine.
            }
        }
        if (!empty($added)) {
            Wled::refresh($added);
        }
        return $added;
    }
}

/*
 * The actions behind the FPP commands. Each takes the command's positional
 * argument list (strings, exactly as FPP passes them) and returns
 *   ['ok' => bool, 'message' => string, 'results' => [[device, ok, message]]]
 */
class Actions
{
    public static function run($action, array $args)
    {
        switch ($action) {
            case 'preset':
                return self::preset($args, 'presets');
            case 'playlist':
                return self::preset($args, 'playlists');
            case 'effect':
                return self::effect($args);
            case 'power':
                return self::power($args);
            case 'brightness':
                return self::brightness($args);
            case 'override':
                return self::override($args);
        }
        return ['ok' => false, 'message' => 'Unknown action ' . $action, 'results' => []];
    }

    private static function arg(array $args, $i, $default = '')
    {
        return isset($args[$i]) && $args[$i] !== null ? (string) $args[$i] : $default;
    }

    private static function targets($deviceArg)
    {
        $targets = Devices::resolveTargets($deviceArg);
        if (empty($targets)) {
            throw new \InvalidArgumentException(
                trim($deviceArg) === '' || strcasecmp(trim($deviceArg), ALL_DEVICES) === 0
                    ? 'No WLED devices are configured'
                    : 'Unknown WLED device "' . $deviceArg . '"'
            );
        }
        $byId = [];
        foreach ($targets as $d) {
            $byId[$d['id']] = $d;
        }
        return $byId;
    }

    /*
     * Build a payload per device. $build($device, $cacheEntry) returns the
     * state object, or a string error when something (an effect or preset
     * name) can't be resolved from the cache. Those devices are refreshed
     * once and retried before giving up. A device whose build fails after
     * that is reported but doesn't stop the others.
     */
    private static function dispatch($label, array $targets, callable $build)
    {
        $cache = Cache::all();
        $payloads = [];
        $errors = [];
        $retry = [];
        foreach ($targets as $id => $d) {
            $p = $build($d, isset($cache[$id]) ? $cache[$id] : null);
            if (is_array($p)) {
                $payloads[$id] = $p;
            } else {
                $retry[$id] = $d;
                $errors[$id] = $p;
            }
        }
        if (!empty($retry)) {
            $fresh = Wled::refresh(array_values($retry), CMD_CONNECT_MS, CMD_TIMEOUT_MS);
            foreach ($retry as $id => $d) {
                if (isset($fresh[$id]) && !empty($fresh[$id]['online'])) {
                    $p = $build($d, $fresh[$id]);
                    if (is_array($p)) {
                        $payloads[$id] = $p;
                        unset($errors[$id]);
                    } else {
                        $errors[$id] = $p;
                    }
                } else {
                    $errors[$id] = 'offline (' . (isset($fresh[$id]['error']) ? $fresh[$id]['error'] : 'no response') . ')';
                }
            }
        }

        $responses = Wled::sendState($targets, $payloads);
        $results = [];
        $okCount = 0;
        foreach ($targets as $id => $d) {
            if (isset($errors[$id])) {
                $results[] = ['device' => $d['name'], 'ok' => false, 'message' => $errors[$id]];
                continue;
            }
            $r = $responses[$id];
            $ok = $r['ok'];
            // WLED answers {"success":true} (or the new state) on success and
            // {"error":N} when it rejects the request.
            if ($ok && is_array($r['json']) && isset($r['json']['error'])) {
                $ok = false;
                $r['error'] = 'WLED error ' . $r['json']['error'];
            }
            if ($ok) {
                $okCount++;
            }
            $results[] = ['device' => $d['name'], 'ok' => $ok, 'message' => $ok ? 'OK' : $r['error']];
        }

        $parts = [];
        foreach ($results as $r) {
            $parts[] = $r['device'] . ': ' . $r['message'];
        }
        $summary = $label . ' -> ' . implode('; ', $parts);
        if ($okCount === count($results)) {
            Log::info($summary);
        } else {
            Log::error($summary);
        }
        return [
            'ok' => $okCount > 0 && $okCount === count($results),
            'message' => $okCount . ' of ' . count($results) . ' device(s) succeeded',
            'results' => $results,
        ];
    }

    private static function overrideFields($takeover)
    {
        return $takeover ? ['lor' => 1] : [];
    }

    // Args: Device, Preset|Playlist name, Take over from live data
    private static function preset(array $args, $kind)
    {
        $deviceArg = self::arg($args, 0, ALL_DEVICES);
        $name = self::arg($args, 1);
        $takeover = Util::parseBool(self::arg($args, 2), false);
        $noun = $kind === 'playlists' ? 'playlist' : 'preset';
        if (trim($name) === '') {
            throw new \InvalidArgumentException('No ' . $noun . ' selected');
        }
        $targets = self::targets($deviceArg);
        $multi = count($targets) > 1;
        $result = self::dispatch(
            ucfirst($noun) . ' "' . $name . '"',
            $targets,
            function ($d, $c) use ($name, $kind, $noun, $takeover) {
                // Playlists are presets too; accept either list so a name
                // that moved between them still works.
                $lists = $kind === 'playlists' ? ['playlists', 'presets'] : ['presets', 'playlists'];
                foreach ($lists as $list) {
                    if ($c && isset($c[$list]) && is_array($c[$list])) {
                        $id = Util::findByName($c[$list], $name);
                        if ($id !== null) {
                            return array_merge(['on' => true, 'ps' => $id], self::overrideFields($takeover));
                        }
                    }
                }
                return 'no ' . $noun . ' named "' . $name . '"';
            }
        );
        // With "All WLED Devices", devices without that preset are skipped
        // rather than counted as a failure.
        if ($multi) {
            $applied = 0;
            $skipped = 0;
            $failed = 0;
            foreach ($result['results'] as &$r) {
                if ($r['ok']) {
                    $applied++;
                } elseif (strpos($r['message'], 'no ' . $noun . ' named') === 0) {
                    $r['skipped'] = true;
                    $skipped++;
                } else {
                    $failed++;
                }
            }
            unset($r);
            if ($applied > 0 && $failed === 0) {
                $result['ok'] = true;
                $result['message'] = $applied . ' device(s) applied' . ($skipped ? ', ' . $skipped . ' without this ' . $noun . ' skipped' : '');
            }
        }
        return $result;
    }

    // Args: Device, Effect, Palette, Color 1, Color 2, Color 3, Speed,
    //       Intensity, Brightness, Segments, Take over from live data
    private static function effect(array $args)
    {
        $deviceArg = self::arg($args, 0, ALL_DEVICES);
        $effect = self::arg($args, 1);
        $palette = self::arg($args, 2);
        $colors = [];
        foreach ([3, 4, 5] as $i) {
            $c = Util::parseColor(self::arg($args, $i));
            if ($c !== null) {
                $colors[] = $c;
            }
        }
        $speed = Util::clampInt(self::arg($args, 6), 0, 255, 128);
        $intensity = Util::clampInt(self::arg($args, 7), 0, 255, 128);
        $bri = Util::clampInt(self::arg($args, 8), 0, 255, 0);
        $segArg = trim(self::arg($args, 9, 'Selected'));
        $takeover = Util::parseBool(self::arg($args, 10), false);
        if (trim($effect) === '') {
            throw new \InvalidArgumentException('No effect selected');
        }
        $targets = self::targets($deviceArg);

        return self::dispatch(
            'Effect "' . $effect . '"',
            $targets,
            function ($d, $c) use ($effect, $palette, $colors, $speed, $intensity, $bri, $segArg, $takeover) {
                if (!$c || empty($c['effects'])) {
                    return 'effect list not loaded';
                }
                $fx = Util::findByName($c['effects'], $effect);
                if ($fx === null) {
                    return 'no effect named "' . $effect . '"';
                }
                $seg = ['on' => true, 'fx' => $fx, 'sx' => $speed, 'ix' => $intensity];
                if (trim($palette) !== '') {
                    $pal = Util::findByName(isset($c['palettes']) ? $c['palettes'] : [], $palette);
                    if ($pal === null) {
                        return 'no palette named "' . $palette . '"';
                    }
                    $seg['pal'] = $pal;
                }
                if (!empty($colors)) {
                    $seg['col'] = $colors;
                }

                $state = ['on' => true];
                if ($bri > 0) {
                    $state['bri'] = $bri;
                }
                if (strcasecmp($segArg, 'All') === 0) {
                    $ids = !empty($c['segments']) ? $c['segments'] : [0];
                    $state['seg'] = array_map(function ($id) use ($seg) {
                        return array_merge(['id' => $id], $seg);
                    }, $ids);
                } elseif (ctype_digit($segArg)) {
                    $state['seg'] = array_merge(['id' => (int) $segArg], $seg);
                } else {
                    // Object without an id: WLED applies it to every
                    // selected segment.
                    $state['seg'] = $seg;
                }
                return array_merge($state, self::overrideFields($takeover));
            }
        );
    }

    // Args: Device, State (On|Off|Toggle)
    private static function power(array $args)
    {
        $deviceArg = self::arg($args, 0, ALL_DEVICES);
        $state = strtolower(trim(self::arg($args, 1, 'On')));
        if ($state === 'toggle') {
            $on = 't';
        } else {
            $on = Util::parseBool($state, false);
        }
        $targets = self::targets($deviceArg);
        return self::dispatch('Power ' . ucfirst($state), $targets, function () use ($on) {
            return ['on' => $on];
        });
    }

    // Args: Device, Brightness (0-255)
    private static function brightness(array $args)
    {
        $deviceArg = self::arg($args, 0, ALL_DEVICES);
        $bri = Util::clampInt(self::arg($args, 1), 0, 255, 128);
        $targets = self::targets($deviceArg);
        return self::dispatch('Brightness ' . $bri, $targets, function () use ($bri) {
            return $bri > 0 ? ['on' => true, 'bri' => $bri] : ['on' => false];
        });
    }

    // Args: Device, Mode (see LIVE_OVERRIDE_MODES)
    private static function override(array $args)
    {
        $deviceArg = self::arg($args, 0, ALL_DEVICES);
        $modeArg = self::arg($args, 1, 'Off');
        $mode = null;
        foreach (LIVE_OVERRIDE_MODES as $label => $value) {
            if (strcasecmp($label, trim($modeArg)) === 0 || (string) $value === trim($modeArg)) {
                $mode = $value;
            }
        }
        if ($mode === null) {
            throw new \InvalidArgumentException('Unknown live override mode "' . $modeArg . '"');
        }
        $targets = self::targets($deviceArg);
        return self::dispatch('Live override ' . $modeArg, $targets, function () use ($mode) {
            return ['lor' => $mode];
        });
    }
}

/*
 * Option lists for the FPP command argument dropdowns (contentListUrl).
 * An array becomes value=label; an object becomes value => label.
 */
class Lists
{
    public static function devices()
    {
        $out = [ALL_DEVICES];
        foreach (Devices::all() as $d) {
            $out[] = $d['name'];
        }
        return $out;
    }

    // {name: "name (Device A, Device B)"} across all devices.
    public static function named($field)
    {
        $cache = Cache::all();
        $where = [];
        foreach (Devices::all() as $d) {
            if (!isset($cache[$d['id']][$field]) || !is_array($cache[$d['id']][$field])) {
                continue;
            }
            foreach ($cache[$d['id']][$field] as $name) {
                $where[$name][] = $d['name'];
            }
        }
        uksort($where, 'strnatcasecmp');
        $deviceCount = count(Devices::all());
        $out = [];
        foreach ($where as $name => $devices) {
            $devices = array_unique($devices);
            $suffix = ($deviceCount > 1 && count($devices) === $deviceCount) ? 'all devices' : implode(', ', $devices);
            $out[(string) $name] = $deviceCount > 1 ? $name . '  (' . $suffix . ')' : (string) $name;
        }
        return (object) $out;
    }

    // Plain sorted list of names across all devices; $first entries lead.
    public static function names($field, array $first = [])
    {
        $set = [];
        foreach (Cache::all() as $entry) {
            if (isset($entry[$field]) && is_array($entry[$field])) {
                foreach ($entry[$field] as $name) {
                    $set[$name] = true;
                }
            }
        }
        $names = array_keys($set);
        usort($names, 'strnatcasecmp');
        $lead = array_values(array_filter($first, function ($n) use ($set) {
            return isset($set[$n]);
        }));
        return array_values(array_merge($lead, array_diff($names, $lead)));
    }

    public static function effects()
    {
        return self::names('effects', ['Solid']);
    }

    public static function palettes()
    {
        return self::names('palettes', ['Default']);
    }
}
