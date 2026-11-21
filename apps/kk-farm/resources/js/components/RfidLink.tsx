import { router, useForm, usePage } from '@inertiajs/react';
import { ScanLine } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { native } from '../lib/native';
import { Button, Chip, Field, Panel } from './ui';

/**
 * D-336: link an RFID tag to a goat on its own page. Click in the box and pull the gun's trigger (or type the chip code):
 * the read is linked, and every scanner in the apps then opens this goat. The old tag, if any, is closed in the tag history.
 */
export default function RfidLink({ goatId, number, current, canAssign, className }: { goatId: string; number: string; current: string | null | undefined; canAssign: boolean; className?: string }) {
    const form = useForm({ tag: '' });
    const admin = (usePage().props as { auth?: { role?: string } }).auth?.role === 'ceo'; // D-382
    const box = useRef<HTMLInputElement>(null);
    const [open, setOpen] = useState(!current);
    const [reason, setReason] = useState('');
    // D-337: on the gun, a touch tag is written with the goat's Tag ID (replacing the factory number) and then linked
    const nfc = native()?.nfcState?.() ?? 'none';
    // D-339: with the Chainway SDK in the gun app, UHF tags work the same way: touch the tag to the gun, no trigger
    const uhf = native()?.uhfState?.() === 'ready';
    const touch = uhf ? 'on' : nfc;
    const [writing, setWriting] = useState(false);
    const [nfcError, setNfcError] = useState<string | null>(null);
    useEffect(() => {
        const on = (e: Event) => {
            const d = (e as CustomEvent<{ ok: boolean; code: string; chip: string; error: string | null }>).detail;
            setWriting(false);
            if (!d.ok) { setNfcError(d.error ?? 'The tag could not be written. Try again.'); return; }
            setNfcError(null);
            router.post(`/goats/${goatId}/tag`, { tag: d.code, chip: d.chip }, { preserveScroll: true, onSuccess: () => setOpen(false) });
        };
        window.addEventListener('kk:nfc-written', on);
        window.addEventListener('kk:uhf-written', on);
        return () => { window.removeEventListener('kk:nfc-written', on); window.removeEventListener('kk:uhf-written', on); native()?.cancelNfcWrite?.(); };
    }, [goatId]);
    // D-339 / D-359: a UHF tag touched to the gun. Already carrying this goat's Tag ID: linked. Another goat's tag: never
    // written. This goat's own factory tag, or "Write tag by touch" pressed: written with no question. Any other tag:
    // written after a yes. Only the tag that was checked is written (the gun compares its EPC).
    const [armed, setArmed] = useState(false);
    const [checking, setChecking] = useState(false);
    useEffect(() => {
        if (!uhf || !canAssign || writing || checking) return;
        const onTouch = (e: Event) => {
            const { code, epc } = (e as CustomEvent<{ code: string; epc: string }>).detail;
            if (code === current && code === number) return;
            if (code === number) { router.post(`/goats/${goatId}/tag`, { tag: number }, { preserveScroll: true, onSuccess: () => setOpen(false) }); return; }
            setChecking(true);
            setNfcError(null);
            fetch(`/goats/find?code=${encodeURIComponent(code)}`, { headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? r.json() : r.status === 404 ? null : Promise.reject(r.status)))
                .then((owner: { id: string; number: string } | null) => {
                    if (owner && owner.id !== goatId) { setNfcError(`This tag is ${owner.number}'s tag. It was not written. Touch a new tag, or ${number}'s own tag.`); return; }
                    const own = current !== null && current !== undefined && (current === code || current === epc);
                    if (!own && !armed) {
                        const replacing = current ? ` ${number} has tag ${current} now; it is closed in the history.` : '';
                        if (!window.confirm(`Write ${number} into this tag (${epc})? Its old number is replaced.${replacing}`)) return;
                    }
                    setWriting(true);
                    const n = native();
                    if (n?.writeUhfTag) n.writeUhfTag(number, epc); else n?.writeUhf?.(number);
                })
                .catch(() => setNfcError('Could not check the tag with the server. Check the internet and touch the tag again.'))
                .finally(() => { setChecking(false); setArmed(false); });
        };
        window.addEventListener('kk:uhf-touch', onTouch);
        native()?.uhfTouch?.(true);
        return () => { window.removeEventListener('kk:uhf-touch', onTouch); native()?.uhfTouch?.(false); };
    }, [uhf, canAssign, writing, checking, armed, current, number, goatId]);
    const writeByTouch = () => {
        if (current && current !== number && !window.confirm(`Replace tag ${current} on ${number}? The old tag is closed in the history.`)) return;
        setNfcError(null);
        if (uhf) { setArmed(true); return; } // the next touched tag is checked, then written
        setWriting(true);
        native()?.writeNfc?.(number);
    };
    const link = () => {
        if (!form.data.tag.trim()) return;
        if (current && !window.confirm(`Replace tag ${current} on ${number} with ${form.data.tag.trim().toUpperCase()}? The old tag is closed in the history.`)) return;
        form.post(`/goats/${goatId}/tag`, { preserveScroll: true, onSuccess: () => { form.reset(); setOpen(false); } });
    };
    const remove = () => {
        if (!reason) return;
        if (reason === 'wrong' && !window.confirm(`Unlink ${current} from ${number}? Use this when the tag was linked to the wrong goat. The tag becomes free to link to the right goat.`)) return;
        router.post(`/goats/${goatId}/tag/remove`, { reason }, { preserveScroll: true, onSuccess: () => { setReason(''); setOpen(true); } });
    };

    return (
        <Panel title="RFID Tag" description="The chip in the ear tag. Once linked, scanning it anywhere in the apps opens this goat." className={className}>
            <div className="flex flex-wrap items-center gap-3">
                {current ? <Chip className="bg-turq-100 font-mono text-turq-800">{current}</Chip> : <Chip className="bg-watch-100 text-watch-800">No RFID tag linked</Chip>}
                {canAssign && current && !open ? <Button type="button" variant="secondary" onClick={() => { setOpen(true); setTimeout(() => box.current?.focus(), 30); }}><ScanLine size={16} aria-hidden /> Replace tag</Button> : null}
                {canAssign && current ? (
                    <span className="flex items-center gap-2">
                        <select className="field w-auto" value={reason} onChange={(e) => setReason(e.target.value)} aria-label="Why the tag comes off">
                            <option value="">Remove tag…</option>
                            <option value="lost">Lost</option>
                            <option value="damaged">Damaged</option>
                            <option value="removed">Taken off</option>
                            {admin ? <option value="wrong">Linked by mistake (unlink)</option> : null}
                        </select>
                        {reason ? <Button type="button" variant="danger" onClick={remove}>Remove</Button> : null}
                    </span>
                ) : null}
            </div>
            {canAssign && touch !== 'none' ? (
                <div className="mt-3 rounded-tile border border-rule bg-glaze px-3 py-3">
                    {touch === 'off' ? (
                        <p className="text-sm">The gun's NFC is switched off. <button type="button" className="text-cobalt-800 underline" onClick={() => native()?.openNfcSettings?.()}>Turn it on</button>, then come back.</p>
                    ) : writing || armed || checking ? (
                        <div className="flex flex-wrap items-center gap-3">
                            <p className="text-sm font-medium">{checking ? 'Checking the tag…' : `Touch the tag to the ${uhf ? 'top' : 'back'} of the gun and hold it still. It will be written with ${number}.`}</p>
                            <Button type="button" variant="secondary" onClick={() => { native()?.cancelNfcWrite?.(); setWriting(false); setArmed(false); }}>Cancel</Button>
                        </div>
                    ) : (
                        <div className="flex flex-wrap items-center gap-3">
                            <Button type="button" onClick={writeByTouch}><ScanLine size={16} aria-hidden /> Write tag by touch</Button>
                            <span className="text-sm text-ink-soft">{uhf ? `Or just touch the tag to the top of the gun: ${number} is written into it, replacing the number it came with, and it is linked to this goat.` : `Writes ${number} into the tag, replacing the number it came with, and links it to this goat.`}</span>
                        </div>
                    )}
                    {nfcError ? <p className="mt-2 text-sm text-crit-700">{nfcError}</p> : null}
                </div>
            ) : null}
            {canAssign && open ? (
                <div className="mt-3 grid gap-2 sm:grid-cols-[1fr_auto] sm:items-end">
                    <Field label={current ? 'New tag' : 'Link a tag'} error={form.errors.tag} hint="Click here, then pull the RFID gun's trigger near the ear tag. Or type the code printed on the tag.">
                        <input ref={box} className="field font-mono uppercase" value={form.data.tag} onChange={(e) => form.setData('tag', e.target.value.toUpperCase())}
                            onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); link(); } }} placeholder="Scan the tag, or type the code" autoComplete="off" spellCheck={false} />
                    </Field>
                    <Button type="button" onClick={link} disabled={form.processing || !form.data.tag.trim()}><ScanLine size={16} aria-hidden /> Link tag</Button>
                </div>
            ) : null}
            {!canAssign ? <p className="mt-2 text-sm text-ink-soft">Your role cannot link RFID tags. Ask the administrator to tick "Assign tags" for you.</p> : null}
        </Panel>
    );
}
