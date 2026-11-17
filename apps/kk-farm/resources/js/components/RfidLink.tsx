import { router, useForm } from '@inertiajs/react';
import { ScanLine } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button, Chip, Field, Panel } from './ui';

/**
 * D-336: link an RFID tag to a goat on its own page. Click in the box and pull the gun's trigger (or type the chip code):
 * the read is linked, and every scanner in the apps then opens this goat. The old tag, if any, is closed in the tag history.
 */
export default function RfidLink({ goatId, number, current, canAssign, className }: { goatId: string; number: string; current: string | null | undefined; canAssign: boolean; className?: string }) {
    const form = useForm({ tag: '' });
    const box = useRef<HTMLInputElement>(null);
    const [open, setOpen] = useState(!current);
    const [reason, setReason] = useState('');
    const link = () => {
        if (!form.data.tag.trim()) return;
        if (current && !window.confirm(`Replace tag ${current} on ${number} with ${form.data.tag.trim().toUpperCase()}? The old tag is closed in the history.`)) return;
        form.post(`/goats/${goatId}/tag`, { preserveScroll: true, onSuccess: () => { form.reset(); setOpen(false); } });
    };
    const remove = () => {
        if (!reason) return;
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
                        </select>
                        {reason ? <Button type="button" variant="danger" onClick={remove}>Remove</Button> : null}
                    </span>
                ) : null}
            </div>
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
