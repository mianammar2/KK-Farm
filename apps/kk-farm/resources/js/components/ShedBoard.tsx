import { Link, router } from '@inertiajs/react';
import { ArrowRightLeft, ChevronDown, X } from 'lucide-react';
import { useState, type DragEvent } from 'react';
import { cx, Panel } from './ui';

export type BoardGoat = { id: string; number: string; name: string | null; sex: string; age: string | null; ageLabel: string | null; flag: string | null; weight: number | null };
export type BoardPen = { id: number; name: string; code: string; purpose: string; capacity: number | null; goats: BoardGoat[] };
export type ShedBoardData = { sheds: Array<{ id: number; name: string; typeName: string | null; pens: BoardPen[] }>; noPen: BoardGoat[] };

const FLAG: Record<string, string> = { sick: 'bg-crit-500', quarantine: 'bg-crit-500', monitor: 'bg-watch-400', recovering: 'bg-watch-400' };
const AGE: Record<string, string> = { kid: 'Kid', grower: 'Grower', adult: 'Adult' };

/**
 * D-085: sheds as rows of pen cards with their goats. Drag goats onto another pen (mouse), or tap goats to pick them
 * and press "Move here" on the pen (phone or RFID gun). Every move is a recorded pen transfer.
 */
export default function ShedBoard({ board, canMove }: { board: ShedBoardData; canMove: boolean }) {
    const [shedId, setShedId] = useState<number | 'all'>(board.sheds.length > 1 ? 'all' : board.sheds[0]?.id ?? 'all');
    const [picked, setPicked] = useState<string[]>([]);
    const [over, setOver] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);
    // Closed until opened; each viewer's choice is remembered on this device.
    const [open, setOpenState] = useState(() => { try { return localStorage.getItem('kk.shedBoard.open') === '1'; } catch { return false; } });
    const setOpen = (v: boolean) => { setOpenState(v); try { localStorage.setItem('kk.shedBoard.open', v ? '1' : '0'); } catch { /* storage off */ } };
    const sheds = shedId === 'all' ? board.sheds : board.sheds.filter((s) => s.id === shedId);
    const all = [...board.sheds.flatMap((s) => s.pens.flatMap((p) => p.goats)), ...board.noPen];
    const pickedGoats = all.filter((g) => picked.includes(g.id));
    const goatsIn = (penId: number) => board.sheds.flatMap((s) => s.pens).find((p) => p.id === penId)?.goats.map((g) => g.id) ?? [];

    const toggle = (id: string) => setPicked((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]));
    const move = (ids: string[], penId: number) => {
        const moving = ids.filter((id) => !goatsIn(penId).includes(id));
        if (!moving.length || busy) return;
        setBusy(true);
        router.post('/movements', { animal_ids: moving, type: 'transfer', to_pen_id: penId, moved_on: new Date().toLocaleDateString('en-CA'), reason: 'Moved on the dashboard' }, {
            preserveScroll: true, preserveState: true,
            onSuccess: () => setPicked([]), onFinish: () => { setBusy(false); setOver(null); },
        });
    };
    const onDragStart = (e: DragEvent, id: string) => {
        const ids = picked.includes(id) ? picked : [id];
        e.dataTransfer.setData('text/plain', JSON.stringify(ids));
        e.dataTransfer.effectAllowed = 'move';
    };
    const onDrop = (e: DragEvent, penId: number) => {
        e.preventDefault();
        try { move(JSON.parse(e.dataTransfer.getData('text/plain')) as string[], penId); } catch { setOver(null); }
    };

    const chip = (g: BoardGoat) => {
        const on = picked.includes(g.id);
        return (
            <li key={g.id} draggable={canMove} onDragStart={(e) => onDragStart(e, g.id)}
                className={cx('flex items-center gap-1.5 rounded-[6px] border px-2 py-1 text-sm', canMove && 'cursor-grab active:cursor-grabbing', on ? 'border-cobalt-700 bg-cobalt-700 text-white' : 'border-rule bg-tile')}>
                {canMove ? <input type="checkbox" aria-label={`Pick ${g.number}`} checked={on} onChange={() => toggle(g.id)} className="size-4 shrink-0" /> : null}
                <span className={cx('size-2 shrink-0 rounded-full', FLAG[g.flag ?? ''] ?? 'bg-ok-700')} title={g.flag ?? 'healthy'} />
                <Link href={`/goats/${g.id}`} className={cx('min-w-0 truncate font-mono font-semibold hover:underline', on ? 'text-white' : 'text-cobalt-900')}>{g.number}</Link>
                <span className={cx('ml-auto shrink-0 text-xs', on ? 'text-white/80' : 'text-ink-soft')}>{g.sex === 'male' ? '♂' : '♀'} {g.age ? AGE[g.age] : ''}{g.weight ? ` · ${g.weight} KG` : ''}</span>
            </li>
        );
    };

    const penCard = (p: BoardPen) => {
        const full = p.capacity !== null && p.goats.length > p.capacity;
        const target = canMove && picked.length > 0 && picked.some((id) => !p.goats.some((g) => g.id === id));
        return (
            <div key={p.id} onDragOver={canMove ? (e) => { e.preventDefault(); setOver(p.id); } : undefined} onDragLeave={() => setOver((o) => (o === p.id ? null : o))} onDrop={canMove ? (e) => onDrop(e, p.id) : undefined}
                className={cx('flex min-h-32 flex-col rounded-tile border bg-glaze/60 p-2 transition-colors', over === p.id ? 'border-turq-600 bg-turq-100 ring-2 ring-turq-600' : 'border-rule')}>
                <div className="mb-1.5 flex items-baseline justify-between gap-2">
                    <span className="truncate font-mono text-sm font-semibold text-cobalt-900" title={p.name}>{p.code}</span>
                    <span className={cx('shrink-0 text-xs font-semibold tabular-nums', full ? 'text-crit-700' : 'text-ink-soft')}>{p.goats.length}{p.capacity !== null ? `/${p.capacity}` : ''} goat{p.goats.length === 1 && p.capacity === null ? '' : 's'}</span>
                </div>
                <div className="mb-1.5 truncate text-xs text-ink-soft">{p.purpose}</div>
                {p.goats.length ? <ul className="space-y-1">{p.goats.map(chip)}</ul> : <p className="flex flex-1 items-center justify-center rounded-[6px] border border-dashed border-rule-strong py-3 text-xs text-ink-faint">Empty pen</p>}
                {target ? <button type="button" disabled={busy} onClick={() => move(picked, p.id)} className="mt-2 rounded-[6px] bg-turq-700 px-2 py-1.5 text-sm font-semibold text-white hover:bg-turq-800 disabled:opacity-50">Move {picked.length} here</button> : null}
            </div>
        );
    };

    return (
        <Panel className="mt-4" flush>
            <div className="flex items-center gap-3 px-4 py-3">
                <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} className="flex min-w-0 flex-1 items-center gap-3 text-left">
                    <ChevronDown size={18} className={cx('shrink-0 text-ink-soft transition-transform', open && 'rotate-180')} aria-hidden />
                    <span className="min-w-0">
                        <span className="block font-semibold text-cobalt-900">Sheds and pens</span>
                        <span className="block truncate text-sm text-ink-soft">
                            {board.sheds.length} shed{board.sheds.length === 1 ? '' : 's'} · {board.sheds.reduce((n, sh) => n + sh.pens.length, 0)} pens · {all.length} goats
                            {board.noPen.length ? ` · ${board.noPen.length} not in a pen` : ''}
                            {open ? (canMove ? ' · drag goats to another pen, or tick them and press “Move here”' : '') : ' · tap to open'}
                        </span>
                    </span>
                </button>
                <Link href="/sheds" className="shrink-0 text-sm font-semibold text-turq-800 hover:underline">Manage sheds</Link>
            </div>
            {open ? <div className="border-t border-rule px-4 py-3">
            {board.sheds.length > 1 ? (
                <div className="mb-3 flex gap-1.5 overflow-x-auto" role="group" aria-label="Shed">
                    {[{ id: 'all' as const, name: 'All sheds', count: all.length - board.noPen.length }, ...board.sheds.map((s) => ({ id: s.id, name: s.name, count: s.pens.reduce((n, p) => n + p.goats.length, 0) }))].map((s) => (
                        <button key={s.id} type="button" aria-pressed={shedId === s.id} onClick={() => setShedId(s.id)}
                            className={cx('min-h-10 shrink-0 rounded-tile border px-3 text-sm font-semibold', shedId === s.id ? 'border-cobalt-700 bg-cobalt-700 text-white' : 'border-rule bg-tile text-cobalt-900')}>
                            <span className="font-mono">{s.name}</span> <span className="font-normal opacity-75">{s.count}</span>
                        </button>
                    ))}
                </div>
            ) : null}

            {picked.length ? (
                <div className="sticky top-2 z-10 mb-3 flex flex-wrap items-center gap-2 rounded-tile bg-cobalt-900 px-3 py-2 text-sm text-white shadow-tile">
                    <ArrowRightLeft size={16} aria-hidden />
                    <span className="font-semibold">{picked.length} picked:</span>
                    <span className="min-w-0 flex-1 truncate font-mono">{pickedGoats.map((g) => g.number).join(', ')}</span>
                    <span className="text-white/80">Drop on a pen or press “Move here”.</span>
                    <button type="button" onClick={() => setPicked([])} className="flex items-center gap-1 rounded-[6px] px-2 py-1 font-semibold hover:bg-white/10"><X size={14} aria-hidden /> Clear</button>
                </div>
            ) : null}

            {board.sheds.length ? (
                <div className="space-y-4">
                    {sheds.map((s) => (
                        <section key={s.id}>
                            <h3 className="mb-2 flex items-baseline gap-2"><span className="font-mono font-semibold text-cobalt-900">{s.name}</span>{s.typeName ? <span className="text-xs text-ink-soft">{s.typeName}</span> : null}<span className="text-xs text-ink-faint">{s.pens.length} pen{s.pens.length === 1 ? '' : 's'}</span></h3>
                            {s.pens.length ? <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">{s.pens.map(penCard)}</div> : <p className="text-sm text-ink-soft">No pens in this shed yet.</p>}
                        </section>
                    ))}
                </div>
            ) : <p className="text-sm text-ink-soft">No sheds yet. Add one under Sheds.</p>}

            {board.noPen.length ? (
                <div className="mt-4 rounded-tile border border-watch-400 bg-watch-100 p-2">
                    <div className="mb-1.5 text-sm font-semibold text-watch-700">Not in a pen ({board.noPen.length}){canMove ? ': drag or tick them into a pen' : ''}</div>
                    <ul className="grid gap-1 sm:grid-cols-2 lg:grid-cols-4">{board.noPen.map(chip)}</ul>
                </div>
            ) : null}
            </div> : null}
        </Panel>
    );
}
