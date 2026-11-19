import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, FileText, Pencil, RefreshCw, Trash2 } from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';
import Sheet from '../../components/Sheet';
import { Button, cx, Empty, Field, Note, PageHeader, Panel } from '../../components/ui';

/**
 * D-354: every gun with the KK Farm app, reporting by itself (no adb, no cable): its app version, whether its UHF
 * reader is working, what it last did, and its log on request. The Administrator sends it test commands (read only:
 * nothing here writes a tag) and sees the answers here.
 */
type Command = { id: number; label: string; status: 'pending' | 'sent' | 'done' | 'failed'; result: string | null; by: string; at: string };
type Gun = {
    id: number; label: string; version: string | null; code: number | null; reader: string | null; state: Record<string, string | number | boolean | null>;
    user: string | null; seen: string | null; online: boolean; logsAt: string | null; logLines: number; commands: Command[];
};
type Props = { guns: Gun[]; commands: Record<string, string>; latest: { versionName: string; versionCode: number } | null };

const READER: Record<string, [string, string]> = {
    ready: ['Working', 'bg-ok-100 text-ok-700'],
    error: ['Not starting', 'bg-crit-500 text-white'],
    none: ['No UHF reader in this app', 'bg-treat-100 text-treat-700'],
};
const STATUS: Record<Command['status'], string> = { pending: 'Waiting for the gun', sent: 'Sent, waiting for the answer', done: 'Done', failed: 'Failed' };
const STATE_LABELS: Array<[string, string]> = [
    ['page', 'Page open'], ['onScreen', 'App on screen'], ['lastTrigger', 'Last trigger'], ['lastHold', 'Last hold'], ['lastScan', 'Last tag read'],
    ['service', 'Scan service'], ['infowedge', 'InfoWedge'], ['battery', 'Battery'], ['android', 'Android'], ['model', 'Model'], ['crash', 'Last crash'],
];

export default function Guns({ guns, commands, latest }: Props) {
    const [editing, setEditing] = useState<Gun | null>(null);
    const waiting = guns.some((g) => g.commands.some((c) => c.status === 'pending' || c.status === 'sent'));

    useEffect(() => { // answers come back within seconds: refresh while any are outstanding, otherwise every 30 s
        const t = window.setInterval(() => router.reload({ only: ['guns'] }), waiting ? 5000 : 30000);
        return () => window.clearInterval(t);
    }, [waiting]);

    return (
        <>
            <Head title="Guns" />
            <PageHeader title="Guns" description={`Each gun with the KK Farm app reports here by itself while the app is open.${latest ? ` Latest app: ${latest.versionName}.` : ''}`}
                actions={<Link href="/settings"><Button variant="secondary"><ArrowLeft size={16} aria-hidden /> Settings</Button></Link>} />

            {!guns.length ? (
                <Panel><Empty title="No gun has reported yet">Install KK Farm 0.8.4 or newer on the gun, sign in and keep the app open. It appears here within a minute.</Empty></Panel>
            ) : (
                <div className="grid gap-3 xl:grid-cols-2">
                    {guns.map((g) => <GunCard key={g.id} g={g} commands={commands} latest={latest} onEdit={() => setEditing(g)} />)}
                </div>
            )}
            <div className="mt-3"><Note>The commands only read. Nothing on this page writes, locks or kills a tag. The gun answers while the KK Farm app is open on it.</Note></div>

            {editing ? <RenameGun gun={editing} onClose={() => setEditing(null)} /> : null}
        </>
    );
}

function GunCard({ g, commands, latest, onEdit }: { g: Gun; commands: Record<string, string>; latest: Props['latest']; onEdit: () => void }) {
    const [reader, readerTone] = READER[g.reader ?? ''] ?? ['Unknown', 'bg-glaze text-ink-soft'];
    const old = latest && g.code != null && g.code < latest.versionCode;
    const send = (command: string) => router.post(`/settings/guns/${g.id}/commands`, { command }, { preserveScroll: true });
    const remove = () => { if (window.confirm(`Remove ${g.label}? It comes back by itself if its app reports again.`)) router.delete(`/settings/guns/${g.id}`, { preserveScroll: true }); };

    return (
        <Panel title={g.label} description={g.seen ? `Last report ${g.seen}${g.user ? ` · signed in as ${g.user}` : ''}` : 'Not reported yet'}
            actions={<>
                <span className={cx('rounded px-2 py-0.5 text-xs font-semibold', g.online ? 'bg-ok-100 text-ok-700' : 'bg-glaze text-ink-soft')}>{g.online ? 'Online' : 'Offline'}</span>
                <button type="button" onClick={onEdit} className="inline-flex items-center gap-1 text-xs font-semibold text-cobalt-700 hover:underline"><Pencil size={13} aria-hidden /> Edit</button>
                <button type="button" onClick={remove} className="inline-flex items-center gap-1 text-xs font-semibold text-crit-700 hover:underline"><Trash2 size={13} aria-hidden /> Delete</button>
            </>}>
            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                <dt className="text-ink-soft">App</dt>
                <dd>{g.version ?? '—'}{old ? <span className="ml-2 rounded bg-treat-100 px-1.5 text-xs font-semibold text-treat-700">Update to {latest?.versionName}</span> : null}</dd>
                <dt className="text-ink-soft">UHF reader</dt>
                <dd><span className={cx('rounded px-1.5 text-xs font-semibold', readerTone)}>{reader}</span></dd>
                {STATE_LABELS.filter(([k]) => g.state[k] != null && g.state[k] !== '').map(([k, label]) => (
                    <Fragment key={k}><dt className="text-ink-soft">{label}</dt><dd className={cx('min-w-0 break-words', k === 'crash' && 'text-crit-700')}>{String(g.state[k] === true ? 'Yes' : g.state[k] === false ? 'No' : g.state[k])}</dd></Fragment>
                ))}
            </dl>

            <div className="mt-3 flex flex-wrap gap-2">
                {Object.entries(commands).map(([key, label]) => (
                    <Button key={key} variant="secondary" onClick={() => send(key)}>{key === 'reopen_reader' ? <RefreshCw size={14} aria-hidden /> : null}{label}</Button>
                ))}
                {g.logLines ? <a href={`/settings/guns/${g.id}/logs`} target="_blank" rel="noreferrer"><Button variant="secondary"><FileText size={14} aria-hidden /> Open its log ({g.logLines} lines, {g.logsAt})</Button></a> : null}
            </div>

            {g.commands.length ? (
                <table className="data-table mt-3 [&_td]:px-2 [&_td]:py-1.5 [&_th]:px-2">
                    <thead><tr><th>Asked</th><th>Command</th><th>Answer</th></tr></thead>
                    <tbody>
                        {g.commands.map((c) => (
                            <tr key={c.id}>
                                <td className="whitespace-nowrap text-xs text-ink-soft">{c.at}<br />{c.by}</td>
                                <td className="whitespace-nowrap text-sm">{c.label}</td>
                                <td className="text-sm">
                                    <span className={cx('font-semibold', c.status === 'done' ? 'text-ok-700' : c.status === 'failed' ? 'text-crit-700' : 'text-ink-soft')}>{STATUS[c.status]}</span>
                                    {c.result ? <div className="mt-0.5 font-mono text-xs break-words whitespace-pre-wrap">{c.result}</div> : null}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            ) : null}
        </Panel>
    );
}

function RenameGun({ gun, onClose }: { gun: Gun; onClose: () => void }) {
    const form = useForm({ label: gun.label });
    return (
        <Sheet onClose={onClose}>
            <Panel title={`Edit ${gun.label}`}>
                <form onSubmit={(e) => { e.preventDefault(); form.post(`/settings/guns/${gun.id}`, { preserveScroll: true, onSuccess: onClose }); }} className="space-y-3">
                    <Field label="Name" error={form.errors.label}><input className="field" value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} placeholder="e.g. Shed 1 gun" /></Field>
                    <Button type="submit" disabled={form.processing || !form.data.label.trim()}>Save</Button>
                </form>
            </Panel>
        </Sheet>
    );
}
