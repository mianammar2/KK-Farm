package com.kkfarm.scanner

import android.content.Context
import android.util.Log
import android.webkit.CookieManager
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLDecoder
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.UUID

/**
 * D-354: the gun reports to the KK Farm server by itself, so it can be checked and tested from Settings → Guns
 * without adb or a cable.
 *
 * While the app runs it posts /gun/report with the signed-in person's session (the WebView's cookies and its XSRF
 * token): app version, reader state, what it last did, and its own log when asked. The answer carries the
 * Administrator's commands (read-only tests); their results go back with the next report.
 *
 * The gun is known by a random install id made on first run. No serial, IMEI or MAC is ever sent.
 */
object GunLink {
    private const val PREF_ID = "install_id"
    private const val PREF_CRASH = "last_crash"
    private val stamp get() = SimpleDateFormat("dd/MM/yyyy HH:mm:ss", Locale.ROOT).format(Date())

    // What the gun last did, shown on Settings → Guns.
    @Volatile var lastTrigger = ""
    @Volatile var lastHold = ""
    @Volatile var lastScan = ""

    fun now(): String = stamp

    fun installId(context: Context): String {
        val p = prefs(context)
        return p.getString(PREF_ID, null) ?: UUID.randomUUID().toString().also { p.edit().putString(PREF_ID, it).apply() }
    }

    /** Keeps the last crash (time, error, first lines of the stack) so the next report carries it. */
    fun catchCrashes(context: Context) {
        val app = context.applicationContext
        val previous = Thread.getDefaultUncaughtExceptionHandler()
        Thread.setDefaultUncaughtExceptionHandler { t, e ->
            runCatching {
                val trace = e.stackTrace.take(8).joinToString("\n") { "  at $it" }
                prefs(app).edit().putString(PREF_CRASH, "$stamp on ${t.name}: $e\n$trace").commit()
            }
            previous?.uncaughtException(t, e)
        }
    }

    fun lastCrash(context: Context): String? = prefs(context).getString(PREF_CRASH, null)

    /** This app's own log (the Chainway SDK runs inside it, so its lines are here too). */
    fun ownLog(lines: Int = 3000): String = runCatching {
        val p = Runtime.getRuntime().exec(arrayOf("logcat", "-d", "-v", "time", "-t", lines.toString(), "--pid", android.os.Process.myPid().toString()))
        p.inputStream.bufferedReader().use { it.readText() }.also { p.waitFor() }
    }.getOrElse { "could not read the log: ${it.message}" }

    /**
     * Posts one report. Runs on a worker thread. Returns the server's answer ({commands, every}), or null when
     * nobody is signed in on the gun or the server cannot be reached.
     */
    fun report(server: String, body: JSONObject): JSONObject? {
        val cookies = CookieManager.getInstance().getCookie(server) ?: return null
        val xsrf = cookies.split(";").map { it.trim() }.firstOrNull { it.startsWith("XSRF-TOKEN=") }?.substringAfter("=")
            ?.let { URLDecoder.decode(it, "UTF-8") } ?: return null
        return runCatching {
            val c = URL("$server/gun/report").openConnection() as HttpURLConnection
            c.requestMethod = "POST"
            c.connectTimeout = 10_000
            c.readTimeout = 20_000
            c.doOutput = true
            c.instanceFollowRedirects = false
            c.setRequestProperty("Content-Type", "application/json")
            c.setRequestProperty("Accept", "application/json")
            c.setRequestProperty("X-Requested-With", "XMLHttpRequest")
            c.setRequestProperty("X-XSRF-TOKEN", xsrf)
            c.setRequestProperty("Cookie", cookies)
            c.setRequestProperty("User-Agent", "KKFarmScanner/${BuildConfigVersion.NAME}")
            c.outputStream.use { it.write(body.toString().toByteArray()) }
            val code = c.responseCode
            if (code != 200) { Log.i(MainActivity.TAG, "gun report: server said $code"); c.disconnect(); return null }
            JSONObject(c.inputStream.bufferedReader().use { it.readText() }).also { c.disconnect() }
        }.onFailure { Log.i(MainActivity.TAG, "gun report failed: ${it.message}") }.getOrNull()
    }

    fun commands(answer: JSONObject?): List<Pair<Long, String>> {
        val list: JSONArray = answer?.optJSONArray("commands") ?: return emptyList()
        return (0 until list.length()).map { list.getJSONObject(it).let { c -> c.getLong("id") to c.getString("command") } }
    }

    private fun prefs(context: Context) = context.getSharedPreferences("kkfarm", Context.MODE_PRIVATE)
}
