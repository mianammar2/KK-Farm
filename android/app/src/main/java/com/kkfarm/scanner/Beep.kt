package com.kkfarm.scanner

import android.media.AudioManager
import android.media.ToneGenerator

/**
 * D-365: sounds for the trigger, like a scanner: a high beep when a tag is read, a low double tone when the trigger
 * found no tag, a short tick when the trigger starts reading. Played on the media volume, so the volume keys set it.
 */
object Beep {
    private val tones by lazy { runCatching { ToneGenerator(AudioManager.STREAM_MUSIC, 90) }.getOrNull() }

    fun reading() = play(ToneGenerator.TONE_PROP_BEEP, 40)
    fun found() = play(ToneGenerator.TONE_CDMA_ALERT_CALL_GUARD, 160)
    fun nothing() = play(ToneGenerator.TONE_PROP_NACK, 300)

    private fun play(tone: Int, ms: Int) { runCatching { tones?.startTone(tone, ms) } }
}
