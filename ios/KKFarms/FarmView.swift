import SwiftUI
import WebKit

/// The KK Farms web app in a WKWebView: sign-in is kept, links to other sites open in Safari, pull down to refresh,
/// and when the app returns to the front after 5 minutes it reloads, which also picks up a new version of the app.
struct FarmView: UIViewRepresentable {
    static let server = URL(string: "https://103-163-48-203.sslip.io/")!

    func makeCoordinator() -> Coordinator { Coordinator() }

    func makeUIView(context: Context) -> WKWebView {
        let config = WKWebViewConfiguration()
        config.websiteDataStore = .default() // keeps the session cookie between launches
        config.allowsInlineMediaPlayback = true
        let web = WKWebView(frame: .zero, configuration: config)
        web.customUserAgent = nil
        web.allowsBackForwardNavigationGestures = true
        web.navigationDelegate = context.coordinator
        web.scrollView.contentInsetAdjustmentBehavior = .never // the web app pads for the notch itself
        let refresh = UIRefreshControl()
        refresh.addTarget(context.coordinator, action: #selector(Coordinator.pulled(_:)), for: .valueChanged)
        web.scrollView.refreshControl = refresh
        context.coordinator.web = web
        web.load(URLRequest(url: Self.server))
        return web
    }

    func updateUIView(_ web: WKWebView, context: Context) {}

    final class Coordinator: NSObject, WKNavigationDelegate {
        weak var web: WKWebView?
        private var hiddenAt: Date?
        private var offline = false

        override init() {
            super.init()
            NotificationCenter.default.addObserver(self, selector: #selector(background), name: UIApplication.didEnterBackgroundNotification, object: nil)
            NotificationCenter.default.addObserver(self, selector: #selector(foreground), name: UIApplication.willEnterForegroundNotification, object: nil)
        }

        @objc func background() { hiddenAt = Date() }

        @objc func foreground() {
            if let at = hiddenAt, Date().timeIntervalSince(at) > 300 || offline { if offline { _ = web?.load(URLRequest(url: FarmView.server)) } else { _ = web?.reload() }; offline = false }
            hiddenAt = nil
        }

        @objc func pulled(_ control: UIRefreshControl) {
            // After the "No connection" page, reloading would only show it again: go back to the farm instead.
            if let web, web.url?.host == FarmView.server.host, !offline { web.reload() } else { offline = false; web?.load(URLRequest(url: FarmView.server)) }
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.8) { control.endRefreshing() }
        }

        /// Farm pages stay in the app; anything else (maps, WhatsApp, other sites) opens outside it.
        func webView(_ webView: WKWebView, decidePolicyFor action: WKNavigationAction, decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
            guard let url = action.request.url else { return decisionHandler(.cancel) }
            if url.host == FarmView.server.host || url.scheme == "about" || url.scheme == "blob" || url.scheme == "data" {
                decisionHandler(.allow)
            } else {
                UIApplication.shared.open(url)
                decisionHandler(.cancel)
            }
        }

        func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: Error) { showOffline(webView) }
        func webView(_ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: Error) { showOffline(webView) }

        private func showOffline(_ webView: WKWebView) {
            if (webView.url?.absoluteString ?? "").isEmpty == false, offline { return }
            offline = true
            webView.loadHTMLString("""
            <html><body style="font-family:-apple-system;background:#f7f6ef;color:#163d22;display:flex;align-items:center;justify-content:center;height:100vh;text-align:center">
            <div><h2>No connection</h2><p>KK Farms needs the internet. Pull down to try again.</p></div></body></html>
            """, baseURL: FarmView.server)
        }
    }
}
