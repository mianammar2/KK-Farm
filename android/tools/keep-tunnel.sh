#!/usr/bin/env bash
# Keeps the C72's localhost:8000 pointed at this laptop's KK Farm server while testing over wireless adb.
# Wireless debugging reconnects from time to time and Android drops `adb reverse` tunnels when it does,
# which makes the app on the gun show "connection refused". This loop puts the tunnel back.
# Usage: android/tools/keep-tunnel.sh            (Ctrl-C to stop)
PORT="${PORT:-8000}"
while true; do
  for serial in $(adb devices | awk 'NR>1 && $2=="device" {print $1}'); do
    if ! adb -s "$serial" reverse --list 2>/dev/null | grep -q "tcp:$PORT tcp:$PORT"; then
      adb -s "$serial" reverse "tcp:$PORT" "tcp:$PORT" >/dev/null 2>&1 && echo "$(date '+%H:%M:%S') tunnel restored on $serial"
    fi
  done
  sleep 3
done
