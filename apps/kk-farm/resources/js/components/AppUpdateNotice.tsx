import { usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';
import { native } from '../lib/native';
import type { SharedProps } from '../types';

const key = (code: number) => `kk-app-update-later-${code}`;

/**
 * D-330: inside the Android app, a newer version on the server is announced on every page at once, not only at the
 * app's own check (on start, at most every 6 hours). Update now downloads it, checks it and asks to Install; Later hides
 * the notice until the app is opened again. Browsers and the iPhone home-screen app never see it (their pages are
 * always current).
 */
export default function AppUpdateNotice() {
    const app = usePage<SharedProps>().props.androidApp;
    const n = native();
    const mine = n?.versionCode?.() ?? 0;
    const [later, setLater] = useState(() => {
        try { return app ? sessionStorage.getItem(key(app.code)) === '1' : false; } catch { return false; }
    });
    if (!app || !n?.checkForUpdate || !mine || app.code <= mine || later) return null;
    const hide = () => {
        try { sessionStorage.setItem(key(app.code), '1'); } catch { /* private mode */ }
        setLater(true);
    };
    return (
        <div role="status" className="mb-3 flex flex-wrap items-center gap-3 rounded-tile border border-watch-400 bg-watch-100 px-3 py-2.5 text-sm">
            <Download size={18} className="shrink-0 text-cobalt-800" aria-hidden />
            <span className="min-w-0 flex-1">
                <span className="block font-semibold text-cobalt-900">Update the KK Farms app to {app.name}</span>
                <span className="block text-ink-soft">{app.notes || 'A newer version is ready.'} Your sign-in and settings stay.</span>
            </span>
            <span className="flex gap-2">
                <button type="button" onClick={hide} className="rounded-md px-3 py-1.5 font-semibold text-ink-soft hover:bg-white">Later</button>
                <button type="button" onClick={() => n.checkForUpdate?.()} className="rounded-md bg-cobalt-800 px-3 py-1.5 font-semibold text-white hover:bg-cobalt-900">Update now</button>
            </span>
        </div>
    );
}
