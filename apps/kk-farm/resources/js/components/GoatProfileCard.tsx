import { Link } from '@inertiajs/react';
import { AlertTriangle, ExternalLink, Printer } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import GoatPortrait from './GoatPortrait';
import { AgeBadge, BreedBadge } from './GoatBadges';
import { cx, HealthChip, ReproChip } from './ui';
import type { Health as HealthFlag } from '../types';

/**
 * D-300: one goat's full profile, as a card: shown after a scan, from the registry and the 3D shed, and wherever a
 * goat is shown to someone. Everything at a glance: who it is, its age, PEN, weight and growth, its problems
 * (cases, temperature, Watch, withdrawal, care due; for those with the health right), breeding and family.
 */
type Profile = {
    id: string; number: string; name: string | null; sex: string; breed: string | null; age: string | null; ageExact: boolean; stage: string; ageCode: string | null; ageMonths: number | null;
    teethCode: string | null; roles: string[]; roleLabels: Record<string, string>; pen: string | null; shed: string | null; status: string; health: HealthFlag; repro: string; monitorUntil: string | null;
    photo: number | null; rfid: string | null; origin: string; arrived: string | null; born: string | null; bornEstimated: boolean; from: string | null; purpose: string | null;
    growth: { latestKg: number | null; latestOn: string | null; gain: { kg: number; pct: number | null; days: number } | null; weighings: number; totalGain: number | null; adg: number | null; bcs: number | null; heightIn: number | null };
    breeding: { repro: string | null; pregnancyDay: number | null; lastMating: { on: string | null; buck: string | null; status: string; expected: string | null } | null; kids: number; kiddings: number | null };
    family: { dam: { id: string; number: string } | null; sire: { id: string; number: string } | null };
    missing: string[]; seeHealth: boolean; healthDetail: Health | null;
};
type Health = {
    cases: Array<{ label: string; condition: string; disease: string | null; code: string | null; severity: string; since: string | null; isolated: boolean; symptoms: string[] }>;
    lastCheck: { on: string | null; temp: number | null; fever: boolean; result: string; by: string; findings: string | null } | null;
    watch: { state: string; since: string; title: string } | null;
    withdrawal: { meat: string | null; milk: string | null } | null;
    lastTreatment: { on: string | null; product: string; dose: string | null } | null;
    careDue: { what: string; on: string | null; overdue: boolean } | null;
};

const WATCH: Record<string, string> = { improving: 'improving', not_improving: 'not improving', no_recheck: 'waiting for a recheck', recovered: 'recovered' };

function Item({ label, children }: { label: string; children: ReactNode }) {
    return <div className="min-w-0"><dt className="text-xs text-ink-soft">{label}</dt><dd className="text-sm break-words text-ink">{children ?? '—'}</dd></div>;
}

export default function GoatProfileCard({ id, actions }: { id: string; actions?: ReactNode }) {
    const [p, setP] = useState<Profile | null>(null);
    const [h, setH] = useState<Health | null>(null);
    const [failed, setFailed] = useState(false);
    useEffect(() => {
        const c = new AbortController();
        setP(null); setFailed(false);
        fetch(`/goats/${id}/profile`, { headers: { Accept: 'application/json' }, signal: c.signal })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((d: Profile) => { setP(d); setH(d.healthDetail); })
            .catch((e) => { if (e?.name !== 'AbortError') setFailed(true); });
        return () => c.abort();
    }, [id]);

    if (failed) return <p className="p-4 text-sm text-crit-700">The profile could not be loaded.</p>;
    if (!p) return <p className="p-4 text-sm text-ink-soft">Loading the goat's profile…</p>;
    const g = p.growth, b = p.breeding;
    const problems: Array<{ tone: 'crit' | 'watch'; text: ReactNode }> = [];
    for (const c of h?.cases ?? []) problems.push({ tone: 'crit', text: <><b className="font-medium">{c.disease ?? c.condition}</b>{c.code ? ` (${c.code})` : ''} · {c.severity}{c.isolated ? ' · isolated' : ''} · since {c.since}{c.symptoms.length ? <span className="block text-xs opacity-80">Signs: {c.symptoms.join(', ')}</span> : null}</> });
    if (h?.watch) problems.push({ tone: 'watch', text: <>On Watch since {h.watch.since}: {h.watch.title} · {WATCH[h.watch.state] ?? h.watch.state}</> });
    if (h?.withdrawal) problems.push({ tone: 'crit', text: <>Under withdrawal{h.withdrawal.meat ? ` · no sale for meat before ${h.withdrawal.meat}` : ''}{h.withdrawal.milk ? ` · no milk before ${h.withdrawal.milk}` : ''}</> });
    if (h?.careDue?.overdue) problems.push({ tone: 'watch', text: <>Overdue: {h.careDue.what} (due {h.careDue.on})</> });
    if (h?.lastCheck?.fever) problems.push({ tone: 'crit', text: <>Last temperature {h.lastCheck.temp} °F on {h.lastCheck.on} is outside 101.3–103.5 °F</> });

    return (
        <article className="kk-print-card @container overflow-hidden rounded-tile border border-rule bg-tile" aria-label={`Profile of ${p.number}`}>
            <header className="flex flex-col gap-4 border-b border-rule p-4 @xl:flex-row">
                <div className="shrink-0 self-center @xl:self-start">{p.photo ? <img src={`/media/${p.photo}`} alt="" className="size-28 rounded-2xl object-cover" /> : <GoatPortrait number={p.number} sex={p.sex} stage={p.stage} repro={p.repro} size={112} />}</div>
                <div className="min-w-0 flex-1">
                    <h2 className="font-mono text-2xl text-cobalt-900">{p.number}</h2>
                    <p className="flex flex-wrap items-center gap-1.5 text-ink-soft">{p.name ? <span>{p.name} ·</span> : null}<BreedBadge breed={p.breed} withName /><span>·</span><AgeBadge stage={p.stage} code={p.ageCode} /> <span>{p.sex === 'female' ? 'Female' : p.sex === 'male' ? 'Male' : ''}</span></p>
                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                        {p.status === 'active' ? <HealthChip health={p.health} /> : <span className="rounded bg-glaze px-2 py-0.5 text-xs text-ink-soft">Left the farm</span>}
                        <ReproChip repro={p.repro} until={p.monitorUntil} />
                        {Object.entries(p.roleLabels).map(([c, l]) => <span key={c} className="rounded-full bg-turq-100 px-2 py-0.5 text-xs text-turq-800">{l}</span>)}
                    </div>
                    <div className="mt-3 grid grid-cols-2 gap-2 @3xl:grid-cols-4">
                        <Big label="Age">{p.ageExact && p.ageMonths !== null ? `${p.ageMonths} months` : p.ageCode ?? p.age ?? '—'}</Big>
                        <Big label="Weight">{g.latestKg !== null ? `${g.latestKg} KG` : '—'}{g.gain ? <span className={cx('block text-xs', g.gain.kg > 0 ? 'text-ok-700' : g.gain.kg < 0 ? 'text-crit-700' : 'text-ink-soft')}>{g.gain.kg > 0 ? '▲ +' : g.gain.kg < 0 ? '▼ ' : ''}{g.gain.kg} KG in {g.gain.days} days</span> : null}</Big>
                        <Big label="Temperature">{h?.lastCheck?.temp != null ? <span className={h.lastCheck.fever ? 'text-crit-700' : ''}>{h.lastCheck.temp} °F</span> : '—'}{h?.lastCheck ? <span className="block text-xs text-ink-soft">{h.lastCheck.on}</span> : null}</Big>
                        <Big label="PEN">{p.pen ?? 'No PEN'}{p.shed ? <span className="block text-xs text-ink-soft">{p.shed}</span> : null}</Big>
                    </div>
                </div>
            </header>
            {problems.length ? (
                <div className="space-y-1.5 border-b border-rule bg-crit-100/40 px-4 py-3">
                    <p className="flex items-center gap-1.5 text-sm text-crit-700"><AlertTriangle size={15} aria-hidden /> Problems</p>
                    {problems.map((x, i) => <p key={i} className={cx('rounded-md px-2.5 py-1.5 text-sm', x.tone === 'crit' ? 'bg-crit-100 text-crit-700' : 'bg-watch-100 text-watch-700')}>{x.text}</p>)}
                </div>
            ) : p.seeHealth ? <p className="border-b border-rule bg-ok-100/50 px-4 py-2 text-sm text-ok-700">No open problems.</p> : null}
            <div className="grid gap-x-6 gap-y-4 p-4 @md:grid-cols-2 @4xl:grid-cols-4">
                <Section title="Identity">
                    <Item label="RFID tag">{p.rfid ?? 'None'}</Item>
                    <Item label="Entry">{p.origin}{p.arrived ? ` · ${p.arrived}` : ''}</Item>
                    {p.from ? <Item label="From farm">{p.from}</Item> : null}
                    <Item label="Birth date">{p.born ? `${p.born}${p.bornEstimated ? ' (estimated)' : ''}` : 'Not known'}</Item>
                    <Item label="Teeth">{p.teethCode ?? '—'}</Item>
                    {p.purpose ? <Item label="Purpose">{p.purpose}</Item> : null}
                </Section>
                <Section title="Growth">
                    <Item label="Weighings">{g.weighings}{g.latestOn ? ` · last ${g.latestOn}` : ''}</Item>
                    <Item label="Total gain">{g.totalGain !== null ? `${g.totalGain > 0 ? '+' : ''}${g.totalGain} KG` : '—'}</Item>
                    <Item label="Daily gain">{g.adg !== null ? `${g.adg} g / day` : '—'}</Item>
                    <Item label="Body score">{g.bcs ?? '—'}</Item>
                    <Item label="Height">{g.heightIn !== null ? `${g.heightIn} in` : '—'}</Item>
                </Section>
                {h ? (
                    <Section title="Health">
                        <Item label="Last checkup">{h.lastCheck ? `${h.lastCheck.on} · ${h.lastCheck.result}${h.lastCheck.by ? ` · ${h.lastCheck.by}` : ''}` : 'None yet'}</Item>
                        {h.lastCheck?.findings ? <Item label="Findings">{h.lastCheck.findings}</Item> : null}
                        <Item label="Last treatment">{h.lastTreatment ? `${h.lastTreatment.on} · ${h.lastTreatment.product}${h.lastTreatment.dose ? ` ${h.lastTreatment.dose}` : ''}` : 'None'}</Item>
                        <Item label="Next care">{h.careDue ? `${h.careDue.what} · ${h.careDue.on}` : '—'}</Item>
                    </Section>
                ) : null}
                <Section title="Breeding & family">
                    {b.repro ? <Item label="Breeding status">{b.repro}{b.pregnancyDay ? ` · day ${b.pregnancyDay}` : ''}</Item> : null}
                    {b.lastMating ? <Item label="Last mating">{b.lastMating.on}{b.lastMating.buck ? ` · ${b.lastMating.buck}` : ''} · {b.lastMating.status}</Item> : null}
                    {b.lastMating?.expected ? <Item label="Expected kidding">{b.lastMating.expected}</Item> : null}
                    <Item label="Kids">{b.kids}{b.kiddings !== null ? ` · ${b.kiddings} kiddings` : ''}</Item>
                    <Item label="Mother">{p.family.dam ? <Link href={`/goats/${p.family.dam.id}`} className="font-mono text-cobalt-800 hover:underline">{p.family.dam.number}</Link> : '—'}</Item>
                    <Item label="Father">{p.family.sire ? <Link href={`/goats/${p.family.sire.id}`} className="font-mono text-cobalt-800 hover:underline">{p.family.sire.number}</Link> : '—'}</Item>
                </Section>
            </div>
            {p.missing.length ? <p className="border-t border-rule px-4 py-2 text-sm text-watch-700">Missing: {p.missing.join(', ')}</p> : null}
            <footer className="flex flex-wrap items-center gap-2 border-t border-rule px-4 py-3 print:hidden">
                {actions}
                <Link href={`/goats/${p.id}`} className="inline-flex h-9 items-center gap-1.5 rounded-md border border-rule px-3 text-sm text-cobalt-800 hover:bg-glaze"><ExternalLink size={15} aria-hidden /> Open full record</Link>
                <button type="button" onClick={() => window.print()} className="inline-flex h-9 items-center gap-1.5 rounded-md border border-rule px-3 text-sm text-cobalt-800 hover:bg-glaze"><Printer size={15} aria-hidden /> Print</button>
            </footer>
        </article>
    );
}

function Big({ label, children }: { label: string; children: ReactNode }) {
    return <div className="rounded-md bg-glaze px-3 py-2"><p className="text-xs text-ink-soft">{label}</p><p className="text-lg text-cobalt-900 tabular-nums">{children}</p></div>;
}
function Section({ title, children }: { title: string; children: ReactNode }) {
    return <section><h3 className="mb-2 text-xs tracking-wide text-ink-soft uppercase">{title}</h3><dl className="space-y-1.5">{children}</dl></section>;
}
