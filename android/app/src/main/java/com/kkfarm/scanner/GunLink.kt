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

    private const val PREF_KEY = "gun_key"

    /**
     * Posts one report. Runs on a worker thread. With the gun's own key (given by the server on the first report while
     * someone was signed in) it uses /api/v1/gun/report and works with nobody signed in; without it, the session.
     * Returns the server's answer ({commands, every, key?}), or null when it cannot report.
     */
    fun report(context: Context, server: String, body: JSONObject): JSONObject? {
        val answer = post(context, server, "/gun/report", body) ?: return null
        answer.optString("key").takeIf { it.startsWith("kkg_") }?.let { prefs(context).edit().putString(PREF_KEY, it).apply(); Log.i(MainActivity.TAG, "gun key received") }
        return answer
    }

    fun screen(context: Context, server: String, body: JSONObject): Boolean = post(context, server, "/gun/screen", body) != null

    private fun post(context: Context, server: String, path: String, body: JSONObject): JSONObject? {
        val key = prefs(context).getString(PREF_KEY, null)
        val headers = LinkedHashMap<String, String>()
        val url: String
        if (key != null) {
            url = "$server/api/v1$path"
            headers["Authorization"] = "Bearer $key"
        } else {
            val cookies = runCatching { CookieManager.getInstance().getCookie(server) }.getOrNull() ?: return null
            val xsrf = cookies.split(";").map { it.trim() }.firstOrNull { it.startsWith("XSRF-TOKEN=") }?.substringAfter("=")
                ?.let { URLDecoder.decode(it, "UTF-8") } ?: return null
            url = "$server$path"
            headers["X-XSRF-TOKEN"] = xsrf
            headers["Cookie"] = cookies
            headers["X-Requested-With"] = "XMLHttpRequest"
        }
        return runCatching {
            val c = URL(url).openConnection() as HttpURLConnection
            c.requestMethod = "POST"
            c.connectTimeout = 10_000
            c.readTimeout = 30_000
            c.doOutput = true
            c.instanceFollowRedirects = false
            c.setRequestProperty("Content-Type", "application/json")
            c.setRequestProperty("Accept", "application/json")
            c.setRequestProperty("User-Agent", "KKFarmScanner/${BuildConfigVersion.NAME}")
            headers.forEach { (k, v) -> c.setRequestProperty(k, v) }
            c.outputStream.use { it.write(body.toString().toByteArray()) }
            val code = c.responseCode
            if (code == 401 && key != null) { // the gun was removed on Settings → Guns: start again with the next signed-in report
                prefs(context).edit().remove(PREF_KEY).apply()
                Log.i(MainActivity.TAG, "gun key refused: cleared")
            }
            if (code != 200) { Log.i(MainActivity.TAG, "gun $path: server said $code"); c.disconnect(); return null }
            JSONObject(c.inputStream.bufferedReader().use { it.readText() }).also { c.disconnect() }
        }.onFailure { Log.i(MainActivity.TAG, "gun $path failed: ${it.message}") }.getOrNull()
    }

    fun commands(answer: JSONObject?): List<Triple<Long, String, JSONObject>> {
        val list: JSONArray = answer?.optJSONArray("commands") ?: return emptyList()
        return (0 until list.length()).map { list.getJSONObject(it).let { c -> Triple(c.getLong("id"), c.getString("command"), c.optJSONObject("args") ?: JSONObject()) } }
    }

    private fun prefs(context: Context) = context.getSharedPreferences("kkfarm", Context.MODE_PRIVATE)
}
