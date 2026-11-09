/**
 * D-109: titles in Title Case, as a professional system writes them: "Sheds and Pens", "Weight History",
 * "Kidding in the Next 10 Days". Small words stay lower case (not as the first word); codes, units and words already
 * containing capitals stay exactly as written (KG, PKR, RFID, °F, KKF-BDU-01-P01, TD141026-06, iPhone).
 */
const SMALL = new Set(['a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'from', 'in', 'into', 'nor', 'of', 'on', 'or', 'per', 'the', 'to', 'vs', 'via', 'with', 'than']);

export function titleCase(text: string): string;
export function titleCase(text: string | null | undefined): string | null | undefined;
export function titleCase(text: string | null | undefined) {
    if (!text) return text;
    let first = true;
    return text.replace(/[^\s]+/g, (word) => {
        const lead = word.match(/^[^A-Za-z]*/)?.[0] ?? '';
        const core = word.slice(lead.length);
        const isFirst = first;
        first = /[:·—–]$/.test(word); // after a colon or a separator, the next word starts a new title
        if (!core) return word;
        if (/\d/.test(core) || /[A-Z]/.test(core) || core.includes('/') || core.includes('@')) return word; // codes, units, already capitalised
        const bare = core.replace(/[^A-Za-z'-].*$/, '').toLowerCase();
        if (!isFirst && SMALL.has(bare)) return lead + core.toLowerCase();
        return lead + core.charAt(0).toUpperCase() + core.slice(1).replace(/-([a-z])/g, (_, c: string) => `-${c.toUpperCase()}`);
    });
}
