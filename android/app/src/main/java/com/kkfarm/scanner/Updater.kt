package com.kkfarm.scanner

import android.app.Activity
import android.app.AlertDialog
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.util.Log
import android.widget.Toast
import androidx.core.content.FileProvider
import org.json.JSONObject
import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import java.security.MessageDigest

/**
 * D-090: the app updates itself from the KK Farms server.
 *
 * The server publishes /downloads/kkfarm.json ({versionCode, versionName, url, sha256, notes}). On start and at most
 * every 6 hours the app compares versionCode with its own. A newer build is downloaded into the app's cache, its
 * SHA-256 is checked, and Android's installer is opened. Android always asks the person to confirm an install for a
 * sideloaded app, so "automatic" means: found, downloaded and verified by itself, then one tap on Install. Android also
 * refuses an update signed with a different key, so a tampered APK cannot replace KK Farms.
 *
 * The farm pages themselves come from the server, so they are always current without any update.
 */
object Updater {
    private const val PREF_AUTO = "auto_update"
    private const val PREF_LAST_CHECK = "update_last_check"
    private const val CHECK_EVERY_MS = 6 * 60 * 60 * 1000L
    private val main = Handler(Looper.getMainLooper())
    @Volatile private var busy = false

    fun isAuto(context: Context): Boolean = prefs(context).getBoolean(PREF_AUTO, true)

    fun setAuto(context: Context, on: Boolean) = prefs(context).edit().putBoolean(PREF_AUTO, on).apply()

    /** On start: checks if it is time (every 6 h). [manual] checks now and says so when the app is up to date. */
    fun check(activity: Activity, server: String, manual: Boolean = false) {
        val p = prefs(activity)
        if (!manual && System.currentTimeMillis() - p.getLong(PREF_LAST_CHECK, 0) < CHECK_EVERY_MS) return
        if (busy) return
        busy = true
        Thread {
            try {
                val info = JSONObject(fetch("$server/downloads/kkfarm.json"))
                p.edit().putLong(PREF_LAST_CHECK, System.currentTimeMillis()).apply()
                val code = info.getInt("versionCode")
                val name = info.optString("versionName", code.toString())
                Log.i(MainActivity.TAG, "update check: server $code ($name), installed ${BuildConfigVersion.CODE}")
                if (code <= BuildConfigVersion.CODE) {
                    if (manual) toast(activity, "KK Farms is up to date (${BuildConfigVersion.NAME}).")
                    return@Thread
                }
                val url = info.getString("url").let { if (it.startsWith("http")) it else server + it }
                val notes = info.optString("notes", "")
                if (isAuto(activity) || manual) {
                    if (!manual) toast(activity, "Downloading KK Farms $name…")
                    val apk = download(activity, url, code, info.optString("sha256", ""))
                    main.post { askToInstall(activity, apk, name, notes) }
                } else {
                    main.post { offer(activity, server, name, notes) }
                }
            } catch (e: Exception) {
                Log.w(MainActivity.TAG, "update check failed", e)
                if (manual) toast(activity, "Could not check for updates: ${e.message ?: "no connection"}")
            } finally {
                busy = false
            }
        }.start()
    }

    private fun offer(activity: Activity, server: String, name: String, notes: String) {
        if (activity.isFinishing) return
        AlertDialog.Builder(activity)
            .setTitle("Update available: KK Farms $name")
            .setMessage(notes.ifEmpty { "A newer version of the app is ready." })
            .setPositiveButton("Update") { _, _ -> check(activity, server, manual = true) }
            .setNegativeButton("Later", null)
            .show()
    }

    private fun askToInstall(activity: Activity, apk: File, name: String, notes: String) {
        if (activity.isFinishing) return
        AlertDialog.Builder(activity)
            .setTitle("KK Farms $name is ready")
            .setMessage((if (notes.isNotEmpty()) "$notes\n\n" else "") + "Tap Install. Your sign-in and settings stay.")
            .setPositiveButton("Install") { _, _ -> install(activity, apk) }
            .setNegativeButton("Later", null)
            .show()
    }

    fun install(activity: Activity, apk: File) {
        // Once per device, Android asks to allow KK Farms to install updates ("Install unknown apps").
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && !activity.packageManager.canRequestPackageInstalls()) {
            toast(activity, "Allow KK Farms to install updates, then tap Install again.")
            activity.startActivity(Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:${activity.packageName}")))
            return
        }
        val uri = FileProvider.getUriForFile(activity, "${activity.packageName}.files", apk)
        activity.startActivity(
            Intent(Intent.ACTION_VIEW).setDataAndType(uri, "application/vnd.android.package-archive")
                .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK),
        )
    }

    private fun download(context: Context, url: String, code: Int, sha256: String): File {
        val dir = File(context.cacheDir, "updates").apply { mkdirs() }
        dir.listFiles()?.forEach { it.delete() } // only the newest download is kept
        val file = File(dir, "kkfarm-$code.apk")
        val conn = (URL(url).openConnection() as HttpURLConnection).apply { connectTimeout = 15_000; readTimeout = 60_000 }
        val digest = MessageDigest.getInstance("SHA-256")
        conn.inputStream.use { input ->
            file.outputStream().use { out ->
                val buf = ByteArray(64 * 1024)
                while (true) {
                    val n = input.read(buf)
                    if (n < 0) break
                    digest.update(buf, 0, n)
                    out.write(buf, 0, n)
                }
            }
        }
        val got = digest.digest().joinToString("") { "%02x".format(it) }
        if (sha256.isNotEmpty() && !got.equals(sha256, ignoreCase = true)) {
            file.delete()
            throw IllegalStateException("the download was damaged (checksum mismatch)")
        }
        return file
    }

    private fun fetch(url: String): String {
        val conn = (URL(url).openConnection() as HttpURLConnection).apply {
            connectTimeout = 10_000; readTimeout = 10_000; useCaches = false
            setRequestProperty("Cache-Control", "no-cache")
        }
        return conn.inputStream.bufferedReader().use { it.readText() }
    }

    private fun toast(context: Context, text: String) = main.post { Toast.makeText(context, text, Toast.LENGTH_LONG).show() }

    private fun prefs(context: Context) = context.getSharedPreferences("kkfarm", Context.MODE_PRIVATE)
}
