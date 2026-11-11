import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Chip, Empty, Field, PageHeader, Panel, pkr, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';

type Case = {
    id: number; no: string; damId: string; dam: string | null; sire: string | null; pen: string | null; type: string; on: string; gestation: number | null; kidsLost: number; status: string; cluster: boolean;
    isolated: string | null; containHours: number | null; handledBy: string | null; ppe: boolean | null; materialNote: string | null; sampled: string | null; samples: string | null; lab: string | null; labCost: number | null;
    labResult: string | null; zoonotic: boolean; diagnosis: string | null; diagnosedBy: string | null; breeding: string | null; breedAfter: string | null; prevention: string | null; closedBy: string | null;
};
type Props = {
    cases: Case[]; kpi: { incidence: number | null; losses: number; ended: number; containHours: number | null; open: number; clusters: number; bySire: Array<{ sire: string | null; losses: number }> };
    pens: Array<{ id: number; name: string; purpose: string }>; staff: Array<{ id: number; name: string; role: string; biohazard_trained: boolean }>;
    options: { types: Record<string, string>; status: Record<string, string>; breeding: Record<string, string> }; isVet: boolean; canHandle: boolean; canTrain: boolean;
};

const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

/** D-206: journey Q Reproductive Loss Investigation (V3 flow 40). */
export default function ReproLoss(p: Props) {
    const [tab, setTab] = useTabParam('cases');
    const open = p.cases.filter((c) => c.status !== 'closed');
    return (
        <>
            <Head title="Pregnancy Loss" />
            <PageHeader title="Pregnancy Loss Investigation" description="Abortion, stillbirth or abnormal kidding: isolate, handle the material safely, sample, and let the vet diagnose. Some causes spread to people: do not touch the material unless trained." />
            <StatRow>
                <Stat label="Loss incidence (12 months)" value={p.kpi.incidence !== null ? `${p.kpi.incidence}%` : '—'} note={`${p.kpi.losses} losses of ${p.kpi.ended} pregnancies ended`} />
                <Stat label="Time to containment" value={p.kpi.containHours !== null ? `${p.kpi.containHours} h` : '—'} note="Recorded → doe isolated" />
                <Stat label="Open investigations" value={p.kpi.open} tone={p.kpi.open ? 'alert' : undefined} />
                <Stat label="Clusters (12 months)" value={p.kpi.clusters} tone={p.kpi.clusters ? 'alert' : undefined} note="2+ losses in a pen within 30 days" />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'cases', label: 'Investigations', count: open.length || undefined }, { id: 'closed', label: 'Closed' }, { id: 'sires', label: 'Losses by Buck' }, ...(p.canTrain ? [{ id: 'staff', label: 'Trained Staff' }] : [])]} />
            {tab === 'cases' ? <Panel flush title="Open Investigations">{open.length ? <ul className="divide-y divide-rule">{open.map((c) => <CaseRow key={c.id} c={c} {...p} />)}</ul> : <Empty title="No open investigations">They open automatically when an abortion, stillbirth or abnormal kidding is recorded.</Empty>}</Panel> : null}
            {tab === 'closed' ? <Panel flush title="Closed (6 months)">{p.cases.some((c) => c.status === 'closed') ? <ul className="divide-y divide-rule">{p.cases.filter((c) => c.status === 'closed').map((c) => <CaseRow key={c.id} c={c} {...p} />)}</ul> : <Empty title="None yet" />}</Panel> : null}
            {tab === 'sires' ? <Panel flush title="Losses by Buck (12 months)">{p.kpi.bySire.length ? <ul className="divide-y divide-rule">{p.kpi.bySire.map((s) => <li key={s.sire} className="flex justify-between px-4 py-2 text-sm"><span className="font-mono">{s.sire}</span><strong>{s.losses}</strong></li>)}</ul> : <Empty title="No losses with a recorded buck" />}</Panel> : null}
            {tab === 'staff' && p.canTrain ? (
                <Panel flush title="Trained to Handle Aborted Material" description="Only the vet and these people may handle aborted material, placentas and samples.">
                    <ul className="divide-y divide-rule">{p.staff.map((u) => <li key={u.id} className="flex items-center gap-2 px-4 py-2 text-sm"><span>{u.name}</span><span className="text-xs text-ink-soft">{u.role}</span>
                        <label className="ml-auto flex items-center gap-2"><input type="checkbox" checked={u.biohazard_trained} onChange={(x) => router.post(`/repro-loss/staff/${u.id}/train`, { trained: x.target.checked }, { preserveScroll: true })} /> Trained</label></li>)}</ul>
                </Panel>
            ) : null}
        </>
    );
}

function CaseRow({ c, pens, options, isVet, canHandle }: { c: Case } & Props) {
    const [mode, setMode] = useState<string | null>(null);
    const iso = pens.filter((p) => /isol|hospital|quarant/i.test(`${p.name} ${p.purpose}`));
    const contain = useForm({ pen_id: String((iso[0] ?? pens[0])?.id ?? '') });
    const material = useForm({ ppe_used: false, note: '' });
    const sample = useForm({ samples: 'Fetus, placenta, blood', lab: '', sampled_on: today(), cost: '' });
    const diag = useForm({ lab_result: c.labResult ?? '', diagnosis: '', zoonotic_suspected: false });
    const close = useForm({ breeding: 'delay', breed_after: '', prevention: '' });
    const err = (f: { errors: object }) => Object.values(f.errors as Record<string, string>)[0];
    const post = (f: ReturnType<typeof useForm<any>>, url: string) => f.post(url, { preserveScroll: true, onSuccess: () => setMode(null) });
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <strong className="font-mono text-cobalt-900">{c.no}</strong>
                <Link href={`/goats/${c.damId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{c.dam}</Link>
                <span>{options.types[c.type]}</span><span className="text-ink-soft">{c.on}{c.gestation ? ` · day ${c.gestation}` : ''}{c.kidsLost ? ` · ${c.kidsLost} kid(s) lost` : ''}{c.sire ? ` · buck ${c.sire}` : ''}{c.pen ? ` · ${c.pen}` : ''}</span>
                <Chip className={c.status === 'closed' ? 'bg-ok-100 text-ok-700' : c.status === 'open' ? 'bg-crit-100 text-crit-700' : 'bg-watch-100 text-watch-700'}>{options.status[c.status]}</Chip>
                {c.cluster ? <Chip className="bg-crit-100 text-crit-700">Cluster</Chip> : null}
                {c.zoonotic ? <Chip className="bg-crit-100 text-crit-700">Zoonotic risk: pen restricted</Chip> : null}
            </div>
            <ul className="mt-1 space-y-0.5 text-xs text-ink-soft">
                {c.isolated ? <li>Isolated {c.isolated} ({c.containHours} h after recording)</li> : null}
                {c.handledBy ? <li>Material handled by {c.handledBy} with protection: {c.materialNote}</li> : null}
                {c.sampled ? <li>Samples {c.sampled}: {c.samples} → {c.lab}{c.labCost ? ` · ${pkr(c.labCost / 100)}` : ''}</li> : null}
                {c.diagnosis ? <li className="text-ink">Diagnosis ({c.diagnosedBy}): {c.diagnosis}{c.labResult ? ` · lab: ${c.labResult}` : ''}</li> : null}
                {c.breeding ? <li className="text-ink">{options.breeding[c.breeding]}{c.breedAfter ? ` · ${c.breedAfter}` : ''} · prevention: {c.prevention}</li> : null}
            </ul>
            {c.status !== 'closed' ? (
                <div className="mt-2 flex flex-wrap gap-1.5">
                    {c.status === 'open' ? <Button onClick={() => setMode('contain')}>Isolate the Doe</Button> : null}
                    {!c.handledBy && canHandle ? <Button variant="secondary" onClick={() => setMode('material')}>Handle Material</Button> : null}
                    {!c.handledBy && !canHandle ? <span className="text-xs font-semibold text-crit-700">Do not touch the material: the vet or trained staff handle it.</span> : null}
                    {['open', 'contained'].includes(c.status) && c.handledBy && canHandle ? <Button variant="secondary" onClick={() => setMode('sample')}>Send Samples</Button> : null}
                    {isVet && c.isolated && !c.diagnosis ? <Button variant="secondary" onClick={() => setMode('diagnose')}>Diagnose</Button> : null}
                    {isVet && c.status === 'diagnosed' ? <Button onClick={() => setMode('close')}>Breeding Decision & Close</Button> : null}
                </div>
            ) : null}
            {mode === 'contain' ? <form className="mt-2 flex flex-wrap items-end gap-2" onSubmit={(ev) => { ev.preventDefault(); post(contain, `/repro-loss/${c.id}/contain`); }}><Field label="Isolation pen" error={err(contain)}><select className="field" value={contain.data.pen_id} onChange={(x) => contain.setData('pen_id', x.target.value)}>{pens.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field><Button type="submit" disabled={contain.processing}>Isolate</Button></form> : null}
            {mode === 'material' ? <form className="mt-2 flex flex-wrap items-end gap-2" onSubmit={(ev) => { ev.preventDefault(); post(material, `/repro-loss/${c.id}/material`); }}><label className="flex items-center gap-2 pb-2"><input type="checkbox" checked={material.data.ppe_used} onChange={(x) => material.setData('ppe_used', x.target.checked)} /> Gloves, mask, protection used</label><input className="field flex-1" placeholder="What was collected; bagged and kept cold for the lab / disposed of how" value={material.data.note} onChange={(x) => material.setData('note', x.target.value)} /><Button type="submit" disabled={material.processing}>Save</Button>{err(material) ? <span className="w-full text-xs text-crit-700">{err(material)}</span> : null}</form> : null}
            {mode === 'sample' ? <form className="mt-2 grid gap-2 sm:grid-cols-5" onSubmit={(ev) => { ev.preventDefault(); sample.transform((d) => ({ ...d, cost: d.cost || null })); post(sample, `/repro-loss/${c.id}/sample`); }}><Field label="Samples"><input className="field" value={sample.data.samples} onChange={(x) => sample.setData('samples', x.target.value)} /></Field><Field label="Lab" error={sample.errors.lab}><input className="field" value={sample.data.lab} onChange={(x) => sample.setData('lab', x.target.value)} /></Field><Field label="Date"><input type="date" max={today()} className="field" value={sample.data.sampled_on} onChange={(x) => sample.setData('sampled_on', x.target.value)} /></Field><Field label="Fee PKR"><input type="number" className="field" value={sample.data.cost} onChange={(x) => sample.setData('cost', x.target.value)} /></Field><div className="flex items-end"><Button type="submit" disabled={sample.processing}>Save</Button></div></form> : null}
            {mode === 'diagnose' ? <form className="mt-2 grid gap-2 sm:grid-cols-4" onSubmit={(ev) => { ev.preventDefault(); post(diag, `/repro-loss/${c.id}/diagnose`); }}><Field label="Lab result"><input className="field" value={diag.data.lab_result} onChange={(x) => diag.setData('lab_result', x.target.value)} /></Field><Field label="Diagnosis" error={diag.errors.diagnosis}><input className="field" value={diag.data.diagnosis} onChange={(x) => diag.setData('diagnosis', x.target.value)} /></Field><label className="flex items-end gap-2 pb-2"><input type="checkbox" checked={diag.data.zoonotic_suspected} onChange={(x) => diag.setData('zoonotic_suspected', x.target.checked)} /> Zoonotic cause suspected (restricts the pen)</label><div className="flex items-end"><Button type="submit" disabled={diag.processing}>Save</Button></div></form> : null}
            {mode === 'close' ? <form className="mt-2 grid gap-2 sm:grid-cols-4" onSubmit={(ev) => { ev.preventDefault(); close.transform((d) => ({ ...d, breed_after: d.breeding === 'delay' ? d.breed_after : null })); post(close, `/repro-loss/${c.id}/close`); }}><Field label="Breeding"><select className="field" value={close.data.breeding} onChange={(x) => close.setData('breeding', x.target.value)}>{Object.entries(options.breeding).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>{close.data.breeding === 'delay' ? <Field label="Breed after" error={close.errors.breed_after}><input type="date" className="field" value={close.data.breed_after} onChange={(x) => close.setData('breed_after', x.target.value)} /></Field> : null}<Field label="Prevention plan" error={close.errors.prevention}><input className="field" value={close.data.prevention} onChange={(x) => close.setData('prevention', x.target.value)} /></Field><div className="flex items-end"><Button type="submit" disabled={close.processing}>Close</Button></div></form> : null}
        </li>
    );
}
