#!/bin/bash
# End-to-end check of the plugin inside a real FPP, using FPP's Docker image.
#
#   tests/fpp_integration.sh [fpp-image-tag ...]     (default: latest)
#   tests/fpp_integration.sh 8.5.1 9.5.3 10.2
#
# For each tag: boots falconchristmas/fpp:<tag> with a copy of this plugin
# installed, starts mock WLED controllers inside the container, then checks
# that fppd registers the commands, the command dropdown endpoints answer,
# every command reaches the mock controllers through fppd, an FPP playlist
# built from the commands plays, and the plugin pages are served.
#
# Needs docker, curl and python3. FPP_TEST_PORT picks the host port (8091).

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${FPP_TEST_PORT:-8091}"
PLUGIN=fpp-wled-effects
API="http://localhost:${PORT}/api"
TOTAL_FAILED=0

if [ $# -eq 0 ]; then
    set -- latest
fi

pass() { echo "  ok   $1"; }
fail() {
    echo "  FAIL $1"
    [ -n "${2:-}" ] && echo "       $2"
    FAILED=$((FAILED + 1))
}
check() { # check <label> <command...>
    local label=$1
    shift
    local out
    if out=$("$@" 2>&1); then pass "$label"; else fail "$label" "$out"; fi
}

# Python one-liner over JSON read from stdin; exits non-zero when the
# expression is false.
jcheck() { python3 -c "import json,sys; d=json.load(sys.stdin); sys.exit(0 if ($1) else 1)"; }

post() { curl -s -m 20 -X POST -H 'Content-Type: application/json' -d "$2" "${API}$1"; }

in_fpp() { docker exec "$NAME" bash -c "$1"; }

# Mock WLED payloads a port received since the last call, one JSON per line.
posts() { in_fpp "cat /tmp/mock/posts-$1.jsonl 2>/dev/null; rm -f /tmp/mock/posts-$1.jsonl"; }

run_one() {
    local tag=$1
    NAME="fpp-wled-it-$(echo "$tag" | tr -c 'A-Za-z0-9.\n' '-')"
    FAILED=0
    echo
    echo "=== FPP image falconchristmas/fpp:${tag}"

    local media
    media="$(mktemp -d)"
    mkdir -p "${media}/plugins/${PLUGIN}"
    # A copy, not a bind mount of the repo: FPP chowns its media directory
    # at startup.
    (cd "$ROOT" && git ls-files -z | xargs -0 tar cf -) | tar xf - -C "${media}/plugins/${PLUGIN}"

    docker rm -f "$NAME" >/dev/null 2>&1
    if ! docker run -d --name "$NAME" -p "${PORT}:80" -v "${media}:/home/fpp/media" \
        "falconchristmas/fpp:${tag}" >/dev/null; then
        fail "container starts"
        TOTAL_FAILED=$((TOTAL_FAILED + FAILED))
        return
    fi

    local up=0
    for _ in $(seq 120); do
        if curl -s -m 3 "${API}/fppd/status" | jcheck "d.get('fppd') == 'running' or 'status_name' in d" 2>/dev/null; then
            up=1
            break
        fi
        sleep 2
    done
    if [ $up -eq 0 ]; then
        fail "fppd comes up" "$(docker logs --tail 20 "$NAME" 2>&1)"
        cleanup_one "$media"
        return
    fi
    pass "fppd is running ($(curl -s "${API}/fppd/version" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("version","?"))' 2>/dev/null))"

    # Mock WLED controllers inside the container.
    local P=/home/fpp/media/plugins/${PLUGIN}/tests/mock_wled.php
    in_fpp "mkdir -p /tmp/mock
        MOCK_NAME=Porch MOCK_DIR=/tmp/mock setsid nohup php -S 127.0.0.1:9001 $P >/dev/null 2>&1 < /dev/null &
        MOCK_NAME=Garage MOCK_PRESETS=alt MOCK_DIR=/tmp/mock setsid nohup php -S 127.0.0.1:9002 $P >/dev/null 2>&1 < /dev/null &
        for p in 9001 9002; do for i in \$(seq 50); do curl -s -m 1 127.0.0.1:\$p/json/info >/dev/null && break; sleep 0.1; done; done"

    check "fppd registered the WLED commands" bash -c "curl -s '${API}/commands' | python3 -c \"
import json,sys
names = {c['name'] for c in json.load(sys.stdin)}
want = {'WLED - Apply Preset','WLED - Start Playlist','WLED - Run Effect','WLED - Power','WLED - Brightness','WLED - Live Override'}
missing = want - names
print('missing:', sorted(missing)); sys.exit(1 if missing else 0)\""

    for host in 127.0.0.1:9001 127.0.0.1:9002; do
        check "add device ${host}" bash -c "curl -s -m 20 -X POST -H 'Content-Type: application/json' -d '{\"host\":\"${host}\"}' '${API}/plugin/${PLUGIN}/devices' | python3 -c \"import json,sys; d=json.load(sys.stdin); print(d); sys.exit(0 if 'added' in d else 1)\""
    done

    check "list/devices" bash -c "curl -s '${API}/plugin/${PLUGIN}/list/devices' | grep -q '\"Garage\"'"
    check "list/presets" bash -c "curl -s '${API}/plugin/${PLUGIN}/list/presets' | grep -q 'Candy Cane'"
    check "list/playlists" bash -c "curl -s '${API}/plugin/${PLUGIN}/list/playlists' | grep -q 'Show Loop'"
    check "list/effects" bash -c "curl -s '${API}/plugin/${PLUGIN}/list/effects' | grep -q 'Fireworks'"

    posts 9001 >/dev/null
    posts 9002 >/dev/null
    post /command '{"command":"WLED - Apply Preset","args":["All WLED Devices","Candy Cane","false"]}' >/dev/null
    check "Apply Preset reaches Porch by name" test "$(posts 9001)" = '{"on":true,"ps":1}'
    check "Apply Preset reaches Garage with its own id" test "$(posts 9002)" = '{"on":true,"ps":4}'

    post /command '{"command":"WLED - Run Effect","args":["Porch","Fireworks","Party","#ff0000","#00ff00","#0000ff","200","100","150","1","true"]}' >/dev/null
    check "Run Effect payload" test "$(posts 9001)" = '{"on":true,"bri":150,"seg":{"id":1,"on":true,"fx":8,"sx":200,"ix":100,"pal":2,"col":[[255,0,0],[0,255,0],[0,0,255]]},"lor":1}'

    post /command '{"command":"WLED - Start Playlist","args":["Porch","Show Loop","false"]}' >/dev/null
    post /command '{"command":"WLED - Power","args":["Porch","Toggle"]}' >/dev/null
    post /command '{"command":"WLED - Brightness","args":["Porch","42"]}' >/dev/null
    post /command '{"command":"WLED - Live Override","args":["Porch","Until Reboot"]}' >/dev/null
    check "Start Playlist / Power / Brightness / Live Override" test "$(posts 9001 | tr '\n' ' ')" = '{"on":true,"ps":3} {"on":"t"} {"on":true,"bri":42} {"lor":2} '

    post /playlists '{"name":"WLED IT","version":3,"repeat":0,"loopCount":0,"empty":false,"desc":"","random":0,"leadIn":[],"mainPlaylist":[{"type":"command","enabled":1,"playOnce":0,"command":"WLED - Apply Preset","args":["Porch","Warm White","false"]},{"type":"pause","enabled":1,"playOnce":0,"duration":1},{"type":"command","enabled":1,"playOnce":0,"command":"WLED - Power","args":["Garage","Off"]}],"leadOut":[],"playlistInfo":{"total_duration":1,"total_items":3}}' >/dev/null
    post /command '{"command":"Start Playlist","args":["WLED IT","false","false"]}' >/dev/null
    local got1="" got2=""
    for _ in $(seq 20); do
        sleep 0.5
        got1="${got1}$(posts 9001)"
        got2="${got2}$(posts 9002)"
        [ -n "$got2" ] && break
    done
    check "FPP playlist runs the commands in order" test "${got1}|${got2}" = '{"on":true,"ps":2}|{"on":false}'

    check "plugin page is served" bash -c "curl -s 'http://localhost:${PORT}/plugin.php?plugin=${PLUGIN}&page=wled.php&nopage=1' | grep -q 'id=\"wledApp\"'"
    check "help page is served" bash -c "curl -s 'http://localhost:${PORT}/plugin.php?plugin=${PLUGIN}&page=help.php&nopage=1' | grep -q 'WLED - Apply Preset'"
    check "page script is served" bash -c "curl -s 'http://localhost:${PORT}/plugin.php?plugin=${PLUGIN}&file=js/fpp-wled-effects.js&nopage=1' | grep -q 'fpp-wled-effects page script'"
    check "plugin files stay owned by the FPP user" bash -c "docker exec '$NAME' bash -c 'stat -c %U /home/fpp/media/plugindata/${PLUGIN}/*.json /home/fpp/media/logs/plugin-${PLUGIN}.log' | sort -u | grep -qx fpp"

    cleanup_one "$media"
}

cleanup_one() {
    # Files written in the container belong to its users; remove them there.
    docker exec "$NAME" bash -c 'rm -rf /home/fpp/media/* /home/fpp/media/.[!.]*' >/dev/null 2>&1
    docker rm -f "$NAME" >/dev/null 2>&1
    rm -rf "$1" 2>/dev/null
    echo "  -- ${FAILED} failed"
    TOTAL_FAILED=$((TOTAL_FAILED + FAILED))
}

for tag in "$@"; do
    run_one "$tag"
done

echo
if [ "$TOTAL_FAILED" -eq 0 ]; then
    echo "All FPP integration checks passed"
else
    echo "${TOTAL_FAILED} FPP integration check(s) failed"
fi
[ "$TOTAL_FAILED" -eq 0 ]
