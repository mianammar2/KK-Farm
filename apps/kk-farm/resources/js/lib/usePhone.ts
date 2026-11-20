import { useEffect, useState } from 'react';

/** D-367: true on a phone-width screen (under Tailwind's sm, 640px), kept up to date when the window turns or resizes. */
const QUERY = '(max-width: 639px)';

export function usePhone(): boolean {
    const [phone, setPhone] = useState(() => typeof window !== 'undefined' && window.matchMedia(QUERY).matches);
    useEffect(() => {
        const m = window.matchMedia(QUERY);
        const on = () => setPhone(m.matches);
        on();
        m.addEventListener('change', on);
        return () => m.removeEventListener('change', on);
    }, []);
    return phone;
}
