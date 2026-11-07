package com.kkfarm.scanner

import android.annotation.SuppressLint
import android.app.Activity
import android.app.AlertDialog
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.graphics.Bitmap
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.text.InputType
import android.util.Log
import android.view.KeyEvent
import android.view.ViewGroup
import android.webkit.JavascriptInterface
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.TextView
import android.widget.Toast
import java.net.URLEncoder

/**
 * KK Farm on the Chainway C72 (D-039).
 *
 * The app shows the KK Farm web app full screen and turns RFID reads into scans:
 *  - Broadcast mode: Chainway InfoWedge (or the keyboard emulator) sends each read as a broadcast intent.
 *    The app opens /scan?tag=CODE, which shows the goat or starts the goat register.
 *  - Keyboard mode: InfoWedge types the read into the focused field; the web app handles that itself
 *    (it switches on its scan-from-any-screen capture when it sees this app's user agent).
 *
 * Everything the reader sends (intent extras, key codes) is written to logcat under the tag "KKFarm",
 * so the exact InfoWedge output can be confirmed on a real unit: adb logcat -s KKFarm
 *
 * The physical menu key (≡) opens the settings: server address and broadcast action.
 */
class MainActivity : Activity() {

    private lateinit var web: WebView
    private val prefs by lazy { getSharedPreferences("kkfarm", Context.MODE_PRIVATE) }
    private var lastCode = ""
    private var lastAt = 0L

    private val server: String get() = prefs.getString(KEY_SERVER, DEFAULT_SERVER)!!.trimEnd('/')
    private val action: String get() = prefs.getString(KEY_ACTION, DEFAULT_ACTION)!!
    private val extraKey: String get() = prefs.getString(KEY_EXTRA, DEFAULT_EXTRA)!!

    private val receiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context, intent: Intent) {
            val extras = intent.extras
            Log.i(TAG, "broadcast action=${intent.action} extras=${extras?.keySet()?.associateWith { k -> extras.get(k).let { v -> "${v?.javaClass?.simpleName}:${if (v is ByteArray) String(v) else v}" } }}")
            val code = extractCode(intent) ?: return
            onScan(code, "broadcast")
        }
    }

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        WebView.setWebContentsDebuggingEnabled(true) // lets Chrome DevTools inspect the page during the spike

        web = WebView(this).apply {
            layoutParams = ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT)
            settings.javaScriptEnabled = true
            settings.domStorageEnabled = true
            settings.userAgentString = settings.userAgentString + " KKFarmScanner/" + BuildConfigVersion.NAME
            addJavascriptInterface(Bridge(), "KKNative")
            webViewClient = object : WebViewClient() {
                override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                    val target = request.url
                    val home = Uri.parse(server)
                    if (target.host == home.host && target.port == home.port) {
                        return false
                    }
                    startActivity(Intent(Intent.ACTION_VIEW, target)) // other sites open in the browser
                    return true
                }

                override fun onPageStarted(view: WebView, url: String, favicon: Bitmap?) {
                    Log.i(TAG, "page $url")
                }

                override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
                    if (request.isForMainFrame) {
                        Log.w(TAG, "load failed ${request.url}: ${error.description}")
                        showSettings("Cannot reach $server (${error.description}). Check the address and that the laptop is running KK Farm.")
                    }
                }
            }
        }
        setContentView(web)

        if (savedInstanceState != null) {
            web.restoreState(savedInstanceState)
        } else {
            web.loadUrl(intent?.dataString ?: "$server/scan")
        }
    }

    override fun onResume() {
        super.onResume()
        registerScanReceiver()
    }

    override fun onPause() {
        runCatching { unregisterReceiver(receiver) }
        super.onPause()
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        web.saveState(outState)
    }

    private fun registerScanReceiver() {
        runCatching { unregisterReceiver(receiver) }
        val filter = IntentFilter().apply {
            addAction(action)
            KNOWN_ACTIONS.forEach { addAction(it) }
        }
        if (Build.VERSION.SDK_INT >= 33) {
            registerReceiver(receiver, filter, Context.RECEIVER_EXPORTED) // InfoWedge is another app
        } else {
            @Suppress("UnspecifiedRegisterReceiverFlag")
            registerReceiver(receiver, filter)
        }
        Log.i(TAG, "listening for ${filter.actionsIterator().asSequence().toList()}")
    }

    /** Takes the configured extra first, then common names, then any string extra that looks like a tag. */
    private fun extractCode(intent: Intent): String? {
        val extras = intent.extras ?: return null
        val keys = listOf(extraKey) + KNOWN_EXTRAS + extras.keySet()
        for (key in keys.distinct()) {
            val value = when (val v = extras.get(key)) {
                is String -> v
                is ByteArray -> String(v)
                is CharSequence -> v.toString()
                else -> null
            }?.trim()
            if (!value.isNullOrEmpty() && TAG_PATTERN.matches(value)) {
                return value
            }
        }
        return null
    }

    private fun onScan(raw: String, via: String) {
        val code = raw.trim().uppercase()
        val now = System.currentTimeMillis()
        if (code == lastCode && now - lastAt < 1500) {
            return // the reader can report the same tag several times per trigger pull
        }
        lastCode = code
        lastAt = now
        Log.i(TAG, "scan $code via $via")
        runOnUiThread { web.loadUrl("$server/scan?tag=" + URLEncoder.encode(code, "UTF-8")) }
    }

    override fun onKeyDown(keyCode: Int, event: KeyEvent): Boolean {
        Log.i(TAG, "keyDown code=$keyCode ${KeyEvent.keyCodeToString(keyCode)} scan=${event.scanCode}")
        when (keyCode) {
            KeyEvent.KEYCODE_MENU -> { showSettings(null); return true }
            KeyEvent.KEYCODE_BACK -> if (web.canGoBack()) { web.goBack(); return true }
        }
        return super.onKeyDown(keyCode, event)
    }

    private fun showSettings(problem: String?) {
        if (isFinishing) return
        val pad = (16 * resources.displayMetrics.density).toInt()
        val serverField = EditText(this).apply { setText(server); inputType = InputType.TYPE_TEXT_VARIATION_URI; hint = "http://192.168.20.105:8000" }
        val actionField = EditText(this).apply { setText(action); hint = DEFAULT_ACTION }
        val extraField = EditText(this).apply { setText(extraKey); hint = DEFAULT_EXTRA }
        val layout = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(pad, pad / 2, pad, 0)
            if (problem != null) addView(TextView(context).apply { text = problem; setPadding(0, 0, 0, pad / 2) })
            addView(TextView(context).apply { text = "KK Farm address" })
            addView(serverField)
            addView(TextView(context).apply { text = "InfoWedge broadcast action" })
            addView(actionField)
            addView(TextView(context).apply { text = "Broadcast data key" })
            addView(extraField)
        }
        AlertDialog.Builder(this)
            .setTitle("KK Farm settings")
            .setView(layout)
            .setPositiveButton("Save") { _, _ ->
                prefs.edit()
                    .putString(KEY_SERVER, serverField.text.toString().trim().ifEmpty { DEFAULT_SERVER })
                    .putString(KEY_ACTION, actionField.text.toString().trim().ifEmpty { DEFAULT_ACTION })
                    .putString(KEY_EXTRA, extraField.text.toString().trim().ifEmpty { DEFAULT_EXTRA })
                    .apply()
                registerScanReceiver()
                web.loadUrl("$server/scan")
            }
            .setNeutralButton("Retry") { _, _ -> web.reload() }
            .setNegativeButton("Close", null)
            .show()
    }

    /** Lets the web page ask whether it runs inside the scanner app, and simulate a read for testing. */
    inner class Bridge {
        @JavascriptInterface fun isScannerApp(): Boolean = true
        @JavascriptInterface fun version(): String = BuildConfigVersion.NAME
        @JavascriptInterface fun simulateScan(code: String) = onScan(code, "simulated")
        @JavascriptInterface fun openSettings() = runOnUiThread { showSettings(null) }
    }

    companion object {
        const val TAG = "KKFarm"
        private const val KEY_SERVER = "server"
        private const val KEY_ACTION = "action"
        private const val KEY_EXTRA = "extra"
        // With `adb reverse tcp:8000 tcp:8000`, localhost on the gun is the laptop. Change in settings for Wi-Fi use.
        const val DEFAULT_SERVER = "http://localhost:8000"
        const val DEFAULT_ACTION = "com.kkfarm.SCAN"
        const val DEFAULT_EXTRA = "data"
        // Broadcast actions seen on Chainway devices. Unconfirmed for this firmware: logcat shows what really arrives.
        // com.rscja.android.DATA_RESULT is what InfoWedge broadcasts after each read on this C72 (seen in the
        // broadcast history, 2026-11-07); its extras carry the tag data.
        val KNOWN_ACTIONS = listOf("com.rscja.android.DATA_RESULT", "com.scanner.broadcast", "com.rscja.scanner.action.scanner.RFID", "android.rfid.FUN_KEY")
        val KNOWN_EXTRAS = listOf("data", "barcode_string", "barcodeData", "scannerdata", "epc", "EPC", "barcode", "code")
        private val TAG_PATTERN = Regex("^[A-Za-z0-9._:-]{4,64}$")
    }
}

/** Kept separate so the web user agent and the bridge report the same version. */
object BuildConfigVersion {
    const val NAME = "0.1.1"
}
