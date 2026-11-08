import { inScannerApp } from './native';

/**
 * D-088: how often live screens refresh. The RFID gun has a slow processor and every refresh re-draws the page, so
 * it refreshes every 30 s; phones and computers every 10 s. Inertia also slows polling while the tab is hidden.
 */
export const LIVE_MS = inScannerApp() ? 30000 : 10000;
