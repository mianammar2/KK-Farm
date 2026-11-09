import { router } from '@inertiajs/react';
import { Radio, ScanLine } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useConfirm } from './Confirm';
import { EarTag } from './FarmScene';
import Sheet from './Sheet';
import { Button, Field } from './ui';

export type TagGoat = { id: string; number: string; name: string | null; tag?: string | null };

/**
 * D-144: attach an RFID tag to a goat from the registry. Opening the panel arms the attach on the server, so the
 * gun's next scan (which opens the Scan page) is offered to this goat. A code can also be typed or typed by the gun
 * into the field. A tag already on another goat is moved only after the user confirms.
 */
export function RfidAttachSheet({ goat, onClose }: { goat: TagGoat; onClose: () => void }) {
    const [code, setCode] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [ask, confirmBox] = useConfirm();
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => {
        router.post(`/goats/${goat.id}/rfid/arm`, {}, { preserveState: true, preserveScroll: true, only: [] });
        input.current?.focus();
    }, [goat.id]);

    // Closing the panel keeps the attach armed for its 3 minutes: on the gun, the scan itself may close it.
    const close = onClose;

    const send = (tag: string, move = false) => {
        setBusy(true);
        setError(null);
        router.post(`/goats/${goat.id}/rfid`, { tag, move }, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: async (e) => {
                if (e.holder) {
                    if (await ask({ title: `Move this tag to ${goat.number}?`, message: `${tag} is on ${e.holder}. It will be taken off ${e.holder} (kept in its history) and put on ${goat.number}.`, confirm: 'Yes, move tag', danger: false })) {
                        send(tag, true);
                        return;
                    }
                }
                setError(e.tag ?? 'Could not attach the tag.');
            },
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Sheet onClose={close}>
            {confirmBox}
            <div className="space-y-4 rounded-tile bg-tile p-4 shadow-tile">
                <div className="pr-10">
                    <h2 className="text-lg font-semibold text-cobalt-900">{goat.tag ? 'Replace RFID Tag' : 'Attach RFID Tag'}</h2>
                    <p className="mt-0.5 text-sm text-ink-soft">The RFID tag will open this goat when it is scanned.</p>
                </div>
                <div className="flex items-center gap-3"><EarTag number={goat.number} />{goat.name ? <span className="font-semibold text-cobalt-900">{goat.name}</span> : null}</div>
                {goat.tag ? <p className="rounded-tile bg-watch-100 px-3 py-2 text-sm text-watch-700">Now: <span className="font-mono font-semibold">{goat.tag}</span>. A new scan replaces it; the old tag stays in this goat's history.</p> : null}
                <div className="flex items-center gap-3 rounded-tile border-2 border-dashed border-turq-600 bg-turq-100 p-3">
                    <span className="relative grid size-12 shrink-0 place-items-center rounded-full bg-turq-700 text-white"><Radio size={22} aria-hidden /><span className="kk-ring absolute inset-0 rounded-full border-2 border-turq-600" aria-hidden /></span>
                    <p className="text-sm text-cobalt-900"><span className="font-semibold">Pull the trigger on the RFID gun now.</span> The scan opens a page that asks to attach it to {goat.number}. This waits for 3 minutes.</p>
                </div>
                <form onSubmit={(e) => { e.preventDefault(); if (code.trim()) send(code.trim()); }} className="space-y-2">
                    <Field label="Or type the tag code" error={error ?? undefined}>
                        <input ref={input} className="field font-mono uppercase" value={code} onChange={(e) => setCode(e.target.value)} autoComplete="off" autoCapitalize="characters" spellCheck={false} placeholder="E28011700000020C…" />
                    </Field>
                    <Button type="submit" className="w-full" disabled={busy || !code.trim()}><ScanLine size={16} className="mr-1 inline" aria-hidden />{goat.tag ? 'Replace Tag' : 'Attach Tag'}</Button>
                </form>
            </div>
        </Sheet>
    );
}

/** D-144: the small RFID button next to a goat's tag number in the registry. */
export function RfidButton({ goat, onOpen }: { goat: TagGoat; onOpen: () => void }) {
    return (
        <button type="button" onClick={(e) => { e.preventDefault(); e.stopPropagation(); onOpen(); }}
            title={goat.tag ? `RFID ${goat.tag}: tap to replace` : 'No RFID tag: tap to scan one'}
            className={`inline-flex shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-bold ${goat.tag ? 'border-ok-700/40 bg-ok-100 text-ok-700' : 'border-turq-600 bg-turq-100 text-turq-800 hover:bg-turq-400/40'}`}>
            <Radio size={12} aria-hidden />{goat.tag ? 'RFID' : 'Scan RFID'}
        </button>
    );
}

/** D-144: on the Scan page, the scan that follows "Scan RFID": attach it to that goat, in one tap. */
export function AttachPrompt({ attach }: { attach: { code: string; goat: TagGoat; current: string | null; holder: string | null; same: boolean } }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const go = () => {
        setBusy(true);
        router.post(`/goats/${attach.goat.id}/rfid`, { tag: attach.code, move: !!attach.holder, from: 'scan' }, { onError: (e) => setError(e.tag ?? 'Could not attach the tag.'), onFinish: () => setBusy(false) });
    };
    const cancel = () => router.post('/goats/rfid/disarm', {}, { onSuccess: () => router.visit('/goats') });
    return (
        <section className="kk-rise space-y-3 rounded-tile border-2 border-turq-600 bg-tile p-4 shadow-tile">
            <h2 className="text-lg font-semibold text-cobalt-900">{attach.same ? 'Already on this goat' : `Attach this tag to ${attach.goat.number}?`}</h2>
            <div className="flex flex-wrap items-center gap-3"><span className="rounded-tile bg-glaze px-2 py-1 font-mono text-sm font-semibold">{attach.code}</span><span aria-hidden>→</span><EarTag number={attach.goat.number} />{attach.goat.name ? <span className="font-semibold">{attach.goat.name}</span> : null}</div>
            {attach.holder ? <p className="rounded-tile bg-watch-100 px-3 py-2 text-sm text-watch-700">This tag is on <span className="font-semibold">{attach.holder}</span> now. It will be taken off {attach.holder} (kept in its history).</p> : null}
            {attach.current && !attach.same ? <p className="text-sm text-ink-soft">{attach.goat.number} now has <span className="font-mono">{attach.current}</span>; it will be replaced and kept in its history.</p> : null}
            {error ? <p className="text-sm font-semibold text-crit-700" role="alert">{error}</p> : null}
            <div className="flex flex-wrap gap-2">
                {attach.same ? <Button type="button" onClick={() => router.visit(`/goats/${attach.goat.id}`)}>Open {attach.goat.number}</Button> : <Button type="button" onClick={go} disabled={busy}>{attach.holder ? 'Yes, Move Tag Here' : 'Yes, Attach Tag'}</Button>}
                <Button type="button" variant="secondary" onClick={cancel}>Cancel</Button>
            </div>
        </section>
    );
}
