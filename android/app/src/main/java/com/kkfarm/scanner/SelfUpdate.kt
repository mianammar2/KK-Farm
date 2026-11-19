package com.kkfarm.scanner

import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInstaller
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.util.Log
import org.json.JSONObject
import java.net.URL

/**
 * D-355: the app installs its own update when the Administrator asks (Settings → Guns → Install the latest app).
 * Android 12+ lets an app update itself without a tap once it is the app that installed itself; the first time
 * Android still asks, and the Install button can be tapped on the remote screen. The APK is checked against the
 * server's SHA-256 and must carry the same signing key, so nothing else can be installed this way.
 */
object SelfUpdate {
    private val main = Handler(Looper.getMainLooper())

    fun run(context: Context, server: String, done: (ok: Boolean, text: String) -> Unit) {
        Thread {
            try {
                val info = JSONObject(URL("$server/downloads/kkfarm.json").readText())
                val code = info.getInt("versionCode")
                val name = info.optString("versionName", code.toString())
                if (code <= BuildConfigVersion.CODE) { main.post { done(true, "Already on the latest app (${BuildConfigVersion.NAME}).") }; return@Thread }
                val url = info.getString("url").let { if (it.startsWith("http")) it else server + it }
                val apk = Updater.download(context, url, code, info.optString("sha256", ""))
                val installer = context.packageManager.packageInstaller
                val params = PackageInstaller.SessionParams(PackageInstaller.SessionParams.MODE_FULL_INSTALL).apply {
                    setAppPackageName(context.packageName)
                    if (Build.VERSION.SDK_INT >= 31) setRequireUserAction(PackageInstaller.SessionParams.USER_ACTION_NOT_REQUIRED)
                }
                val id = installer.createSession(params)
                installer.openSession(id).use { s ->
                    s.openWrite("kkfarm.apk", 0, apk.length()).use { out -> apk.inputStream().use { it.copyTo(out) }; s.fsync(out) }
                    val status = PendingIntent.getBroadcast(context, id, Intent(context, InstallStatus::class.java), PendingIntent.FLAG_MUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
                    s.commit(status.intentSender)
                }
                Log.i(MainActivity.TAG, "self-update to $name committed")
                main.post { done(true, "Installing $name. If Android asks, tap Install on the remote screen. KK Farm opens again by itself.") }
            } catch (e: Exception) {
                Log.w(MainActivity.TAG, "self-update failed", e)
                main.post { done(false, "Update failed: ${e.message ?: e.javaClass.simpleName}") }
            }
        }.start()
    }

    /** Android's answer to the install: asks the person when it must, otherwise logs the result. */
    class InstallStatus : BroadcastReceiver() {
        override fun onReceive(context: Context, intent: Intent) {
            val status = intent.getIntExtra(PackageInstaller.EXTRA_STATUS, -999)
            Log.i(MainActivity.TAG, "self-update status $status ${intent.getStringExtra(PackageInstaller.EXTRA_STATUS_MESSAGE) ?: ""}")
            if (status == PackageInstaller.STATUS_PENDING_USER_ACTION) {
                @Suppress("DEPRECATION")
                val confirm = (if (Build.VERSION.SDK_INT >= 33) intent.getParcelableExtra(Intent.EXTRA_INTENT, Intent::class.java) else intent.getParcelableExtra(Intent.EXTRA_INTENT)) ?: return
                runCatching { context.startActivity(confirm.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
            }
        }
    }
}
