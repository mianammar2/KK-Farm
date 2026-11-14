/** D-293: matching a typed Tag ID (or part of one) to the goats a field offers. Pure, so it is tested with node --test. */
export type Opt = { value: string; text: string };

export const norm = (s: string): string => s.trim().toUpperCase();

/** The Tag ID at the start of an option's text ("MC-101026P-01 · Rani" → "MC-101026P-01"). */
export const tagOf = (text: string): string => norm(text.split(/[\s·(,]/)[0] ?? '');

/** Exactly one goat for what was typed: the whole Tag ID, else a part of it that fits one goat only; 'many' when it fits several. */
export function matchGoat(options: Opt[], typed: string): Opt | 'many' | null {
    const code = norm(typed);
    if (!code) return null;
    const exact = options.find((o) => tagOf(o.text) === code || norm(o.text) === code);
    if (exact) return exact;
    const part = options.filter((o) => norm(o.text).includes(code));
    return part.length === 1 ? part[0] : part.length > 1 ? 'many' : null;
}
