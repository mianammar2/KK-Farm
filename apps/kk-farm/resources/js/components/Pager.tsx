import { useEffect, useMemo, useState } from 'react';
import { cx } from './ui';

/**
 * D-263: numbered pages for long lists: "Showing 1–25 of 120", « Previous 1 2 3 … 9 10 Next », and how many per page.
 * The page goes back to 1 when the list changes (a filter or a search); the size is remembered per list on this device.
 */
const SIZES = [10, 25, 50, 100];

export function usePaged<T>(items: T[], opts: { size?: number; id?: string } = {}) {
    const key = opts.id ? `kk.pager.${opts.id}` : null;
    const [size, setSizeState] = useState<number>(() => {
        try { const v = key ? Number(localStorage.getItem(key)) : 0; return SIZES.includes(v) ? v : (opts.size ?? 25); } catch { return opts.size ?? 25; }
    });
    const [page, setPage] = useState(1);
    const pages = Math.max(1, Math.ceil(items.length / size));
    useEffect(() => { setPage(1); }, [items]); // a new filter or search starts at page 1
    const now = Math.min(page, pages);
    const rows = useMemo(() => items.slice((now - 1) * size, now * size), [items, now, size]);
    const setSize = (n: number) => { setSizeState(n); setPage(1); try { if (key) localStorage.setItem(key, String(n)); } catch { /* private window */ } };
    const pager = <Pager total={items.length} page={now} size={size} onPage={setPage} onSize={setSize} />;

    return { rows, pager, page: now, size };
}

/** 1 2 3 4 5 6 7 … 9 10, or 1 2 … 5 6 7 … 9 10 around the current page. */
function numbers(page: number, pages: number): Array<number | '…'> {
    if (pages <= 9) return Array.from({ length: pages }, (_, i) => i + 1);
    const out = new Set<number>([1, 2, pages - 1, pages]);
    const from = page <= 5 ? 1 : page >= pages - 4 ? pages - 6 : page - 2;
    const to = page <= 5 ? 7 : page >= pages - 4 ? pages : page + 2;
    for (let i = from; i <= to; i++) out.add(i);
    const list = [...out].filter((n) => n >= 1 && n <= pages).sort((a, b) => a - b);
    return list.flatMap((n, i) => (i && n - list[i - 1] > 1 ? ['…' as const, n] : [n]));
}

export default function Pager({ total, page, size, onPage, onSize }: { total: number; page: number; size: number; onPage: (p: number) => void; onSize: (n: number) => void }) {
    if (total <= SIZES[0]) return null;
    const pages = Math.max(1, Math.ceil(total / size));
    const from = (page - 1) * size + 1;
    const to = Math.min(total, page * size);
    const go = (p: number) => { onPage(Math.min(pages, Math.max(1, p))); };
    const btn = 'inline-flex min-h-9 min-w-9 items-center justify-center rounded-lg border px-2.5 text-sm font-semibold tabular-nums transition-colors';
    return (
        <nav className="flex flex-wrap items-center justify-between gap-3 border-t border-rule px-4 py-3" aria-label="Pages">
            <div className="flex w-full flex-wrap items-center justify-between gap-2">
                <p className="text-sm text-ink-soft">Showing <b className="text-ink">{from.toLocaleString('en-PK')}–{to.toLocaleString('en-PK')}</b> of <b className="text-ink">{total.toLocaleString('en-PK')}</b></p>
                <label className="flex items-center gap-2 text-sm text-ink-soft">Show
                    <select className="field min-h-9 w-20 py-1" value={size} onChange={(e) => onSize(Number(e.target.value))}>{SIZES.map((n) => <option key={n} value={n}>{n}</option>)}</select>
                </label>
            </div>
            {pages > 1 ? (
                <ul className="flex flex-wrap items-center gap-1.5">
                    <li><button type="button" className={cx(btn, 'border-rule bg-tile text-cobalt-800 hover:bg-glaze disabled:opacity-40')} disabled={page === 1} onClick={() => go(page - 1)}>« Previous</button></li>
                    {numbers(page, pages).map((n, i) => n === '…'
                        ? <li key={`e${i}`} aria-hidden className="px-1 text-ink-faint">…</li>
                        : <li key={n}><button type="button" aria-current={n === page ? 'page' : undefined} onClick={() => go(n)}
                            className={cx(btn, n === page ? 'border-cobalt-800 bg-cobalt-800 text-white shadow-tile' : 'border-rule bg-tile text-cobalt-900 hover:bg-glaze')}>{n}</button></li>)}
                    <li><button type="button" className={cx(btn, 'border-rule bg-tile text-cobalt-800 hover:bg-glaze disabled:opacity-40')} disabled={page === pages} onClick={() => go(page + 1)}>Next »</button></li>
                </ul>
            ) : null}
        </nav>
    );
}
