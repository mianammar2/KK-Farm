/**
 * D-365: on a phone a wide table is hard to use (it scrolls sideways and the headings leave the screen). Every plain
 * table with three or more columns is marked here and each cell is given its column heading; the stylesheet then shows
 * each row as a card of labelled lines below 640px. Data sheets draw their own cards (data-keep-table); a table can opt
 * out with data-keep-table. Runs once for the whole app and follows pages and pop cards as they change.
 */
function label(table: HTMLTableElement): void {
    const head = table.tHead?.rows[table.tHead.rows.length - 1];
    if (!head) return;
    const labels: string[] = [];
    for (const th of Array.from(head.cells)) {
        const text = (th.getAttribute('aria-label') || th.textContent || '').replace(/\s+/g, ' ').trim();
        for (let i = 0; i < (th.colSpan || 1); i++) labels.push(text);
    }
    if (labels.length < 3) return;
    table.dataset.cards = '1';
    const rows = [...Array.from(table.tBodies).flatMap((b) => Array.from(b.rows)), ...(table.tFoot ? Array.from(table.tFoot.rows) : [])];
    for (const tr of rows) {
        let col = 0;
        const cells = Array.from(tr.cells);
        if (cells.length === 1 && cells[0].colSpan > 1) { tr.dataset.cardWide = '1'; continue; } // a detail or note row
        for (const td of cells) {
            const l = labels[col] ?? '';
            if (td.dataset.label !== l) td.dataset.label = l;
            const blank = /^[—–-]?$/.test((td.textContent || '').trim()) && !td.querySelector('input, select, textarea, button, img, svg');
            if ((td.dataset.blank === '1') !== blank) td.dataset.blank = blank ? '1' : ''; // an empty line is left out of the card
            col += td.colSpan || 1;
        }
    }
}

let queued = false;
function scan(): void {
    queued = false;
    document.querySelectorAll<HTMLTableElement>('table').forEach((t) => {
        if (t.closest('[data-keep-table], .kk-chalkboard')) return;
        label(t);
    });
}

export function startCardTables(): void {
    if (typeof window === 'undefined' || typeof MutationObserver === 'undefined') return;
    const queue = () => { if (!queued) { queued = true; setTimeout(scan, 30); } };
    new MutationObserver(queue).observe(document.body, { childList: true, subtree: true });
    queue();
}
