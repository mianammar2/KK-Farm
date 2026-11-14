import { ScanLine } from 'lucide-react';
import { Children, isValidElement, useEffect, useId, useRef, useState, type ChangeEvent, type ReactElement, type ReactNode, type SelectHTMLAttributes } from 'react';
import { matchGoat, norm, tagOf, type Opt } from '../lib/goatMatch';
import { haptic } from '../lib/native';

/**
 * D-293: every field that picks a goat also takes a typed Tag ID or an RFID scan. A drop-in for <select>: the same options
 * and value/onChange, with a box above it. Typing a Tag ID (or part of one that fits a single goat) and pressing Enter picks
 * the goat; a code that is not a Tag ID (an RFID chip) is looked up on the server. On the RFID gun, a scan fills the goat
 * field that has (or last had) the focus instead of opening the Scan page, when the gun app asks the page first (window.kkScan).
 */

/** The options of a <select>'s children, with their visible text (option elements, inside fragments, arrays or optgroups). */
function optionsOf(children: ReactNode): Opt[] {
    const out: Opt[] = [];
    const text = (n: ReactNode): string => Children.toArray(n).map((c) => (typeof c === 'string' || typeof c === 'number' ? String(c) : isValidElement(c) ? text((c as ReactElement<{ children?: ReactNode }>).props.children) : '')).join('');
    const walk = (n: ReactNode) => Children.forEach(n, (c) => {
        if (!isValidElement(c)) return;
        const el = c as ReactElement<{ value?: string | number; children?: ReactNode; disabled?: boolean }>;
        if (el.type === 'option') {
            if (el.props.value !== undefined && el.props.value !== '' && !el.props.disabled) out.push({ value: String(el.props.value), text: text(el.props.children).trim() });
        } else {
            walk(el.props.children);
        }
    });
    walk(children);
    return out;
}

// The goat field a scan from the gun goes to: the one focused last on this page.
let active: ((code: string) => void) | null = null;
if (typeof window !== 'undefined') {
    (window as unknown as { kkScan?: (code: string) => boolean }).kkScan = (code: string) => {
        if (!active) return false;
        active(code);
        return true;
    };
}

type Props = Omit<SelectHTMLAttributes<HTMLSelectElement>, 'onChange'> & { onChange?: (e: ChangeEvent<HTMLSelectElement> | { target: { value: string } }) => void; children?: ReactNode };

export default function GoatSelect({ children, onChange, className, value, ...rest }: Props) {
    const options = optionsOf(children);
    const [typed, setTyped] = useState('');
    const [note, setNote] = useState<{ ok: boolean; text: string } | null>(null);
    const id = useId();
    const pick = useRef<(code: string) => void>(() => undefined);

    const choose = (o: Opt) => {
        onChange?.({ target: { value: o.value } });
        setTyped('');
        setNote({ ok: true, text: `${tagOf(o.text)} chosen` });
        haptic();
    };

    pick.current = async (raw: string) => {
        const code = norm(raw);
        if (!code) return;
        const m = matchGoat(options, code);
        if (m && m !== 'many') return choose(m);
        if (m === 'many') return setNote({ ok: false, text: `More than one goat fits ${code}: type more of the Tag ID.` });
        // not a Tag ID in this list: perhaps an RFID chip
        try {
            const res = await fetch(`/goats/find?code=${encodeURIComponent(code)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (res.ok) {
                const g: { id: string; number: string } = await res.json();
                const o = options.find((x) => x.value === String(g.id)) ?? options.find((x) => tagOf(x.text) === norm(g.number));
                if (o) return choose(o);
                return setNote({ ok: false, text: `${g.number} is not one of the goats this field takes.` });
            }
        } catch {
            /* offline: fall through */
        }
        setNote({ ok: false, text: `No goat found for ${code}.` });
    };

    const claim = () => {
        active = (code: string) => {
            setTyped(code);
            void pick.current(code);
        };
    };
    useEffect(() => {
        if (!active) claim(); // the first goat field on the page takes a scan until another is focused
        return () => {
            active = null;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <div className="space-y-1" onFocus={claim}>
            <div className="relative">
                <ScanLine size={15} aria-hidden className="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-ink-faint" />
                <input id={id} className="field py-1.5 pl-8 font-mono text-sm" value={typed} placeholder="Type Tag ID or scan RFID" aria-label="Type Tag ID or scan RFID" autoComplete="off" autoCapitalize="characters"
                    onChange={(e) => { setTyped(e.target.value); setNote(null); }}
                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); void pick.current(typed); } }}
                    onBlur={() => { if (typed.trim()) void pick.current(typed); }} />
            </div>
            <select {...rest} className={className} value={value} onChange={(e) => { setNote(null); onChange?.(e); }}>{children}</select>
            {note ? <p className={`text-xs ${note.ok ? 'text-forest' : 'text-danger'}`} role="status">{note.text}</p> : null}
        </div>
    );
}
