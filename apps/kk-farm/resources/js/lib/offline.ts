import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/*
 * D-359: working without internet.
 *
 * The service worker (public/sw.js) keeps the app's files, the pages already opened and the farm's goats, so pages
 * and scans still open offline. This file registers it, refreshes the kept goats every few minutes, and queues what
 * is saved while offline: each save (a form sent with POST, PUT, PATCH or DELETE) waits on this device and is sent,
 * in order, as soon as the internet is back. A save with a photo or file needs the internet and is not queued.
 */

type Queued = { id: string; at: string; method: string; url: string; data: Record<string, unknown>; label: string; error?: string };

const KEY = 'kk-offline-queue';
const GOATS_EVERY_MS = 5 * 60 * 1000;
let sending = false;

function read(): Queued[] {
    try { return JSON.parse(localStorage.getItem(KEY) ?? '[]') as Queued[]; } catch { return []; }
}
function write(list: Queued[]) {
    try { localStorage.setItem(KEY, JSON.stringify(list)); } catch { /* storage full or blocked: nothing more to do */ }
    window.dispatchEvent(new Event('kk:queue'));
}

const stamp = () => {
    const d = new Date();
    const p = (n: number) => String(n).padStart(2, '0');
    return `${p(d.getDate())}/${p(d.getMonth() + 1)}/${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}`;
};

const hasFile = (v: unknown): boolean => v instanceof Blob || (Array.isArray(v) ? v.some(hasFile) : !!v && typeof v === 'object' && Object.values(v).some(hasFile));

function xsrf(): string {
    const m = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
    return m ? decodeURIComponent(m.slice('XSRF-TOKEN='.length)) : '';
}

/** Sends the waiting saves in order; stops at the first that cannot go (still offline), keeps one the server refused. */
export async function sendQueued(): Promise<void> {
    if (sending || !navigator.onLine) return;
    sending = true;
    try {
        for (;;) {
            const list = read();
            const next = list.find((q) => !q.error);
            if (!next) break;
            let response: Response;
            try {
                response = await fetch(next.url, {
                    method: next.method.toUpperCase(),
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'text/html, application/xhtml+xml', 'X-Requested-With': 'XMLHttpRequest', 'X-Inertia': 'true', 'X-XSRF-TOKEN': xsrf(), 'X-KK-Queued': next.id },
                    body: JSON.stringify(next.data),
                });
            } catch {
                break; // the network went again: try later
            }
            let error: string | undefined;
            if (response.status === 419) error = 'The sign-in ran out. Sign in again; this save is kept.';
            else if (response.status >= 400) error = `The server refused it (${response.status}).`;
            else {
                const body = await response.json().catch(() => null) as { props?: { errors?: Record<string, string> } } | null;
                const errors = body?.props?.errors ? Object.values(body.props.errors) : [];
                if (errors.length) error = errors.join(' ');
            }
            if (error && response.status === 419) break;
            write(read().map((q) => (q.id === next.id ? { ...q, error } : q)).filter((q) => q.id !== next.id || !!error));
        }
    } finally {
        sending = false;
        if (!read().some((q) => !q.error)) router.reload();
    }
}

export function discardQueued(id: string) { write(read().filter((q) => q.id !== id)); }

/** Starts offline support once, when the app loads. */
export function startOffline(): void {
    if (!('serviceWorker' in navigator) || location.protocol !== 'https:') return;
    navigator.serviceWorker.register('/sw.js').catch(() => undefined);
    const refresh = () => { if (navigator.onLine) navigator.serviceWorker.controller?.postMessage('refresh-goats'); };
    navigator.serviceWorker.ready.then(() => window.setTimeout(refresh, 3000)).catch(() => undefined);
    window.setInterval(refresh, GOATS_EVERY_MS);
    window.addEventListener('online', () => { refresh(); void sendQueued(); });
    window.setInterval(() => { void sendQueued(); }, 30_000);
    void sendQueued();

    // a save while offline waits here instead of failing: when the device knows it is offline, before sending; when
    // Wi-Fi is connected but the internet is not (common on the farm), after the send fails
    type Visit = { method: string; url: URL | string; data?: unknown };
    let last: Visit | null = null;
    const queue = (visit: Visit): boolean => {
        const data = (visit.data ?? {}) as Record<string, unknown>;
        if (visit.method === 'get' || hasFile(data)) return false; // a photo or file needs the internet: it fails and says so
        const url = typeof visit.url === 'string' ? visit.url : visit.url.toString();
        const label = document.title.replace(/ · KK Farms$/, '');
        write([...read(), { id: crypto.randomUUID?.() ?? String(Date.now()), at: stamp(), method: visit.method, url, data, label }]);
        window.dispatchEvent(new CustomEvent('kk:queued', { detail: label }));
        return true;
    };
    router.on('before', (event) => {
        const visit = event.detail.visit as unknown as Visit;
        last = visit;
        if (!navigator.onLine && queue(visit)) return false;
    });
    router.on('networkError', () => {
        const visit = last;
        last = null;
        if (visit && queue(visit)) return false;
    });
    // signing out: forget the pages and goats kept for this person
    router.on('success', (event) => {
        if (event.detail.page.component === 'Login') navigator.serviceWorker.controller?.postMessage('forget');
    });
}

/** Online or not, and the saves waiting to be sent, for the bar at the top of every page. */
export function useOffline(): { online: boolean; queued: Queued[] } {
    const [online, setOnline] = useState(typeof navigator === 'undefined' ? true : navigator.onLine);
    const [queued, setQueued] = useState<Queued[]>(() => (typeof window === 'undefined' ? [] : read()));
    useEffect(() => {
        const on = () => setOnline(navigator.onLine);
        const q = () => setQueued(read());
        window.addEventListener('online', on); window.addEventListener('offline', on); window.addEventListener('kk:queue', q);
        return () => { window.removeEventListener('online', on); window.removeEventListener('offline', on); window.removeEventListener('kk:queue', q); };
    }, []);
    return { online, queued };
}
