package com.kkfarm.scanner

import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.os.PowerManager
import android.util.Base64
import android.util.Log
import org.json.JSONArray
import org.json.JSONObject
import java.io.ByteArrayOutputStream

/**
 * D-354 / D-355: the gun's link to Settings → Guns. Started by the scan service, so it runs whenever the gun is on,
 * with KK Farm open, in the background or closed. Every report carries the gun's state; the answer carries the
 * Administrator's commands. Commands that need the app's page or reader go to the open app ([host]); if the app is
 * closed it is opened first.
 */
object GunAgent {

    /** The open KK Farm screen (MainActivity), when there is one. Called on the main thread. */
    interface Host {
        fun hostState(o: JSONObject)
        fun reader(): String
        fun onScreen(): Boolean
        /** Runs a command that needs the app; false when it is not one of those. */
        fun run(id: Long, command: String, done: (ok: Boolean, text: String) -> Unit): Boolean
        /** The app's own page as a picture (used when remote control is off). */
        fun snapshot(): Bitmap?
    }

    @Volatile var host: Host? = null
    private lateinit var app: Context
    private val handler = Handler(Looper.getMainLooper())
    private val results = ArrayList<JSONObject>()
    @Volatile private var sendLog = false
    private var busy = false
    private var started = false
    private var waitingForApp = ArrayList<Pair<Long, String>>()
    private val loop = Runnable { report() }

    fun start(context: Context) {
        app = context.applicationContext
        if (started) return
        started = true
        GunLink.catchCrashes(app)
        handler.postDelayed(loop, 3000)
        Log.i(MainActivity.TAG, "gun agent started (${GunLink.installId(app)})")
    }

    /** Report soon (after a command, or when the app comes to the front). */
    fun soon(ms: Long = 300) {
        if (!started) return
        handler.removeCallbacks(loop)
        handler.postDelayed(loop, ms)
    }

    /** The open app calls this when it is ready: commands that waited for it run now. */
    fun hostReady() {
        val h = host ?: return
        val waiting = waitingForApp.also { waitingForApp = ArrayList() }
        waiting.forEach { (id, command) -> if (!h.run(id, command) { ok, text -> done(id, ok, text) }) done(id, false, "Unknown command $command") }
        soon(1000)
    }

    private fun server(): String = app.getSharedPreferences("kkfarm", Context.MODE_PRIVATE).getString(MainActivity.KEY_SERVER, MainActivity.DEFAULT_SERVER)!!.trimEnd('/')

    private fun state(): JSONObject = JSONObject().apply {
        put("onScreen", host?.onScreen() ?: false)
        put("lastTrigger", GunLink.lastTrigger)
        put("lastHold", GunLink.lastHold)
        put("lastScan", GunLink.lastScan)
        put("service", if (ScannerService.running) "Running" else "Not running")
        put("remote", if (RemoteControl.instance != null) "On" else "Off (Accessibility → KK Farms remote control)")
        put("infowedge", if (runCatching { app.packageManager.getPackageInfo("com.rscja.infowedge", 0) }.isSuccess) "Installed" else "Not installed")
        runCatching { (app.getSystemService(Context.BATTERY_SERVICE) as android.os.BatteryManager).getIntProperty(android.os.BatteryManager.BATTERY_PROPERTY_CAPACITY) }.getOrNull()?.let { put("battery", "$it%") }
        put("screenOn", (app.getSystemService(Context.POWER_SERVICE) as PowerManager).isInteractive)
        put("android", Build.VERSION.RELEASE)
        put("model", Build.MODEL)
        GunLink.lastCrash(app)?.let { put("crash", it) }
        host?.hostState(this) ?: put("page", "KK Farm is closed")
    }

    private fun report() {
        handler.removeCallbacks(loop)
        if (busy) { handler.postDelayed(loop, 2000); return }
        busy = true
        val sent = synchronized(results) { ArrayList(results).also { results.clear() } }
        val withLog = sendLog
        sendLog = false
        val body = JSONObject().put("install_id", GunLink.installId(app)).put("version", BuildConfigVersion.NAME).put("code", BuildConfigVersion.CODE)
            .put("reader", host?.reader() ?: "closed").put("state", state())
            .put("can", JSONObject().put("remote", RemoteControl.instance != null).put("selfUpdate", Build.VERSION.SDK_INT >= 31))
        if (sent.isNotEmpty()) body.put("results", JSONArray(sent))
        val srv = server()
        Thread {
            if (withLog) body.put("logs", GunLink.ownLog())
            val answer = GunLink.report(app, srv, body)
            handler.post {
                busy = false
                if (answer == null) { // not signed in or no network: keep the answers and the log for the next try
                    synchronized(results) { results.addAll(0, sent) }
                    if (withLog) sendLog = true
                }
                GunLink.commands(answer).forEach { (id, command, args) -> runCatching { run(id, command, args) }.onFailure { done(id, false, "Error: ${it.message}") } }
                handler.postDelayed(loop, (answer?.optInt("every", 60) ?: 60).coerceIn(2, 300) * 1000L)
            }
        }.start()
    }

    fun done(id: Long, ok: Boolean, text: String) {
        Log.i(MainActivity.TAG, "server command $id ${if (ok) "done" else "failed"}: $text")
        synchronized(results) { results.add(JSONObject().put("id", id).put("ok", ok).put("text", text.take(3900))) }
        soon()
    }

    private fun run(id: Long, command: String, args: JSONObject) {
        Log.i(MainActivity.TAG, "server command $id: $command $args")
        val rc = RemoteControl.instance
        val noRemote = "Remote control is off on this gun: Settings → Accessibility → KK Farms remote control → On."
        when (command) {
            "send_logs" -> { sendLog = true; done(id, true, "Log sent (${BuildConfigVersion.NAME}).") }
            "screenshot" -> screen(id)
            "tap" -> if (rc == null) done(id, false, noRemote) else rc.tap(args.getDouble("x"), args.getDouble("y")) { ok -> afterAction(id, ok, "Tapped") }
            "swipe" -> if (rc == null) done(id, false, noRemote) else rc.swipe(args.getDouble("x"), args.getDouble("y"), args.getDouble("x2"), args.getDouble("y2")) { ok -> afterAction(id, ok, "Swiped") }
            "key" -> if (rc == null) done(id, false, noRemote) else afterAction(id, rc.key(args.optString("key")), "Pressed ${args.optString("key")}")
            "text" -> if (rc == null) done(id, false, noRemote) else afterAction(id, rc.type(args.optString("text")), "Typed into the focused field")
            "wake" -> { wake(); afterAction(id, true, "Screen woken") }
            "open_app" -> { openApp(); afterAction(id, true, "Opened KK Farm") }
            "install_update" -> SelfUpdate.run(app, server()) { ok, text -> done(id, ok, text); screenSoon(2500) }
            else -> {
                val h = host
                if (h != null && h.run(id, command) { ok, text -> done(id, ok, text) }) return
                if (h == null) { // the app is closed: open it, and run the command when it is ready
                    waitingForApp.add(id to command)
                    openApp()
                    return
                }
                done(id, false, "This app (${BuildConfigVersion.NAME}) does not know '$command'. Update the app.")
            }
        }
    }

    private fun afterAction(id: Long, ok: Boolean, text: String) {
        done(id, ok, if (ok) text else "$text: refused by Android")
        screenSoon(900)
    }

    private fun screenSoon(ms: Long) = handler.postDelayed({ screen(null) }, ms)

    /** Sends the screen: the whole screen with remote control, else the app's own page when it is on screen. */
    private fun screen(id: Long?) {
        val rc = RemoteControl.instance
        if (rc != null) {
            rc.screenshot(540) { jpeg, w, h, error -> if (jpeg != null) upload(id, jpeg, w, h) else id?.let { done(it, false, error ?: "No screenshot") } }
            return
        }
        val shot = host?.takeIf { it.onScreen() }?.snapshot()
        if (shot == null) { id?.let { done(it, false, "Remote control is off, and KK Farm is not on screen, so there is nothing to show.") }; return }
        upload(id, jpeg(shot, 540), shot.width, shot.height, "KK Farm page only (remote control is off)")
    }

    private fun upload(id: Long?, jpeg: ByteArray, w: Int, h: Int, note: String = "Screen sent") {
        val body = JSONObject().put("install_id", GunLink.installId(app)).put("image", Base64.encodeToString(jpeg, Base64.NO_WRAP)).put("w", w).put("h", h)
        val srv = server()
        Thread {
            val ok = GunLink.screen(app, srv, body)
            handler.post { id?.let { done(it, ok, if (ok) note else "Could not send the screen") } }
        }.start()
    }

    fun jpeg(bitmap: Bitmap, width: Int): ByteArray {
        val scaled = if (bitmap.width > width) Bitmap.createScaledBitmap(bitmap, width, bitmap.height * width / bitmap.width, true) else bitmap
        return ByteArrayOutputStream().use { out -> scaled.compress(Bitmap.CompressFormat.JPEG, 60, out); if (scaled !== bitmap) scaled.recycle(); out.toByteArray() }
    }

    @Suppress("DEPRECATION")
    private fun wake() {
        val pm = app.getSystemService(Context.POWER_SERVICE) as PowerManager
        runCatching { pm.newWakeLock(PowerManager.SCREEN_BRIGHT_WAKE_LOCK or PowerManager.ACQUIRE_CAUSES_WAKEUP, "kkfarm:remote").acquire(3000) }
    }

    private fun openApp() {
        wake()
        runCatching {
            app.startActivity(Intent(app, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_REORDER_TO_FRONT))
        }.onFailure { Log.w(MainActivity.TAG, "could not open KK Farm: ${it.message}") }
    }
}
