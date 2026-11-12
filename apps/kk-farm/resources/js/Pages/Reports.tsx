import { Head, Link, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useMemo } from 'react';
import Chart from '../components/LazyChart';
import { axis } from '../lib/chartTheme';
import { Button, PageHeader, Panel, pkr, Stat, StatRow } from '../components/ui';

type Props = {
    breeds: Array<{ name: string; total: number; female: number; male: number; unwell: number }>;
    ages: Array<{ band: string; value: number }>;
    teeth: Array<{ band: string; value: number }>; // D-229
    exits: Array<{ name: string; value: number }>;
    breeding: { kiddings: number; alive: number; stillborn: number; perKidding: number };
    sires: Array<{ id: string; number: string; name: string | null; offspring: number }>;
    costs: Array<{ name: string; value: number }> | null;
};

const TITLES: Record<string, string> = { herd: 'Herd by Breed and Sex', age: 'Herd by Age', breeds: 'Breed Summary', sires: 'Most Used Sires', exits: 'Exits from the Herd', spending: 'Spending, Last 12 Months' };

export default function Reports({ breeds, ages, teeth, exits, breeding, sires, costs }: Props) {
    // D-215: a menu link opens one report on its own (?section=); /reports without it is the master page with all of them.
    const section = new URLSearchParams(usePage().url.split('?')[1] ?? '').get('section');
    const solo = section && TITLES[section] ? section : null;
    const show = (id: string) => !solo || solo === id;
    const ageChart = useMemo(() => ({
        tooltip: { trigger: 'axis' },
        xAxis: axis.category(ages.map((a) => a.band)),
        yAxis: axis.value(),
        series: [{ type: 'bar', barMaxWidth: 46, data: ages.map((a) => a.value), itemStyle: { color: '#0b4f35', borderRadius: [3, 3, 0, 0] } }],
    }), [ages]);
    const teethChart = useMemo(() => ({
        tooltip: { trigger: 'axis' },
        xAxis: axis.category(teeth.map((a) => a.band)),
        yAxis: axis.value(),
        series: [{ type: 'bar', barMaxWidth: 46, data: teeth.map((a) => a.value), itemStyle: { color: '#0b4f35', borderRadius: [3, 3, 0, 0] } }],
    }), [teeth]);

    const breedChart = useMemo(() => ({
        tooltip: { trigger: 'axis' },
        legend: { bottom: 0, itemWidth: 12, itemHeight: 10 },
        grid: { left: 8, right: 12, top: 14, bottom: 30, containLabel: true },
        xAxis: axis.category(breeds.map((b) => b.name)),
        yAxis: axis.value(),
        series: [
            { name: 'Female', type: 'bar', stack: 'sex', data: breeds.map((b) => b.female), itemStyle: { color: '#c18a14' } },
            { name: 'Male', type: 'bar', stack: 'sex', barMaxWidth: 46, data: breeds.map((b) => b.male), itemStyle: { color: '#0b4f35' } },
        ],
    }), [breeds]);

    return (
        <>
            <Head title="Reports" />
            <PageHeader title={solo ? TITLES[solo] : 'Reports'} description={solo ? undefined : 'The master page: every report. Each one also opens on its own from the menu.'} actions={<>{solo ? <Link href="/reports"><Button variant="secondary">All reports</Button></Link> : null}<a href="/goats/export"><Button variant="secondary"><Download size={16} aria-hidden /> Goat register CSV</Button></a></>} />
            {solo ? null : <StatRow>
                <Stat label="Kiddings recorded" value={breeding.kiddings} />
                <Stat label="Kids born alive" value={breeding.alive} />
                <Stat label="Kids per kidding" value={breeding.perKidding} />
                <Stat label="Stillborn" value={breeding.stillborn} />
            </StatRow>}
            <div className={solo ? 'max-w-3xl' : 'grid gap-4 lg:grid-cols-2'}>
                {show('herd') ? <Panel id="herd" title="Herd by breed and sex"><Chart option={breedChart} label="Goats by breed and sex" height={250} /></Panel> : null}
                {show('age') ? <Panel id="age" title="Herd by age"><Chart option={ageChart} label="Goats by age band" height={250} /></Panel> : null}
                {show('age') && teeth.length ? <Panel id="teeth" title="Herd by teeth" description="Dental code from each goat's latest mouth check. UNK = not examined."><Chart option={teethChart} label="Goats by dental code" height={250} /></Panel> : null}
                {show('breeds') ? <Panel id="breeds" title="Breed summary" flush>
                    <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>Breed</th><th>Goats</th><th>Female</th><th>Male</th><th>Unwell</th></tr></thead><tbody>{breeds.map((b) => <tr key={b.name}><td className="font-semibold">{b.name}</td><td>{b.total}</td><td>{b.female}</td><td>{b.male}</td><td>{b.unwell}</td></tr>)}</tbody></table></div>
                </Panel> : null}
                {show('sires') ? <Panel id="sires" title="Most used sires" flush>
                    <ul className="divide-y divide-rule text-sm">{sires.map((s) => <li key={s.id} className="flex justify-between px-4 py-2"><Link href={`/goats/${s.id}`} className="font-semibold text-cobalt-800 hover:underline">{s.name ?? s.number}</Link><span className="text-ink-soft">{s.offspring} offspring</span></li>)}</ul>
                </Panel> : null}
                {show('exits') ? <Panel id="exits" title="Exits from the herd" flush>
                    <ul className="divide-y divide-rule text-sm">{exits.map((e) => <li key={e.name} className="flex justify-between px-4 py-2 capitalize"><span>{e.name}</span><span className="font-semibold">{e.value}</span></li>)}</ul>
                </Panel> : null}
                {costs && show('spending') ? (
                    <Panel id="spending" title="Spending, last 12 months" flush>
                        <ul className="divide-y divide-rule text-sm">{costs.map((c) => <li key={c.name} className="flex justify-between px-4 py-2"><span>{c.name}</span><span className="font-semibold">{pkr(c.value)}</span></li>)}</ul>
                    </Panel>
                ) : null}
            </div>
        </>
    );
}
