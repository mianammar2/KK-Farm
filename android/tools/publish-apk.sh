#!/bin/sh
# D-090: copy the built APK to the web app's downloads and write kkfarm.json, which the app's updater reads.
# Usage: tools/publish-apk.sh "What changed"   (run from android/ after ./gradlew assembleDebug)
set -e
APK=app/build/outputs/apk/debug/app-debug.apk
OUT=../apps/kk-farm/public/downloads
CODE=$(grep -E '^\s*versionCode\s*=' app/build.gradle.kts | grep -oE '[0-9]+')
NAME=$(grep -E '^\s*versionName\s*=' app/build.gradle.kts | grep -oE '"[^"]+"' | tr -d '"')
SHA=$(shasum -a 256 "$APK" | cut -d' ' -f1)
cp "$APK" "$OUT/kkfarm.apk"
printf '{\n  "versionCode": %s,\n  "versionName": "%s",\n  "url": "/downloads/kkfarm.apk",\n  "sha256": "%s",\n  "notes": "%s"\n}\n' "$CODE" "$NAME" "$SHA" "${1:-}" > "$OUT/kkfarm.json"
echo "Published $NAME ($CODE) $SHA"
