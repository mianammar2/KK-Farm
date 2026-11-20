import { createInertiaApp, router } from '@inertiajs/react';
import { MotionConfig } from 'motion/react';
import type { ComponentType, ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import AppShell from './components/AppShell';
import { inScannerApp } from './lib/native';
import { startOffline } from './lib/offline';
import { startCardTables } from './lib/cardTables';

// Lite mode on the RFID gun (D-050): its graphics chip is modest, so no blur, light shadows, and framer-motion
// animations reduced to quick fades. Phones and desktop keep the full effects.
const LITE = inScannerApp();
if (LITE) document.documentElement.classList.add('lite');

// D-090: an installed app (iPhone home screen, the gun) can sit in the background for days. When it comes back after
// 5 minutes away it reloads its data; if a new version was deployed meanwhile, Inertia sees the changed asset version
// and loads the new app by itself. So the app is always up to date without anyone updating it.
let hiddenAt = 0;
document.addEventListener('visibilitychange', () => {
    if (document.hidden) hiddenAt = Date.now();
    else if (hiddenAt && Date.now() - hiddenAt > 5 * 60 * 1000) router.reload();
});

// D-357: on the gun a page that focuses a field on open would pop the on-screen keyboard over it, though the
// trigger does the scanning. Fields focused by a page itself lose focus there; a field the person taps still opens it.
if (LITE) {
    router.on('navigate', () => {
        window.setTimeout(() => {
            const el = document.activeElement;
            if (el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement) el.blur();
        }, 0);
    });
}

startOffline(); // D-359: pages, scans and saves keep working without internet
startCardTables(); // D-368: wide tables read as cards on a phone

type PageModule = { default: ComponentType & { layout?: unknown } };

const pages = import.meta.glob<PageModule>('./Pages/**/*.tsx');

createInertiaApp({
    title: (title) => (title ? `${title} · KK Farms` : 'KK Farms'),
    resolve: async (name) => {
        const page = await pages[`./Pages/${name}.tsx`]();
        if (name !== 'Login' && page.default.layout === undefined) {
            page.default.layout = (content: ReactNode) => <AppShell>{content}</AppShell>;
        }
        return page as never;
    },
    setup({ el, App, props }) {
        createRoot(el as HTMLElement).render(
            <MotionConfig reducedMotion={LITE ? 'always' : 'user'}>
                <App {...props} />
            </MotionConfig>,
        );
    },
    progress: LITE ? false : { color: '#e0a526', delay: 120 }, // D-357: no web-style loading bar on the gun
});
