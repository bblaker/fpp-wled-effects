#!/bin/bash
# Shared runner for this plugin's FPP commands: run-action.sh <action> [args...]
#
# fppd starts command scripts with a bare environment (only MEDIADIR, FPPDIR
# and SCRIPTDIR), so set PATH ourselves. When fppd runs us, stdout is fppd's;
# results are already written to the plugin log, so only keep stderr (PHP
# errors) and send it to that same log.

export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/opt/homebrew/bin:${PATH}"
: "${MEDIADIR:=/home/fpp/media}"
export MEDIADIR

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ ! -t 1 ]; then
    LOGFILE="${LOGDIR:-${MEDIADIR}/logs}/plugin-fpp-wled-effects.log"
    exec >/dev/null 2>>"${LOGFILE}"
fi

exec php -d display_errors=stderr "${DIR}/../lib/cli.php" "$@"
