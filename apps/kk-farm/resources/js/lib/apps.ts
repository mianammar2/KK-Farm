/**
 * D-170: KK Farms ERP as Odoo-style applications (owner's 16-app architecture + Settings). Each app has an icon from
 * the KK Farms icon library, the modules that let a role see it, its own menu (sections: pages and ?tab= sections that
 * exist today) and the apps its records link to. Inside an app the sidebar shows only this app (owner's rule: open the
 * app's scenarios, not the whole system; keep data interlinked). New pages and tabs are added to their app here.
 */
/** Odoo menu groups: every app has Operations, Reporting and Configuration (owner's rule); Overview and Records when it has them. */
export type MenuGroup = 'Overview' | 'Operations' | 'Records' | 'Reporting' | 'Configuration';
export const GROUPS: MenuGroup[] = ['Overview', 'Operations', 'Records', 'Reporting', 'Configuration'];

export type ErpApp = {
    key: string; no: string; label: string; art: string; modules: string[];
    sections: Array<{ href: string; label: string; group: MenuGroup }>; linked: string[];
};

const S = (group: MenuGroup, href: string, label: string) => ({ group, href, label });

export const APPS: ErpApp[] = [
    { key: 'dashboard', no: '01', label: 'Farm Dashboard', art: 'mod-dashboard', modules: ['dashboard'], linked: ['livestock', 'breeding', 'veterinary', 'feeding', 'reports'], sections: [
        S('Overview', '/', 'Executive Dashboard'),
        S('Operations', '/actions', 'Alerts & Action Center'),
        S('Records', '/activity', 'Activity Log'),
        S('Reporting', '/reports', 'Farm Reports'),
        S('Configuration', '/settings#users', 'Users & Roles'),
    ] },
    { key: 'livestock', no: '02', label: 'Livestock', art: 'mod-livestock', modules: ['goats'], linked: ['housing', 'breeding', 'maternity', 'veterinary', 'growth', 'sales'], sections: [
        S('Overview', '/goats', 'All Goats'),
        S('Operations', '/goats/register', 'Register Purchased Goat'),
        S('Operations', '/intake', 'Intake & Quarantine'),
        S('Operations', '/goats?show=newborn', 'Newborns to Complete'),
        S('Operations', '/scan', 'RFID Scan'),
        S('Operations', '/movements', 'Animal Exit'),
        S('Records', '/goats?show=pregnant', 'Pregnant Does'),
        S('Records', '/goats?show=attention', 'Need Attention'),
        S('Records', '/goats?show=missing', 'Missing Data'),
        S('Reporting', '/reports#herd', 'Herd Census'),
        S('Reporting', '/reports#age', 'Age-Wise Report'),
        S('Reporting', '/reports#breeds', 'Breed-Wise Population'),
        S('Reporting', '/reports#exits', 'Exits & Mortality'),
        S('Configuration', '/settings#breeds', 'Breed Master'),
        S('Configuration', '/settings#ages', 'Age Groups'),
        S('Configuration', '/settings#fields', 'Register Fields'),
    ] },
    { key: 'breeding', no: '03', label: 'Breeding', art: 'mod-breeding', modules: ['breeding'], linked: ['maternity', 'livestock', 'veterinary', 'feeding'], sections: [
        S('Operations', '/breeding?tab=heat', 'Heat Detection'),
        S('Operations', '/breeding?tab=cycle', 'Mating, Pregnancy & Ultrasound'),
        S('Records', '/breeding?tab=calendar', 'Pregnancy Calendar'),
        S('Records', '/breeding?tab=pedigree', 'Genetics & Family Tree'),
        S('Records', '/goats?show=pregnant', 'Pregnant Does'),
        S('Reporting', '/breeding?tab=performance', 'Doe Performance'),
        S('Reporting', '/reports#sires', 'Buck Performance'),
        S('Configuration', '/breeding?tab=settings', 'Gestation & Check Days'),
    ] },
    { key: 'maternity', no: '04', label: 'Maternity', art: 'ic-kidding', modules: ['breeding'], linked: ['breeding', 'veterinary', 'livestock', 'housing', 'feeding'], sections: [
        S('Overview', '/breeding?tab=calendar', 'Upcoming Deliveries'),
        S('Operations', '/breeding?tab=kidding', 'Kidding & Pregnancy Outcomes'),
        S('Operations', '/goats?show=newborn', 'Newborns to Complete'),
        S('Operations', '/breeding?tab=kidcare', 'Kid Care'),
        S('Operations', '/breeding?tab=weaning', 'Weaning'),
        S('Operations', '/milking', 'Mother Care & Lactation'),
        S('Records', '/sheds', 'Maternity Unit'),
        S('Reporting', '/breeding?tab=performance', 'Kidding Rate & Litter Size'),
        S('Configuration', '/breeding?tab=settings', 'Weaning & Kidding Alerts'),
    ] },
    { key: 'veterinary', no: '05', label: 'Veterinary', art: 'mod-health', modules: ['health'], linked: ['livestock', 'maternity', 'inventory', 'operations'], sections: [
        S('Operations', '/health?tab=checkups', 'Daily Health & Checkups'),
        S('Operations', '/health?tab=cases', 'Medical Cases'),
        S('Operations', '/health?tab=reports', 'Reported Concerns'),
        S('Operations', '/health?tab=calendar', 'Vaccination & Deworming Due'),
        S('Records', '/health?tab=care', 'Preventive Care Given'),
        S('Operations', '/intake', 'Quarantine & Clearance'),
        S('Records', '/health?tab=withdrawal', 'Withdrawal & Biosecurity'),
        S('Records', '/goats?show=attention', 'Sick & Under Watch'),
        S('Records', '/health?tab=mortality', 'Mortality & Postmortem'),
        S('Reporting', '/health?tab=mortality', 'Mortality by Cause'),
        S('Configuration', '/health?tab=protocols', 'Vaccine & Deworming Protocols'),
        S('Configuration', '/health?tab=diseases', 'Disease Master'),
        S('Configuration', '/health?tab=store', 'Medicines & Vaccines'),
    ] },
    { key: 'feeding', no: '06', label: 'Feeding', art: 'mod-feeding', modules: ['feeding'], linked: ['housing', 'growth', 'inventory', 'accounting'], sections: [
        S('Operations', '/feeding?tab=now', 'Daily Feeding'),
        S('Operations', '/feeding?tab=requirement', "Today's Requirement"),
        S('Operations', '/feeding?tab=tmr', 'TMR Mixing Batches'),
        S('Records', '/feeding?tab=history', 'Feeding History'),
        S('Records', '/feeding?tab=rations', 'Feeding Plans & Rations'),
        S('Reporting', '/reports#spending', 'Feed Cost'),
        S('Reporting', '/feeding?tab=waste', 'Feed Offered, Refused & Wasted'),
        S('Configuration', '/feeding?tab=nutrition', 'Nutrition Standards'),
        S('Configuration', '/feeding?tab=tmr', 'TMR Formulas'),
        S('Configuration', '/feeding?tab=store', 'Feed Master'),
        S('Configuration', '/feeding?tab=times', 'Feeding Times'),
    ] },
    { key: 'housing', no: '07', label: 'Housing', art: 'mod-housing', modules: ['sheds'], linked: ['livestock', 'feeding', 'maternity', 'maintenance'], sections: [
        S('Overview', '/sheds', 'Farms, Units & Pens'),
        S('Operations', '/movements', 'Animal Movements'),
        S('Records', '/goats?show=no_pen', 'Goats Without a Pen'),
        S('Reporting', '/', 'Pen Occupancy'),
        S('Configuration', '/sheds', 'Unit & Pen Setup'),
    ] },
    { key: 'growth', no: '08', label: 'Growth', art: 'mod-growth', modules: ['weights'], linked: ['livestock', 'feeding', 'sales'], sections: [
        S('Operations', '/weights', 'Record Weights'),
        S('Records', '/goats?show=no_weight', 'No Weight Yet'),
        S('Reporting', '/weights', 'Daily Gain (ADG)'),
        S('Configuration', '/settings#breeds', 'Breed Weight Lines'),
    ] },
    { key: 'operations', no: '09', label: 'Operations', art: 'ic-active', modules: ['tasks'], linked: ['employees', 'dashboard', 'veterinary', 'feeding'], sections: [
        S('Operations', '/tasks?tab=open', "Today's Tasks"),
        S('Operations', '/tasks?tab=mine', 'My Tasks'),
        S('Operations', '/tasks?tab=overdue', 'Overdue'),
        S('Records', '/tasks?tab=closed', 'Done This Week'),
        S('Records', '/activity', 'Daily Activity Log'),
        S('Reporting', '/actions', 'Pending Actions'),
        S('Configuration', '/employees', 'Departments & Staff'),
    ] },
    { key: 'inventory', no: '10', label: 'Inventory', art: 'mod-inventory', modules: ['inventory', 'feeding', 'health'], linked: ['feeding', 'veterinary', 'purchase', 'maintenance'], sections: [
        S('Overview', '/inventory', 'All Stock'),
        S('Operations', '/inventory?tab=general', 'General Store'),
        S('Operations', '/feeding?tab=store', 'Feed Inventory'),
        S('Operations', '/health?tab=store', 'Medicine Inventory'),
        S('Operations', '/feeding?tab=tmr', 'TMR Mixing'),
        S('Records', '/inventory?tab=moves', 'Stock Moves'),
        S('Reporting', '/inventory?tab=reorder', 'Reorder List'),
        S('Reporting', '/reports#spending', 'Consumption & Spending'),
        S('Configuration', '/inventory?tab=general', 'Store Items'),
    ] },
    { key: 'purchase', no: '11', label: 'Purchase', art: 'mod-procurement', modules: ['purchase', 'finance', 'goats'], linked: ['inventory', 'livestock', 'accounting'], sections: [
        S('Overview', '/purchase', 'Purchase Orders'),
        S('Operations', '/purchase', 'New Purchase Order'),
        S('Operations', '/goats/register', 'Goat Purchase'),
        S('Operations', '/intake', 'Animal Intake & Quarantine'),
        S('Records', '/purchase?tab=suppliers', 'Suppliers'),
        S('Reporting', '/reports#spending', 'Purchase Analysis'),
        S('Configuration', '/finance/books?tab=accounts', 'Purchase Accounts'),
    ] },
    { key: 'sales', no: '12', label: 'Sales', art: 'mod-sales', modules: ['sales', 'value', 'finance'], linked: ['livestock', 'growth', 'accounting'], sections: [
        S('Overview', '/sales', 'All Sales'),
        S('Operations', '/movements', 'Sell a Goat (Dispatch)'),
        S('Operations', '/sales', 'Sell Products'),
        S('Records', '/sales?tab=customers', 'Customers'),
        S('Records', '/value', 'Herd Value & Pricing'),
        S('Reporting', '/sales?tab=monthly', 'Monthly Sales'),
        S('Reporting', '/reports#exits', 'Sales & Exits'),
        S('Configuration', '/value', 'Market Rates'),
    ] },
    { key: 'accounting', no: '13', label: 'Accounting', art: 'mod-finance', modules: ['finance'], linked: ['sales', 'purchase', 'employees', 'reports'], sections: [
        S('Operations', '/finance', 'Income & Farm Expenses'),
        S('Operations', '/finance/books?tab=journal', 'Journal Entries'),
        S('Records', '/finance/books?tab=ledger', 'General Ledger'),
        S('Reporting', '/finance/books?tab=trial', 'Trial Balance'),
        S('Reporting', '/finance/books?tab=pl', 'Profit & Loss'),
        S('Reporting', '/finance/books?tab=balance', 'Balance Sheet'),
        S('Configuration', '/finance/books?tab=accounts', 'Chart of Accounts'),
    ] },
    { key: 'employees', no: '14', label: 'Employees', art: 'mod-hr', modules: ['employees'], linked: ['operations', 'accounting'], sections: [
        S('Operations', '/employees', 'Employees & Departments'),
        S('Records', '/activity', 'Staff Activity'),
        S('Reporting', '/tasks?tab=closed', 'Task Completion'),
        S('Configuration', '/settings#users', 'Users & Permissions'),
    ] },
    { key: 'maintenance', no: '15', label: 'Maintenance', art: 'mod-assets', modules: ['sensors'], linked: ['housing', 'operations'], sections: [
        S('Operations', '/sensors?tab=alerts', 'Alerts & Breakdowns'),
        S('Records', '/sensors?tab=devices', 'Equipment & Devices'),
        S('Records', '/sensors?tab=out', 'Open Areas'),
        S('Reporting', '/sensors?tab=readings', 'Readings'),
        S('Configuration', '/sensors?tab=connect', 'Connect a Device'),
    ] },
    { key: 'reports', no: '16', label: 'Reports', art: 'mod-reports', modules: ['reports'], linked: ['dashboard', 'accounting', 'breeding', 'veterinary'], sections: [
        S('Overview', '/reports', 'Reports & Analytics'),
        S('Operations', '/', 'CEO Dashboard'),
        S('Reporting', '/reports#herd', 'Livestock Reports'),
        S('Reporting', '/breeding?tab=performance', 'Breeding Reports'),
        S('Reporting', '/finance/books?tab=pl', 'Financial Reports'),
        S('Configuration', '/settings#access', 'Report Access'),
    ] },
    { key: 'settings', no: '', label: 'Settings', art: 'mod-settings', modules: ['settings'], linked: ['employees', 'housing', 'feeding', 'breeding'], sections: [
        S('Configuration', '/settings#breeds', 'Animal Master Data'),
        S('Configuration', '/settings#fields', 'Identification & Register'),
        S('Configuration', '/settings#users', 'User Management'),
        S('Configuration', '/settings#access', 'Roles & Access'),
    ] },
];

export const visibleApps = (modules: string[]) => APPS.filter((a) => a.modules.some((m) => modules.includes(m)));

/** Does this menu link point at the page being shown? Same path, and the same ?tab / ?show when the link has one. */
export function isHere(href: string, url: string): boolean {
    const [hp, hq = ''] = href.split('#')[0].split('?');
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
    const base = (h: string) => h.split('#')[0].split('?')[0];
    const owns = (a: ErpApp) => a.sections.some((x) => base(x.href) === path || (path.startsWith(`${base(x.href)}/`) && base(x.href) !== '/'));
    const remembered = apps.find((a) => a.key === last);
    if (remembered && owns(remembered)) return remembered;
    return apps.find((a) => a.sections.some((x) => isHere(x.href, url))) ?? apps.find(owns) ?? null;
}
