import { Head, Link, router } from '@inertiajs/react';
import { Chip, Empty, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import DataGrid, { type Col } from '../components/DataGrid';
import { useTabParam } from '../lib/useTabParam';
import { useFive } from '../lib/useFive';

type Group = { name: string; courses: number; goats: number; qty: number; cia?: boolean; unit?: string | null };
type Props = {
    days: number; kpi: { courses: number; goats: number; per100: number; cia: number; noBatch: number; noCase: number; herd: number };
    byMedicine: Group[]; byCondition: Group[]; byBreed: Group[]; byMonth: Array<{ month: string; courses: number; goats: number; qty: number }>;
    recent: Array<{ id: number; goatId: string; goat: string | null; on: string; product: string; dose: string; route: string; condition: string | null; cia: boolean; by: string; batch: string | null }>;
    antibiotics: Array<{ id: number; name: string; cia: boolean }>; isVet: boolean;
};

const CIA = <Chip className="bg-crit-100 text-crit-700">Critically important</Chip>;

/** D-209: App 05 antimicrobial use report. */
export default function Antimicrobials(p: Props) {
    const [tab, setTab] = useTabParam('summary');
    return (
        <>
            <Head title="Antimicrobial Use" />
            <PageHeader title="Antimicrobial Use" description="Every antibiotic treatment from the medicine store over the period, for the vet's stewardship review. The system records; the vet decides what to prescribe." />
            <div className="mb-3 flex flex-wrap gap-1">{[30, 90, 180, 365].map((d) => <button key={d} type="button" onClick={() => router.get('/health/antimicrobials', { days: d, tab }, { preserveState: true })} className={d === p.days ? 'rounded-full bg-cobalt-800 px-3 py-1 text-xs font-semibold text-white' : 'rounded-full bg-glaze px-3 py-1 text-xs font-semibold ring-1 ring-rule'}>{d === 365 ? '12 months' : `${d} days`}</button>)}</div>
            <StatRow>
                <Stat label="Antibiotic courses" value={p.kpi.courses} />
                <Stat label="Goats treated" value={p.kpi.goats} />
                <Stat label="Courses per 100 goats" value={p.kpi.per100} note={`${p.kpi.herd} goats in the period`} />
                <Stat label="Critically important" value={p.kpi.cia} tone={p.kpi.cia ? 'alert' : undefined} />
                <Stat label="Doses without a batch" value={p.kpi.noBatch} tone={p.kpi.noBatch ? 'alert' : undefined} note="BR-024" />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'summary', label: 'By Medicine & Condition' }, { id: 'trend', label: 'By Month & Breed' }, { id: 'recent', label: 'Treatments', count: p.recent.length || undefined }, { id: 'list', label: 'Antibiotic List' }]} />
            {tab === 'summary' ? <div className="grid gap-4 lg:grid-cols-2"><Table title="By Medicine" rows={p.byMedicine} /><Table title="By Condition" rows={p.byCondition} /></div> : null}
            {tab === 'trend' ? <div className="grid gap-4 lg:grid-cols-2"><Table title="By Month" rows={p.byMonth.map((m) => ({ ...m, name: m.month }))} /><Table title="By Breed" rows={p.byBreed} /></div> : null}
            {tab === 'recent' ? <Recent {...p} /> : null}
            {tab === 'list' ? <List {...p} /> : null}
        </>
    );
}

/** D-273: each summary and the treatments list are spreadsheets (sort, filter, totals, export). */
const GROUP_COLS = (title: string): Col<Group>[] => [
    { key: 'name', label: title.replace('By ', ''), type: 'text', total: 'count', render: (r) => <span>{r.name} {r.cia ? CIA : null}</span> },
    { key: 'courses', label: 'Courses', type: 'number', total: 'sum' },
    { key: 'goats', label: 'Goats', type: 'number', total: 'sum' },
    { key: 'qty', label: 'Quantity', type: 'number', total: 'sum', render: (r) => (r.qty ? `${r.qty}${r.unit ? ` ${r.unit}` : ''}` : '—') },
    { key: 'ciaText', label: 'Critically important', type: 'enum', value: (r) => (r.cia ? 'Yes' : 'No') },
];
function Table({ title, rows }: { title: string; rows: Group[] }) {
    return (
        <Panel flush title={title}>
            {rows.length ? <DataGrid id={`antimicrobial-${title}`} name={`Antimicrobial use ${title.toLowerCase()}`} cols={GROUP_COLS(title)} rows={rows} /> : <Empty title="No antibiotic use in this period" />}
        </Panel>
    );
}

type Treat = Props['recent'][number];
const TREAT_COLS: Col<Treat>[] = [
    { key: 'on', label: 'Date', type: 'text' },
    { key: 'goat', label: 'Goat', type: 'text', total: 'count', render: (t) => <Link href={`/goats/${t.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{t.goat}</Link> },
    { key: 'product', label: 'Medicine', type: 'enum', render: (t) => <span className="font-semibold">{t.product} {t.cia ? CIA : null}</span> },
    { key: 'dose', label: 'Dose', type: 'text' },
    { key: 'route', label: 'Route', type: 'enum' },
    { key: 'condition', label: 'For', type: 'enum', render: (t) => t.condition ?? <Chip className="bg-watch-100 text-watch-700">No case</Chip> },
    { key: 'batch', label: 'Batch', type: 'text', render: (t) => t.batch ?? <Chip className="bg-watch-100 text-watch-700">No batch</Chip> },
    { key: 'by', label: 'By', type: 'enum' },
];
function Recent({ recent }: Props) {
    return (
        <Panel flush title="Antibiotic Treatments">
            {recent.length ? <DataGrid id="antimicrobial-treatments" name="Antibiotic treatments" cols={TREAT_COLS} rows={recent} rowKey={(t) => String(t.id)} /> : <Empty title="No antibiotic treatments" />}
        </Panel>
    );
}

function List({ antibiotics, isVet }: Props) {
    return (
        <Panel flush title="Antibiotics in the Medicine Store" description="The vet marks those that are critically important for human medicine, so their use stands out.">
            {antibiotics.length ? <ul className="divide-y divide-rule">{antibiotics.map((m) => (
                <li key={m.id} className="px-4 py-2 text-sm"><label className="flex items-center gap-2"><input type="checkbox" disabled={!isVet} checked={m.cia} onChange={(x) => router.post(`/health/antimicrobials/${m.id}`, { critically_important: x.target.checked }, { preserveScroll: true })} /> {m.name}</label></li>
            ))}</ul> : <Empty title="No antibiotics in the medicine store">Add them with the category Antibiotic.</Empty>}
        </Panel>
    );
}
