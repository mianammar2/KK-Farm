package com.kkfarm.scanner

import android.accessibilityservice.AccessibilityService
import android.accessibilityservice.GestureDescription
import android.graphics.Bitmap
import android.graphics.Path
import android.os.Build
import android.os.Bundle
import android.util.Log
import android.view.Display
import android.view.WindowManager
import android.view.accessibility.AccessibilityEvent
import android.view.accessibility.AccessibilityNodeInfo

/**
 * D-355: the gun's remote screen for the Administrator (Settings → Guns): a screenshot of the whole screen, a tap or
 * swipe, a system key (Back, Home, Recent apps, Notifications) and typing into the focused field.
 *
 * It is an Accessibility service, switched on once on the gun (Settings → Accessibility → KK Farms remote control, or
 * by adb). It only acts on commands from the farm's own server, sent by the Administrator; it reads nothing by itself.
 */
class RemoteControl : AccessibilityService() {

    override fun onServiceConnected() {
        instance = this
        Log.i(MainActivity.TAG, "remote control on")
        GunAgent.soon()
    }

    override fun onDestroy() {
        if (instance === this) instance = null
        super.onDestroy()
    }

    override fun onAccessibilityEvent(event: AccessibilityEvent?) {}
    override fun onInterrupt() {}

    private fun size(): Pair<Int, Int> {
        val b = getSystemService(WindowManager::class.java).maximumWindowMetrics.bounds
        return b.width() to b.height()
    }

    fun tap(fx: Double, fy: Double, done: (Boolean) -> Unit) {
        val (w, h) = size()
        val p = Path().apply { moveTo((fx * w).toFloat(), (fy * h).toFloat()) }
        gesture(GestureDescription.StrokeDescription(p, 0, 60), done)
    }

    fun swipe(fx: Double, fy: Double, fx2: Double, fy2: Double, done: (Boolean) -> Unit) {
        val (w, h) = size()
        val p = Path().apply { moveTo((fx * w).toFloat(), (fy * h).toFloat()); lineTo((fx2 * w).toFloat(), (fy2 * h).toFloat()) }
        gesture(GestureDescription.StrokeDescription(p, 0, 350), done)
    }

    private fun gesture(stroke: GestureDescription.StrokeDescription, done: (Boolean) -> Unit) {
        val ok = dispatchGesture(GestureDescription.Builder().addStroke(stroke).build(), object : GestureResultCallback() {
            override fun onCompleted(g: GestureDescription?) = done(true)
            override fun onCancelled(g: GestureDescription?) = done(false)
        }, null)
        if (!ok) done(false)
    }

    fun key(name: String): Boolean {
        val action = when (name) {
            "back" -> GLOBAL_ACTION_BACK
            "home" -> GLOBAL_ACTION_HOME
            "recents" -> GLOBAL_ACTION_RECENTS
            "notifications" -> GLOBAL_ACTION_NOTIFICATIONS
            else -> return false
        }
        return performGlobalAction(action)
    }

    /** Adds [text] to the field that has the focus. */
    fun type(text: String): Boolean {
        val node = rootInActiveWindow?.findFocus(AccessibilityNodeInfo.FOCUS_INPUT) ?: return false
        val now = (node.text?.toString() ?: "") + text
        return node.performAction(AccessibilityNodeInfo.ACTION_SET_TEXT, Bundle().apply { putCharSequence(AccessibilityNodeInfo.ACTION_ARGUMENT_SET_TEXT_CHARSEQUENCE, now) })
    }

    /** The whole screen as a JPEG, [width] pixels wide. Android allows about 3 per second. */
    fun screenshot(width: Int, done: (jpeg: ByteArray?, w: Int, h: Int, error: String?) -> Unit) {
        if (Build.VERSION.SDK_INT < 30) { done(null, 0, 0, "Android ${Build.VERSION.RELEASE} cannot take screenshots for apps"); return }
        takeScreenshot(Display.DEFAULT_DISPLAY, mainExecutor, object : TakeScreenshotCallback {
            override fun onSuccess(shot: ScreenshotResult) {
                runCatching {
                    val hw = Bitmap.wrapHardwareBuffer(shot.hardwareBuffer, shot.colorSpace) ?: error("no image")
                    val full = hw.copy(Bitmap.Config.ARGB_8888, false)
                    hw.recycle(); shot.hardwareBuffer.close()
                    done(GunAgent.jpeg(full, width), full.width, full.height, null)
                    full.recycle()
                }.onFailure { done(null, 0, 0, it.message) }
            }

            override fun onFailure(code: Int) = done(null, 0, 0, "screenshot refused (code $code)")
        })
    }

    companion object {
        @Volatile var instance: RemoteControl? = null
    }
}
