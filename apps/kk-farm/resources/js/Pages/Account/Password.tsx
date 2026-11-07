import { Head, useForm } from '@inertiajs/react';
import { Button, Field, Note, PageHeader, Panel } from '../../components/ui';

/** Change your own password. A new account lands here first, to replace the temporary password (D-044). */
export default function Password({ mustChange }: { mustChange: boolean }) {
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });

    return (
        <>
            <Head title="Change password" />
            <PageHeader title="Change password" description={mustChange ? 'Choose your own password to continue. The one you were given is temporary.' : 'Use at least 8 characters.'} />
            <div className="max-w-md">
                <Panel>
                    <form onSubmit={(e) => { e.preventDefault(); form.post('/account/password', { onFinish: () => form.reset() }); }} className="space-y-3">
                        <Field label={mustChange ? 'Temporary password' : 'Current password'} error={form.errors.current_password}><input className="field" type="password" autoComplete="current-password" value={form.data.current_password} onChange={(e) => form.setData('current_password', e.target.value)} autoFocus /></Field>
                        <Field label="New password" error={form.errors.password} hint="At least 8 characters"><input className="field" type="password" autoComplete="new-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} /></Field>
                        <Field label="Repeat new password"><input className="field" type="password" autoComplete="new-password" value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)} /></Field>
                        <Button type="submit" disabled={form.processing}>Save password</Button>
                    </form>
                    {mustChange ? <div className="mt-3"><Note>Nobody else will know this password, not even the owner.</Note></div> : null}
                </Panel>
            </div>
        </>
    );
}
