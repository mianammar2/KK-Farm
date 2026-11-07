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
import android.provider.MediaStore
import android.webkit.JavascriptInterface
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.TextView
import android.widget.Toast
import androidx.core.content.FileProvider
import java.io.File
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
    private var isVisible = false
    private var fileCallback: ValueCallback<Array<Uri>>? = null
    private var cameraUri: Uri? = null
    private var pendingChoice: List<String>? = null
    private var pendingUrl: String? = null
    private var pendingAt = 0L
    private var lastCode = ""
    private var lastAt = 0L

    private val server: String get() = prefs.getString(KEY_SERVER, DEFAULT_SERVER)!!.trimEnd('/')
    private val action: String get() = prefs.getString(KEY_ACTION, DEFAULT_ACTION)!!
    private val extraKey: String get() = prefs.getString(KEY_EXTRA, DEFAULT_EXTRA)!!

    private val receiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context, intent: Intent) {
            val extras = intent.extras
            Log.i(TAG, "broadcast action=${intent.action} extras=${extras?.keySet()?.associateWith { k -> extras.get(k).let { v -> "${v?.javaClass?.simpleName}:${if (v is ByteArray) String(v) else v}" } }}")
            val codes = extractCodes(intent)
            when {
                codes.isEmpty() -> return
                codes.size == 1 -> onScan(codes[0], "broadcast")
                else -> runOnUiThread { chooseTag(codes) }
            }
        }
    }

    /**
     * InfoWedge sends every tag it saw in one trigger pull, one per line. More than one means neighbouring
     * goats were in range, so the user picks the right one instead of the app guessing.
     */
    private fun chooseTag(codes: List<String>) {
        if (isFinishing) return
        if (!isVisible) {
            pendingChoice = codes // shown when the app is back in front
            startActivity(Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP))
            return
        }
        Log.i(TAG, "multiple tags ${codes.joinToString()}")
        AlertDialog.Builder(this)
            .setTitle("${codes.size} tags read. Which goat?")
            .setItems(codes.toTypedArray()) { _, i -> onScan(codes[i], "broadcast-choice") }
            .setNegativeButton("Cancel", null)
            .show()
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
            // A WebView ignores <input type="file"> unless the app handles it: this opens the C72 camera
            // ("Open camera", capture attribute) or a chooser with the gallery and the camera.
            webChromeClient = object : WebChromeClient() {
                override fun onShowFileChooser(view: WebView, callback: ValueCallback<Array<Uri>>, params: FileChooserParams): Boolean {
                    fileCallback?.onReceiveValue(null)
                    fileCallback = callback
                    return runCatching { openPhotoPicker(params.isCaptureEnabled); true }.getOrElse {
                        Log.w(TAG, "photo picker failed", it)
                        fileCallback = null
                        callback.onReceiveValue(null)
                        false
                    }
                }
            }
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
        // Listen for the whole life of the app, not only while it is on screen: a worker who pressed Home
        // still expects the trigger to work. A scan brings the app back to the front (see onScan).
        registerScanReceiver()
    }

    override fun onResume() {
        super.onResume()
        isVisible = true
        handler.post(readerWatchdog)
        pendingChoice?.let { pendingChoice = null; chooseTag(it) }
        pendingUrl?.let { url ->
            pendingUrl = null
            if (System.currentTimeMillis() - pendingAt < PENDING_SCAN_MS) web.loadUrl(url)
        }
    }

    override fun onPause() {
        isVisible = false
        handler.removeCallbacks(readerWatchdog)
        super.onPause()
    }

    /**
     * InfoWedge is what reads the tags. If it crashes (seen on this C72: a null-profile bug in its settings
     * screen), nothing restarts it and the trigger silently does nothing. While KK Farm is on screen we start
     * its WedgeService every 20 s: a no-op when it runs, a restart when it died.
     */
    private val handler = android.os.Handler(android.os.Looper.getMainLooper())
    private val readerWatchdog = object : Runnable {
        override fun run() {
            runCatching { startService(Intent(INFOWEDGE_SERVICE).setPackage(INFOWEDGE_PACKAGE)) }
                .onSuccess { if (it == null) Log.w(TAG, "InfoWedge service not found") }
                .onFailure { Log.w(TAG, "could not start InfoWedge: ${it.message}") }
            if (isVisible) handler.postDelayed(this, 20_000)
        }
    }

    override fun onDestroy() {
        runCatching { unregisterReceiver(receiver) }
        super.onDestroy()
    }

    /**
     * Opens a scan, but never throws away a half-filled goat register: while that form is open the user is
     * asked first. Scanning another tag by accident must not cost them what they typed.
     */
    private fun openScan(url: String, code: String) {
        val onForm = web.url?.contains("/goats/register") == true
        if (!onForm) {
            web.loadUrl(url)
            return
        }
        AlertDialog.Builder(this)
            .setTitle("Tag $code read")
            .setMessage("You are filling in the goat register. Leave it and open this tag? What you typed will be lost.")
            .setPositiveButton("Open tag") { _, _ -> web.loadUrl(url) }
            .setNegativeButton("Stay on the form", null)
            .show()
    }

    /** A scan that arrived while the app was in the background relaunches it with the scan address. */
    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        intent.dataString?.let { web.loadUrl(it) }
    }

    private fun openPhotoPicker(cameraOnly: Boolean) {
        val dir = File(cacheDir, "photos").apply { mkdirs() }
        val photo = File.createTempFile("goat_", ".jpg", dir)
        val uri = FileProvider.getUriForFile(this, "$packageName.files", photo)
        cameraUri = uri
        val camera = Intent(MediaStore.ACTION_IMAGE_CAPTURE)
            .putExtra(MediaStore.EXTRA_OUTPUT, uri)
            .addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION or Intent.FLAG_GRANT_READ_URI_PERMISSION)
        val intent = if (cameraOnly) {
            camera
        } else {
            val gallery = Intent(Intent.ACTION_GET_CONTENT).addCategory(Intent.CATEGORY_OPENABLE).setType("image/*")
            Intent.createChooser(gallery, "Goat photo").putExtra(Intent.EXTRA_INITIAL_INTENTS, arrayOf(camera))
        }
        @Suppress("DEPRECATION")
        startActivityForResult(intent, REQUEST_PHOTO)
    }

    @Deprecated("Activity result API needs androidx.activity; this app stays framework-only")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        @Suppress("DEPRECATION")
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode != REQUEST_PHOTO) return
        val picked: Uri? = when {
            resultCode != RESULT_OK -> null
            data?.data != null -> data.data // chosen from the gallery
            else -> cameraUri?.takeIf { uri -> contentResolver.openFileDescriptor(uri, "r")?.use { it.statSize > 0 } == true } // taken with the camera
        }
        Log.i(TAG, "photo picked=${picked != null}")
        fileCallback?.onReceiveValue(picked?.let { arrayOf(it) })
        fileCallback = null
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

    /**
     * Distinct tag codes from the broadcast. The configured extra is tried first, then common names, then any
     * text extra. A value may hold several tags separated by line breaks (InfoWedge, one tag per line).
     */
    private fun extractCodes(intent: Intent): List<String> {
        val extras = intent.extras ?: return emptyList()
        val keys = listOf(extraKey) + KNOWN_EXTRAS + extras.keySet()
        for (key in keys.distinct()) {
            val value = when (val v = extras.get(key)) {
                is String -> v
                is ByteArray -> String(v)
                is CharSequence -> v.toString()
                else -> null
            } ?: continue
            val codes = value.split(Regex("[\\s,;]+")).map { it.trim().uppercase() }.filter { TAG_PATTERN.matches(it) && !JUNK_PATTERN.matches(it) }.distinct()
            if (codes.isNotEmpty()) {
                return codes
            }
        }
        return emptyList()
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
        val url = "$server/scan?tag=" + URLEncoder.encode(code, "UTF-8")
        if (isVisible) {
            runOnUiThread { openScan(url, code) }
        } else {
            // Android 10+ refuses to let a background app bring itself to the front, so remember the scan and
            // open it as soon as KK Farm is opened again. (Set KK Farm as the Home app to avoid this entirely.)
            pendingUrl = url
            pendingAt = now
            runCatching { startActivity(Intent(this, MainActivity::class.java).setData(Uri.parse(url)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)) }
        }
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
        private const val PENDING_SCAN_MS = 120_000L
        private const val REQUEST_PHOTO = 41
        private const val INFOWEDGE_PACKAGE = "com.rscja.infowedge"
        private const val INFOWEDGE_SERVICE = "com.rscja.infowedge.SERVICE"
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
        // Empty or failed reads come back as all zeros (seen on the C72) or all F.
        private val JUNK_PATTERN = Regex("^(0+|F+)$")
    }
}

/** Kept separate so the web user agent and the bridge report the same version. */
object BuildConfigVersion {
    const val NAME = "0.2.1"
}
