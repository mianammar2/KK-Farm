import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * D-170: a page's tab follows ?tab= in the address, so an app's menu (sidebar) can open a section directly, a link
 * from another app can land on the right section, and the address shows where you are (shareable, back button).
 */
export function useTabParam(fallback: string): [string, (t: string) => void] {
    const { url } = usePage();
    const read = () => new URLSearchParams(url.split('?')[1] ?? '').get('tab');
    const [tab, setTabState] = useState(() => read() ?? fallback);
    useEffect(() => { const t = read(); if (t && t !== tab) setTabState(t); }, [url]); // eslint-disable-line react-hooks/exhaustive-deps
    const setTab = (t: string) => {
        setTabState(t);
        try {
            const u = new URL(window.location.href);
            u.searchParams.set('tab', t);
            window.history.replaceState(window.history.state, '', u.toString());
        } catch { /* no address bar in some previews */ }
    };
    return [tab, setTab];
}
