import SwiftUI

/// D-090: KK Farms for iPhone. The farm screens come from the KK Farms server, so they are always the newest
/// version without an App Store update; this app is the full-screen shell around them.
@main
struct KKFarmsApp: App {
    var body: some Scene {
        WindowGroup {
            FarmView()
                .ignoresSafeArea(edges: .bottom)
                .background(Color(red: 0.086, green: 0.239, blue: 0.133)) // KK Farms green behind the status bar
        }
    }
}
