import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/**
 * RFID gun support for the web app (D-039, keyboard-wedge mode).
 *
 * The Chainway C72 can "type" each tag it reads into the focused field, followed by Enter. The Scan page has its
 * own focused field. On every other screen, a device marked as a scanner keeps a hidden capture field focused
 * (inputmode="none", so no on-screen keyboard), and a read opens /scan?tag=… from anywhere.
 * Desktop users are unaffected: the capture field only exists when this device is marked as a scanner.
 */
const KEY = 'kkfarm.scannerDevice';

export function isScannerDevice(): boolean {
    try {
        return window.localStorage.getItem(KEY) === '1';
    } catch {
        return false;
    }
}

export function setScannerDevice(on: boolean): void {
    try {
        on ? window.localStorage.setItem(KEY, '1') : window.localStorage.removeItem(KEY);
    } catch {
        /* storage blocked: the setting simply does not persist */
    }
    window.dispatchEvent(new Event('kkfarm-scanner-change'));
}

export function useScannerDevice(): [boolean, (on: boolean) => void] {
    const [on, setOn] = useState(false);
    useEffect(() => {
        const sync = () => setOn(isScannerDevice());
        sync();
        window.addEventListener('kkfarm-scanner-change', sync);
        return () => window.removeEventListener('kkfarm-scanner-change', sync);
    }, []);
    return [on, setScannerDevice];
}

const isEditable = (el: Element | null) =>
    !!el && (el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement || el instanceof HTMLSelectElement || (el as HTMLElement).isContentEditable);

/** Hidden capture field rendered once in the app shell. Only active on devices marked as scanners. */
export function ScanCapture({ disabled }: { disabled?: boolean }) {
    const [enabled] = useScannerDevice();
    const ref = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (!enabled || disabled) {
            return;
        }
        const refocus = () => {
            // Never steal focus from a real field the user is typing in.
            if (!isEditable(document.activeElement) || document.activeElement === ref.current) {
                ref.current?.focus({ preventScroll: true });
            }
        };
        refocus();
        const id = window.setInterval(refocus, 800);
        document.addEventListener('click', refocus);
        return () => {
            window.clearInterval(id);
            document.removeEventListener('click', refocus);
        };
    }, [enabled, disabled]);

    if (!enabled || disabled) {
        return null;
    }

    return (
        <form
            aria-hidden
            className="pointer-events-none fixed -top-24 left-0 h-0 w-0 overflow-hidden opacity-0"
            onSubmit={(e) => {
                e.preventDefault();
                const tag = ref.current?.value.trim() ?? '';
                if (ref.current) {
                    ref.current.value = '';
                }
                if (tag.length >= 4) {
                    router.visit(`/scan?tag=${encodeURIComponent(tag)}`);
                }
            }}
        >
            <input ref={ref} tabIndex={-1} inputMode="none" autoComplete="off" autoCapitalize="characters" spellCheck={false} data-scan-capture />
        </form>
    );
}
