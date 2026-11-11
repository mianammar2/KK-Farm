import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Chip, Empty, Field, LibIcon, PageHeader, Panel, pkr, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';
import { useFive } from '../lib/useFive';

type ShedRow = { id: number; name: string; typeName: string | null; points: Array<{ id: number; name: string; kind: string }>; last: { at: string; thi: number | null; temp: number | null; risk: boolean; water: boolean; waste: boolean } | null; openIssues: number };
type WaterInfo = {
    problem: string | null; started: string; outage: number | null; restored: string | null; exposed: number | null; ill: number | null; outcome: string | null; checkedBy: string | null;
    supply: { source: string; at: string; by: string; cost: number | null; approvedBy: string | null; approvedAt: string | null } | null;
    welfare: { label: string; status: string } | null; jobs: Array<{ id: number; asset: string | null; status: string }>; cost: number; blockers: string[];
};
type Issue = { id: number; shed: string | null; kind: string; severity: string; description: string; status: string; at: string; resolution: string | null; resolvedBy: string | null; verifiedBy: string | null; water: WaterInfo | null };
type WaterTestRow = { id: number; point: string | null; shed: string | null; on: string; lab: string | null; ph: number | null; tds: number | null; coliform: number | null; nitrate: number | null; verdict: string; cost: number | null; by: string; notes: string | null };
type Round = { id: number; at: string; shed: string | null; trigger: string; water: boolean; temp: number | null; rh: number | null; thi: number | null; risk: boolean; waste: boolean; cost: number | null; utility: string | null; by: string };
type Props = {
    sheds: ShedRow[]; issues: Issue[]; rounds: Round[]; threshold: number;
    waterPoints: Array<{ id: number; name: string; kind: string; shedId: number | null; shed: string | null; penId: number | null; assetId: number | null; active: boolean }>;
    kpi: { interruptions: number; heatExceptions: number; compliance: number | null; rounds: number; utility30: number };
    waterKpi: { outageMinutes: number; incidents: number; verifiedPct: number | null; cost: number; toSupply: number | null }; waterTests: WaterTestRow[]; isVet: boolean;
    pens: Array<{ id: number; name: string; shedId: number }>; assets: Array<{ id: number; name: string; code: string }>;
    options: { kinds: Record<string, string>; quality: Record<string, string>; issues: Record<string, string>; utilities: Record<string, string>; problems: Record<string, string>; sources: Record<string, string> }; canConfigure: boolean;
};

const SEV: Record<string, string> = { medium: 'bg-watch-100 text-watch-700', high: 'bg-treat-100 text-treat-700', critical: 'bg-crit-100 text-crit-700' };
const mins = (m: number | null) => (m === null ? '—' : m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${m % 60} min`);
const thiOf = (c: number, rh: number) => Math.round(((1.8 * c + 32) - (0.55 - 0.0055 * rh) * (1.8 * c - 26)) * 10) / 10;

/** D-186: App 20 Water, Climate & Environment (V3 flow 20). */
export default function Environment(p: Props) {
    const [tab, setTab] = useTabParam('sheds');
    const open = p.issues.filter((i) => i.status !== 'verified');
    return (
        <>
            <Head title="Water & Climate" />
            <PageHeader title="Water, Climate & Environment" description={`Drinking water, heat stress, ventilation and waste, shed by shed. Heat-stress alert at THI ${p.threshold}.`} />
            <StatRow>
                <Stat label="Open issues" value={open.length} tone={open.some((i) => i.severity === 'critical' && i.status === 'open') ? 'alert' : undefined} />
                <Stat label="Water interruptions (30 days)" value={p.kpi.interruptions} tone={p.kpi.interruptions ? 'alert' : undefined} />
                <Stat label="Heat exceptions (30 days)" value={p.kpi.heatExceptions} />
                <Stat label="Compliant rounds" value={p.kpi.compliance !== null ? `${p.kpi.compliance}%` : '—'} note={`${p.kpi.rounds} rounds · utilities ${pkr(p.kpi.utility30)}`} />
                <Stat label="Water outage (30 days)" value={mins(p.waterKpi.outageMinutes)} note={`${p.waterKpi.incidents} incidents${p.waterKpi.toSupply !== null ? ` · safe supply in ${mins(p.waterKpi.toSupply)}` : ''}`} tone={p.waterKpi.outageMinutes ? 'alert' : undefined} />
                <Stat label="Verified corrective actions" value={p.waterKpi.verifiedPct !== null ? `${p.waterKpi.verifiedPct}%` : '—'} note={p.waterKpi.cost ? `Water incident cost ${pkr(p.waterKpi.cost)}` : undefined} />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[
                { id: 'sheds', label: 'Shed Rounds' }, { id: 'issues', label: 'Issues', count: open.length || undefined }, { id: 'tests', label: 'Water Tests' }, { id: 'records', label: 'Round Records' },
                ...(p.canConfigure ? [{ id: 'setup', label: 'Water Points & Limits' }] : []),
            ]} />
            {tab === 'sheds' ? <div className="grid gap-3 lg:grid-cols-2">{p.sheds.map((s) => <ShedCard key={s.id} s={s} {...p} />)}</div> : null}
            {tab === 'issues' ? <Issues {...p} /> : null}
            {tab === 'tests' ? <WaterTests {...p} /> : null}
            {tab === 'records' ? (
                <Panel flush title="Last 30 Days">
                    {p.rounds.length ? <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>When</th><th>Shed</th><th>Water</th><th>°C / RH</th><th>THI</th><th>Climate</th><th>Waste</th><th>Utility</th><th>By</th></tr></thead><tbody>
                        {p.rounds.map((r) => <tr key={r.id}><td>{r.at}</td><td>{r.shed}</td><td>{r.water ? 'OK' : <span className="font-semibold text-crit-700">Problem</span>}</td><td>{r.temp ?? '—'}{r.rh !== null ? ` / ${r.rh}%` : ''}</td><td>{r.thi ?? '—'}</td><td>{r.risk ? <span className="font-semibold text-crit-700">Risk</span> : 'Normal'}</td><td>{r.waste ? 'OK' : <span className="font-semibold text-watch-700">Issue</span>}</td><td>{r.cost ? `${p.options.utilities[r.utility ?? ''] ?? ''} ${pkr(r.cost)}` : '—'}</td><td>{r.by}</td></tr>)}
                    </tbody></table></div> : <Empty title="No rounds yet" />}
                </Panel>
            ) : null}
            {tab === 'setup' && p.canConfigure ? <Setup {...p} /> : null}
        </>
    );
}

function ShedCard({ s, options, threshold }: { s: ShedRow } & Props) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        shed_id: s.id, trigger: 'scheduled', water: s.points.map((w) => ({ water_point_id: w.id, available: true, quality: 'clean', tds_ppm: '' as string | number })), water_ok: true,
        temperature_c: '', humidity_pct: '', ventilation_ok: true, power_ok: true, waste_ok: true, waste_notes: '', utility_kind: '', utility_cost: '', notes: '',
    });
    const d = form.data;
    const e = form.errors as Record<string, string>;
    const thi = d.temperature_c !== '' && d.humidity_pct !== '' ? thiOf(Number(d.temperature_c), Number(d.humidity_pct)) : null;
    const waterOk = d.water.length ? d.water.every((w) => w.available && w.quality === 'clean') : d.water_ok;
    const setW = (i: number, k: Partial<typeof d.water[number]>) => form.setData('water', d.water.map((w, j) => (j === i ? { ...w, ...k } : w)));
    return (
        <Panel title={s.name} description={s.last ? `Last round ${s.last.at}${s.last.thi !== null ? ` · THI ${s.last.thi}` : ''}` : 'No round yet'}
            actions={<span className="flex items-center gap-1.5">{s.openIssues ? <Chip className="bg-crit-100 text-crit-700">{s.openIssues} open</Chip> : null}{s.last ? <Chip className={s.last.water && !s.last.risk && s.last.waste ? 'bg-ok-100 text-ok-700' : 'bg-watch-100 text-watch-700'}>{s.last.water && !s.last.risk && s.last.waste ? 'Normal' : 'Attention'}</Chip> : null}<Button onClick={() => setOpen(!open)}>{open ? 'Close' : 'Start Round'}</Button></span>}>
            {open ? (
                <form className="space-y-4 p-4" onSubmit={(ev) => { ev.preventDefault(); form.transform((x) => ({ ...x, water: x.water.map((w) => ({ ...w, tds_ppm: w.tds_ppm === '' ? null : w.tds_ppm })), temperature_c: x.temperature_c || null, humidity_pct: x.humidity_pct || null, utility_kind: x.utility_kind || null, utility_cost: x.utility_cost || null, waste_notes: x.waste_notes || null })); form.post('/environment/rounds', { preserveScroll: true, onSuccess: () => setOpen(false) }); }}>
                    <Step n={1} title="Check water points and tanks" ok={waterOk} bad="Not available / not acceptable: emergency supply and repair task">
                        {d.water.length ? d.water.map((w, i) => (
                            <div key={w.water_point_id} className="flex flex-wrap items-center gap-2 text-sm">
                                <span className="w-36 font-semibold">{s.points[i].name}</span>
                                <label className="flex items-center gap-1"><input type="checkbox" checked={w.available} onChange={(x) => setW(i, { available: x.target.checked })} /> Water available</label>
                                <select className="field !w-36 !py-1" value={w.quality} onChange={(x) => setW(i, { quality: x.target.value })}>{Object.entries(options.quality).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
                                <input className="field !w-28 !py-1" type="number" placeholder="TDS ppm" value={w.tds_ppm} onChange={(x) => setW(i, { tds_ppm: x.target.value })} />
                            </div>
                        )) : <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={d.water_ok} onChange={(x) => form.setData('water_ok', x.target.checked)} /> Drinking water available and clean (add water points under setup to check each one)</label>}
                    </Step>
                    <Step n={2} title="Record climate and ventilation" ok={!(thi !== null && thi >= threshold) && d.power_ok && d.ventilation_ok} bad="Heat / power / airflow risk: shade, cooling and vet escalation">
                        <div className="grid gap-2 sm:grid-cols-4">
                            <Field label="Temperature °C" error={e.temperature_c}><input type="number" step="0.1" className="field" value={d.temperature_c} onChange={(x) => form.setData('temperature_c', x.target.value)} /></Field>
                            <Field label="Humidity %" error={e.humidity_pct}><input type="number" className="field" value={d.humidity_pct} onChange={(x) => form.setData('humidity_pct', x.target.value)} /></Field>
                            <div className="pt-6 text-sm">THI <strong className={thi !== null && thi >= threshold ? 'text-crit-700' : 'text-cobalt-900'}>{thi ?? '—'}</strong> <span className="text-ink-soft">(limit {threshold})</span></div>
                            <div className="space-y-1 pt-5 text-sm">
                                <label className="flex items-center gap-2"><input type="checkbox" checked={d.ventilation_ok} onChange={(x) => form.setData('ventilation_ok', x.target.checked)} /> Fans / airflow working</label>
                                <label className="flex items-center gap-2"><input type="checkbox" checked={d.power_ok} onChange={(x) => form.setData('power_ok', x.target.checked)} /> Power on</label>
                            </div>
                        </div>
                    </Step>
                    <Step n={3} title="Check manure, drainage, waste" ok={d.waste_ok} bad="Not compliant: contain, correct and verify">
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <label className="flex items-center gap-2"><input type="checkbox" checked={d.waste_ok} onChange={(x) => form.setData('waste_ok', x.target.checked)} /> Clean, drained and compliant</label>
                            {!d.waste_ok ? <input className="field flex-1" placeholder="What is wrong" value={d.waste_notes} onChange={(x) => form.setData('waste_notes', x.target.value)} /> : null}
                            {e.waste_notes ? <span className="text-xs text-crit-700">{e.waste_notes}</span> : null}
                        </div>
                    </Step>
                    <Step n={4} title="Record utility cost + inspection" ok>
                        <div className="grid gap-2 sm:grid-cols-3">
                            <Field label="Utility (optional)"><select className="field" value={d.utility_kind} onChange={(x) => form.setData('utility_kind', x.target.value)}><option value="">None</option>{Object.entries(options.utilities).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                            <Field label="Cost PKR" error={e.utility_cost} hint="Goes to Accounting as Utilities"><input type="number" min={0} className="field" value={d.utility_cost} onChange={(x) => form.setData('utility_cost', x.target.value)} /></Field>
                            <Field label="Notes"><input className="field" value={d.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
                        </div>
                    </Step>
                    <div className="flex items-center gap-2">
                        <select className="field !w-44" value={d.trigger} onChange={(x) => form.setData('trigger', x.target.value)}><option value="scheduled">Scheduled reading</option><option value="sensor_alarm">Sensor alarm</option><option value="report">Report / complaint</option></select>
                        <Button type="submit" disabled={form.processing}><LibIcon name="ic-active" size={18} /> Save Round</Button>
                    </div>
                </form>
            ) : null}
        </Panel>
    );
}

function Step({ n, title, ok, bad, children }: { n: number; title: string; ok: boolean; bad?: string; children: React.ReactNode }) {
    return (
        <div className={`rounded-tile p-3 ring-1 ${ok ? 'bg-glaze ring-rule' : 'bg-crit-100/40 ring-crit-500/30'}`}>
            <div className="mb-2 flex items-center gap-2 text-sm font-bold text-cobalt-900"><span className="grid size-6 place-items-center rounded-full bg-cobalt-900 text-xs text-white">{n}</span>{title}{!ok && bad ? <span className="ml-auto text-xs font-semibold text-crit-700">{bad}</span> : null}</div>
            {children}
        </div>
    );
}

function Issues({ issues, options, canConfigure, isVet, sheds }: Props) {
    const [shown, more] = useFive(issues);
    const [reporting, setReporting] = useState(false);
    const err = (usePage().props.errors ?? {}) as Record<string, string>;
    const shownErr = err.verify ?? err.supply ?? err.resolution;
    return (
        <div className="space-y-3">
            {reporting ? <ReportWater sheds={sheds} problems={options.problems} onDone={() => setReporting(false)} /> : null}
            <Panel flush title="Environment Issues" description={shownErr ?? 'Open first. Whoever fixes it marks it resolved; another supervisor verifies.'}
                actions={<Button onClick={() => setReporting(!reporting)}>{reporting ? 'Close' : 'Report Water Problem'}</Button>}>
                {issues.length ? <ul className="divide-y divide-rule">{shown.map((i) => <IssueItem key={i.id} i={i} options={options} canVerify={canConfigure} isVet={isVet} />)}</ul> : <Empty title="No environment issues" />}
                {more}
            </Panel>
        </div>
    );
}

/** D-198: report a water interruption, shortage or contamination at any time. */
function ReportWater({ sheds, problems, onDone }: { sheds: ShedRow[]; problems: Record<string, string>; onDone: () => void }) {
    const form = useForm({ shed_id: String(sheds[0]?.id ?? ''), problem: 'interruption', point_ids: [] as number[], description: '' });
    const e = form.errors as Record<string, string>;
    const shed = sheds.find((x) => String(x.id) === form.data.shed_id);
    const toggle = (id: number) => form.setData('point_ids', form.data.point_ids.includes(id) ? form.data.point_ids.filter((x) => x !== id) : [...form.data.point_ids, id]);
    return (
        <Panel title="Report Water Problem" description="Welfare first: management and the vet are alerted at once, and a welfare case opens for the goats affected.">
            <form className="grid gap-3 p-4 sm:grid-cols-3" onSubmit={(ev) => { ev.preventDefault(); form.post('/environment/water/report', { preserveScroll: true, onSuccess: onDone }); }}>
                <Field label="Shed" error={e.shed_id}><select className="field" value={form.data.shed_id} onChange={(x) => { form.setData('shed_id', x.target.value); form.setData('point_ids', []); }}>{sheds.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
                <Field label="Problem" error={e.problem}><select className="field" value={form.data.problem} onChange={(x) => form.setData('problem', x.target.value)}>{Object.entries(problems).filter(([k]) => k !== 'inspection').map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="What happened" error={e.description}><input className="field" placeholder="e.g. Pump stopped at 6 AM" value={form.data.description} onChange={(x) => form.setData('description', x.target.value)} required /></Field>
                {shed?.points.length ? <div className="flex flex-wrap gap-2 text-sm sm:col-span-3">{shed.points.map((w) => <label key={w.id} className="flex items-center gap-1"><input type="checkbox" checked={form.data.point_ids.includes(w.id)} onChange={() => toggle(w.id)} /> {w.name}</label>)}</div> : null}
                <div className="flex gap-2 sm:col-span-3"><Button type="submit" disabled={form.processing}>Raise Water Incident</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}

/** D-198: lab tests of water points; an unsafe result opens a contamination incident. */
function WaterTests({ waterTests, waterPoints }: Props) {
    const form = useForm({ water_point_id: String(waterPoints.find((w) => w.active)?.id ?? ''), sampled_on: new Date().toISOString().slice(0, 10), lab: '', ph: '', tds_ppm: '', coliform_cfu: '', nitrate_mg_l: '', verdict: 'safe', notes: '', cost: '' });
    const e = form.errors as Record<string, string>;
    const num = (v: string) => (v === '' ? null : v);
    return (
        <div className="space-y-3">
            <Panel title="Record Water Test" description="Enter the lab's result and its verdict. Unsafe water opens a contamination incident; the lab fee goes to Accounting.">
                {waterPoints.length ? (
                    <form className="grid gap-3 p-4 sm:grid-cols-5" onSubmit={(ev) => { ev.preventDefault(); form.transform((x) => ({ ...x, ph: num(x.ph), tds_ppm: num(x.tds_ppm), coliform_cfu: num(x.coliform_cfu), nitrate_mg_l: num(x.nitrate_mg_l), cost: num(x.cost), lab: x.lab || null, notes: x.notes || null })); form.post('/environment/water/tests', { preserveScroll: true, onSuccess: () => form.reset('ph', 'tds_ppm', 'coliform_cfu', 'nitrate_mg_l', 'notes', 'cost') }); }}>
                        <Field label="Water point" error={e.water_point_id}><select className="field" value={form.data.water_point_id} onChange={(x) => form.setData('water_point_id', x.target.value)}>{waterPoints.filter((w) => w.active).map((w) => <option key={w.id} value={w.id}>{w.name} · {w.shed}</option>)}</select></Field>
                        <Field label="Sampled on" error={e.sampled_on}><input type="date" className="field" value={form.data.sampled_on} onChange={(x) => form.setData('sampled_on', x.target.value)} /></Field>
                        <Field label="Lab" error={e.lab}><input className="field" value={form.data.lab} onChange={(x) => form.setData('lab', x.target.value)} /></Field>
                        <Field label="pH" error={e.ph}><input type="number" step="0.1" className="field" value={form.data.ph} onChange={(x) => form.setData('ph', x.target.value)} /></Field>
                        <Field label="TDS ppm" error={e.tds_ppm}><input type="number" className="field" value={form.data.tds_ppm} onChange={(x) => form.setData('tds_ppm', x.target.value)} /></Field>
                        <Field label="Coliform / 100 ml" error={e.coliform_cfu}><input type="number" className="field" value={form.data.coliform_cfu} onChange={(x) => form.setData('coliform_cfu', x.target.value)} /></Field>
                        <Field label="Nitrate mg/L" error={e.nitrate_mg_l}><input type="number" step="0.1" className="field" value={form.data.nitrate_mg_l} onChange={(x) => form.setData('nitrate_mg_l', x.target.value)} /></Field>
                        <Field label="Verdict" error={e.verdict}><select className="field" value={form.data.verdict} onChange={(x) => form.setData('verdict', x.target.value)}><option value="safe">Safe to drink</option><option value="unsafe">Unsafe</option></select></Field>
                        <Field label="Lab fee PKR" error={e.cost}><input type="number" min={0} className="field" value={form.data.cost} onChange={(x) => form.setData('cost', x.target.value)} /></Field>
                        <Field label="Notes" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
                        <div className="sm:col-span-5"><Button type="submit" disabled={form.processing}>Save Test</Button></div>
                    </form>
                ) : <Empty title="No water points">Add water points under setup first.</Empty>}
            </Panel>
            <Panel flush title="Test Results">
                {waterTests.length ? <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>Sampled</th><th>Point</th><th>Lab</th><th>pH</th><th>TDS</th><th>Coliform</th><th>Nitrate</th><th>Verdict</th><th>Fee</th><th>By</th></tr></thead><tbody>
                    {waterTests.map((t) => <tr key={t.id}><td>{t.on}</td><td>{t.point}<span className="block text-xs text-ink-soft">{t.shed}</span></td><td>{t.lab ?? '—'}</td><td>{t.ph ?? '—'}</td><td>{t.tds ?? '—'}</td><td>{t.coliform ?? '—'}</td><td>{t.nitrate ?? '—'}</td><td><Chip className={t.verdict === 'safe' ? 'bg-ok-100 text-ok-700' : 'bg-crit-100 text-crit-700'}>{t.verdict}</Chip></td><td>{t.cost ? pkr(t.cost / 100) : '—'}</td><td>{t.by}</td></tr>)}
                </tbody></table></div> : <Empty title="No water tests yet" />}
            </Panel>
        </div>
    );
}

function IssueItem({ i, options, canVerify, isVet }: { i: Issue; options: Props['options']; canVerify: boolean; isVet: boolean }) {
    const [fixing, setFixing] = useState(false);
    const form = useForm({ resolution: '' });
    const verify = useForm({});
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <LibIcon name={i.kind === 'water' ? 'ic-sick' : i.status === 'verified' ? 'ic-healthy' : 'ic-recovery'} size={20} />
                <strong className="text-cobalt-900">{options.issues[i.kind]}</strong><span>{i.shed}</span>
                <Chip className={SEV[i.severity] ?? 'bg-glaze text-ink-soft'}>{i.severity}</Chip>
                <Chip className={i.status === 'open' ? 'bg-crit-100 text-crit-700' : i.status === 'resolved' ? 'bg-watch-100 text-watch-700' : 'bg-ok-100 text-ok-700'}>{i.status === 'open' ? 'Open' : i.status === 'resolved' ? 'Resolved: verify' : `Verified by ${i.verifiedBy}`}</Chip>
                <span className="text-ink-soft">{i.at}</span>
                <span className="ml-auto flex gap-1.5">
                    {i.status === 'open' ? <Button variant="secondary" onClick={() => setFixing(!fixing)}>{i.water ? 'Supply Restored' : 'Mark Resolved'}</Button> : null}
                    {i.status === 'resolved' && canVerify ? <Button onClick={() => verify.post(`/environment/issues/${i.id}/verify`, { preserveScroll: true })}>Verify</Button> : null}
                </span>
            </div>
            <p className="mt-1">{i.description}</p>
            {i.water ? <WaterTimeline i={i} w={i.water} options={options} canApprove={canVerify} isVet={isVet} /> : null}
            {i.resolution ? <p className="mt-1 text-ok-700">Fixed by {i.resolvedBy}: {i.resolution}</p> : null}
            {fixing ? <form className="mt-2 flex gap-2" onSubmit={(ev) => { ev.preventDefault(); form.post(`/environment/issues/${i.id}/resolve`, { preserveScroll: true, onSuccess: () => setFixing(false) }); }}><input className="field flex-1" placeholder="What was done" value={form.data.resolution} onChange={(x) => form.setData('resolution', x.target.value)} /><Button type="submit" disabled={form.processing}>Save</Button></form> : null}
        </li>
    );
}

/** D-198: one water incident from start to verified fix: safe supply, repair, welfare, vet check, restoration, cost. */
function WaterTimeline({ i, w, options, canApprove, isVet }: { i: Issue; w: WaterInfo; options: Props['options']; canApprove: boolean; isVet: boolean }) {
    const [mode, setMode] = useState<'supply' | 'health' | null>(null);
    const supply = useForm({ source: 'tanker', cost: '' });
    const health = useForm({ goats_ill: '0', outcome: '' });
    const approve = useForm({});
    const open = i.status === 'open';
    const step = (done: boolean, label: React.ReactNode) => <li className={`flex items-start gap-2 ${done ? 'text-ink' : 'text-ink-soft'}`}><span className={`mt-1 size-2.5 shrink-0 rounded-full ${done ? 'bg-ok-700' : 'bg-rule'}`} />{label}</li>;
    return (
        <div className="mt-2 rounded-tile bg-glaze p-3">
            <div className="mb-1 flex flex-wrap gap-3 text-xs text-ink-soft"><span>{options.problems[w.problem ?? ''] ?? 'Water problem'}</span><span>Outage {mins(w.outage)}{w.restored ? '' : ' and counting'}</span>{w.exposed !== null ? <span>{w.exposed} goats exposed</span> : null}{w.cost ? <span>Cost {pkr(w.cost / 100)}</span> : null}</div>
            <ul className="space-y-1">
                {step(true, <>Started {w.started}</>)}
                {step(!!w.supply, w.supply ? <>Emergency supply: {options.sources[w.supply.source]} at {w.supply.at} by {w.supply.by}{w.supply.cost ? ` · ${pkr(w.supply.cost / 100)}` : ''}</> : 'Emergency supply not recorded yet')}
                {w.supply ? step(!!w.supply.approvedBy, w.supply.approvedBy ? <>Approved as safe by {w.supply.approvedBy} ({w.supply.approvedAt})</> : 'Supply waiting for safety approval') : null}
                {w.jobs.map((j) => step(j.status === 'done', <>Repair job: {j.asset} · {j.status}</>))}
                {w.welfare ? step(w.welfare.status === 'closed', <>Welfare case {w.welfare.label} · {w.welfare.status}</>) : null}
                {step(!!w.checkedBy, w.checkedBy ? <>Vet check by {w.checkedBy}: {w.ill} ill · {w.outcome}</> : 'Vet health check of the goats pending')}
                {step(!!w.restored, w.restored ? <>Supply restored {w.restored}</> : 'Normal supply not restored')}
            </ul>
            {w.blockers.length ? <p className="mt-1 text-xs font-semibold text-crit-700">Before verifying: {w.blockers.join(', ')}</p> : null}
            <div className="mt-2 flex flex-wrap gap-1.5">
                {open && !w.supply ? <Button variant="secondary" onClick={() => setMode(mode === 'supply' ? null : 'supply')}>Record Emergency Supply</Button> : null}
                {open && w.supply && !w.supply.approvedBy && canApprove ? <Button onClick={() => approve.post(`/environment/issues/${i.id}/approve-supply`, { preserveScroll: true })}>Approve Supply as Safe</Button> : null}
                {i.status !== 'verified' && isVet && !w.checkedBy ? <Button variant="secondary" onClick={() => setMode(mode === 'health' ? null : 'health')}>Vet Health Check</Button> : null}
            </div>
            {mode === 'supply' ? (
                <form className="mt-2 flex flex-wrap items-end gap-2" onSubmit={(ev) => { ev.preventDefault(); supply.transform((x) => ({ ...x, cost: x.cost || null })); supply.post(`/environment/issues/${i.id}/supply`, { preserveScroll: true, onSuccess: () => setMode(null) }); }}>
                    <Field label="Source"><select className="field" value={supply.data.source} onChange={(x) => supply.setData('source', x.target.value)}>{Object.entries(options.sources).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                    <Field label="Cost PKR" error={supply.errors.cost}><input type="number" min={0} className="field w-32" value={supply.data.cost} onChange={(x) => supply.setData('cost', x.target.value)} /></Field>
                    <Button type="submit" disabled={supply.processing}>Save</Button>
                </form>
            ) : null}
            {mode === 'health' ? (
                <form className="mt-2 flex flex-wrap items-end gap-2" onSubmit={(ev) => { ev.preventDefault(); health.post(`/environment/issues/${i.id}/health-check`, { preserveScroll: true, onSuccess: () => setMode(null) }); }}>
                    <Field label="Goats ill" error={health.errors.goats_ill}><input type="number" min={0} className="field w-24" value={health.data.goats_ill} onChange={(x) => health.setData('goats_ill', x.target.value)} /></Field>
                    <Field label="Findings and action" error={health.errors.outcome}><input className="field w-72" value={health.data.outcome} onChange={(x) => health.setData('outcome', x.target.value)} required /></Field>
                    <Button type="submit" disabled={health.processing}>Save</Button>
                </form>
            ) : null}
        </div>
    );
}

function Setup({ waterPoints, sheds, pens, assets, options, threshold }: Props) {
    const [editing, setEditing] = useState<Props['waterPoints'][number] | 'new' | null>(null);
    const limit = useForm({ heat_stress_thi: String(threshold) });
    return (
        <div className="space-y-3">
            <Panel title="Heat-Stress Limit" description="THI from temperature and humidity at which a round raises a heat-stress alert. Set from the vet's / farm SOP.">
                <form className="flex flex-wrap items-end gap-3 p-4" onSubmit={(ev) => { ev.preventDefault(); limit.post('/environment/settings', { preserveScroll: true }); }}>
                    <Field label="Alert at THI" error={limit.errors.heat_stress_thi}><input type="number" step="0.5" className="field w-32" value={limit.data.heat_stress_thi} onChange={(x) => limit.setData('heat_stress_thi', x.target.value)} /></Field>
                    <Button type="submit" disabled={limit.processing}>Save</Button>
                </form>
            </Panel>
            {editing ? <PointForm p={editing === 'new' ? null : editing} sheds={sheds} pens={pens} assets={assets} kinds={options.kinds} onDone={() => setEditing(null)} /> : null}
            <Panel flush title="Water Points & Tanks" actions={<Button onClick={() => setEditing('new')}>+ Water Point</Button>}>
                {waterPoints.length ? <ul className="divide-y divide-rule">{waterPoints.map((w) => (
                    <li key={w.id} className={`flex items-center gap-2 px-4 py-2 text-sm ${w.active ? '' : 'opacity-60'}`}><strong>{w.name}</strong><span className="text-ink-soft">{options.kinds[w.kind]} · {w.shed}</span><Button variant="quiet" className="ml-auto" onClick={() => setEditing(w)}>Edit</Button></li>
                ))}</ul> : <Empty title="No water points yet">Add each trough, drinker line and tank so every round checks them.</Empty>}
            </Panel>
        </div>
    );
}

function PointForm({ p, sheds, pens, assets, kinds, onDone }: { p: Props['waterPoints'][number] | null; sheds: ShedRow[]; pens: Props['pens']; assets: Props['assets']; kinds: Record<string, string>; onDone: () => void }) {
    const form = useForm({ name: p?.name ?? '', kind: p?.kind ?? 'trough', shed_id: String(p?.shedId ?? sheds[0]?.id ?? ''), pen_id: p?.penId ? String(p.penId) : '', asset_id: p?.assetId ? String(p.assetId) : '', active: p?.active ?? true });
    const e = form.errors as Record<string, string>;
    return (
        <Panel title={p ? `Edit ${p.name}` : 'New Water Point'}>
            <form className="grid gap-3 p-4 sm:grid-cols-3" onSubmit={(ev) => { ev.preventDefault(); form.transform((x) => ({ ...x, pen_id: x.pen_id || null, asset_id: x.asset_id || null })); form.post(p ? `/environment/water-points/${p.id}` : '/environment/water-points', { preserveScroll: true, onSuccess: onDone }); }}>
                <Field label="Name" error={e.name}><input className="field" placeholder="e.g. P01 trough" value={form.data.name} onChange={(x) => form.setData('name', x.target.value)} /></Field>
                <Field label="Type" error={e.kind}><select className="field" value={form.data.kind} onChange={(x) => form.setData('kind', x.target.value)}>{Object.entries(kinds).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Shed" error={e.shed_id}><select className="field" value={form.data.shed_id} onChange={(x) => form.setData('shed_id', x.target.value)}>{sheds.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
                <Field label="Pen (optional)" error={e.pen_id}><select className="field" value={form.data.pen_id} onChange={(x) => form.setData('pen_id', x.target.value)}><option value="">—</option>{pens.filter((x) => String(x.shedId) === form.data.shed_id).map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
                <Field label="Pump / tank asset (optional)" error={e.asset_id} hint="A failure opens a repair job on it"><select className="field" value={form.data.asset_id} onChange={(x) => form.setData('asset_id', x.target.value)}><option value="">—</option>{assets.map((a) => <option key={a.id} value={a.id}>{a.name} ({a.code})</option>)}</select></Field>
                <label className="flex items-end gap-2 pb-2 text-sm font-semibold"><input type="checkbox" checked={form.data.active} onChange={(x) => form.setData('active', x.target.checked)} /> In use</label>
                <div className="flex gap-2 sm:col-span-3"><Button type="submit" disabled={form.processing}>Save</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}
