import { AlertTriangle } from 'lucide-react';
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { titleCase } from '../lib/titleCase';

type Ask = { title: string; message?: string; confirm?: string; danger?: boolean };

/**
 * D-132: the app's own "Are you sure?" box. The browser's confirm() does not show reliably inside the Android and
 * iPhone apps, so anything that deletes or cannot be undone asks with this instead.
 * Usage: const [ask, confirmBox] = useConfirm(); … if (await ask({ title: 'Delete X?' })) doIt(); … render {confirmBox}.
 */
export function useConfirm(): [(a: Ask) => Promise<boolean>, ReactNode] {
    const [pending, setPending] = useState<(Ask & { resolve: (ok: boolean) => void }) | null>(null);
    const ask = useCallback((a: Ask) => new Promise<boolean>((resolve) => setPending({ ...a, resolve })), []);
    const done = (ok: boolean) => { pending?.resolve(ok); setPending(null); };
    // Drawn on the page itself, not inside a panel that moves (a side panel), so it is always in the middle of the screen.
    return [ask, pending ? createPortal(<ConfirmBox ask={pending} onAnswer={done} />, document.body) : null];
}

function ConfirmBox({ ask, onAnswer }: { ask: Ask; onAnswer: (ok: boolean) => void }) {
    const cancel = useRef<HTMLButtonElement>(null);
    const danger = ask.danger ?? true;
    useEffect(() => {
        cancel.current?.focus(); // the safe choice has focus, so Enter does not delete by accident
        const key = (e: KeyboardEvent) => { if (e.key === 'Escape') onAnswer(false); };
        window.addEventListener('keydown', key);
        return () => window.removeEventListener('keydown', key);
    }, [onAnswer]);

    return (
        <div className="fixed inset-0 z-[60] grid place-items-center bg-cobalt-950/50 p-4" role="alertdialog" aria-modal="true" aria-labelledby="kk-confirm-title" onClick={() => onAnswer(false)}>
            <div className="kk-rise w-full max-w-sm rounded-tile bg-tile p-5 shadow-float" onClick={(e) => e.stopPropagation()}>
                <div className="flex items-start gap-3">
                    <span className={`grid size-10 shrink-0 place-items-center rounded-full ${danger ? 'bg-crit-100 text-crit-700' : 'bg-watch-100 text-watch-700'}`}><AlertTriangle size={20} aria-hidden /></span>
                    <div>
                        <h2 id="kk-confirm-title" className="text-lg font-semibold text-cobalt-900">{ask.title}</h2>
                        {ask.message ? <p className="mt-1 text-sm text-ink-soft">{ask.message}</p> : null}
                    </div>
                </div>
                <div className="mt-5 flex justify-end gap-2">
                    <button ref={cancel} type="button" onClick={() => onAnswer(false)} className="min-h-11 rounded-full border border-rule bg-tile px-5 text-sm font-semibold text-cobalt-900 hover:border-turq-600">Cancel</button>
                    <button type="button" onClick={() => onAnswer(true)} className={`min-h-11 rounded-full px-5 text-sm font-semibold text-white ${danger ? 'bg-crit-700 hover:brightness-90' : 'bg-cobalt-800 hover:bg-cobalt-900'}`}>{titleCase(ask.confirm ?? 'Yes, delete')}</button>
                </div>
            </div>
        </div>
    );
}
