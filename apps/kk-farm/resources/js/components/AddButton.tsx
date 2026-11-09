import { Plus } from 'lucide-react';
import type { ReactNode } from 'react';
import { titleCase } from '../lib/titleCase';

/** D-127: the one way to start a new entry anywhere: a + button that opens the form in a panel (Sheet). */
export default function AddButton({ label, onClick, icon, primary = true }: { label: string; onClick: () => void; icon?: ReactNode; primary?: boolean }) {
    return (
        <button type="button" onClick={onClick}
            className={`kk-pill inline-flex min-h-11 items-center gap-2 rounded-full border px-4 text-sm font-semibold ${primary ? 'border-cobalt-800 bg-cobalt-800 text-white hover:bg-cobalt-900' : 'border-rule bg-tile text-cobalt-900 hover:border-turq-600'}`}>
            <Plus size={16} aria-hidden />{icon}{titleCase(label)}
        </button>
    );
}
