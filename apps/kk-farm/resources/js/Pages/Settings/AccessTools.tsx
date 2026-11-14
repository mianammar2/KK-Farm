import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Check, History, Search, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { useConfirm } from '../../components/Confirm';
import { Button, Note, PageHeader, Panel } from '../../components/ui';

type Change = { kind: string; subject: string; item: string; old: string | null; new: string | null };
type Batch = { batch: string; actor: string; at: string; count: number; rollback_of: string | null; changes: Change[] };
type Step = { text: string; says: string | null; decides: boolean };
type Trace = { label: string; decision: boolean; steps: Step[]; person: string; role: string };
type Check = { label: string; status: 'ok' | 'warn' | 'info'; detail: string; items: string[] };
type Props = {
    tab: 'history' | 'why' | 'health';
    history: Batch[] | null;
    why: { people: Array<{ id: number; name: string; role: string }>; items: Array<{ value: string; label: string }>; trace: Trace | null; user: string; item: string } | null;
    health: Check[] | null;
};

const KIND: Record<string, string> = { role_module: 'Role · app', role_gate: 'Role · action', role_page: 'Role · page', user_module: 'Person · app', user_gate: 'Person · action', user_page: 'Person · page' };
const show = (v: string | null, person: boolean) => (v === null ? (person ? 'follows role' : '—') : v === '1' ? 'allowed' : 'refused');
const TABS = [{ id: 'history', label: 'History', icon: History }, { id: 'why', label: 'Why?', icon: Search }, { id: 'health', label: 'Health checks', icon: ShieldCheck }] as const;

export default function AccessTools({ tab, history, why, health }: Props) {
    const [ask, confirmBox] = useConfirm();
    const [user, setUser] = useState(why?.user ?? '');
    const [item, setItem] = useState(why?.item ?? '');
    const undo = async (b: Batch) => {
        if (await ask({ title: 'Undo this change?', message: `${b.count} ${b.count === 1 ? 'setting goes' : 'settings go'} back to what ${b.count === 1 ? 'it' : 'they'} were before ${b.at}. This is written to the history too.`, confirm: 'Undo', danger: false })) router.post(`/settings/access/history/${b.batch}/rollback`, {}, { preserveScroll: true });
    };
    return (
        <>
            <Head title="Access Tools" />
            <PageHeader title="Access Tools" description="What changed, why a person can or cannot do something, and what needs attention."
                actions={<Link href="/settings/access"><Button variant="secondary"><ArrowLeft size={16} aria-hidden /> Access Rights</Button></Link>} />
            <nav className="mb-4 flex gap-1 border-b border-rule" aria-label="Access tools">
                {TABS.map((t) => <Link key={t.id} href={`/settings/access/tools?tab=${t.id}`} aria-current={tab === t.id ? 'page' : undefined}
                    className={`-mb-px inline-flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-semibold ${tab === t.id ? 'border-forest text-forest' : 'border-transparent text-ink-soft hover:text-ink'}`}><t.icon size={15} aria-hidden /> {t.label}</Link>)}
            </nav>

            {tab === 'history' && history ? (history.length === 0 ? <Note>No access-rights change has been saved yet. Every save from now on is listed here and can be undone.</Note> : (
                <div className="space-y-3">{history.map((b) => (
                    <Panel key={b.batch} title={`${b.at} · ${b.actor}`} description={b.rollback_of ? 'An undo of an earlier change' : `${b.count} ${b.count === 1 ? 'setting' : 'settings'} changed`}
                        actions={b.rollback_of ? undefined : <Button variant="secondary" onClick={() => undo(b)}>Undo</Button>} flush>
                        <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>Where</th><th>Who</th><th>What</th><th>Was</th><th>Now</th></tr></thead><tbody>
                            {b.changes.map((c, i) => <tr key={i}><td>{KIND[c.kind] ?? c.kind}</td><td>{c.subject}</td><td>{c.item}</td><td>{show(c.old, c.kind.startsWith('user'))}</td><td className="font-semibold">{show(c.new, c.kind.startsWith('user'))}</td></tr>)}
                        </tbody></table></div>
                    </Panel>))}
                </div>)) : null}

            {tab === 'why' && why ? (
                <Panel title="Why can this person do this?" description="Pick a person and an app, action or page. The steps below are applied in this order; the one marked decides the answer.">
                    <form className="grid gap-3 sm:grid-cols-[1fr_2fr_auto]" onSubmit={(e) => { e.preventDefault(); router.get('/settings/access/tools', { tab: 'why', user, item }, { preserveState: true }); }}>
                        <select className="field" value={user} onChange={(e) => setUser(e.target.value)} aria-label="Person" required>
                            <option value="">Person…</option>{why.people.map((p) => <option key={p.id} value={p.id}>{p.name} · {p.role}</option>)}
                        </select>
                        <select className="field" value={item} onChange={(e) => setItem(e.target.value)} aria-label="App, action or page" required>
                            <option value="">App, action or page…</option>{why.items.map((i) => <option key={i.value} value={i.value}>{i.label}</option>)}
                        </select>
                        <Button type="submit">Explain</Button>
                    </form>
                    {why.trace ? (
                        <div className="mt-4">
                            <p className="text-sm"><strong>{why.trace.person}</strong> ({why.trace.role}) · {why.trace.label}: <strong className={why.trace.decision ? 'text-forest' : 'text-danger'}>{why.trace.decision ? 'allowed' : 'not allowed'}</strong></p>
                            <ol className="mt-2 space-y-1.5 text-sm">{why.trace.steps.map((s, i) => (
                                <li key={i} className={`flex items-start gap-2 rounded border px-3 py-2 ${s.decides ? 'border-forest bg-forest/5' : 'border-rule'}`}>
                                    <span className="mt-0.5 w-5 shrink-0 text-ink-faint">{s.decides ? <Check size={15} aria-hidden /> : i + 1}</span>
                                    <span className="flex-1">{s.text}</span>
                                    <span className="shrink-0 font-semibold">{s.says ?? ''}{s.decides ? ' (decides)' : ''}</span>
                                </li>))}
                            </ol>
                        </div>) : null}
                </Panel>) : null}

            {tab === 'health' && health ? (
                <div className="grid gap-3 lg:grid-cols-2">{health.map((c) => (
                    <Panel key={c.label} title={c.label} description={c.detail}
                        actions={<span className={`rounded px-2 py-0.5 text-xs font-semibold ${c.status === 'ok' ? 'bg-forest/10 text-forest' : c.status === 'warn' ? 'bg-amber-100 text-amber-900' : 'bg-rule text-ink-soft'}`}>{c.status === 'ok' ? 'OK' : c.status === 'warn' ? 'Needs attention' : 'For information'}</span>}>
                        {c.items.length ? <ul className="max-h-48 space-y-1 overflow-y-auto text-sm">{c.items.map((x, i) => <li key={i} className="border-b border-rule/60 py-1 last:border-0">{x}</li>)}</ul> : <p className="text-sm text-ink-soft">Nothing to show.</p>}
                    </Panel>))}
                </div>) : null}
            {confirmBox}
        </>
    );
}
