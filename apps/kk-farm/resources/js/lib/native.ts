/** Bridge to the KK Farm Android app (MainActivity.Bridge). Every call is a no-op in a normal browser. */
type Native = { vibrate?: (ms: number) => void; isScannerApp?: () => boolean; openSettings?: () => void };

const native = (): Native | undefined => (typeof window !== 'undefined' ? (window as unknown as { KKNative?: Native }).KKNative : undefined);

export const inScannerApp = (): boolean => typeof navigator !== 'undefined' && navigator.userAgent.includes('KKFarmScanner');

/** A short vibration on the gun, like a native app confirming a scan. Falls back to the web Vibration API. */
export function haptic(ms = 35): void {
    try {
        const n = native();
        if (n?.vibrate) {
            n.vibrate(ms);
        } else if (typeof navigator !== 'undefined' && 'vibrate' in navigator) {
            navigator.vibrate(ms);
        }
    } catch {
        /* haptics are a nicety */
    }
}
