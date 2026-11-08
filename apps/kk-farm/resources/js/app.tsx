import { createInertiaApp } from '@inertiajs/react';
import { MotionConfig } from 'motion/react';
import type { ComponentType, ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import AppShell from './components/AppShell';
import { inScannerApp } from './lib/native';

// Lite mode on the RFID gun (D-050): its graphics chip is modest, so no blur, light shadows, and framer-motion
// animations reduced to quick fades. Phones and desktop keep the full effects.
const LITE = inScannerApp();
if (LITE) document.documentElement.classList.add('lite');

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
    progress: { color: '#c9a23f', delay: 120 },
});
