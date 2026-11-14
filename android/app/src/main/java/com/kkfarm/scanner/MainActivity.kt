package com.kkfarm.scanner

import android.annotation.SuppressLint
import android.app.Activity
import android.app.AlertDialog
import android.content.Context
import android.content.Intent
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
import org.json.JSONObject

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
    private lateinit var progress: android.widget.ProgressBar
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
        // Guns set up during the laptop spike still point at localhost: move them to the farm server.
        // D-150: guns saved on the old sslip.io address move to the farm's own domain.
        if (prefs.getString(KEY_SERVER, null)?.trimEnd('/') in listOf(OLD_LAPTOP_SERVER, OLD_SSLIP_SERVER)) {
            prefs.edit().remove(KEY_SERVER).apply()
        }
        WebView.setWebContentsDebuggingEnabled(true) // lets Chrome DevTools inspect the page during the spike

        web = WebView(this).apply {
            layoutParams = ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT)
            settings.javaScriptEnabled = true
            settings.domStorageEnabled = true
            // D-125: an app screen: no pinch zoom, no zoom buttons, the system font size does not blow up the layout.
            settings.setSupportZoom(false)
            settings.builtInZoomControls = false
            settings.displayZoomControls = false
            settings.textZoom = 100
            settings.userAgentString = settings.userAgentString + " KKFarmScanner/" + BuildConfigVersion.NAME
            addJavascriptInterface(Bridge(), "KKNative")
            // A WebView ignores <input type="file"> unless the app handles it: this opens the C72 camera
            // ("Open camera", capture attribute) or a chooser with the gallery and the camera.
            webChromeClient = object : WebChromeClient() {
                override fun onProgressChanged(view: WebView, newProgress: Int) {
                    val bar = this@MainActivity.progress
                    bar.progress = newProgress
                    bar.visibility = if (newProgress >= 100) android.view.View.GONE else android.view.View.VISIBLE
                }

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
                        showSettings("Cannot reach KK Farms at $server (${error.description}). Check the Wi-Fi or mobile data, then tap Retry.")
                    }
                }
            }
        }
        // A thin loading bar along the top, like a native app, instead of a blank screen while pages load.
        progress = android.widget.ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal).apply {
            max = 100
            progressTintList = android.content.res.ColorStateList.valueOf(0xFFC9A23F.toInt()) // KK Farms gold
            layoutParams = android.widget.FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, (3 * resources.displayMetrics.density).toInt())
        }
        setContentView(android.widget.FrameLayout(this).apply { addView(web); addView(progress) })

        if (savedInstanceState != null) {
            web.restoreState(savedInstanceState)
        } else {
            web.loadUrl(intent?.dataString ?: "$server/apps") // D-169: open on the Apps panel; a scan still opens /scan
        }
        // The scanner service listens to the reader for the whole life of the gun (D-049) and opens this
        // screen with each scan, even after the app was swiped away.
        ScannerService.start(this)
        handleLaunch(intent)
        Updater.check(this, server) // D-090: at most every 6 hours
    }

    /** A scan or a trigger press that (re)opened KK Farm. */
    private fun handleLaunch(intent: Intent?) {
        intent ?: return
        val codes = intent.getStringArrayExtra(EXTRA_CODES)?.toList().orEmpty()
        intent.removeExtra(EXTRA_CODES)
        when {
            codes.size == 1 -> onScan(codes[0], "reader")
            codes.size > 1 -> chooseTag(codes)
            intent.getBooleanExtra(EXTRA_OPEN_SCAN, false) -> {
                intent.removeExtra(EXTRA_OPEN_SCAN)
                // Keep a half-filled goat register; otherwise show the Scan screen, ready for the read.
                if (web.url?.contains("/goats/register") != true && web.url?.contains("/scan") != true) web.loadUrl("$server/scan")
            }
        }
    }

    override fun onResume() {
        super.onResume()
        isVisible = true
        visible = true
        handler.post(readerWatchdog)
        pendingChoice?.let { pendingChoice = null; chooseTag(it) }
        Updater.check(this, server)
        pendingUrl?.let { url ->
            pendingUrl = null
            if (System.currentTimeMillis() - pendingAt < PENDING_SCAN_MS) web.loadUrl(url)
        }
    }

    override fun onPause() {
        isVisible = false
        visible = false
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
        setIntent(intent)
        intent.dataString?.let { web.loadUrl(it) }
        handleLaunch(intent)
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

    private fun vibrate(ms: Long) {
        runCatching {
            val vibrator = getSystemService(android.os.Vibrator::class.java)
            vibrator?.vibrate(android.os.VibrationEffect.createOneShot(ms, android.os.VibrationEffect.DEFAULT_AMPLITUDE))
        }
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
        vibrate(40)
        val url = "$server/scan?tag=" + URLEncoder.encode(code, "UTF-8")
        if (isVisible) {
            // D-293: a form with a goat field on screen takes the scan itself (window.kkScan); otherwise the Scan page opens
            runOnUiThread {
                web.evaluateJavascript("(function(){try{return window.kkScan&&window.kkScan(" + JSONObject.quote(code) + ")?'1':'0'}catch(e){return '0'}})()") { r ->
                    if (r != "\"1\"") openScan(url, code)
                }
            }
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
        val triggerSwitch = android.widget.CheckBox(this).apply {
            text = "Open KK Farms when the trigger is pressed"
            isChecked = prefs.getBoolean(KEY_OPEN_ON_TRIGGER, true)
        }
        val autoUpdate = android.widget.CheckBox(this).apply {
            text = "Update the app automatically"
            isChecked = Updater.isAuto(this@MainActivity)
        }
        val updateButton = android.widget.Button(this).apply {
            text = "Check for updates (now ${BuildConfigVersion.NAME})"
            setOnClickListener { Updater.check(this@MainActivity, server, manual = true) }
        }
        val overlayNote = TextView(this).apply {
            text = "Needs \"Display over other apps\" for KK Farms. Tap here to allow it."
            setTextColor(0xFFB3261E.toInt())
            setPadding(0, pad / 3, 0, 0)
            visibility = if (android.provider.Settings.canDrawOverlays(this@MainActivity)) android.view.View.GONE else android.view.View.VISIBLE
            setOnClickListener { startActivity(Intent(android.provider.Settings.ACTION_MANAGE_OVERLAY_PERMISSION, Uri.parse("package:$packageName"))) }
        }
        val layout = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(pad, pad / 2, pad, 0)
            if (problem != null) addView(TextView(context).apply { text = problem; setPadding(0, 0, 0, pad / 2) })
            addView(TextView(context).apply { text = "KK Farms address" })
            addView(serverField)
            addView(TextView(context).apply { text = "InfoWedge broadcast action" })
            addView(actionField)
            addView(TextView(context).apply { text = "Broadcast data key" })
            addView(extraField)
            addView(triggerSwitch)
            addView(overlayNote)
            addView(autoUpdate)
            addView(updateButton)
        }
        AlertDialog.Builder(this)
            .setTitle("KK Farms settings")
            .setView(layout)
            .setPositiveButton("Save") { _, _ ->
                prefs.edit()
                    .putString(KEY_SERVER, serverField.text.toString().trim().ifEmpty { DEFAULT_SERVER })
                    .putString(KEY_ACTION, actionField.text.toString().trim().ifEmpty { DEFAULT_ACTION })
                    .putString(KEY_EXTRA, extraField.text.toString().trim().ifEmpty { DEFAULT_EXTRA })
                    .putBoolean(KEY_OPEN_ON_TRIGGER, triggerSwitch.isChecked)
                    .apply()
                Updater.setAuto(this, autoUpdate.isChecked)
                ScannerService.restart(this)
                web.loadUrl("$server/apps")
            }
            .setNeutralButton("Retry") { _, _ -> web.reload() }
            .setNegativeButton("Close", null)
            .show()
    }

    /** Lets the web page ask whether it runs inside the scanner app, and simulate a read for testing. */
    inner class Bridge {
        @JavascriptInterface fun isScannerApp(): Boolean = true
        @JavascriptInterface fun vibrate(ms: Int) = this@MainActivity.vibrate(ms.coerceIn(10, 400).toLong())
        @JavascriptInterface fun version(): String = BuildConfigVersion.NAME
        @JavascriptInterface fun simulateScan(code: String) = onScan(code, "simulated")
        @JavascriptInterface fun openSettings() = runOnUiThread { showSettings(null) }
        // D-090: app updates, shown on the web Account page inside the app.
        @JavascriptInterface fun versionCode(): Int = BuildConfigVersion.CODE
        @JavascriptInterface fun isAutoUpdate(): Boolean = Updater.isAuto(this@MainActivity)
        @JavascriptInterface fun setAutoUpdate(on: Boolean) = Updater.setAuto(this@MainActivity, on)
        @JavascriptInterface fun checkForUpdate() = runOnUiThread { Updater.check(this@MainActivity, server, manual = true) }
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
        // The farm server (D-045). For laptop testing set http://localhost:8000 in settings and use adb reverse.
        const val DEFAULT_SERVER = "https://fms.kkfarms.com.pk"
        private const val OLD_SSLIP_SERVER = "https://103-163-48-203.sslip.io"
        private const val OLD_LAPTOP_SERVER = "http://localhost:8000"
        const val DEFAULT_ACTION = "com.kkfarm.SCAN"
        const val DEFAULT_EXTRA = "data"
        const val KEY_OPEN_ON_TRIGGER = "open_on_trigger"
        const val EXTRA_CODES = "kkfarm.codes"
        const val EXTRA_OPEN_SCAN = "kkfarm.open_scan"
        /** True while the KK Farm screen is in front; the scanner service only opens it when it is not. */
        @Volatile var visible = false
    }
}

/** Kept separate so the web user agent and the bridge report the same version. */
object BuildConfigVersion {
    const val NAME = "0.6.5"
    const val CODE = 17 // must match versionCode in build.gradle.kts; the updater compares it with the server's
}
