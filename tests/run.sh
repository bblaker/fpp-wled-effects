#!/bin/bash
# Run the plugin tests against mock WLED controllers and a mock FPP API.
#
#   tests/run.sh            # needs php (with curl) on PATH
#   tests/run.sh --docker   # runs inside php:8.2-cli instead

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ "${1:-}" = "--docker" ]; then
    exec docker run --rm -v "${ROOT}:/plugin" -w /plugin php:8.2-cli bash tests/run.sh
fi

WORK="$(mktemp -d)"
export MEDIADIR="${WORK}/media"
export MOCK_DIR="${WORK}/mock"
mkdir -p "${MEDIADIR}/logs" "${MEDIADIR}/plugindata" "${MOCK_DIR}"

PIDS=()
cleanup() {
    for p in "${PIDS[@]}"; do kill "$p" 2>/dev/null; done
    rm -rf "${WORK}"
}
trap cleanup EXIT

serve() { # port router [env...]
    local port=$1 router=$2
    shift 2
    env "$@" php -S "127.0.0.1:${port}" "${ROOT}/tests/${router}" >/dev/null 2>&1 &
    PIDS+=($!)
}

serve 9001 mock_wled.php MOCK_NAME=Porch
serve 9002 mock_wled.php MOCK_NAME=Garage MOCK_PRESETS=alt
serve 9003 mock_wled.php MOCK_NAME=Tree
serve 9080 mock_fpp.php MOCK_SYSTEMS=127.0.0.1:9002 MOCK_OUTPUTS=127.0.0.1:9003,127.0.0.1:9009,127.0.0.1:9001x

for port in 9001 9002 9003 9080; do
    for _ in $(seq 50); do
        php -r "exit(@fsockopen('127.0.0.1', $port) ? 0 : 1);" && break
        sleep 0.1
    done
done

for f in "${ROOT}"/*.php "${ROOT}"/lib/*.php "${ROOT}"/menu.inc; do
    php -l "$f" >/dev/null || { echo "Syntax error in $f"; exit 1; }
done

php "${ROOT}/tests/test_plugin.php"
