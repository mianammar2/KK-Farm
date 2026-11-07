package com.kkfarm.scanner

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/** Starts the scanner service when the gun boots, so the trigger opens KK Farm without opening it first (D-049). */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Intent.ACTION_BOOT_COMPLETED || intent.action == Intent.ACTION_MY_PACKAGE_REPLACED) {
            ScannerService.start(context)
        }
    }
}
