import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useMemo } from 'react';
import GoatPortrait from '../components/GoatPortrait';
import { Button, Field } from '../components/ui';
import type { SharedProps } from '../types';

const SCOPE: Record<string, string> = {
    ceo: 'Everything: herd, finance, settings',
    manager: 'Daily operations, no finance',
    vet: 'Clinical records and approvals',
    caretaker: 'Scan, weigh, observe, report',
    feeding: "Today's feeding and tasks",
    cleaning: 'Cleaning tasks and sheds',
    finance: 'Expenses, income, reports',
};

const COATS = ['black', 'spotted', 'brown', 'boer', 'tan', 'white'];

export default function Login({ demoLogin }: { demoLogin: boolean }) {
    const { roles } = usePage<SharedProps>().props;
    // Remember is on by default: the RFID gun should not ask staff to sign in every day.
    const form = useForm({ login: '', password: '', remember: true });
    const tiles = useMemo(() => Array.from({ length: 48 }, (_, i) => ({ number: `DEMO-${i * 7 + 3}`, coat: COATS[(i * 5) % COATS.length], sex: i % 3 ? 'female' : 'male' })), []);

    return (
        <div className="grid min-h-dvh lg:grid-cols-[1fr_30rem]">
            <Head title="Sign in" />
            <div className="relative hidden overflow-hidden bg-cobalt-900 lg:block" aria-hidden>
                <div className="grid h-full grid-cols-8 content-center gap-2 p-8 opacity-90">
                    {tiles.map((t) => (
                        <GoatPortrait key={t.number} number={t.number} coat={t.coat} sex={t.sex} size={84} className="h-auto w-full" />
                    ))}
                </div>
            </div>
            <main className="flex flex-col justify-center bg-tile px-6 py-10 sm:px-10">
                <div className="mb-7">
                    <h1 className="text-3xl font-bold tracking-tight text-cobalt-900">KK Farm</h1>
                    <p className="mt-1 text-ink-soft">Every goat, one permanent record.</p>
                </div>
                <form onSubmit={(e) => { e.preventDefault(); form.post('/login', { onFinish: () => form.reset('password') }); }} className="space-y-3">
                    <Field label="Username or email" error={form.errors.login}><input className="field" autoComplete="username" autoCapitalize="none" spellCheck={false} value={form.data.login} onChange={(e) => form.setData('login', e.target.value)} autoFocus /></Field>
                    <Field label="Password" error={form.errors.password}><input className="field" type="password" autoComplete="current-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} /></Field>
                    <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" className="size-5" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} /> Keep me signed in on this device</label>
                    <Button type="submit" className="w-full" disabled={form.processing}>{form.processing ? 'Signing in…' : 'Sign in'}</Button>
                    <p className="text-sm text-ink-soft">No account yet? Ask the farm owner to add you.</p>
                </form>
                {demoLogin ? (<>
                <h2 className="mt-8 font-semibold text-ink">Local testing: choose a role</h2>
                <p className="mb-3 text-sm text-ink-soft">Only on this laptop (KKFARM_DEMO_LOGIN). Never available on a server.</p>
                <div className="divide-y divide-rule rounded-tile border border-rule">
                    {Object.entries(roles).map(([role, label]) => (
                        <button key={role} type="button" onClick={() => router.post('/login', { role })} className="group flex min-h-14 w-full items-center justify-between gap-3 px-3.5 py-2 text-left transition-colors hover:bg-cobalt-100">
                            <span className="min-w-0">
                                <span className="block font-semibold text-cobalt-900">{label}</span>
                                <span className="block truncate text-sm text-ink-soft">{SCOPE[role]}</span>
                            </span>
                            <ArrowRight size={17} aria-hidden className="shrink-0 text-turq-700 transition-transform group-hover:translate-x-0.5" />
                        </button>
                    ))}
                </div>
                </>) : null}
            </main>
        </div>
    );
}
