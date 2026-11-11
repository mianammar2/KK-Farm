import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { ArtIcon } from '../components/ArtIcon';
import { Empty, PageHeader, Panel } from '../components/ui';
import { APPS, GROUPS, masterHref, menuHref, rememberApp, visibleApps } from '../lib/apps';
import type { SharedProps } from '../types';

/**
 * D-215: an app's master page. Every page of the app is listed here and opens on its own (a section of a larger
 * screen opens without the other sections). The apps this one shares records with are linked at the bottom.
 */
export default function AppHub({ appKey }: { appKey: string }) {
    const { auth } = usePage<SharedProps>().props;
    const modules = auth?.modules ?? [];
    const app = visibleApps(modules).find((a) => a.key === appKey);
    if (!app) return <><Head title="App" /><Empty title="This app is not available to you" /></>;
    const linked = app.linked.map((k) => APPS.find((x) => x.key === k)).filter((x): x is NonNullable<typeof x> => !!x && x.modules.some((m) => modules.includes(m)));
    return (
        <>
            <Head title={app.label} />
            <div className="mb-4 flex items-center gap-3">
                <ArtIcon name={app.art} size={52} />
                <PageHeader title={app.label} description="Master page: every page of this app. Each one opens on its own." />
            </div>
            <div className="grid gap-4 lg:grid-cols-2">
                {GROUPS.map((g) => {
                    const items = app.sections.filter((x) => x.group === g);
                    if (!items.length) return null;
                    return (
                        <Panel key={g} flush title={g}>
                            <ul className="divide-y divide-rule">{items.map((x) => (
                                <li key={`${g}${x.href}${x.label}`}>
                                    <Link href={menuHref(x.href)} onClick={() => rememberApp(app.key)} className="group flex min-h-12 items-center gap-2 px-4 py-2 text-sm font-semibold text-cobalt-900 hover:bg-glaze">
                                        <span className="flex-1">{x.label}</span><ChevronRight size={16} className="text-ink-faint group-hover:text-cobalt-800" aria-hidden />
                                    </Link>
                                </li>
                            ))}</ul>
                        </Panel>
                    );
                })}
            </div>
            {linked.length ? (
                <div className="mt-6">
                    <h2 className="mb-2 text-sm font-semibold tracking-wide text-ink-soft uppercase">Linked apps</h2>
                    <ul className="flex flex-wrap gap-2">{linked.map((l) => (
                        <li key={l.key}><Link href={masterHref(l.key)} onClick={() => rememberApp(l.key)} className="flex items-center gap-2 rounded-full border border-rule bg-tile px-3 py-1.5 text-sm font-semibold text-cobalt-900 hover:border-cobalt-200"><ArtIcon name={l.art} size={22} />{l.label}</Link></li>
                    ))}</ul>
                </div>
            ) : null}
        </>
    );
}
