/**
 * D-104: warnings for a checkup as it is typed; the same rules as App\Support\Checkups (the server repeats them).
 * A temperature outside normal or a weight drop (3% or more, or 0.3 KG or more while pregnant) means the result
 * cannot be Healthy, and the vet gets a task when it is saved.
 */
export type Weighing = { on: string; kg: number };

const fmt = (iso: string) => new Date(`${iso}T12:00:00`).toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit' });

export function lastBefore(weights: Weighing[] | undefined, date: string): Weighing | null {
    const list = (weights ?? []).filter((w) => w.on < date);
    return list.length ? list[list.length - 1] : null;
}

export function readingWarnings(opts: { weights?: Weighing[]; date: string; weight: string; temp: string; normal: [number, number]; pregnant: boolean }) {
    const out: string[] = [];
    let weightNote: { text: string; bad: boolean } | null = null;
    const t = opts.temp !== '' && !Number.isNaN(Number(opts.temp)) ? Number(opts.temp) : null;
    if (t !== null && t > opts.normal[1]) out.push(`Temperature ${t} °F (high)`);
    if (t !== null && t < opts.normal[0]) out.push(`Temperature ${t} °F (low)`);
    const kg = opts.weight !== '' && !Number.isNaN(Number(opts.weight)) ? Number(opts.weight) : null;
    const last = lastBefore(opts.weights, opts.date);
    if (kg && last) {
        const diff = Math.round((kg - last.kg) * 10) / 10;
        const pct = Math.round((Math.abs(kg - last.kg) / last.kg) * 1000) / 10;
        const bad = diff < 0 && (pct >= 3 || (opts.pregnant && -diff >= 0.3));
        weightNote = { text: `Last ${last.kg} KG on ${fmt(last.on)} · ${diff === 0 ? 'no change' : `${diff > 0 ? 'up' : 'down'} ${Math.abs(diff)} KG (${pct}%)`}`, bad };
        if (bad) out.push(`Weight down ${-diff} KG (${pct}%) since ${fmt(last.on)}${opts.pregnant ? ' while pregnant' : ''}`);
    }
    return { warnings: out, weightNote };
}
