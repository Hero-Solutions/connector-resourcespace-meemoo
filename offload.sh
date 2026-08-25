#!/bin/bash

logfile="/opt/connector-resourcespace-meemoo/output/offloadlog.txt"
lockfile="/tmp/connector-resourcespace-meemoo.lock"
show_progress=false

if [ "$#" -gt 1 ] || { [ "$#" -eq 1 ] && [ "$1" != "--progress" ]; }; then
    echo "Usage: $0 [--progress]" >&2
    exit 2
fi
if [ "${1:-}" = "--progress" ]; then
    show_progress=true
fi

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
if [ "$show_progress" = true ]; then
    echo "$(date -u '+%Y-%m-%d %H:%M:%S UTC') - Starting app:process-offloaded-resources." | tee -a "$logfile"
    process_started=$SECONDS
    php /opt/connector-resourcespace-meemoo/bin/console app:process-offloaded-resources -v 2>&1 | tee -a "$logfile"
    process_status=${PIPESTATUS[0]}
    echo "$(date -u '+%Y-%m-%d %H:%M:%S UTC') - Finished app:process-offloaded-resources in $((SECONDS - process_started)) seconds (status $process_status)." | tee -a "$logfile"

    echo "$(date -u '+%Y-%m-%d %H:%M:%S UTC') - Starting app:offload-resources." | tee -a "$logfile"
    offload_started=$SECONDS
    php /opt/connector-resourcespace-meemoo/bin/console app:offload-resources -v --progress 2>&1 | tee -a "$logfile"
    offload_status=${PIPESTATUS[0]}
    echo "$(date -u '+%Y-%m-%d %H:%M:%S UTC') - Finished app:offload-resources in $((SECONDS - offload_started)) seconds (status $offload_status)." | tee -a "$logfile"
else
    php /opt/connector-resourcespace-meemoo/bin/console app:process-offloaded-resources -v >> "$logfile" 2>&1
    process_status=$?
    php /opt/connector-resourcespace-meemoo/bin/console app:offload-resources -v >> "$logfile" 2>&1
    offload_status=$?
fi
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
