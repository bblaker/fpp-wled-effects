#!/bin/bash

# fpp-wled-effects uninstall script. Safe to run more than once.

. ${FPPDIR}/scripts/common

# The plugin's data (device list and cached WLED lists) lives here.
rm -rf "${MEDIADIR}/plugindata/fpp-wled-effects"

# Drop the "WLED - ..." commands from fppd's command list.
setSetting restartFlag 1
