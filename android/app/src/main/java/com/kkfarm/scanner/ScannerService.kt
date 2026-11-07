package com.kkfarm.scanner

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import android.util.Log

/**
 * Keeps the RFID trigger connected to KK Farm all the time (D-049), even when the app was swiped away.
 *
 *  - A tag read (InfoWedge DATA_RESULT) opens KK Farm with that tag: the goat, or the goat register.
 *  - Pressing the trigger while another app is on screen brings KK Farm to the front on the Scan screen.
 *
 * Android lets a background app open a screen only with "Display over other apps", so that permission is
 * granted to KK Farm on the gun. The service runs as a foreground service ("KK Farm is ready to scan") and
 * starts again after a reboot (BootReceiver).
 */
class ScannerService : Service() {

    private val prefs by lazy { getSharedPreferences("kkfarm", Context.MODE_PRIVATE) }
    private var lastTriggerAt = 0L

    private val receiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context, intent: Intent) {
            val action = intent.action ?: return
            if (action in ScanParser.TRIGGER_ACTIONS) {
                onTrigger(action)
                return
            }
            Log.i(MainActivity.TAG, "service broadcast action=$action extras=${ScanParser.describe(intent)}")
            val codes = ScanParser.codes(intent, prefs.getString("extra", MainActivity.DEFAULT_EXTRA)!!)
            if (codes.isNotEmpty()) {
                open(Intent(context, MainActivity::class.java).putExtra(MainActivity.EXTRA_CODES, codes.toTypedArray()))
            }
        }
    }

    override fun onCreate() {
        super.onCreate()
        goForeground()
        val filter = IntentFilter().apply {
            (ScanParser.READ_ACTIONS + ScanParser.TRIGGER_ACTIONS + listOf(prefs.getString("action", MainActivity.DEFAULT_ACTION)!!)).distinct().forEach { addAction(it) }
        }
        if (Build.VERSION.SDK_INT >= 33) registerReceiver(receiver, filter, Context.RECEIVER_EXPORTED) else @Suppress("UnspecifiedRegisterReceiverFlag") registerReceiver(receiver, filter)
        running = true
        Log.i(MainActivity.TAG, "scanner service listening for ${filter.actionsIterator().asSequence().toList()}")
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int = START_STICKY

    override fun onDestroy() {
        running = false
        runCatching { unregisterReceiver(receiver) }
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    private fun onTrigger(action: String) {
        val now = System.currentTimeMillis()
        if (MainActivity.visible || !prefs.getBoolean(MainActivity.KEY_OPEN_ON_TRIGGER, true) || now - lastTriggerAt < 1500) return
        lastTriggerAt = now
        Log.i(MainActivity.TAG, "trigger ($action) while KK Farm is in the background: opening the Scan screen")
        open(Intent(this, MainActivity::class.java).putExtra(MainActivity.EXTRA_OPEN_SCAN, true))
    }

    private fun open(intent: Intent) {
        runCatching {
            startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_REORDER_TO_FRONT))
        }.onFailure { Log.w(MainActivity.TAG, "could not open KK Farm: ${it.message}") }
    }

    private fun goForeground() {
        val manager = getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(NotificationChannel(CHANNEL, "RFID scanner", NotificationManager.IMPORTANCE_MIN).apply { setShowBadge(false) })
        val tap = PendingIntent.getActivity(this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE)
        val notification = Notification.Builder(this, CHANNEL)
            .setSmallIcon(R.drawable.ic_stat_scan)
            .setContentTitle("KK Farm is ready to scan")
            .setContentText("Pull the trigger at a goat's ear tag")
            .setContentIntent(tap)
            .setOngoing(true)
            .build()
        if (Build.VERSION.SDK_INT >= 34) startForeground(1, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE) else startForeground(1, notification)
    }

    companion object {
        private const val CHANNEL = "scanner"
        @Volatile var running = false

        fun start(context: Context) {
            runCatching { context.startForegroundService(Intent(context, ScannerService::class.java)) }
                .onFailure { Log.w(MainActivity.TAG, "could not start scanner service: ${it.message}") }
        }

        fun restart(context: Context) {
            context.stopService(Intent(context, ScannerService::class.java))
            start(context)
        }
    }
}
