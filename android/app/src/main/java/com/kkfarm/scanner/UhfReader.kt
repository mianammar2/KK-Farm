package com.kkfarm.scanner

import android.content.Context
import android.util.Log
import java.lang.reflect.Method
import java.util.Locale
import kotlin.concurrent.thread

/**
 * D-339: the C72's UHF reader driven by KK Farm itself, through Chainway's DeviceAPI SDK.
 *
 * The SDK (.aar) is vendor-supplied and kept out of git (CLAUDE.md): put it in android/app/libs/ and rebuild.
 * It is called by reflection, so the app builds and runs without it; then [state] is "none" and InfoWedge
 * stays the reader, as before.
 *
 * What it does when the SDK is there:
 *  - Hold the trigger: reads continuously at full power; release: stops. (Counting a room.)
 *  - Touch mode (the goat's Link tag box): short low-power reads with no trigger; a tag held against the gun is read.
 *  - Write: overwrites one tag's EPC with the goat's Tag ID (ASCII, padded to whole words), filtered on that
 *    tag's current EPC so no other tag can be written, then reads it back to check.
 * A tag whose EPC is a written Tag ID reads as that Tag ID; any other tag reads as its EPC hex.
 */
class UhfReader(private val context: Context) {

    data class Read(val code: String, val epc: String, val rssi: Double)

    private var reader: Any? = null
    private var tagInfoClass: Class<*>? = null
    @Volatile private var state = "none"
    @Volatile private var busy = false // one job at a time: hold-read, touch burst or write
    private val lock = Object()

    /** "none" (no SDK in this build or no UHF module), "ready", or "error" (the module would not start). */
    fun state(): String = state

    fun open(): Boolean {
        if (reader != null) return true
        val cls = runCatching { Class.forName("com.rscja.deviceapi.RFIDWithUHFUART") }.getOrNull()
        if (cls == null) { state = "none"; return false }
        return runCatching {
            val r = cls.getMethod("getInstance").invoke(null)!!
            val ok = runCatching { cls.getMethod("init", Context::class.java).invoke(r, context) }.getOrElse { cls.getMethod("init").invoke(r) } as Boolean
            tagInfoClass = runCatching { Class.forName("com.rscja.deviceapi.entity.UHFTAGInfo") }.getOrNull()
            if (ok) { reader = r; state = "ready" } else state = "error"
            Log.i(MainActivity.TAG, "UHF SDK init ok=$ok")
            if (ok) Log.i(MainActivity.TAG, "UHF settings: ${settings()}")
            ok
        }.getOrElse { Log.w(MainActivity.TAG, "UHF SDK init failed: ${it.message}"); state = "error"; false }
    }

    fun close() {
        stopAll()
        synchronized(lock) {} // wait for a running read or write to stop before the module is freed
        reader?.let { r -> runCatching { r.javaClass.getMethod("free").invoke(r) } }
        reader = null // state keeps its last value, so the page still knows the SDK is there; open() runs again on resume
    }

    private fun call(name: String, vararg args: Any?): Any? {
        val r = reader ?: return null
        val m = r.javaClass.methods.firstOrNull { it.name == name && it.parameterTypes.size == args.size }
            ?: run { Log.w(MainActivity.TAG, "UHF SDK has no $name/${args.size}"); return null }
        return runCatching { m.invoke(r, *args) }.onFailure { Log.w(MainActivity.TAG, "UHF $name failed: ${it.cause?.message ?: it.message}") }.getOrNull()
    }

    /** The reader's region, power and module versions, for the log (no serial numbers). */
    private fun settings(): String = listOf("getFrequencyMode", "getPower", "getVersion", "getHardwareVersion", "getEPCAndTIDUserMode", "getGen2")
        .joinToString(", ") { n -> "$n=${if (reader!!.javaClass.methods.any { it.name == n && it.parameterTypes.isEmpty() }) call(n)?.let { v -> if (v.javaClass.name.startsWith("com.rscja")) runCatching { v.javaClass.declaredFields.joinToString(";") { f -> f.isAccessible = true; "${f.name}:${f.get(v)}" } }.getOrDefault(v.toString()) else v.toString() } else "n/a"}" }

    /**
     * D-356: Chainway's InfoWedge opens and then frees (powers off) the UHF module whenever an app comes to the front,
     * right after KK Farm opened it; the module then answers nothing and every read finds 0 tags. Before each job:
     * a quick question to the module, and when it does not answer, the module is opened again.
     */
    private fun ensureAlive(): Boolean {
        val r = reader ?: return false
        val power = call("getPower") as? Int ?: -1
        if (power > 0) return true
        Log.i(MainActivity.TAG, "UHF module not answering (power=$power): opening it again")
        runCatching { r.javaClass.getMethod("free").invoke(r) }
        val ok = runCatching { (runCatching { r.javaClass.getMethod("init", Context::class.java).invoke(r, context) }.getOrElse { r.javaClass.getMethod("init").invoke(r) }) as Boolean }.getOrDefault(false)
        Log.i(MainActivity.TAG, "UHF reopened ok=$ok")
        if (!ok) state = "error"
        return ok
    }

    private fun setPower(dbm: Int) { Log.i(MainActivity.TAG, "UHF setPower($dbm) = ${call("setPower", dbm)}") }

    private fun startInventory(): Boolean = (call("startInventoryTag") as? Boolean) ?: false
    private fun stopInventory() { call("stopInventory") }

    private fun nextTag(): Read? {
        val info = call("readTagFromBuffer") ?: return null
        val epc = (runCatching { info.javaClass.getMethod("getEPC").invoke(info) as String? }.getOrNull() ?: return null).uppercase(Locale.ROOT)
        val rssi = runCatching { (info.javaClass.getMethod("getRssi").invoke(info) as String?)?.replace(",", ".")?.toDouble() }.getOrNull() ?: -99.0
        return Read(decode(epc), epc, rssi)
    }

    // ---------- hold the trigger ----------
    @Volatile private var holding = false

    /** Reads at full power until [stopHold]. Each tag is reported once per hold, with its best signal. */
    fun startHold(onTag: (Read) -> Unit) {
        if (holding) return
        if (reader == null && !open()) { Log.w(MainActivity.TAG, "UHF hold: reader not open ($state)"); GunLink.lastHold = "${GunLink.now()} reader not open ($state)"; return }
        stopTouch()
        holding = true
        thread(name = "uhf-hold") {
            synchronized(lock) {
                busy = true
                ensureAlive()
                setPower(FULL_POWER)
                val seen = HashSet<String>()
                var reads = 0
                val started = startInventory()
                Log.i(MainActivity.TAG, "UHF hold: startInventoryTag = $started")
                if (started) {
                    while (holding) {
                        val t = nextTag()
                        if (t == null) { Thread.sleep(15); continue }
                        reads++
                        if (seen.add(t.epc)) onTag(t)
                    }
                    Log.i(MainActivity.TAG, "UHF hold: stopInventory = ${call("stopInventory")}, $reads reads, ${seen.size} tags")
                    if (seen.isEmpty()) { // D-356: say why: the reader's settings and a few single reads
                        Log.i(MainActivity.TAG, "UHF diag: ${settings()}")
                        repeat(5) { k ->
                            val one = call("inventorySingleTag")
                            val epc = one?.let { runCatching { it.javaClass.getMethod("getEPC").invoke(it) }.getOrNull() }
                            Log.i(MainActivity.TAG, "UHF diag single $k: ${if (one == null) "nothing" else "EPC $epc"}")
                        }
                    }
                }
                GunLink.lastHold = "${GunLink.now()} start=$started, $reads reads, ${seen.size} tags"
                busy = false
            }
        }
    }

    fun stopHold() { holding = false }

    // ---------- touch to read (no trigger) ----------
    @Volatile private var touching = false

    /** Short low-power bursts while the Link tag box is open: only a tag held against the gun answers. */
    fun startTouch(onTag: (Read) -> Unit) {
        if (reader == null || touching) return
        touching = true
        thread(name = "uhf-touch") {
            var last = ""
            var lastAt = 0L
            while (touching) {
                if (holding) { Thread.sleep(200); continue }
                val best = synchronized(lock) {
                    busy = true
                    ensureAlive()
                    setPower(TOUCH_POWER)
                    val reads = burst(300)
                    busy = false
                    reads.filter { it.rssi >= TOUCH_RSSI }.maxByOrNull { it.rssi }
                }
                val now = System.currentTimeMillis()
                if (best != null && touching && (best.epc != last || now - lastAt > 3000)) {
                    last = best.epc; lastAt = now
                    onTag(best)
                }
                Thread.sleep(350) // half on, half off: saves battery while the page waits
            }
        }
    }

    fun stopTouch() { touching = false }

    fun stopAll() { holding = false; touching = false }

    private fun burst(ms: Long): List<Read> {
        val best = HashMap<String, Read>()
        if (!startInventory()) return emptyList()
        val end = System.currentTimeMillis() + ms
        while (System.currentTimeMillis() < end) {
            val t = nextTag()
            if (t == null) { Thread.sleep(10); continue }
            val had = best[t.epc]
            if (had == null || t.rssi > had.rssi) best[t.epc] = t
        }
        stopInventory()
        return best.values.toList()
    }

    // ---------- write ----------
    /** Writes [code] into the one tag held against the gun. Calls back on a worker thread. */
    fun write(code: String, onDone: (ok: Boolean, code: String, oldEpc: String, error: String?) -> Unit) {
        val wanted = code.trim().uppercase(Locale.ROOT)
        if (reader == null) { onDone(false, wanted, "", "This gun app has no UHF writer yet."); return }
        stopTouch() // the page starts touch mode again when it wants it
        thread(name = "uhf-write") {
            synchronized(lock) {
                busy = true
                try {
                    ensureAlive()
                    setPower(WRITE_POWER)
                    val near = burst(600).filter { it.rssi >= TOUCH_RSSI }
                    when {
                        near.isEmpty() -> { onDone(false, wanted, "", "No tag found. Hold the tag flat against the top of the gun and try again."); return@thread }
                        near.size > 1 -> { onDone(false, wanted, "", "${near.size} tags are touching the gun. Keep only this goat's tag close and try again."); return@thread }
                    }
                    val old = near[0].epc
                    val data = encode(wanted)
                    val words = data.length / 4
                    val pc = String.format(Locale.ROOT, "%04X", words shl 11) // PC word: EPC length in words, other bits 0
                    val ok = writeEpc(old, pc, data)
                    val back = burst(600).filter { it.rssi >= TOUCH_RSSI }.maxByOrNull { it.rssi }
                    when {
                        !ok -> onDone(false, wanted, old, "The tag refused the write. It may be locked, or too small for $wanted.")
                        back?.code != wanted -> onDone(false, wanted, old, "The tag did not keep $wanted. Hold it still against the gun and try again.")
                        else -> onDone(true, wanted, old, null)
                    }
                    Log.i(MainActivity.TAG, "UHF write $wanted over $old ok=$ok back=${back?.code}")
                } catch (e: Exception) {
                    onDone(false, wanted, "", e.message ?: "The write failed.")
                } finally {
                    setPower(FULL_POWER)
                    busy = false
                }
            }
        }
    }

    /** Filter on the tag's current EPC (bank 1 from bit 32) so only that tag is written; PC word + EPC from word 1. */
    private fun writeEpc(oldEpc: String, pc: String, data: String): Boolean {
        val r = reader ?: return false
        val m: Method = r.javaClass.methods.firstOrNull { it.name == "writeData" && it.parameterTypes.size == 9 } ?: return false
        val result = runCatching { m.invoke(r, ACCESS_PWD, BANK_EPC, 32, oldEpc.length * 4, oldEpc, BANK_EPC, 1, 1 + data.length / 4, pc + data) }
            .onFailure { Log.w(MainActivity.TAG, "UHF writeData failed: ${it.cause?.message ?: it.message}") }.getOrNull()
        return result as? Boolean ?: false
    }

    companion object {
        const val FULL_POWER = 30 // dBm: reads across a 40×20 ft room
        const val TOUCH_POWER = 5
        const val WRITE_POWER = 10
        const val TOUCH_RSSI = -55.0 // dBm: stronger than this at low power means the tag is against the gun
        private const val ACCESS_PWD = "00000000"
        private const val BANK_EPC = 1
        private val CODE = Regex("^[A-Z0-9._:-]{4,64}$")

        /** Tag ID → EPC hex: ASCII bytes, zero-padded to whole 16-bit words. */
        fun encode(code: String): String {
            val bytes = code.toByteArray(Charsets.US_ASCII)
            val padded = if (bytes.size % 2 == 0) bytes else bytes + 0
            return padded.joinToString("") { String.format(Locale.ROOT, "%02X", it) }
        }

        /** EPC hex → the Tag ID written by KK Farm, or the hex itself for any other tag. */
        fun decode(epc: String): String {
            if (epc.length % 2 != 0 || epc.isEmpty()) return epc
            val bytes = runCatching { ByteArray(epc.length / 2) { i -> epc.substring(i * 2, i * 2 + 2).toInt(16).toByte() } }.getOrNull() ?: return epc
            val text = String(bytes.dropLastWhile { it == 0.toByte() }.toByteArray(), Charsets.US_ASCII)
            return if (CODE.matches(text) && text.any { it.isLetter() } && text.contains('-')) text else epc
        }
    }
}
