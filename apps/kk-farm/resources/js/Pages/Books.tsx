import { Head, Link, router, useForm } from '@inertiajs/react';
import { useConfirm } from '../components/Confirm';
import { useState } from 'react';
import { Button, Chip, cx, Field, PageHeader, Panel, pkr, Stat, StatRow, Tabs } from '../components/ui';
import AddButton from '../components/AddButton';
import Sheet from '../components/Sheet';
import { useTabParam } from '../lib/useTabParam';

type Line = { account: string; accountId: number; debit: number; credit: number; goat: string | null };
type Journal = { id: number; no: number; on: string; memo: string; source: string; reference: string | null; by: string; reversed: boolean; isReversal: boolean; lines: Line[] };
type Row = { id: number; code: string; name: string; amount: number };
type AccountRow = { id: number; code: string; name: string; type: string; money: boolean; active: boolean; system: boolean; balance: number; used: boolean };
type Props = {
    from: string; to: string; journal: Journal[];
    trial: { rows: Array<{ id: number; code: string; name: string; type: string; debit: number; credit: number }>; debit: number; credit: number; balanced: boolean };
    pl: { income: Row[]; expenses: Row[]; totalIncome: number; totalExpenses: number; net: number };
    balance: { assets: Row[]; liabilities: Row[]; equity: Row[]; profit: number; totalAssets: number; totalLiabilities: number; totalEquity: number; balanced: boolean };
    ledger: { account: { id: number; label: string; type: string }; opening: number; closing: number; lines: Array<{ entryId: number; no: number; on: string; memo: string; party: string | null; goat: string | null; goatId: string | null; debit: number; credit: number; balance: number }> };
    accounts: AccountRow[]; types: Record<string, string>;
};

const money = (n: number) => (n ? pkr(n) : '—');

export default function Books(p: Props) {
    const [tab, setTab] = useTabParam('journal'); // D-170
    const go = (params: Record<string, string | number>) => router.get('/finance/books', { from: p.from, to: p.to, tab, ...params }, { preserveState: true, preserveScroll: true });
    const openLedger = (id: number) => { setTab('ledger'); go({ account: id, tab: 'ledger' }); };

    return (
        <>
            <Head title="Accounts" />
            <PageHeader title="Accounts (double entry)" description="Every money event posts a journal whose debits equal its credits. Journals are never edited: a correction is a reversing journal."
                actions={<Link href="/finance" className="text-sm font-semibold text-turq-800 hover:underline">Expenses & finance</Link>} />
            <StatRow>
                <Stat label="Cash and bank" value={pkr(p.accounts.filter((a) => a.money).reduce((s, a) => s + a.balance, 0))} />
                <Stat label="Owed to vendors" value={pkr(p.accounts.find((a) => a.code === '2000')?.balance ?? 0)} tone={(p.accounts.find((a) => a.code === '2000')?.balance ?? 0) > 0 ? 'alert' : undefined} />
                <Stat label={`Profit ${p.from} to ${p.to}`} value={pkr(p.pl.net)} tone={p.pl.net >= 0 ? 'good' : 'alert'} />
                <Stat label="Trial balance" value={p.trial.balanced ? 'Balanced' : 'Not balanced'} note={`Dr ${pkr(p.trial.debit)} · Cr ${pkr(p.trial.credit)}`} tone={p.trial.balanced ? 'good' : 'alert'} />
            </StatRow>

            <div className="mb-3 flex flex-wrap items-end gap-2">
                <Field label="From"><input type="date" className="field w-auto" value={p.from} onChange={(e) => go({ from: e.target.value })} /></Field>
                <Field label="To"><input type="date" className="field w-auto" value={p.to} onChange={(e) => go({ to: e.target.value })} /></Field>
            </div>

            <Tabs value={tab} onChange={setTab} tabs={[
                { id: 'journal', label: 'Journal', count: p.journal.length }, { id: 'ledger', label: 'General ledger' }, { id: 'trial', label: 'Trial balance' },
                { id: 'pl', label: 'Profit & loss' }, { id: 'balance', label: 'Balance sheet' }, { id: 'accounts', label: 'Chart of accounts', count: p.accounts.length },
            ]} />

            {tab === 'journal' ? <JournalTab journal={p.journal} accounts={p.accounts} openLedger={openLedger} /> : null}
            {tab === 'ledger' ? <LedgerTab ledger={p.ledger} accounts={p.accounts} pick={(id) => go({ account: id, tab: 'ledger' })} /> : null}
            {tab === 'trial' ? (
                <Panel flush title={`Trial balance at ${p.to}`} description={p.trial.balanced ? 'Total debits equal total credits.' : 'Debits and credits differ: tell support.'}>
                    <div className="overflow-x-auto"><table className="data-table min-w-[520px]">
                        <thead><tr><th>Code</th><th>Account</th><th>Type</th><th className="text-right">Debit</th><th className="text-right">Credit</th></tr></thead>
                        <tbody>{p.trial.rows.map((r) => <tr key={r.id}><td className="font-mono">{r.code}</td><td><button type="button" className="font-semibold text-cobalt-800 hover:underline" onClick={() => openLedger(r.id)}>{r.name}</button></td><td className="capitalize text-ink-soft">{r.type}</td><td className="text-right tabular-nums">{money(r.debit)}</td><td className="text-right tabular-nums">{money(r.credit)}</td></tr>)}</tbody>
                        <tfoot><tr className="font-bold"><td colSpan={3}>Total</td><td className="text-right tabular-nums">{pkr(p.trial.debit)}</td><td className="text-right tabular-nums">{pkr(p.trial.credit)}</td></tr></tfoot>
                    </table></div>
                </Panel>
            ) : null}
            {tab === 'pl' ? (
                <div className="grid gap-4 lg:grid-cols-2">
                    <Statement title="Income" rows={p.pl.income} total={p.pl.totalIncome} openLedger={openLedger} />
                    <Statement title="Expenses" rows={p.pl.expenses} total={p.pl.totalExpenses} openLedger={openLedger} />
                    <Panel className="lg:col-span-2"><p className={cx('text-lg font-bold', p.pl.net >= 0 ? 'text-ok-700' : 'text-crit-700')}>{p.pl.net >= 0 ? 'Net profit' : 'Net loss'} {p.from} to {p.to}: {pkr(Math.abs(p.pl.net))}</p></Panel>
                </div>
            ) : null}
            {tab === 'balance' ? (
                <div className="grid gap-4 lg:grid-cols-2">
                    <Statement title={`Assets at ${p.to}`} rows={p.balance.assets} total={p.balance.totalAssets} openLedger={openLedger} />
                    <div className="space-y-4">
                        <Statement title="Liabilities" rows={p.balance.liabilities} total={p.balance.totalLiabilities} openLedger={openLedger} />
                        <Statement title="Equity" rows={[...p.balance.equity, { id: 0, code: '', name: 'Profit not yet closed', amount: p.balance.profit }]} total={p.balance.totalEquity} openLedger={openLedger} />
                    </div>
                    <Panel className="lg:col-span-2"><p className={cx('font-semibold', p.balance.balanced ? 'text-ok-700' : 'text-crit-700')}>Assets {pkr(p.balance.totalAssets)} {p.balance.balanced ? '=' : '≠'} liabilities + equity {pkr(p.balance.totalLiabilities + p.balance.totalEquity)}</p></Panel>
                </div>
            ) : null}
            {tab === 'accounts' ? <AccountsTab accounts={p.accounts} types={p.types} openLedger={openLedger} /> : null}
        </>
    );
}

function Statement({ title, rows, total, openLedger }: { title: string; rows: Row[]; total: number; openLedger: (id: number) => void }) {
    return (
        <Panel flush title={title}>
            <table className="data-table">
                <tbody>{rows.length ? rows.map((r) => <tr key={`${r.id}-${r.name}`}><td className="w-16 font-mono text-ink-soft">{r.code}</td><td>{r.id ? <button type="button" className="text-cobalt-800 hover:underline" onClick={() => openLedger(r.id)}>{r.name}</button> : r.name}</td><td className="text-right tabular-nums">{pkr(r.amount)}</td></tr>) : <tr><td className="text-ink-soft">Nothing yet</td></tr>}</tbody>
                <tfoot><tr className="font-bold"><td colSpan={2}>Total</td><td className="text-right tabular-nums">{pkr(total)}</td></tr></tfoot>
            </table>
        </Panel>
    );
}

function JournalTab({ journal, accounts, openLedger }: { journal: Journal[]; accounts: AccountRow[]; openLedger: (id: number) => void }) {
    const [ask, confirmBox] = useConfirm(); // D-133
    const [all, setAll] = useState(false);
    const rows = all ? journal : journal.slice(0, 25);
    const [adding, setAdding] = useState(false); // D-127
    return (
        <div>
            {confirmBox}
            {adding ? <Sheet onClose={() => setAdding(false)}><ManualJournal accounts={accounts} onDone={() => setAdding(false)} /></Sheet> : null}
            <Panel flush title="Journal" description="Newest first. Click an account to see its ledger." actions={<AddButton label="Post a journal" onClick={() => setAdding(true)} />}>
                {journal.length ? (
                    <div className="divide-y divide-rule">
                        {rows.map((j) => (
                            <div key={j.id} className={cx('px-4 py-3', j.reversed && 'opacity-60')}>
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <span><span className="font-mono font-semibold text-cobalt-900">#{j.no}</span> <span className="text-sm text-ink-soft">{j.on} · {j.source}{j.reference ? ` · ${j.reference}` : ''} · {j.by}</span></span>
                                    <span className="flex items-center gap-2">
                                        {j.isReversal ? <Chip className="bg-cobalt-100 text-cobalt-800">Reversal</Chip> : null}
                                        {j.reversed ? <Chip className="bg-glaze text-ink-soft">Reversed</Chip> : null}
                                        {!j.reversed && !j.isReversal && ['Manual journal', 'Opening balance'].includes(j.source) ? <button type="button" className="text-xs font-semibold text-turq-800 hover:underline" onClick={async () => { if (await ask({ title: `Reverse journal ${j.no}?`, message: `${j.memo}. A reversing journal is added; this cannot be undone.`, confirm: 'Yes, reverse' })) router.post(`/finance/journals/${j.id}/reverse`, {}, { preserveScroll: true }); }}>Reverse</button> : null}
                                    </span>
                                </div>
                                <p className="text-sm">{j.memo}</p>
                                <table className="mt-1 w-full text-sm">
                                    <tbody>{j.lines.map((l, i) => (
                                        <tr key={i}>
                                            <td className={cx('py-0.5', l.credit ? 'pl-8' : '')}><button type="button" className="text-cobalt-800 hover:underline" onClick={() => openLedger(l.accountId)}>{l.account}</button>{l.goat ? <span className="ml-1 font-mono text-xs text-ink-faint">{l.goat}</span> : null}</td>
                                            <td className="w-28 text-right tabular-nums">{l.debit ? pkr(l.debit) : ''}</td>
                                            <td className="w-28 text-right tabular-nums">{l.credit ? pkr(l.credit) : ''}</td>
                                        </tr>
                                    ))}</tbody>
                                </table>
                            </div>
                        ))}
                        {journal.length > 25 ? <button type="button" onClick={() => setAll(!all)} className="w-full px-4 py-2 text-sm font-semibold text-cobalt-800 hover:bg-glaze">{all ? 'Show less' : `Show ${journal.length - 25} more`}</button> : null}
                    </div>
                ) : <p className="p-4 text-sm text-ink-soft">No journals in these dates.</p>}
            </Panel>
        </div>
    );
}

function ManualJournal({ accounts, onDone }: { accounts: AccountRow[]; onDone?: () => void }) {
    const blank = { account_id: '', debit: '', credit: '' };
    const form = useForm({ posted_on: new Date().toLocaleDateString('en-CA'), memo: '', reference: '', opening: false, lines: [blank, blank] });
    const total = (k: 'debit' | 'credit') => form.data.lines.reduce((s, l) => s + (parseFloat(l[k]) || 0), 0);
    const diff = Math.round((total('debit') - total('credit')) * 100) / 100;
    const set = (i: number, k: keyof typeof blank, v: string) => form.setData('lines', form.data.lines.map((l, n) => (n === i ? { ...l, [k]: v } : l)));
    const errors = form.errors as Record<string, string>;
    return (
        <Panel title="Post a journal" description="Owner's capital, cash to bank, drawings, opening balances or a correction. Debits must equal credits.">
            <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); form.post('/finance/journals', { preserveScroll: true, onSuccess: () => { form.reset(); onDone?.(); } }); }}>
                <Field label="What is it for" error={form.errors.memo}><input className="field" value={form.data.memo} onChange={(e) => form.setData('memo', e.target.value)} placeholder="e.g. Owner put cash into the farm" /></Field>
                <div className="space-y-2">
                    {form.data.lines.map((l, i) => (
                        <div key={i} className="grid grid-cols-[minmax(0,1fr)_5.5rem_5.5rem] gap-1">
                            <select aria-label="Account" className="field" value={l.account_id} onChange={(e) => set(i, 'account_id', e.target.value)}><option value="">Account…</option>{accounts.filter((a) => a.active).map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}</select>
                            <input aria-label="Debit" inputMode="decimal" className="field" placeholder="Debit" value={l.debit} onChange={(e) => set(i, 'debit', e.target.value)} />
                            <input aria-label="Credit" inputMode="decimal" className="field" placeholder="Credit" value={l.credit} onChange={(e) => set(i, 'credit', e.target.value)} />
                        </div>
                    ))}
                    <button type="button" className="text-sm font-semibold text-turq-800 hover:underline" onClick={() => form.setData('lines', [...form.data.lines, blank])}>Add a line</button>
                </div>
                <p className={cx('text-sm font-semibold', diff ? 'text-crit-700' : 'text-ok-700')}>Debits {pkr(total('debit'))} · Credits {pkr(total('credit'))}{diff ? ` · off by ${pkr(Math.abs(diff))}` : ' · balanced'}</p>
                {errors.lines ? <p className="text-sm text-crit-700">{errors.lines}</p> : null}
                <div className="grid grid-cols-2 gap-2">
                    <Field label="Date" error={form.errors.posted_on}><input type="date" className="field" value={form.data.posted_on} onChange={(e) => form.setData('posted_on', e.target.value)} /></Field>
                    <Field label="Reference"><input className="field" value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} /></Field>
                </div>
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.opening} onChange={(e) => form.setData('opening', e.target.checked)} /> Opening balance</label>
                <Button type="submit" className="w-full" disabled={form.processing || diff !== 0 || total('debit') === 0}>Post journal</Button>
            </form>
        </Panel>
    );
}

function LedgerTab({ ledger, accounts, pick }: { ledger: Props['ledger']; accounts: AccountRow[]; pick: (id: number) => void }) {
    return (
        <Panel flush title={`Ledger: ${ledger.account.label}`} description={`Opening ${pkr(ledger.opening)} · closing ${pkr(ledger.closing)}`}
            actions={<select aria-label="Account" className="field w-auto" value={ledger.account.id} onChange={(e) => pick(Number(e.target.value))}>{accounts.map((a) => <option key={a.id} value={a.id}>{a.code} {a.name}</option>)}</select>}>
            <div className="overflow-x-auto"><table className="data-table min-w-[720px]">
                <thead><tr><th>Date</th><th>#</th><th>Details</th><th className="text-right">Debit</th><th className="text-right">Credit</th><th className="text-right">Balance</th></tr></thead>
                <tbody>
                    <tr className="text-ink-soft"><td colSpan={5}>Opening balance</td><td className="text-right tabular-nums">{pkr(ledger.opening)}</td></tr>
                    {ledger.lines.map((l, i) => (
                        <tr key={i}>
                            <td className="whitespace-nowrap">{l.on}</td><td className="font-mono">{l.no}</td>
                            <td className="max-w-80"><span className="block truncate">{l.memo}</span>{l.goat ? <Link href={`/goats/${l.goatId}`} className="font-mono text-xs text-cobalt-800 hover:underline">{l.goat}</Link> : null}</td>
                            <td className="text-right tabular-nums">{l.debit ? pkr(l.debit) : ''}</td><td className="text-right tabular-nums">{l.credit ? pkr(l.credit) : ''}</td>
                            <td className="text-right font-semibold tabular-nums">{pkr(l.balance)}</td>
                        </tr>
                    ))}
                </tbody>
            </table></div>
        </Panel>
    );
}

function AccountsTab({ accounts, types, openLedger }: { accounts: AccountRow[]; types: Record<string, string>; openLedger: (id: number) => void }) {
    const form = useForm({ name: '', type: 'expense', code: '', is_money: false, notes: '' });
    const [adding, setAdding] = useState(false); // D-127
    return (
        <div>
            <Panel flush title="Chart of accounts" actions={<AddButton label="Add an account" onClick={() => setAdding(true)} />}>
                <div className="overflow-x-auto"><table className="data-table min-w-[560px]">
                    <thead><tr><th>Code</th><th>Account</th><th>Type</th><th className="text-right">Balance</th><th /></tr></thead>
                    <tbody>{Object.keys(types).flatMap((t) => accounts.filter((a) => a.type === t)).map((a) => (
                        <tr key={a.id} className={cx(!a.active && 'text-ink-faint')}>
                            <td className="font-mono">{a.code}</td>
                            <td><button type="button" className="font-semibold text-cobalt-800 hover:underline" onClick={() => openLedger(a.id)}>{a.name}</button>{a.money ? <Chip className="ml-1 bg-ok-100 text-ok-700">Cash/bank</Chip> : null}</td>
                            <td>{types[a.type]}</td><td className="text-right tabular-nums">{money(a.balance)}</td>
                            <td>{!a.system ? <button type="button" className="text-xs font-semibold text-turq-800 hover:underline" onClick={() => router.post(`/finance/accounts/${a.id}`, { name: a.name, active: !a.active }, { preserveScroll: true })}>{a.active ? 'Switch off' : 'Switch on'}</button> : null}</td>
                        </tr>
                    ))}</tbody>
                </table></div>
            </Panel>
            {adding ? <Sheet onClose={() => setAdding(false)}>
            <Panel title="Add an account">
                <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); form.post('/finance/accounts', { preserveScroll: true, onSuccess: () => { form.reset(); setAdding(false); } }); }}>
                    <Field label="Name" error={form.errors.name}><input className="field" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="e.g. Meezan Bank" /></Field>
                    <Field label="Type" error={form.errors.type}><select className="field" value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>{Object.entries(types).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                    <Field label="Code (optional)" error={form.errors.code} hint="Left empty, the next free number is used."><input className="field" value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} /></Field>
                    {form.data.type === 'asset' ? <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.is_money} onChange={(e) => form.setData('is_money', e.target.checked)} /> Cash or bank account (money is paid from it)</label> : null}
                    <Button type="submit" className="w-full" disabled={form.processing}>Add account</Button>
                </form>
            </Panel>
            </Sheet> : null}
        </div>
    );
}
