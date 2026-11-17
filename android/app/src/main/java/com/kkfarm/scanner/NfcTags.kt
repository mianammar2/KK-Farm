package com.kkfarm.scanner

import android.app.Activity
import android.nfc.NdefMessage
import android.nfc.NdefRecord
import android.nfc.NfcAdapter
import android.nfc.Tag
import android.nfc.tech.Ndef
import android.nfc.tech.NdefFormatable
import android.os.Bundle
import android.util.Log
import java.util.Locale

/**
 * D-337: touch tags (NFC, 13.56 MHz) on the C72. While KK Farm is on screen the reader is always on, so touching a
 * tag to the gun reads it with no trigger. A tag that carries the farm's Tag ID (written by KK Farm) reads as that
 * Tag ID; a blank one reads as its chip number. When the goat page asks for a write, the next touch writes the
 * goat's Tag ID into the chip (replacing what the factory put there) and reads it back to check.
 */
class NfcTags(private val activity: Activity, private val onRead: (String) -> Unit, private val onWritten: (ok: Boolean, code: String, chip: String, error: String?) -> Unit) {

    private val adapter: NfcAdapter? = NfcAdapter.getDefaultAdapter(activity)
    @Volatile private var pendingWrite: String? = null

    /** "none" (no NFC reader in this device), "off" (switched off in Android settings) or "on". */
    fun state(): String = when {
        adapter == null -> "none"
        !adapter.isEnabled -> "off"
        else -> "on"
    }

    fun start() {
        val nfc = adapter ?: return
        if (!nfc.isEnabled) return
        val flags = NfcAdapter.FLAG_READER_NFC_A or NfcAdapter.FLAG_READER_NFC_B or NfcAdapter.FLAG_READER_NFC_F or NfcAdapter.FLAG_READER_NFC_V
        val extras = Bundle().apply { putInt(NfcAdapter.EXTRA_READER_PRESENCE_CHECK_DELAY, 250) }
        runCatching { nfc.enableReaderMode(activity, { tag -> onTag(tag) }, flags, extras) }
            .onFailure { Log.w(MainActivity.TAG, "NFC reader mode failed: ${it.message}") }
    }

    fun stop() {
        runCatching { adapter?.disableReaderMode(activity) }
    }

    fun armWrite(code: String) {
        pendingWrite = code.trim().uppercase(Locale.ROOT)
        Log.i(MainActivity.TAG, "NFC: next touch writes $pendingWrite")
    }

    fun cancelWrite() {
        pendingWrite = null
    }

    private fun onTag(tag: Tag) {
        val chip = tag.id.joinToString("") { "%02X".format(it) }
        val write = pendingWrite
        if (write != null) {
            pendingWrite = null
            val error = runCatching { writeText(tag, write) }.exceptionOrNull()
            val readBack = if (error == null) runCatching { readText(tag) }.getOrNull() else null
            val ok = error == null && readBack == write
            Log.i(MainActivity.TAG, "NFC write $write to chip $chip ok=$ok err=${error?.message} readBack=$readBack")
            onWritten(ok, write, chip, error?.message ?: if (!ok) "The tag did not keep the code. Hold it still on the gun and try again." else null)
            return
        }
        val text = runCatching { readText(tag) }.getOrNull()
        val code = text?.takeIf { CODE.matches(it) } ?: chip
        Log.i(MainActivity.TAG, "NFC read chip=$chip text=$text -> $code")
        onRead(code)
    }

    private fun message(code: String) = NdefMessage(arrayOf(NdefRecord.createTextRecord("en", code)))

    private fun writeText(tag: Tag, code: String) {
        val msg = message(code)
        Ndef.get(tag)?.let { ndef ->
            ndef.connect()
            try {
                if (!ndef.isWritable) throw IllegalStateException("This tag is locked and cannot be written.")
                if (ndef.maxSize < msg.byteArrayLength) throw IllegalStateException("This tag is too small for the Tag ID.")
                ndef.writeNdefMessage(msg)
            } finally {
                runCatching { ndef.close() }
            }
            return
        }
        NdefFormatable.get(tag)?.let { blank ->
            blank.connect()
            try {
                blank.format(msg)
            } finally {
                runCatching { blank.close() }
            }
            return
        }
        throw IllegalStateException("This kind of tag cannot be written by the gun (techs: ${tag.techList.joinToString { it.substringAfterLast('.') }}).")
    }

    private fun readText(tag: Tag): String? {
        val ndef = Ndef.get(tag) ?: return null
        ndef.connect()
        try {
            val record = (ndef.ndefMessage ?: return null).records.firstOrNull { it.tnf == NdefRecord.TNF_WELL_KNOWN && it.type.contentEquals(NdefRecord.RTD_TEXT) } ?: return null
            val p = record.payload
            val langLength = p[0].toInt() and 0x3F
            return String(p, 1 + langLength, p.size - 1 - langLength, Charsets.UTF_8).trim().uppercase(Locale.ROOT)
        } finally {
            runCatching { ndef.close() }
        }
    }

    companion object {
        private val CODE = Regex("^[A-Z0-9._:-]{4,64}$")
    }
}
