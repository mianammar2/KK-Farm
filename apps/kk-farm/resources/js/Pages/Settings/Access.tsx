import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Lock } from 'lucide-react';
import { Button, PageHeader, Panel } from '../../components/ui';
import type { SharedProps } from '../../types';

type Props = {
    modules: Record<string, string[]>; gates: Record<string, string[]>; moduleLabels: Record<string, string>; gateLabels: Record<string, string>; protected: string[];
    overrides: Array<{ id: number; name: string; count: number }>; canEdit: boolean;
};

/** D-215: master access rights: what each role opens and may do. People's own ticks (Users → person) go on top. */
export default function Access(p: Props) {
    const { roles } = usePage<SharedProps>().props;
    const roleKeys = Object.keys(roles);
    const form = useForm({ modules: p.modules, gates: p.gates });
    const toggle = (kind: 'modules' | 'gates', key: string, role: string) => {
        const cur = form.data[kind][key] ?? [];
        form.setData(kind, { ...form.data[kind], [key]: cur.includes(role) ? cur.filter((r) => r !== role) : [...cur, role] });
    };
    const box = (kind: 'modules' | 'gates', key: string, role: string, locked: boolean) => (
        <td key={role} className="text-center">
            <input type="checkbox" className="size-4.5 accent-cobalt-800" aria-label={`${roles[role]}: ${key}`} disabled={!p.canEdit || locked} checked={(form.data[kind][key] ?? []).includes(role)} onChange={() => toggle(kind, key, role)} />
        </td>
    );
    const head = <thead><tr><th className="text-left">{' '}</th>{roleKeys.map((r) => <th key={r} className="text-center text-xs">{roles[r]}</th>)}</tr></thead>;
    return (
        <>
            <Head title="Access Rights by Role" />
            <PageHeader title="Access Rights by Role" description="The master setting: tick what each role can open and do. A person follows their role unless you tick or untick something for them on their own page (Users → the person)."
                actions={<><Link href="/settings"><Button variant="secondary"><ArrowLeft size={16} aria-hidden /> Settings</Button></Link><Link href="/settings/users"><Button variant="secondary">Users</Button></Link></>} />
            {p.overrides.length ? <p className="mb-3 text-sm text-ink-soft">Own ticks on top of their role: {p.overrides.map((o, i) => <span key={o.id}>{i ? ', ' : ''}<Link href={`/settings/users/${o.id}`} className="font-semibold text-cobalt-800 hover:underline">{o.name}</Link> ({o.count})</span>)}</p> : null}
            <div className="space-y-4">
                <Panel flush title="Apps and areas" description="Opening an app or area. The owner always keeps Settings.">
                    <div className="overflow-x-auto"><table className="data-table min-w-[760px]">{head}<tbody>{Object.keys(p.moduleLabels).map((m) => (
                        <tr key={m}><td className="font-medium">{p.moduleLabels[m]}</td>{roleKeys.map((r) => box('modules', m, r, m === 'settings' && r === 'ceo'))}</tr>
                    ))}</tbody></table></div>
                </Panel>
                <Panel flush title="Actions" description="What a role may do inside the apps. Locked rows stay with the vet: the system records, vets decide.">
                    <div className="overflow-x-auto"><table className="data-table min-w-[760px]">{head}<tbody>{Object.keys(p.gateLabels).map((g) => {
                        const locked = p.protected.includes(g);
                        return <tr key={g}><td className="font-medium">{p.gateLabels[g]} {locked ? <Lock size={13} className="inline text-ink-faint" aria-label="Locked" /> : null}</td>{roleKeys.map((r) => box('gates', g, r, locked))}</tr>;
                    })}</tbody></table></div>
                </Panel>
            </div>
            {p.canEdit ? <div className="sticky bottom-4 mt-4 flex justify-end"><Button disabled={form.processing || !form.isDirty} onClick={() => form.post('/settings/access', { preserveScroll: true })}>Save access rights</Button></div> : null}
        </>
    );
}
