import { Head } from '@inertiajs/react';
import { Download, RefreshCw, Smartphone } from 'lucide-react';
import { useState } from 'react';
import { Button, Note, PageHeader, Panel } from '../../components/ui';
import { inScannerApp, installedWebApp, native, onIPhone } from '../../lib/native';

/**
 * D-090: the app on each device and how it stays up to date.
 *  - RFID gun / Android app: the app checks the server, downloads and verifies a new build, and asks to install it.
 *  - iPhone: KK Farms is installed from Safari (Add to Home Screen); it loads the newest version every time.
 *  - The farm screens themselves come from the server, so every device always shows the latest ones.
 */
export default function AppUpdates({ android }: { android: { versionName: string; versionCode: number; notes: string } | null }) {
    const n = native();
    const inApp = inScannerApp();
    const [auto, setAuto] = useState(() => n?.isAutoUpdate?.() ?? true);
    const mine = n?.versionCode?.() ?? 0;
    const newer = inApp && android && android.versionCode > mine;

    return (
        <>
            <Head title="App and updates" />
            <PageHeader title="App and updates" description="KK Farms on phones, the RFID gun and computers. The farm screens always come from the server, so they are always the newest." />
            <div className="grid max-w-3xl gap-4">
                {inApp ? (
                    <Panel title="This app (Android)" description={`Version ${n?.version?.() ?? '?'}${android ? ` · newest ${android.versionName}` : ''}`}>
                        {newer ? <div className="mb-3"><Note>Version {android!.versionName} is ready{android!.notes ? `: ${android!.notes}` : '.'}</Note></div> : null}
                        <label className="flex items-center justify-between gap-3 rounded-tile bg-glaze px-3 py-3">
                            <span><span className="block font-semibold text-cobalt-900">Update automatically</span><span className="block text-sm text-ink-soft">Downloads new versions by itself and asks you to tap Install.</span></span>
                            <input type="checkbox" className="size-6 accent-turq-700" checked={auto} onChange={(e) => { setAuto(e.target.checked); n?.setAutoUpdate?.(e.target.checked); }} />
                        </label>
                        <Button className="mt-3 w-full" onClick={() => n?.checkForUpdate?.()}><RefreshCw size={16} aria-hidden /> {newer ? `Update to ${android!.versionName}` : 'Check for updates'}</Button>
                        <p className="mt-2 text-xs text-ink-soft">The first time, Android asks to allow KK Farms to install updates. Your sign-in and settings stay after an update.</p>
                    </Panel>
                ) : null}

                {!inApp ? (
                    <Panel title="iPhone" description={installedWebApp() && onIPhone() ? 'Installed on this iPhone' : 'Install KK Farms on an iPhone'}>
                        {installedWebApp() && onIPhone() ? (
                            <p className="text-sm">KK Farms is on your home screen and updates itself: each time it opens it loads the newest version. Nothing to install.</p>
                        ) : (
                            <ol className="list-decimal space-y-1.5 pl-5 text-sm">
                                <li>Open <span className="font-mono">{typeof window !== 'undefined' ? window.location.host : ''}</span> in <strong>Safari</strong> on the iPhone and sign in.</li>
                                <li>Tap the <strong>Share</strong> button (square with an arrow).</li>
                                <li>Tap <strong>Add to Home Screen</strong>, then <strong>Add</strong>.</li>
                                <li>Open KK Farms from the home screen: full screen with its own icon, and always up to date.</li>
                            </ol>
                        )}
                    </Panel>
                ) : null}

                {!inApp ? (
                    <Panel title="Android phone or RFID gun" description={android ? `Newest version ${android.versionName}` : undefined}>
                        <p className="text-sm">Install the KK Farms app once; after that it updates itself.</p>
                        <a href="/app" className="mt-3 inline-flex"><Button><Download size={16} aria-hidden /> Download for Android</Button></a>
                    </Panel>
                ) : null}

                <Panel title="Computer">
                    <p className="flex items-center gap-2 text-sm"><Smartphone size={16} className="text-ink-soft" aria-hidden /> Open the same address in any browser. A new version shows after a refresh, or by itself when you come back to the page.</p>
                </Panel>
            </div>
        </>
    );
}
