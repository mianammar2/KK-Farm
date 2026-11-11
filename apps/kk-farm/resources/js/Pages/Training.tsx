import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Chip, Empty, Field, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';
import { useFive } from '../lib/useFive';

type Cell = { state: 'ok' | 'due' | 'expired' | 'missing'; on: string | null; expires: string | null };
type Person = { id: number; name: string; code: string; role: string; cells: Record<string, Cell>; compliant: boolean; skills: string[] };
type Skill = { id: number; person: string | null; skill: string; authorisation: boolean; level: string; evidence: string | null; on: string; until: string | null; valid: boolean; status: string; by: string; revokedBy: string | null; revokeReason: string | null };
type Props = {
    matrix: Person[]; skills: Skill[];
    trainings: Array<{ id: number; person: string | null; topic: string; title: string | null; on: string; expires: string | null; expired: boolean; trainer: string | null; certificate: string | null; by: string }>;
    kpi: { people: number; compliance: number | null; expired: number; due: number; authorised: number };
    people: Array<{ id: number; name: string }>; options: { topics: Record<string, string>; skills: Record<string, string>; levels: Record<string, string> }; canEdit: boolean;
};

const STATE: Record<string, [string, string]> = { ok: ['Current', 'bg-ok-100 text-ok-700'], due: ['Due soon', 'bg-watch-100 text-watch-700'], expired: ['Expired', 'bg-crit-100 text-crit-700'], missing: ['Missing', 'bg-crit-100 text-crit-700'] };
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

/** D-210: App 14 Training & Skills. */
export default function Training(p: Props) {
    const [tab, setTab] = useTabParam('matrix');
    return (
        <>
            <Head title="Training & Skills" />
            <PageHeader title="Training & Skills" description="The training each job needs (everyone: humane handling and biosecurity), when it expires, and skills and authorisations with their evidence. Giving medicines, releasing milk and approving payments are separate authorisations." />
            <StatRow>
                <Stat label="Training compliance" value={p.kpi.compliance === null ? '—' : `${p.kpi.compliance}%`} note={`${p.kpi.people} active staff`} tone={p.kpi.compliance !== null && p.kpi.compliance < 100 ? 'alert' : undefined} />
                <Stat label="Missing or expired" value={p.kpi.expired} tone={p.kpi.expired ? 'alert' : undefined} />
                <Stat label="Due in 30 days" value={p.kpi.due} />
                <Stat label="Valid authorisations" value={p.kpi.authorised} />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'matrix', label: 'Compliance' }, { id: 'records', label: 'Training Records', count: p.trainings.length || undefined }, { id: 'skills', label: 'Skills & Authorisations', count: p.skills.filter((s) => s.valid).length || undefined }]} />
            {tab === 'matrix' ? <Matrix {...p} /> : null}
            {tab === 'records' ? <Records {...p} /> : null}
            {tab === 'skills' ? <Skills {...p} /> : null}
        </>
    );
}

function Matrix({ matrix, options }: Props) {
    const [shown, more] = useFive(matrix, 30);
    return (
        <Panel flush title="Training Each Person Needs">
            {matrix.length ? <ul className="divide-y divide-rule">{shown.map((m) => (
                <li key={m.id} className="px-4 py-3 text-sm">
                    <div className="flex flex-wrap items-center gap-2"><strong>{m.name}</strong><span className="text-ink-soft">{m.role} · {m.code}</span>{m.compliant ? <Chip className="bg-ok-100 text-ok-700">Compliant</Chip> : <Chip className="bg-crit-100 text-crit-700">Not compliant</Chip>}</div>
                    <div className="mt-1 flex flex-wrap gap-1.5">{Object.entries(m.cells).map(([topic, c]) => <Chip key={topic} className={STATE[c.state][1]}>{options.topics[topic]}: {STATE[c.state][0]}{c.expires ? ` (${c.expires})` : ''}</Chip>)}</div>
                    {m.skills.length ? <p className="mt-1 text-xs text-ink-soft">Skills: {m.skills.join(', ')}</p> : null}
                </li>
            ))}</ul> : <Empty title="No active employees">Add staff under Employees & Departments.</Empty>}
            {more}
        </Panel>
    );
}

function Records(p: Props) {
    const [shown, more] = useFive(p.trainings, 20);
    return (
        <div className="space-y-4">
            {p.canEdit ? <TrainForm {...p} /> : null}
            <Panel flush title="Training Records">
                {p.trainings.length ? <ul className="divide-y divide-rule">{shown.map((t) => (
                    <li key={t.id} className="flex flex-wrap items-center gap-2 px-4 py-3 text-sm">
                        <strong>{t.person}</strong><span className="font-semibold">{t.topic}</span>{t.title ? <span>{t.title}</span> : null}
                        <span className="text-ink-soft">{t.on}{t.trainer ? ` · by ${t.trainer}` : ''}{t.certificate ? ` · cert ${t.certificate}` : ''}</span>
                        {t.expires ? <Chip className={t.expired ? 'bg-crit-100 text-crit-700' : 'bg-glaze'}>{t.expired ? 'Expired' : 'Expires'} {t.expires}</Chip> : null}
                    </li>
                ))}</ul> : <Empty title="No training recorded" />}
                {more}
            </Panel>
        </div>
    );
}

function TrainForm({ people, options }: Props) {
    const form = useForm({ employee_ids: [] as number[], topic: 'handling', title: '', trained_on: today(), trainer: '', expires_on: '', certificate_ref: '' });
    const e = form.errors as Record<string, string>;
    const toggle = (id: number) => form.setData('employee_ids', form.data.employee_ids.includes(id) ? form.data.employee_ids.filter((x) => x !== id) : [...form.data.employee_ids, id]);
    return (
        <Panel title="Record a Training Session" description="Tick everyone who attended.">
            <div className="mb-2 flex flex-wrap gap-1.5">{people.map((pp) => <button key={pp.id} type="button" onClick={() => toggle(pp.id)} className={form.data.employee_ids.includes(pp.id) ? 'rounded-full bg-cobalt-800 px-2.5 py-1 text-xs font-semibold text-white' : 'rounded-full bg-glaze px-2.5 py-1 text-xs font-semibold ring-1 ring-rule'}>{pp.name}</button>)}</div>
            {e.employee_ids ? <p className="mb-2 text-sm text-crit-700">{e.employee_ids}</p> : null}
            <div className="grid gap-2 sm:grid-cols-3">
                <Field label="Topic" error={e.topic}><select className="field" value={form.data.topic} onChange={(x) => form.setData('topic', x.target.value)}>{Object.entries(options.topics).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Title" error={e.title}><input className="field" value={form.data.title} onChange={(x) => form.setData('title', x.target.value)} /></Field>
                <Field label="Date" error={e.trained_on}><input type="date" max={today()} className="field" value={form.data.trained_on} onChange={(x) => form.setData('trained_on', x.target.value)} /></Field>
                <Field label="Trainer" error={e.trainer}><input className="field" value={form.data.trainer} onChange={(x) => form.setData('trainer', x.target.value)} /></Field>
                <Field label="Expires on (refresher due)" error={e.expires_on}><input type="date" className="field" value={form.data.expires_on} onChange={(x) => form.setData('expires_on', x.target.value)} /></Field>
                <Field label="Certificate ref" error={e.certificate_ref}><input className="field" value={form.data.certificate_ref} onChange={(x) => form.setData('certificate_ref', x.target.value)} /></Field>
            </div>
            <div className="mt-2"><Button disabled={form.processing} onClick={() => { form.transform((d) => ({ ...d, expires_on: d.expires_on || null })); form.post('/hr/training', { preserveScroll: true, onSuccess: () => form.reset('employee_ids', 'title', 'certificate_ref') }); }}>Save Training</Button></div>
        </Panel>
    );
}

function Skills(p: Props) {
    const [shown, more] = useFive(p.skills, 20);
    return (
        <div className="space-y-4">
            {p.canEdit ? <SkillForm {...p} /> : null}
            <Panel flush title="Skills & Authorisations">
                {p.skills.length ? <ul className="divide-y divide-rule">{shown.map((s) => <SkillItem key={s.id} s={s} canEdit={p.canEdit} />)}</ul> : <Empty title="No skills recorded" />}
                {more}
            </Panel>
        </div>
    );
}

function SkillItem({ s, canEdit }: { s: Skill; canEdit: boolean }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ reason: '' });
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <strong>{s.person}</strong><span className="font-semibold">{s.skill}</span>{s.authorisation ? <Chip className="bg-cobalt-100 text-cobalt-800">Authorisation</Chip> : null}
                <span className="text-ink-soft">{s.level} · {s.on} · assessed by {s.by}{s.until ? ` · valid until ${s.until}` : ''}</span>
                {s.status === 'revoked' ? <Chip className="bg-crit-100 text-crit-700">Revoked by {s.revokedBy}</Chip> : s.valid ? <Chip className="bg-ok-100 text-ok-700">Valid</Chip> : <Chip className="bg-crit-100 text-crit-700">Expired</Chip>}
                {canEdit && s.status === 'active' ? <Button variant="secondary" className="ml-auto" onClick={() => setOpen(!open)}>Revoke</Button> : null}
            </div>
            {s.evidence ? <p className="text-ink-soft">Evidence: {s.evidence}</p> : null}
            {s.revokeReason ? <p className="text-ink-soft">Revoked: {s.revokeReason}</p> : null}
            {open ? <div className="mt-2 flex flex-wrap items-end gap-2"><div className="min-w-60 flex-1"><Field label="Reason" error={form.errors.reason}><input className="field" value={form.data.reason} onChange={(x) => form.setData('reason', x.target.value)} /></Field></div><Button disabled={form.processing} onClick={() => form.post(`/hr/skills/${s.id}/revoke`, { preserveScroll: true })}>Revoke</Button></div> : null}
        </li>
    );
}

function SkillForm({ people, options }: Props) {
    const form = useForm({ employee_id: '', skill: 'milker', level: 'competent', evidence: '', assessed_on: today(), valid_until: '' });
    const e = form.errors as Record<string, string>;
    return (
        <Panel title="Record a Skill or Authorisation" description="Authorisations (vet licence, giving medicines, releasing milk, approving payments) are granted by the owner or manager and need evidence.">
            <div className="grid gap-2 sm:grid-cols-3">
                <Field label="Person" error={e.employee_id}><select className="field" value={form.data.employee_id} onChange={(x) => form.setData('employee_id', x.target.value)}><option value="">Choose…</option>{people.map((pp) => <option key={pp.id} value={pp.id}>{pp.name}</option>)}</select></Field>
                <Field label="Skill" error={e.skill}><select className="field" value={form.data.skill} onChange={(x) => form.setData('skill', x.target.value)}>{Object.entries(options.skills).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Level" error={e.level}><select className="field" value={form.data.level} onChange={(x) => form.setData('level', x.target.value)}>{Object.entries(options.levels).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Evidence" error={e.evidence}><input className="field" value={form.data.evidence} onChange={(x) => form.setData('evidence', x.target.value)} placeholder="Licence no., assessment, approval" /></Field>
                <Field label="Assessed on" error={e.assessed_on}><input type="date" max={today()} className="field" value={form.data.assessed_on} onChange={(x) => form.setData('assessed_on', x.target.value)} /></Field>
                <Field label="Valid until" error={e.valid_until}><input type="date" className="field" value={form.data.valid_until} onChange={(x) => form.setData('valid_until', x.target.value)} /></Field>
            </div>
            <div className="mt-2"><Button disabled={form.processing} onClick={() => { form.transform((d) => ({ ...d, valid_until: d.valid_until || null })); form.post('/hr/skills', { preserveScroll: true, onSuccess: () => form.reset('evidence') }); }}>Save Skill</Button></div>
        </Panel>
    );
}
