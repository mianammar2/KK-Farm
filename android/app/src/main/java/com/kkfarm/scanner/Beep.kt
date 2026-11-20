package com.kkfarm.scanner

import android.media.AudioAttributes
import android.media.AudioFormat
import android.media.AudioTrack
import kotlin.concurrent.thread
import kotlin.math.PI
import kotlin.math.sin

/**
 * D-365: sounds for the trigger, like a scanner: a short tick when it starts reading, a bright beep when a tag is read,
 * a low double tone when nothing was read.
 * D-366: louder: clipped (nearly square) waves at full level near 2.7 kHz, the pitch handheld scanners use to be heard
 * in a noisy barn, played on the alarm volume, which is normally high and not turned down with the media volume.
 */
object Beep {
    private const val RATE = 22050

    /** Once per install of this version: the alarm volume (the beeps') goes to full; lowered later, it stays lowered. */
    fun setUp(context: android.content.Context) {
        val prefs = context.getSharedPreferences("kkfarm", android.content.Context.MODE_PRIVATE)
        if (prefs.getBoolean("beep_volume_set", false)) return
        runCatching {
            val am = context.getSystemService(android.media.AudioManager::class.java)
            am.setStreamVolume(android.media.AudioManager.STREAM_ALARM, am.getStreamMaxVolume(android.media.AudioManager.STREAM_ALARM), 0)
        }
        prefs.edit().putBoolean("beep_volume_set", true).apply()
    }

    fun reading() = play(listOf(3200.0 to 25))
    fun found() = play(listOf(2700.0 to 150))
    fun nothing() = play(listOf(600.0 to 140, 0.0 to 60, 450.0 to 180))

    private fun play(parts: List<Pair<Double, Int>>) {
        thread(name = "beep") {
            runCatching {
                val total = parts.sumOf { it.second }
                val samples = ShortArray(total * RATE / 1000)
                var at = 0
                for ((hz, ms) in parts) {
                    val n = ms * RATE / 1000
                    for (i in 0 until n) {
                        val edge = minOf(i, n - i, 80) / 80.0 // soft start and end: no click
                        val wave = if (hz == 0.0) 0.0 else (sin(2 * PI * hz * i / RATE) * 3).coerceIn(-1.0, 1.0)
                        samples[at + i] = (wave * edge * Short.MAX_VALUE * 0.95).toInt().toShort()
                    }
                    at += n
                }
                val track = AudioTrack.Builder()
                    .setAudioAttributes(AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_ALARM).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build())
                    .setAudioFormat(AudioFormat.Builder().setEncoding(AudioFormat.ENCODING_PCM_16BIT).setSampleRate(RATE).setChannelMask(AudioFormat.CHANNEL_OUT_MONO).build())
                    .setTransferMode(AudioTrack.MODE_STATIC)
                    .setBufferSizeInBytes(samples.size * 2)
                    .build()
                track.write(samples, 0, samples.size)
                track.setVolume(AudioTrack.getMaxVolume())
                track.play()
                Thread.sleep(total.toLong() + 60)
                track.release()
            }
        }
    }
}
