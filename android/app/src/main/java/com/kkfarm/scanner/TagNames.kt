package com.kkfarm.scanner

import android.util.Log
import android.webkit.CookieManager
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import kotlin.concurrent.thread

/**
 * D-362: which goat each tag belongs to, so lists of read tags show the goats' Tag IDs instead of factory numbers.
 * The farm's goats (/offline/goats, the same list the pages keep for working offline) are fetched with the signed-in
 * session when the app opens and every few minutes; the last list stays when there is no internet.
 */
object TagNames {
    @Volatile private var names: Map<String, String> = emptyMap()
    @Volatile private var fetchedAt = 0L
    private const val EVERY_MS = 5 * 60 * 1000L

    /** The goat's Tag ID for a read code (factory number or Tag ID), or null for a tag not linked to a goat. */
    fun goat(code: String): String? = names[code.trim().uppercase()]

    fun refresh(server: String, force: Boolean = false) {
        if (!force && System.currentTimeMillis() - fetchedAt < EVERY_MS) return
        fetchedAt = System.currentTimeMillis()
        thread(name = "tag-names") {
            runCatching {
                val cookies = CookieManager.getInstance().getCookie(server) ?: return@thread
                val c = URL("$server/offline/goats").openConnection() as HttpURLConnection
                c.connectTimeout = 10_000; c.readTimeout = 30_000; c.instanceFollowRedirects = false
                c.setRequestProperty("Accept", "application/json")
                c.setRequestProperty("Cookie", cookies)
                c.setRequestProperty("X-Requested-With", "XMLHttpRequest")
                if (c.responseCode != 200) { c.disconnect(); fetchedAt = 0; return@thread }
                val goats = JSONObject(c.inputStream.bufferedReader().use { it.readText() }).getJSONArray("goats")
                c.disconnect()
                val map = HashMap<String, String>()
                for (i in 0 until goats.length()) {
                    val g = goats.getJSONObject(i)
                    val number = g.getJSONObject("profile").optString("number")
                    val codes = g.getJSONArray("codes")
                    for (k in 0 until codes.length()) map[codes.getString(k).uppercase()] = number
                }
                names = map
                Log.i(MainActivity.TAG, "tag names: ${map.size} codes")
            }.onFailure { fetchedAt = 0; Log.i(MainActivity.TAG, "tag names not fetched: ${it.message}") }
        }
    }
}
