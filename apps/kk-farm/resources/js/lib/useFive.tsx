import { ChevronDown } from 'lucide-react';
import { useState, type ReactNode } from 'react';

/** D-119: any dashboard list: the first 5, the rest on a tap. */
export function useFive<T>(items: T[], limit = 5, startOpen = false): [T[], ReactNode] {
    const [open, setOpen] = useState(startOpen);
    const button = items.length > limit ? (
        <button type="button" onClick={() => setOpen(!open)} className="flex w-full items-center justify-center gap-1 border-t border-rule px-4 py-2 text-sm font-semibold text-cobalt-800 hover:bg-glaze">
            <ChevronDown size={15} className={open ? 'rotate-180' : ''} aria-hidden />{open ? 'Show Less' : `Show ${items.length - limit} More`}
        </button>
    ) : null;
    return [open ? items : items.slice(0, limit), button];
}

