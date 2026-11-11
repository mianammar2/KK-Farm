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
    // D-179: the worker app for the RFID gun: one job at a time, scan goat after goat
    { key: 'worker', no: '00', label: 'Field Work', art: 'ic-active', modules: ['scan', 'feeding'], linked: ['livestock', 'veterinary', 'growth', 'feeding', 'operations'], sections: [
        S('Overview', '/worker', 'Choose a Job'),
        S('Operations', '/worker', 'Weigh · Health Check · Move · Look Up'),
        S('Operations', '/scan', 'RFID Scan'),
        S('Operations', '/feeding?tab=now', 'Feed Pens'),
        S('Operations', '/tasks?tab=mine', 'My Tasks'),
        S('Reporting', '/activity', 'Activity Log'),
        S('Configuration', '/account/app', 'Gun & App Settings'),
    ] },
    { key: 'dashboard', no: '01', label: 'Farm Dashboard', art: 'mod-dashboard', modules: ['dashboard'], linked: ['livestock', 'breeding', 'veterinary', 'feeding', 'reports'], sections: [
        S('Overview', '/', 'Executive Dashboard'),
        S('Operations', '/actions', 'Alerts & Action Center'),
        S('Records', '/activity', 'Activity Log'),
        S('Reporting', '/reports', 'Farm Reports'),
        S('Configuration', '/settings#users', 'Users & Roles'),
    ] },
    { key: 'livestock', no: '02', label: 'Livestock Registry', art: 'mod-livestock', modules: ['goats'], linked: ['housing', 'breeding', 'maternity', 'veterinary', 'growth', 'sales'], sections: [
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
    { key: 'breeding', no: '03', label: 'Breeding & Genetics', art: 'mod-breeding', modules: ['breeding'], linked: ['maternity', 'livestock', 'veterinary', 'feeding'], sections: [
        S('Operations', '/breeding?tab=heat', 'Heat Detection'),
        S('Operations', '/breeding?tab=cycle', 'Mating, Pregnancy & Ultrasound'),
        S('Records', '/breeding?tab=calendar', 'Pregnancy Calendar'),
        S('Operations', '/growth?tab=selection', 'Replacement Breeder Selection'),
        S('Records', '/breeding?tab=pedigree', 'Genetics & Family Tree'),
        S('Records', '/goats?show=pregnant', 'Pregnant Does'),
        S('Reporting', '/breeding?tab=performance', 'Doe Performance'),
        S('Reporting', '/reports#sires', 'Buck Performance'),
        S('Configuration', '/breeding?tab=settings', 'Gestation & Check Days'),
    ] },
    { key: 'maternity', no: '04', label: 'Maternity & Kid Care', art: 'ic-kidding', modules: ['breeding'], linked: ['breeding', 'veterinary', 'livestock', 'housing', 'feeding'], sections: [
        S('Overview', '/breeding?tab=calendar', 'Upcoming Deliveries'),
        S('Operations', '/breeding?tab=kidding', 'Kidding & Pregnancy Outcomes'),
        S('Operations', '/goats?show=newborn', 'Newborns to Complete'),
        S('Operations', '/breeding?tab=kidcare', 'Kid Care'),
        S('Operations', '/breeding?tab=weaning', 'Weaning'),
        S('Operations', '/growth?tab=growth', 'Kid Growth vs Plan'),
        S('Operations', '/milking', 'Mother Care & Lactation'),
        S('Records', '/sheds', 'Maternity Unit'),
        S('Reporting', '/breeding?tab=performance', 'Kidding Rate & Litter Size'),
        S('Configuration', '/breeding?tab=settings', 'Weaning & Kidding Alerts'),
    ] },
    { key: 'veterinary', no: '05', label: 'Veterinary & Biosecurity', art: 'mod-health', modules: ['health'], linked: ['livestock', 'maternity', 'inventory', 'operations'], sections: [
        S('Operations', '/health?tab=checkups', 'Daily Health & Checkups'),
        S('Operations', '/health?tab=cases', 'Medical Cases'),
        S('Overview', '/disease?tab=cases', 'Disease Control & Recovery'),
        S('Operations', '/disease?tab=triage', 'Triage Reported Symptoms'),
        S('Operations', '/health?tab=reports', 'Reported Concerns'),
        S('Records', '/disease?tab=restricted', 'Restricted Pens'),
        S('Reporting', '/disease?tab=outbreaks', 'Outbreak Reports'),
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
    { key: 'feeding', no: '06', label: 'Feeding & Nutrition', art: 'mod-feeding', modules: ['feeding'], linked: ['housing', 'growth', 'inventory', 'accounting'], sections: [
        S('Operations', '/feeding?tab=now', 'Daily Feeding'),
        S('Operations', '/feeding?tab=requirement', "Today's Requirement"),
        S('Operations', '/feeding?tab=tmr', 'TMR Mixing Batches'),
        S('Records', '/feeding?tab=history', 'Feeding History'),
        S('Records', '/feeding?tab=rations', 'Feeding Plans & Rations'),
        S('Reporting', '/feed-cost', 'Feed True Cost (per Goat & KG Gain)'),
        S('Operations', '/feed-cost?tab=stock', 'Stock Count & Shrinkage'),
        S('Reporting', '/reports#spending', 'Feed Spending'),
        S('Reporting', '/feeding?tab=waste', 'Feed Offered, Refused & Wasted'),
        S('Configuration', '/feeding?tab=nutrition', 'Nutrition Standards'),
        S('Configuration', '/feeding?tab=tmr', 'TMR Formulas'),
        S('Configuration', '/feeding?tab=store', 'Feed Master'),
        S('Configuration', '/feeding?tab=times', 'Feeding Times'),
    ] },
    { key: 'housing', no: '07', label: 'Housing & Pens', art: 'mod-housing', modules: ['sheds'], linked: ['livestock', 'feeding', 'maternity', 'maintenance'], sections: [
        S('Overview', '/sheds', 'Farms, Units & Pens'),
        S('Operations', '/movements', 'Animal Movements'),
        S('Records', '/goats?show=no_pen', 'Goats Without a Pen'),
        S('Reporting', '/', 'Pen Occupancy'),
        S('Configuration', '/sheds', 'Unit & Pen Setup'),
    ] },
    { key: 'growth', no: '08', label: 'Growth & Performance', art: 'mod-growth', modules: ['weights'], linked: ['maternity', 'breeding', 'livestock', 'feeding', 'veterinary', 'sales'], sections: [
        S('Overview', '/growth?tab=growth', 'Growth vs Plan'),
        S('Operations', '/weights', 'Record Weights'),
        S('Operations', '/growth?tab=interventions', 'Nutrition & Vet Interventions'),
        S('Operations', '/growth?tab=selection', 'Breeder / Sale Selection'),
        S('Records', '/goats?show=no_weight', 'No Weight Yet'),
        S('Records', '/growth?tab=selections', 'Selection Records'),
        S('Reporting', '/weights', 'Daily Gain (ADG)'),
        S('Configuration', '/growth?tab=plan', 'Growth Plans & Selection Age'),
        S('Configuration', '/settings#breeds', 'Breed Weight Lines'),
    ] },
    { key: 'operations', no: '09', label: 'Farm Operations', art: 'ic-active', modules: ['tasks'], linked: ['employees', 'dashboard', 'veterinary', 'feeding'], sections: [
        S('Overview', '/operations?tab=checklists', "Today's Checklists"),
        S('Operations', '/tasks?tab=open', "Today's Tasks"),
        S('Operations', '/tasks?tab=mine', 'My Tasks'),
        S('Operations', '/operations?tab=incidents', 'Incident Register'),
        S('Operations', '/operations?tab=handover', 'Shift Handover'),
        S('Records', '/operations?tab=history', 'Checklist History'),
        S('Records', '/tasks?tab=closed', 'Done This Week'),
        S('Records', '/activity', 'Daily Activity Log'),
        S('Reporting', '/tasks?tab=overdue', 'Delayed Tasks'),
        S('Reporting', '/actions', 'Pending Actions'),
        S('Configuration', '/operations?tab=templates', 'Checklist Setup'),
        S('Configuration', '/employees', 'Departments & Staff'),
    ] },
    { key: 'inventory', no: '10', label: 'Inventory & Stores', art: 'mod-inventory', modules: ['inventory', 'feeding', 'health'], linked: ['feeding', 'veterinary', 'purchase', 'maintenance'], sections: [
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
    { key: 'purchase', no: '11', label: 'Purchase & Procurement', art: 'mod-procurement', modules: ['purchase', 'finance', 'goats', 'animal_purchase'], linked: ['inventory', 'livestock', 'veterinary', 'housing', 'accounting'], sections: [
        S('Overview', '/purchase', 'Purchase Orders'),
        S('Operations', '/purchase', 'New Purchase Order'),
        S('Operations', '/purchase/animals', 'Goat Purchase Lots'),
        S('Operations', '/purchase/animals?tab=new', 'Goat Purchase Request'),
        S('Operations', '/intake', 'Animal Intake & Quarantine'),
        S('Records', '/purchase?tab=suppliers', 'Suppliers'),
        S('Reporting', '/reports#spending', 'Purchase Analysis'),
        S('Configuration', '/finance/books?tab=accounts', 'Purchase Accounts'),
    ] },
    { key: 'sales', no: '12', label: 'Sales & Livestock Trading', art: 'mod-sales', modules: ['sales', 'value', 'finance'], linked: ['livestock', 'growth', 'accounting'], sections: [
        S('Overview', '/sales', 'All Sales'),
        S('Operations', '/sales/orders', 'Sale Orders & Dispatch'),
        S('Operations', '/sales/orders?tab=new', 'New Price Quote'),
        S('Records', '/sales/orders?tab=done', 'Invoices & Collections'),
        S('Operations', '/movements', 'Other Exits'),
        S('Operations', '/sales', 'Sell Products'),
        S('Records', '/sales?tab=customers', 'Customers'),
        S('Records', '/value', 'Herd Value & Pricing'),
        S('Reporting', '/sales?tab=monthly', 'Monthly Sales'),
        S('Reporting', '/reports#exits', 'Sales & Exits'),
        S('Configuration', '/value', 'Market Rates'),
    ] },
    { key: 'accounting', no: '13', label: 'Accounting & Finance', art: 'mod-finance', modules: ['finance'], linked: ['sales', 'purchase', 'employees', 'reports'], sections: [
        S('Operations', '/finance', 'Income & Farm Expenses'),
        S('Operations', '/finance/books?tab=journal', 'Journal Entries'),
        S('Records', '/finance/books?tab=ledger', 'General Ledger'),
        S('Reporting', '/finance/books?tab=trial', 'Trial Balance'),
        S('Reporting', '/finance/books?tab=pl', 'Profit & Loss'),
        S('Reporting', '/finance/books?tab=balance', 'Balance Sheet'),
        S('Reporting', '/feed-cost', 'Feed Cost per KG Gained'),
        S('Configuration', '/finance/books?tab=accounts', 'Chart of Accounts'),
    ] },
    { key: 'employees', no: '14', label: 'Employees & Workforce', art: 'mod-hr', modules: ['employees'], linked: ['operations', 'accounting'], sections: [
        S('Overview', '/employees', 'Employees & Departments'),
        S('Operations', '/hr?tab=attendance', 'Attendance Register'),
        S('Operations', '/hr?tab=leave', 'Leave Requests'),
        S('Records', '/hr?tab=roster', 'Shift Roster'),
        S('Records', '/activity', 'Staff Activity'),
        S('Reporting', '/hr?tab=report', 'Attendance Report'),
        S('Reporting', '/tasks?tab=closed', 'Task Completion'),
        S('Configuration', '/settings#users', 'Users & Permissions'),
    ] },
    { key: 'maintenance', no: '15', label: 'Assets & Maintenance', art: 'mod-assets', modules: ['maintenance', 'sensors'], linked: ['housing', 'operations', 'accounting', 'inventory'], sections: [
        S('Overview', '/maintenance?tab=jobs', 'Open Jobs & Breakdowns'),
        S('Operations', '/maintenance?tab=due', 'Preventive Services Due'),
        S('Operations', '/sensors?tab=alerts', 'Sensor Alerts'),
        S('Records', '/maintenance?tab=assets', 'Asset Register'),
        S('Records', '/sensors?tab=devices', 'Sensors & Devices'),
        S('Reporting', '/maintenance?tab=history', 'Repair History & Cost'),
        S('Reporting', '/sensors?tab=readings', 'Sensor Readings'),
        S('Configuration', '/maintenance?tab=assets', 'Asset Categories & Intervals'),
        S('Configuration', '/sensors?tab=connect', 'Connect a Device'),
    ] },
    { key: 'reports', no: '16', label: 'Reports & Analytics', art: 'mod-reports', modules: ['reports'], linked: ['dashboard', 'accounting', 'breeding', 'veterinary'], sections: [
        S('Overview', '/reports', 'Reports & Analytics'),
        S('Operations', '/', 'CEO Dashboard'),
        S('Reporting', '/reports#herd', 'Livestock Reports'),
        S('Reporting', '/breeding?tab=performance', 'Breeding Reports'),
        S('Reporting', '/finance/books?tab=pl', 'Financial Reports'),
        S('Configuration', '/settings#access', 'Report Access'),
    ] },
    // D-184: V3.0 apps 17–21 (owner's blueprint). Each lists the screens that exist today; their own screens follow.
    { key: 'dairy', no: '17', label: 'Dairy & Milk', art: 'ic-lactating', modules: ['milking'], linked: ['maternity', 'veterinary', 'feeding', 'inventory', 'sales', 'accounting'], sections: [
        S('Overview', '/dairy?tab=sessions', 'Close AM / PM Sessions'),
        S('Operations', '/milking', 'Log Litres per Doe'),
        S('Operations', '/dairy?tab=lots', 'Milk Lots, QA & Release'),
        S('Operations', '/dairy?tab=orders', 'Milk Orders'),
        S('Operations', '/dairy?tab=trace', 'Dispatch & Trace'),
        S('Records', '/milking', 'Lactations'),
        S('Reporting', '/dairy?tab=money', 'Milk Invoices & Profit'),
        S('Reporting', '/dairy?tab=trace', 'Lot Traceability & Revenue'),
        S('Configuration', '/milking', 'Milking Settings'),
    ] },
    { key: 'fattening', no: '18', label: 'Fattening & Meat', art: 'cls-ftn', modules: ['fattening'], linked: ['livestock', 'veterinary', 'feeding', 'growth', 'housing', 'sales', 'accounting'], sections: [
        S('Overview', '/fattening?tab=batches', 'Finishing Batches'),
        S('Operations', '/fattening?tab=selection', 'Meat Selection'),
        S('Operations', '/fattening?tab=new', 'New Batch (Select Feeders)'),
        S('Operations', '/feeding?tab=now', 'Daily Feed & Water'),
        S('Records', '/value', 'Herd Value & Market Rates'),
        S('Reporting', '/fattening?tab=profit', 'Batch Profitability'),
        S('Configuration', '/value', 'Market Rates'),
    ] },
    { key: 'welfare', no: '19', label: 'Animal Welfare', art: 'ic-healthy', modules: ['welfare'], linked: ['veterinary', 'housing', 'operations', 'employees', 'quality'], sections: [
        S('Overview', '/welfare?tab=cases', 'Welfare Cases'),
        S('Operations', '/welfare?tab=check', 'Daily Welfare & Mobility Check'),
        S('Operations', '/health?tab=reports', 'Vet Referrals (Reported Concerns)'),
        S('Records', '/welfare?tab=checks', 'Check Records'),
        S('Records', '/welfare?tab=closed', 'Closed Cases'),
        S('Reporting', '/health?tab=mortality', 'Mortality'),
        S('Configuration', '/health?tab=diseases', 'Condition List'),
    ] },
    { key: 'environment', no: '20', label: 'Water & Climate', art: 'mod-assets', modules: ['environment'], linked: ['housing', 'welfare', 'veterinary', 'maintenance', 'quality', 'accounting'], sections: [
        S('Overview', '/environment?tab=sheds', 'Shed Rounds'),
        S('Operations', '/environment?tab=issues', 'Water, Heat & Waste Issues'),
        S('Operations', '/sensors?tab=alerts', 'Sensor Alarms'),
        S('Records', '/environment?tab=records', 'Round Records'),
        S('Records', '/sensors?tab=readings', 'Sensor Readings'),
        S('Reporting', '/environment?tab=records', 'Water & Heat Compliance'),
        S('Configuration', '/environment?tab=setup', 'Water Points & Heat Limit'),
    ] },
    { key: 'quality', no: '21', label: 'Quality & Compliance', art: 'mod-biosecurity', modules: ['quality'], linked: ['dairy', 'inventory', 'veterinary', 'sales', 'operations', 'feeding'], sections: [
        S('Overview', '/quality?tab=dashboard', 'Quality Dashboard'),
        S('Operations', '/quality?tab=new', 'Raise a Quality Alarm'),
        S('Operations', '/quality?tab=cases', 'Cases & CAPA'),
        S('Operations', '/quality?tab=holds', 'Holds, Isolation & Recall'),
        S('Operations', '/operations?tab=incidents', 'Incident Register'),
        S('Records', '/quality?tab=sops', 'SOP Library'),
        S('Records', '/quality?tab=register', 'Regulatory Register'),
        S('Records', '/quality?tab=drills', 'Emergency Readiness'),
        S('Records', '/health?tab=withdrawal', 'Withdrawal Holds'),
        S('Reporting', '/quality?tab=closed', 'Closed Cases & Learning'),
        S('Reporting', '/activity', 'Audit Trail'),
        S('Configuration', '/operations?tab=templates', 'Checklists'),
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
    const [hrefMain, hrefHash = ''] = href.split('#');
    const [urlMain, urlHash = ''] = url.split('#');
    const [hp, hq = ''] = hrefMain.split('?');
    const [up, uq = ''] = urlMain.split('?');
    if (hp !== up) return false;
    if (hrefHash && urlHash && hrefHash !== urlHash) return false; // D-183: /settings#users is not /settings#breeds
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
    const path = url.split('#')[0].split('?')[0];
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
