import { useState } from 'react';
import { Field } from './ui';

const WRITE = '__write'; // D-316

/**
 * D-290: why a goat is sold. Sick asks for the disease (from the Disease Master), Other for the reason in words.
 * When the goat has an open health case, Sick and its disease are filled in from it (the vet's diagnosis).
 */
export type SaleReasonData = { sale_reason: string; disease_id: string; sale_reason_note: string };
export type DiseaseOption = { id: number; label: string };
export type SaleHint = { reason: string; diseaseId: number | null; note: string | null; from: string } | null;

export const saleReasonDefaults = (hint?: SaleHint): SaleReasonData => ({
    sale_reason: hint?.reason ?? '', disease_id: hint?.diseaseId ? String(hint.diseaseId) : '', sale_reason_note: hint && !hint.diseaseId ? (hint.note ?? '') : '',
});

export default function SaleReasonFields({ data, set, errors, reasons, diseases, hint }: {
    data: SaleReasonData; set: (key: keyof SaleReasonData, value: string) => void; errors: Record<string, string | undefined>;
    reasons: Record<string, string>; diseases: DiseaseOption[]; hint?: SaleHint;
}) {
    const [written, setWritten] = useState(() => data.sale_reason === 'sick' && !data.disease_id && data.sale_reason_note !== '');
    return (
        <>
            <div className="w-48"><Field label="Why sold?" error={errors.sale_reason}>
                <select className="field" value={data.sale_reason} onChange={(e) => set('sale_reason', e.target.value)} required>
                    <option value="">Choose…</option>
                    {Object.entries(reasons).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                </select>
            </Field></div>
            {data.sale_reason === 'sick' ? (
                <div className="w-80"><Field label="Disease" error={errors.disease_id}>
                    <select className="field" value={data.disease_id || (written ? WRITE : '')} required
                        onChange={(e) => { if (e.target.value === WRITE) { set('disease_id', ''); setWritten(true); } else { set('disease_id', e.target.value); setWritten(false); set('sale_reason_note', ''); } }}>
                        <option value="">Choose the disease…</option>
                        {diseases.map((d) => <option key={d.id} value={d.id}>{d.label}</option>)}
                        <option value={WRITE}>Not in the list: write the problem</option>
                    </select>
                </Field></div>
            ) : null}
            {data.sale_reason === 'sick' && written && !data.disease_id ? ( // D-316: a problem that is not in the Disease Master
                <div className="w-80"><Field label="Health problem" error={errors.sale_reason_note}>
                    <input className="field" value={data.sale_reason_note} onChange={(e) => set('sale_reason_note', e.target.value)} placeholder="Write the problem" required />
                </Field></div>
            ) : null}
            {data.sale_reason === 'other' ? (
                <div className="w-80"><Field label="Reason" error={errors.sale_reason_note}>
                    <input className="field" value={data.sale_reason_note} onChange={(e) => set('sale_reason_note', e.target.value)} placeholder="Write the reason" required />
                </Field></div>
            ) : null}
            {hint ? <p className="w-full text-sm text-watch-700">Filled in from the goat's health: {hint.from}.</p> : null}
        </>
    );
}
