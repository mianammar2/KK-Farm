import { router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';

/** D-240: the standard Delete on every master list. Used entries are switched off instead (history kept). */
export default function DeleteButton({ type, id, name, className }: { type: string; id: string | number; name: string; className?: string }) {
    return (
        <button type="button" className={className ?? 'ml-3 inline-flex items-center gap-1 text-xs font-semibold text-crit-700 hover:underline'}
            onClick={() => { if (window.confirm(`Delete ${name}? If records use it, it is switched off instead and its history is kept.`)) router.delete(`/masters/${type}/${id}`, { preserveScroll: true }); }}>
            <Trash2 size={13} aria-hidden /> Delete
        </button>
    );
}
