#!/bin/bash

logfile="/opt/connector-resourcespace-meemoo/output/offloadlog.txt"
lockfile="/tmp/connector-resourcespace-meemoo.lock"

if ! command -v flock >/dev/null 2>&1; then
    echo "$(date -u): required command 'flock' is unavailable; connector was not started." >> "$logfile"
    exit 1
fi

# Keep this descriptor open for the complete process-and-offload sequence. The
# kernel releases the lock automatically when this script exits or crashes.
exec 9>"$lockfile"
if ! flock -n 9; then
    echo "$(date -u): connector is already running; this run was skipped." >> "$logfile"
    exit 1
fi

cd /opt/connector-resourcespace-meemoo/
date -u >> "$logfile"
php /opt/connector-resourcespace-meemoo/bin/console app:process-offloaded-resources -v >> "$logfile" 2>&1
process_status=$?
php /opt/connector-resourcespace-meemoo/bin/console app:offload-resources -v >> "$logfile" 2>&1
offload_status=$?
date -u >> "$logfile"

# Report a non-zero exit code to cron so failures are noticed, without hiding it behind 'date'
if [ "$process_status" -ne 0 ]; then
    echo "app:process-offloaded-resources exited with status $process_status" >> "$logfile"
    exit "$process_status"
fi
if [ "$offload_status" -ne 0 ]; then
    echo "app:offload-resources exited with status $offload_status" >> "$logfile"
fi
exit "$offload_status"
