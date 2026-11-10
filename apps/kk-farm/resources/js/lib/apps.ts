/**
 * D-170: KK Farms ERP as Odoo-style applications (owner's 16-app architecture + Settings). Each app has an icon from
 * the KK Farms icon library, the modules that let a role see it, its own menu (sections: pages and ?tab= sections that
 * exist today) and the apps its records link to. Inside an app the sidebar shows only this app (owner's rule: open the
 * app's scenarios, not the whole system; keep data interlinked). New pages and tabs are added to their app here.
 */
export type ErpApp = {
    key: string; no: string; label: string; art: string; modules: string[];
    sections: Array<{ href: string; label: string }>; linked: string[];
};

export const APPS: ErpApp[] = [
    { key: 'dashboard', no: '01', label: 'Farm Dashboard', art: 'mod-dashboard', modules: ['dashboard'],
        sections: [{ href: '/', label: 'Overview' }, { href: '/actions', label: 'Alerts & Action Center' }, { href: '/activity', label: 'Activity Log' }],
        linked: ['livestock', 'breeding', 'veterinary', 'feeding', 'reports'] },
    { key: 'livestock', no: '02', label: 'Livestock', art: 'mod-livestock', modules: ['goats'],
        sections: [
            { href: '/goats', label: 'All Goats' }, { href: '/goats/register', label: 'Register New Goat' }, { href: '/scan', label: 'RFID Scan' },
            { href: '/goats?show=pregnant', label: 'Pregnant Does' }, { href: '/goats?show=attention', label: 'Need Attention' }, { href: '/goats?show=missing', label: 'Missing Data' },
            { href: '/movements', label: 'Animal Exit & Movements' },
        ],
        linked: ['housing', 'breeding', 'veterinary', 'growth', 'sales'] },
    { key: 'breeding', no: '03', label: 'Breeding', art: 'mod-breeding', modules: ['breeding'],
        sections: [
            { href: '/breeding?tab=cycle', label: 'Mating, Pregnancy & Ultrasound' }, { href: '/breeding?tab=pedigree', label: 'Genetics & Family Tree' },
            { href: '/breeding?tab=performance', label: 'Performance' }, { href: '/breeding?tab=settings', label: 'Configuration' },
        ],
        linked: ['maternity', 'livestock', 'veterinary', 'feeding'] },
    { key: 'maternity', no: '04', label: 'Maternity', art: 'ic-kidding', modules: ['breeding'],
        sections: [
            { href: '/breeding?tab=kidding', label: 'Kidding & Pregnancy Outcomes' }, { href: '/breeding?tab=weaning', label: 'Weaning' },
            { href: '/milking', label: 'Mother Care & Lactation' }, { href: '/sheds', label: 'Maternity Unit' },
        ],
        linked: ['breeding', 'veterinary', 'livestock', 'feeding'] },
    { key: 'veterinary', no: '05', label: 'Veterinary', art: 'mod-health', modules: ['health'],
        sections: [
            { href: '/health?tab=checkups', label: 'Daily Health & Checkups' }, { href: '/health?tab=cases', label: 'Medical Cases' }, { href: '/health?tab=reports', label: 'Reported Concerns' },
            { href: '/health?tab=care', label: 'Vaccination & Deworming' }, { href: '/health?tab=withdrawal', label: 'Withdrawal & Biosecurity' }, { href: '/health?tab=store', label: 'Medicine Store' },
        ],
        linked: ['livestock', 'maternity', 'inventory', 'operations'] },
    { key: 'feeding', no: '06', label: 'Feeding', art: 'mod-feeding', modules: ['feeding'],
        sections: [
            { href: '/feeding?tab=now', label: 'Daily Feeding' }, { href: '/feeding?tab=requirement', label: "Today's Requirement" }, { href: '/feeding?tab=rations', label: 'Feeding Plans & Rations' },
            { href: '/feeding?tab=nutrition', label: 'Nutrition Standards' }, { href: '/feeding?tab=store', label: 'Feed Store' }, { href: '/feeding?tab=history', label: 'History' },
            { href: '/feeding?tab=times', label: 'Configuration' },
        ],
        linked: ['housing', 'growth', 'inventory', 'accounting'] },
    { key: 'housing', no: '07', label: 'Housing', art: 'mod-housing', modules: ['sheds'],
        sections: [{ href: '/sheds', label: 'Farms, Units & Pens' }, { href: '/movements', label: 'Animal Movements' }],
        linked: ['livestock', 'feeding', 'maternity', 'maintenance'] },
    { key: 'growth', no: '08', label: 'Growth', art: 'mod-growth', modules: ['weights'],
        sections: [{ href: '/weights', label: 'Weights & Daily Gain' }],
        linked: ['livestock', 'feeding', 'sales'] },
    { key: 'operations', no: '09', label: 'Operations', art: 'ic-active', modules: ['tasks'],
        sections: [{ href: '/tasks?tab=open', label: "Today's Tasks" }, { href: '/tasks?tab=mine', label: 'My Tasks' }, { href: '/tasks?tab=overdue', label: 'Overdue' }, { href: '/tasks?tab=closed', label: 'Done This Week' }, { href: '/activity', label: 'Daily Activity Log' }],
        linked: ['employees', 'dashboard', 'veterinary', 'feeding'] },
    { key: 'inventory', no: '10', label: 'Inventory', art: 'mod-inventory', modules: ['feeding', 'health'],
        sections: [{ href: '/feeding?tab=store', label: 'Feed Inventory' }, { href: '/health?tab=store', label: 'Medicine Inventory' }],
        linked: ['feeding', 'veterinary', 'purchase'] },
    { key: 'purchase', no: '11', label: 'Purchase', art: 'mod-procurement', modules: ['finance', 'goats'],
        sections: [{ href: '/goats/register', label: 'Goat Purchase & Intake' }, { href: '/finance', label: 'Purchase Expenses' }],
        linked: ['livestock', 'inventory', 'accounting'] },
    { key: 'sales', no: '12', label: 'Sales', art: 'mod-sales', modules: ['value', 'finance'],
        sections: [{ href: '/value', label: 'Herd Value & Pricing' }, { href: '/movements', label: 'Sales & Dispatch' }],
        linked: ['livestock', 'growth', 'accounting'] },
    { key: 'accounting', no: '13', label: 'Accounting', art: 'mod-finance', modules: ['finance'],
        sections: [
            { href: '/finance', label: 'Income & Farm Expenses' }, { href: '/finance/books?tab=journal', label: 'Journal Entries' }, { href: '/finance/books?tab=ledger', label: 'General Ledger' },
            { href: '/finance/books?tab=trial', label: 'Trial Balance' }, { href: '/finance/books?tab=pl', label: 'Profit & Loss' }, { href: '/finance/books?tab=balance', label: 'Balance Sheet' },
            { href: '/finance/books?tab=accounts', label: 'Chart of Accounts' },
        ],
        linked: ['sales', 'purchase', 'employees', 'reports'] },
    { key: 'employees', no: '14', label: 'Employees', art: 'mod-hr', modules: ['employees'],
        sections: [{ href: '/employees', label: 'Employees & Departments' }],
        linked: ['operations', 'accounting'] },
    { key: 'maintenance', no: '15', label: 'Maintenance', art: 'mod-assets', modules: ['sensors'],
        sections: [{ href: '/sensors?tab=devices', label: 'Equipment & Devices' }, { href: '/sensors?tab=alerts', label: 'Alerts' }, { href: '/sensors?tab=readings', label: 'Readings' }, { href: '/sensors?tab=out', label: 'Open Areas' }, { href: '/sensors?tab=connect', label: 'Connect a Device' }],
        linked: ['housing', 'operations'] },
    { key: 'reports', no: '16', label: 'Reports', art: 'mod-reports', modules: ['reports'],
        sections: [{ href: '/reports', label: 'Reports & Analytics' }],
        linked: ['dashboard', 'accounting', 'breeding', 'veterinary'] },
    { key: 'settings', no: '', label: 'Settings', art: 'mod-settings', modules: ['settings'],
        sections: [{ href: '/settings', label: 'System Settings' }],
        linked: ['employees', 'housing', 'feeding', 'breeding'] },
];

export const visibleApps = (modules: string[]) => APPS.filter((a) => a.modules.some((m) => modules.includes(m)));

/** Does this menu link point at the page being shown? Same path, and the same ?tab / ?show when the link has one. */
export function isHere(href: string, url: string): boolean {
    const [hp, hq = ''] = href.split('?');
    const [up, uq = ''] = url.split('?');
    if (hp !== up) return false;
    const h = new URLSearchParams(hq);
    const u = new URLSearchParams(uq);
    if (![...h.keys()].length) return !u.get('show') && !u.get('tab');
    return [...h.entries()].every(([k, v]) => u.get(k) === v);
}

const LAST = 'kk-app';

/** Remember the app the user opened, so a page shared by two apps (e.g. /movements) keeps that app's menu. */
export function rememberApp(key: string) {
    try { sessionStorage.setItem(LAST, key); } catch { /* private mode */ }
}

/** The app whose menu the sidebar shows for this address: the last opened app when the page is one of its own,
 *  otherwise the first app with a section for this exact address, then for this path. */
export function currentApp(url: string, modules: string[]): ErpApp | null {
    const path = url.split('?')[0];
    if (path === '/apps') return null;
    const apps = visibleApps(modules);
    let last: string | null = null;
    try { last = sessionStorage.getItem(LAST); } catch { /* private mode */ }
    const owns = (a: ErpApp) => a.sections.some((x) => x.href.split('?')[0] === path || (path.startsWith(`${x.href.split('?')[0]}/`) && x.href !== '/'));
    const remembered = apps.find((a) => a.key === last);
    if (remembered && owns(remembered)) return remembered;
    return apps.find((a) => a.sections.some((x) => isHere(x.href, url))) ?? apps.find(owns) ?? null;
}
