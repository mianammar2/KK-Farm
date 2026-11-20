import { X } from 'lucide-react';
import { useEffect, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

/**
 * D-097/D-306: a form or card opened from a button or a scan: a pop card in the middle of the screen, above
 * everything (drawn on the page body, so no part of the page can box it in). Escape or the background closes it.
 */
export default function Sheet({ children, onClose, title }: { children: ReactNode; onClose: () => void; title?: string }) {
    useEffect(() => {
        const key = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
        window.addEventListener('keydown', key);
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden'; // the page behind does not scroll
        return () => { window.removeEventListener('keydown', key); document.body.style.overflow = overflow; };
    }, [onClose]);
    if (typeof document === 'undefined') return null;
    return createPortal(
        <div className="fixed inset-0 z-50 grid place-items-center bg-cobalt-950/45 p-3 sm:p-6" onClick={onClose} role="dialog" aria-modal="true">
            <div className="kk-rise relative max-h-[92dvh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-glaze p-4 pb-[calc(1rem+env(safe-area-inset-bottom))] shadow-2xl sm:p-5" onClick={(e) => e.stopPropagation()}>
                {/* audit: Close stays in reach while a long card scrolls */}
                <div className="sticky top-0 z-10 flex h-0 justify-end"><button type="button" onClick={onClose} aria-label="Close" className="-me-1 -mt-1 grid size-10 place-items-center rounded-full bg-tile text-ink-soft shadow-tile hover:text-cobalt-900"><X size={18} aria-hidden /></button></div>
                {title ? <h2 className="mb-3 pe-10 text-lg text-cobalt-900">{title}</h2> : null}{/* D-359: a heading for a form that has none of its own */}
                {children}
            </div>
        </div>,
        document.body,
    );
}
