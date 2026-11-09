import { X } from 'lucide-react';
import type { ReactNode } from 'react';

/** D-097: a form opened from a + button: a panel from the right on a computer, from the bottom on a phone or the gun. */
export default function Sheet({ children, onClose }: { children: ReactNode; onClose: () => void }) {
    return (
        <div className="fixed inset-0 z-50 flex items-end justify-end bg-cobalt-950/40 sm:items-stretch" onClick={onClose} role="dialog" aria-modal="true">
            <div className="kk-rise relative max-h-[88dvh] w-full overflow-y-auto rounded-t-3xl bg-glaze p-3 pb-[calc(1rem+env(safe-area-inset-bottom))] shadow-float sm:max-h-none sm:w-[26rem] sm:rounded-none sm:p-4" onClick={(e) => e.stopPropagation()}>
                <button type="button" onClick={onClose} aria-label="Close" className="absolute top-3 right-3 z-10 grid size-10 place-items-center rounded-full bg-tile text-ink-soft shadow-tile hover:text-cobalt-900"><X size={18} aria-hidden /></button>
                {children}
            </div>
        </div>
    );
}
