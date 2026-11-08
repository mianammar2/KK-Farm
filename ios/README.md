# KK Farms for iPhone (D-090)

Two ways to have KK Farms on an iPhone:

1. **Home-screen app (works today, no Mac needed).** Open the KK Farms address in Safari, sign in, tap Share →
   Add to Home Screen. It opens full screen with its own icon and loads the newest version every time.
2. **Native app (this folder).** A small SwiftUI shell (WKWebView) around the same screens, so the screens still update
   by themselves; only the shell needs an install.

## Build the native app

Needs a Mac with **Xcode** (App Store) and an **Apple ID**.

```bash
brew install xcodegen
cd ios && xcodegen          # creates KKFarms.xcodeproj
open KKFarms.xcodeproj
```

In Xcode: KKFarms target → Signing & Capabilities → choose your Team. Connect the iPhone, turn on Developer Mode
(Settings → Privacy & Security), pick the iPhone as the run destination and press Run.

- With a free Apple ID the app must be re-installed from Xcode every 7 days.
- With the Apple Developer Program (USD 99/year), upload to **TestFlight**: the iPhone then installs new versions
  automatically (TestFlight → Automatic Updates). That is the iOS equivalent of the Android app's auto-update.

The server address is in `KKFarms/FarmView.swift` (`FarmView.server`).
