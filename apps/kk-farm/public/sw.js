/*
 * D-359: KK Farm keeps working when the internet drops (the gun, and phones with the app).
 *
 * - The app's files (/build/assets, icons) are kept once fetched: they never change under the same name.
 * - Pages (the first load and Inertia's page visits) come from the network; a copy of each is kept, and shown when
 *   the network does not answer within a few seconds. A page never visited offline shows the Apps page instead.
 * - The goats on the farm (/offline/goats) are kept and refreshed every few minutes while online, so a scanned tag
 *   still opens the goat's card (/lookup/goat, /goats/find) without internet.
 * - Saving (POST and the like) is not handled here: the page queues it (resources/js/lib/offline.ts).
 */
const PAGES = 'kk-pages-v1';
const FILES = 'kk-files-v1';
const DATA = 'kk-data-v1';
const WAIT_MS = 4000;

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keep = [PAGES, FILES, DATA];
        for (const name of await caches.keys()) if (!keep.includes(name)) await caches.delete(name);
        await self.clients.claim();
    })());
});

/** The network, but no longer than `ms`: a slow farm connection falls back to the copy instead of hanging. */
function network(request, ms) {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('slow')), ms);
        fetch(request).then((r) => { clearTimeout(timer); resolve(r); }, (e) => { clearTimeout(timer); reject(e); });
    });
}

/** One key per page and kind (full HTML or Inertia's JSON), without the cache-busting parts of the request. */
function pageKey(request) {
    const url = new URL(request.url);
    return `${url.pathname}${url.search}#${request.headers.get('X-Inertia') ? 'inertia' : 'html'}`;
}

async function page(request) {
    const cache = await caches.open(PAGES);
    const key = pageKey(request);
    try {
        const response = await network(request, WAIT_MS);
        // keep only ordinary pages: not partial reloads, not redirects to the sign-in page
        if (response.ok && !request.headers.get('X-Inertia-Partial-Data') && !response.redirected) await cache.put(key, response.clone());
        return response;
    } catch {
        const url = new URL(request.url);
        if (url.pathname === '/scan' && url.searchParams.get('tag') && request.mode === 'navigate') return scanCard(url.searchParams.get('tag'));
        const kept = (await cache.match(key)) ?? (await cache.match(`/apps#${request.headers.get('X-Inertia') ? 'inertia' : 'html'}`));
        if (kept) {
            const headers = new Headers(kept.headers);
            headers.set('X-KK-Offline', '1');
            return new Response(await kept.blob(), { status: 200, headers });
        }
        return new Response('<!doctype html><meta name="viewport" content="width=device-width"><body style="font-family:sans-serif;padding:24px;background:#0f3d2a;color:#fff"><h2>No internet</h2><p>This page was not opened before, so there is no copy on this device. Go back, or try again when the internet is back.</p><button onclick="location.reload()" style="padding:12px 20px;font-size:16px">Try again</button></body>',
            { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
    }
}

async function file(request) {
    const cache = await caches.open(FILES);
    const kept = await cache.match(request);
    if (kept) return kept;
    const response = await fetch(request);
    if (response.ok) await cache.put(request, response.clone());
    return response;
}

// ---------- goats kept for scanning without internet ----------
async function goats() {
    const kept = await (await caches.open(DATA)).match('/offline/goats');
    return kept ? kept.json() : null;
}

async function refreshGoats() {
    try {
        const response = await fetch('/offline/goats', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (response.ok) await (await caches.open(DATA)).put('/offline/goats', response);
    } catch { /* offline: keep the last list */ }
}

async function findGoat(code) {
    const list = await goats();
    const c = String(code ?? '').trim().toUpperCase();
    return list?.goats?.find((g) => g.codes.includes(c)) ? { goat: list.goats.find((g) => g.codes.includes(c)), at: list.at } : null;
}

async function lookup(request, kind) {
    try {
        return await network(request, WAIT_MS);
    } catch {
        const code = new URL(request.url).searchParams.get('code');
        const hit = await findGoat(code);
        if (!hit) return new Response(JSON.stringify({ message: 'No internet, and no goat with this tag among the goats kept on this device.' }), { status: 404, headers: { 'Content-Type': 'application/json' } });
        const body = kind === 'find' ? { id: hit.goat.profile.id, number: hit.goat.profile.number } : { ...hit.goat.profile, offline: hit.at };
        return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json', 'X-KK-Offline': '1' } });
    }
}

const esc = (v) => String(v ?? '—').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

/** A scan from the gun with no internet: the goat's card from the kept goats, as a page of its own. */
async function scanCard(tag) {
    const hit = await findGoat(tag);
    const p = hit?.goat.profile;
    const row = (label, value) => `<div style="padding:8px 0;border-bottom:1px solid #dbe6de"><div style="font-size:12px;color:#5d7266">${label}</div><div style="font-size:16px;color:#022a1c">${esc(value)}</div></div>`;
    const body = p
        ? `<h1 style="margin:0 0 4px;font-size:22px">${esc(p.number)}${p.name ? ` · ${esc(p.name)}` : ''}</h1>
           <p style="margin:0 0 12px;color:#5d7266">No internet: the goat as kept on this device on ${esc(hit.at)}.</p>
           ${row('PEN', p.pen)}${row('Sex', p.sex === 'female' ? 'Female' : 'Male')}${row('Breed', p.breed)}${row('Age', p.age)}${row('Health', p.health)}${row('Latest weight', p.growth?.latestKg != null ? `${p.growth.latestKg} kg (${p.growth.latestOn})` : null)}${row('RFID tag', p.rfid)}`
        : `<h1 style="margin:0 0 8px;font-size:22px">Tag ${esc(tag)}</h1><p>No internet, and no goat with this tag among the goats kept on this device. Scan again when the internet is back.</p>`;
    return new Response(`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Scan · KK Farms</title>
        <body style="margin:0;font-family:system-ui,sans-serif;background:#f6faf7"><div style="background:#0f3d2a;color:#fff;padding:14px 16px;font-weight:600">KK Farms · Scan (offline)</div>
        <div style="padding:16px">${body}<p style="margin-top:16px"><a href="javascript:history.back()" style="color:#0f3d2a">Back</a></p></div></body>`,
        { status: 200, headers: { 'Content-Type': 'text/html; charset=utf-8', 'X-KK-Offline': '1' } });
}

self.addEventListener('message', (event) => {
    if (event.data === 'refresh-goats') event.waitUntil(refreshGoats());
    if (event.data === 'forget') event.waitUntil(Promise.all([PAGES, DATA].map((n) => caches.delete(n)))); // signed out
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/') || url.pathname.startsWith('/brand/')) { event.respondWith(file(request)); return; }
    if (url.pathname === '/lookup/goat') { event.respondWith(lookup(request, 'lookup')); return; }
    if (url.pathname === '/goats/find') { event.respondWith(lookup(request, 'find')); return; }
    // pages: the browser's own page loads and Inertia's visits; not downloads, images, the API or sign-in
    const isPage = request.mode === 'navigate' || request.headers.get('X-Inertia');
    if (!isPage || url.pathname.startsWith('/api/') || url.pathname.startsWith('/downloads/') || url.pathname === '/login' || url.pathname === '/logout') return;
    event.respondWith(page(request));
});
