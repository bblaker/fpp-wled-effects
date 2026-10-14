#!/bin/bash

# fpp-wled-effects install script. Safe to run more than once.

. ${FPPDIR}/scripts/common

# fppd reads commands/descriptions.json when it starts, so ask for a restart
# to make the "WLED - ..." commands available in playlists and the scheduler.
setSetting restartFlag 1
