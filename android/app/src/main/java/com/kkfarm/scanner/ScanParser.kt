package com.kkfarm.scanner

import android.content.Intent

/** Reads tag codes out of a reader broadcast (InfoWedge sends one EPC per line). Shared by the service and the screen. */
object ScanParser {
    // com.rscja.android.DATA_RESULT is what InfoWedge broadcasts after each read on this C72 (D-039).
    val READ_ACTIONS = listOf("com.rscja.android.DATA_RESULT", "com.scanner.broadcast", "com.rscja.scanner.action.scanner.RFID", "android.rfid.FUN_KEY")

    // The C72 trigger is announced as a broadcast by Chainway's scan service (KEY_UP seen in logcat; KEY_DOWN assumed alongside it).
    val TRIGGER_ACTIONS = listOf("com.rscja.android.KEY_DOWN", "com.rscja.android.KEY_UP")

    private val KNOWN_EXTRAS = listOf("rfid_epc", "data", "barcode_string", "barcodeData", "scannerdata", "epc", "EPC", "barcode", "code")
    private val TAG_PATTERN = Regex("^[A-Za-z0-9._:-]{4,64}$")
    // Empty or failed reads come back as all zeros (seen on the C72) or all F.
    private val JUNK_PATTERN = Regex("^(0+|F+)$")
    // D-339: an empty read comes back with result=0 and words like RFID / SCANNER in other extras; those are never tags.
    private val NOT_TAGS = setOf("RFID", "SCANNER", "BARCODE", "UHF", "NFC", "SUCCESS", "FAIL", "FAILED", "TRUE", "FALSE", "NULL")
    private val NOT_TAG_KEYS = setOf("source", "result", "type", "scantype", "rfid_rssi", "rssi", "rfid_pc", "rfid_tid", "codetype", "symbology", "length", "time")

    fun codes(intent: Intent, preferredExtra: String): List<String> {
        val extras = intent.extras ?: return emptyList()
        @Suppress("DEPRECATION")
        if (extras.get("result")?.toString()?.trim() == "0") return emptyList() // the reader found nothing
        val keys = (listOf(preferredExtra) + KNOWN_EXTRAS + extras.keySet()).filter { it.lowercase() !in NOT_TAG_KEYS }
        for (key in keys.distinct()) {
            @Suppress("DEPRECATION")
            val value = when (val v = extras.get(key)) {
                is String -> v
                is ByteArray -> String(v)
                is CharSequence -> v.toString()
                else -> null
            } ?: continue
            val codes = value.split(Regex("[\\s,;]+")).map { it.trim().uppercase() }.filter { TAG_PATTERN.matches(it) && !JUNK_PATTERN.matches(it) && it !in NOT_TAGS }
                .map { UhfReader.decode(it) }.distinct() // a tag written by KK Farm reads as its Tag ID
            if (codes.isNotEmpty()) return codes
        }
        return emptyList()
    }

    @Suppress("DEPRECATION")
    fun describe(intent: Intent): String = intent.extras?.let { e -> e.keySet().associateWith { k -> e.get(k).let { v -> "${v?.javaClass?.simpleName}:${if (v is ByteArray) String(v) else v}" } } }.toString()
}
