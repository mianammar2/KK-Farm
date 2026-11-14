import { Head, Link, usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useMemo } from 'react';
import Chart from '../components/LazyChart';
import { axis } from '../lib/chartTheme';
import { Button, PageHeader, Panel, pkr, Stat, StatRow } from '../components/ui';
import DataGrid, { type Col } from '../components/DataGrid';

type Props = {
    breeds: Array<{ name: string; total: number; female: number; male: number; unwell: number }>;
    ages: Array<{ band: string; value: number }>;
    teeth: Array<{ band: string; value: number }>; // D-229
    exits: Array<{ name: string; value: number }>;
    breeding: { kiddings: number; alive: number; stillborn: number; perKidding: number } | null;
    sires: Array<{ id: string; number: string; name: string | null; offspring: number }>;
    costs: Array<{ name: string; value: number }> | null;
    allowed: string[]; // D-243: the reports this person may open
    links?: Array<{ label: string; href: string }>; // D-266 F-13: the views of their own work for a person with no report section
};

/** D-273: every report table is a spreadsheet (sort, filter, totals, export). */
const BREED_COLS: Col<Props['breeds'][number]>[] = [
    { key: 'name', label: 'Breed', type: 'enum', render: (b) => <b>{b.name}</b> },
    { key: 'total', label: 'Goats', type: 'number', total: 'sum' }, { key: 'female', label: 'Female', type: 'number', total: 'sum' },
    { key: 'male', label: 'Male', type: 'number', total: 'sum' }, { key: 'unwell', label: 'Unwell', type: 'number', total: 'sum' },
];
const SIRE_COLS: Col<Props['sires'][number]>[] = [
    { key: 'number', label: 'Buck', type: 'text', render: (s) => <Link href={`/goats/${s.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{s.number}</Link> },
    { key: 'name', label: 'Name', type: 'text' }, { key: 'offspring', label: 'Kids sired', type: 'number', total: 'sum' },
];
const EXIT_COLS: Col<Props['exits'][number]>[] = [{ key: 'name', label: 'Reason', type: 'enum', value: (e) => e.name.charAt(0).toUpperCase() + e.name.slice(1) }, { key: 'value', label: 'Goats', type: 'number', total: 'sum' }];
const COST_COLS: Col<{ name: string; value: number }>[] = [{ key: 'name', label: 'Category', type: 'enum' }, { key: 'value', label: 'Spent (PKR)', type: 'number', total: 'sum', render: (c) => pkr(c.value) }];
const BAND_COLS: Col<{ band: string; value: number }>[] = [{ key: 'band', label: 'Age band', type: 'enum' }, { key: 'value', label: 'Goats', type: 'number', total: 'sum' }];

const TITLES: Record<string, string> = { herd: 'Herd by Breed and Sex', age: 'Herd by Age', breeds: 'Breed Summary', sires: 'Most Used Sires', exits: 'Exits from the Herd', spending: 'Spending, Last 12 Months' };

export default function Reports({ breeds, ages, teeth, exits, breeding, sires, costs, allowed, links = [] }: Props) {
    // D-215: a menu link opens one report on its own (?section=); /reports without it is the master page with all of them.
    const section = new URLSearchParams(usePage().url.split('?')[1] ?? '').get('section');
    const solo = section && TITLES[section] ? section : null;
    const show = (id: string) => allowed.includes(id) && (!solo || solo === id);
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
            {solo || !breeding ? null : <StatRow>
                <Stat label="Kiddings recorded" value={breeding.kiddings} />
                <Stat label="Kids born alive" value={breeding.alive} />
                <Stat label="Kids per kidding" value={breeding.perKidding} />
                <Stat label="Stillborn" value={breeding.stillborn} />
            </StatRow>}
            {links.length && !solo ? <Panel title="Feeding reports" description="Feed offered, refused and wasted, and the feeding history. Costs are not shown." flush>
                <ul className="divide-y divide-rule text-sm">{links.map((l) => <li key={l.href}><Link href={l.href} className="flex justify-between px-4 py-2 font-semibold text-forest"><span>{l.label}</span><span aria-hidden>→</span></Link></li>)}</ul>
            </Panel> : null}
            <div className={solo ? 'max-w-3xl' : 'grid gap-4 lg:grid-cols-2'}>
                {show('herd') ? <Panel id="herd" title="Herd by breed and sex"><Chart option={breedChart} label="Goats by breed and sex" height={250} /></Panel> : null}
                {show('age') ? <Panel id="age" title="Herd by age"><Chart option={ageChart} label="Goats by age band" height={250} /></Panel> : null}
                {show('age') && teeth.length ? <Panel id="teeth" title="Herd by teeth" description="Dental code from each goat's latest mouth check. UNK = not examined."><Chart option={teethChart} label="Goats by dental code" height={250} /></Panel> : null}
                {show('breeds') ? <Panel id="breeds" title="Breed summary" flush className={solo ? '' : 'lg:col-span-2'}><DataGrid id="report-breeds" name="Breed summary" cols={BREED_COLS} rows={breeds} /></Panel> : null}
                {show('sires') ? <Panel id="sires" title="Most used sires" flush><DataGrid id="report-sires" name="Most used sires" cols={SIRE_COLS} rows={sires} /></Panel> : null}
                {show('exits') ? <Panel id="exits" title="Exits from the herd" flush><DataGrid id="report-exits" name="Exits from the herd" cols={EXIT_COLS} rows={exits} /></Panel> : null}
                {costs && show('spending') ? <Panel id="spending" title="Spending, last 12 months" flush><DataGrid id="report-spending" name="Spending last 12 months" cols={COST_COLS} rows={costs} /></Panel> : null}
                {show('age') ? <Panel id="age-table" title="Herd by age: table" flush><DataGrid id="report-ages" name="Herd by age" cols={BAND_COLS} rows={ages} /></Panel> : null}
            </div>
        </>
    );
}
