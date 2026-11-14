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
    { key: 'worker', no: '00', label: 'Tasks', art: 'app-fieldwork', modules: ['scan', 'feeding'], linked: ['livestock', 'veterinary', 'growth', 'feeding', 'operations'], sections: [
        S('Overview', '/worker', 'Choose a Job'),
        S('Operations', '/worker', 'Weigh · Temp · Heat · Mating · Milk · Vaccinate · Teeth · Height · Move'),
        S('Operations', '/scan', 'RFID Scan'),
        S('Operations', '/feeding?tab=now', 'Feed PENs'),
        S('Operations', '/tasks?tab=mine', 'My Tasks'),
        S('Reporting', '/activity', 'Activity Log'),
        S('Configuration', '/account/app', 'Gun & App Settings'),
    ] },
    { key: 'dashboard', no: '01', label: 'Dashboard', art: 'app-dashboard', modules: ['dashboard'], linked: ['livestock', 'breeding', 'veterinary', 'feeding', 'reports'], sections: [
        S('Overview', '/', 'Executive Dashboard'),
        S('Overview', '/home', 'My Home'), // D-241: each role's own home
        S('Overview', '/dashboard/scorecard', 'CEO Scorecard'), // D-233
        S('Operations', '/actions', 'Alerts & Action Center'),
        S('Records', '/activity', 'Activity Log'),
        S('Reporting', '/dashboard/weight-change', 'Weight Gain & Loss'),
        S('Reporting', '/reports', 'Farm Reports'),
        S('Configuration', '/settings/users', 'Users & Roles'),
    ] },
    { key: 'livestock', no: '02', label: 'Livestock', art: 'app-livestock', modules: ['goats'], linked: ['housing', 'breeding', 'maternity', 'veterinary', 'growth', 'sales'], sections: [
        S('Overview', '/goats', 'All Goats'),
        S('Operations', '/goats/register', 'Register Purchased Goat'),
        S('Operations', '/intake', 'Intake & Quarantine'),
        S('Operations', '/goats?show=newborn', 'Newborns to Complete'),
        S('Operations', '/scan', 'RFID Scan'),
        S('Operations', '/movements?kind=transfer', 'PEN Movements'), // D-285: moves and exits are separate
        S('Operations', '/movements?kind=exit', 'Animal Exit'),
        S('Reporting', '/reports/sheet', 'Herd Sheet'), // D-288: one sheet for all reporting
        // D-287: no Records section for now; the registry's filter cards show pregnant, attention and missing data
        S('Configuration', '/settings/breeds', 'Breed Master'),
        S('Configuration', '/settings/ages', 'Age Groups'),
        S('Configuration', '/settings/automation', 'Automation Rules'), // D-236
        S('Configuration', '/settings/fields', 'Register Fields'),
    ] },
    { key: 'breeding', no: '03', label: 'Breeding', art: 'app-breeding', modules: ['breeding'], linked: ['maternity', 'livestock', 'veterinary', 'feeding'], sections: [
        S('Operations', '/breeding?tab=heat', 'Heat Detection'),
        S('Operations', '/breeding?tab=cycle', 'Mating, Pregnancy & Ultrasound'),
        S('Records', '/breeding?tab=matings', 'Mating Records'), // D-294
        S('Records', '/breeding?tab=calendar', 'Pregnancy Calendar'),
        S('Operations', '/genetics?tab=review', 'Replacement Review (Genetic Merit)'),
        S('Reporting', '/genetics?tab=progeny', 'Sire & Dam Progeny Performance'),
        S('Operations', '/genetics?tab=mating', 'Inbreeding Check'),
        S('Operations', '/growth?tab=selection', 'Replacement Breeder Selection'),
        S('Operations', '/breeding/eligibility', 'Breeding Eligibility & Approval'), // D-231
        S('Records', '/breeding?tab=pedigree', 'Genetics & Family Tree'),
        S('Records', '/goats?show=pregnant', 'Pregnant Does'),
        S('Reporting', '/breeding?tab=performance', 'Doe Performance'),
        S('Reporting', '/reports?section=sires', 'Buck Performance'),
        S('Configuration', '/breeding?tab=settings', 'Gestation & Check Days'),
        S('Configuration', '/breeding/eligibility#rules', 'Breeding Eligibility Rules'),
    ] },
    { key: 'maternity', no: '04', label: 'Maternity', art: 'app-maternity', modules: ['breeding'], linked: ['breeding', 'veterinary', 'livestock', 'housing', 'feeding'], sections: [
        S('Overview', '/breeding?tab=calendar', 'Upcoming Deliveries'),
        S('Operations', '/breeding?tab=kidding', 'Kidding & Pregnancy Outcomes'),
        S('Operations', '/repro-loss', 'Pregnancy Loss Investigation'),
        S('Operations', '/goats?show=newborn', 'Newborns to Complete'),
        S('Operations', '/breeding?tab=kidcare', 'Kid Care'),
        S('Operations', '/breeding?tab=weaning', 'Weaning'),
        S('Operations', '/growth?tab=growth', 'Kid Growth vs Plan'),
        S('Operations', '/milking', 'Mother Care & Lactation'),
        S('Records', '/sheds', 'Maternity Unit'),
        S('Reporting', '/breeding?tab=performance', 'Kidding Rate & Litter Size'),
        S('Configuration', '/breeding?tab=settings', 'Weaning & Kidding Alerts'),
    ] },
    { key: 'veterinary', no: '05', label: 'Veterinary', art: 'app-veterinary', modules: ['health'], linked: ['livestock', 'maternity', 'inventory', 'operations'], sections: [
        S('Operations', '/health?tab=checkups', 'Daily Health & Checkups'),
        S('Operations', '/health?tab=cases', 'Medical Cases'),
        S('Overview', '/disease?tab=cases', 'Disease Control & Recovery'),
        S('Operations', '/disease?tab=triage', 'Triage Reported Symptoms'),
        S('Operations', '/health?tab=reports', 'Reported Concerns'),
        S('Records', '/disease?tab=restricted', 'Restricted PENs'),
        S('Reporting', '/disease?tab=outbreaks', 'Outbreak Reports'),
        S('Operations', '/parasites', 'Parasite Testing & Control'),
        S('Operations', '/health?tab=calendar', 'Vaccination & Deworming Due'),
        S('Records', '/health?tab=care', 'Preventive Care Given'),
        S('Operations', '/intake', 'Quarantine & Clearance'),
        S('Records', '/health?tab=withdrawal', 'Withdrawal & Biosecurity'),
        S('Reporting', '/health/antimicrobials', 'Antimicrobial Use'),
        S('Records', '/goats?show=attention', 'Sick & Under Watch'),
        S('Records', '/health?tab=mortality', 'Mortality & Postmortem'),
        S('Reporting', '/health?tab=mortality', 'Mortality by Cause'),
        S('Configuration', '/health?tab=protocols', 'Vaccine & Deworming Protocols'),
        S('Configuration', '/health?tab=diseases', 'Disease Master'),
        S('Configuration', '/health?tab=symptoms', 'Symptom Master'), // D-292
        S('Configuration', '/health?tab=store', 'Medicines & Vaccines'),
    ] },
    { key: 'feeding', no: '06', label: 'Feeding', art: 'app-feeding', modules: ['feeding'], linked: ['housing', 'growth', 'inventory', 'accounting'], sections: [
        S('Operations', '/feeding?tab=now', 'Daily Feeding'),
        S('Operations', '/feeding?tab=requirement', "Today's Requirement"),
        S('Operations', '/feeding?tab=tmr', 'TMR Mixing Batches'),
        S('Operations', '/feeding/lots', 'Feed Safety: Lots, Tests & Holds'),
        S('Records', '/feeding?tab=history', 'Feeding History'),
        S('Records', '/feeding?tab=rations', 'Feeding Plans & Rations'),
        S('Reporting', '/feed-cost', 'Feed True Cost (per Goat & KG Gain)'),
        S('Operations', '/feed-cost?tab=stock', 'Stock Count & Shrinkage'),
        S('Reporting', '/reports?section=spending', 'Feed Spending'),
        S('Reporting', '/feeding?tab=waste', 'Feed Offered, Refused & Wasted'),
        S('Configuration', '/feeding?tab=nutrition', 'Nutrition Standards'),
        S('Configuration', '/feeding?tab=tmr', 'TMR Formulas'),
        S('Configuration', '/feeding?tab=store', 'Feed Master'),
        S('Configuration', '/feeding?tab=times', 'Feeding Times'),
    ] },
    { key: 'housing', no: '07', label: 'Housing', art: 'app-housing', modules: ['sheds'], linked: ['livestock', 'feeding', 'maternity', 'maintenance'], sections: [
        S('Overview', '/sheds', 'Farms, Units & PENs'),
        S('Operations', '/movements?kind=transfer', 'PEN Movements'),
        S('Records', '/goats?show=no_pen', 'Goats Without a PEN'),
        S('Operations', '/housing', 'PEN Status, Capacity & Cleaning'),
        S('Reporting', '/housing', 'PEN Occupancy'),
        S('Configuration', '/sheds', 'Unit & PEN Setup'),
    ] },
    { key: 'growth', no: '08', label: 'Growth', art: 'app-growth', modules: ['weights'], linked: ['maternity', 'breeding', 'livestock', 'feeding', 'veterinary', 'sales'], sections: [
        S('Overview', '/growth?tab=growth', 'Growth vs Plan'),
        S('Operations', '/weights', 'Record Weights'),
        S('Operations', '/weights/review', 'Held Weights to Review'),
        S('Operations', '/growth?tab=interventions', 'Nutrition & Vet Interventions'),
        S('Operations', '/growth?tab=selection', 'Breeder / Sale Selection'),
        S('Records', '/goats?show=no_weight', 'No Weight Yet'),
        S('Records', '/growth?tab=selections', 'Selection Records'),
        S('Reporting', '/weights', 'Daily Gain (ADG)'),
        S('Configuration', '/growth?tab=plan', 'Growth Plans & Selection Age'),
        S('Configuration', '/settings/breeds', 'Breed Weight Lines'),
    ] },
    { key: 'operations', no: '09', label: 'Operations', art: 'app-operations', modules: ['tasks'], linked: ['employees', 'dashboard', 'veterinary', 'feeding'], sections: [
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
    { key: 'inventory', no: '10', label: 'Inventory', art: 'app-inventory', modules: ['inventory', 'feeding', 'health'], linked: ['feeding', 'veterinary', 'purchase', 'maintenance'], sections: [
        S('Overview', '/inventory', 'All Stock'),
        S('Operations', '/inventory?tab=general', 'General Store'),
        S('Operations', '/feeding?tab=store', 'Feed Inventory'),
        S('Operations', '/health?tab=store', 'Medicine Inventory'),
        S('Operations', '/feeding?tab=tmr', 'TMR Mixing'),
        S('Records', '/inventory?tab=moves', 'Stock Moves'),
        S('Reporting', '/inventory?tab=reorder', 'Reorder List'),
        S('Reporting', '/reports?section=spending', 'Consumption & Spending'),
        S('Configuration', '/inventory?tab=general', 'Store Items'),
    ] },
    { key: 'purchase', no: '11', label: 'Purchase', art: 'app-purchase', modules: ['purchase', 'finance', 'goats', 'animal_purchase'], linked: ['inventory', 'livestock', 'veterinary', 'housing', 'accounting'], sections: [
        S('Overview', '/purchase', 'Purchase Orders'),
        S('Operations', '/purchase', 'New Purchase Order'),
        S('Operations', '/purchase/animals', 'Goat Purchase Lots'),
        S('Operations', '/purchase/animals?tab=new', 'Goat Purchase Request'),
        S('Operations', '/intake', 'Animal Intake & Quarantine'),
        S('Records', '/purchase?tab=suppliers', 'Suppliers'),
        S('Reporting', '/reports?section=spending', 'Purchase Analysis'),
        S('Configuration', '/finance/books?tab=accounts', 'Purchase Accounts'),
    ] },
    { key: 'sales', no: '12', label: 'Sales', art: 'app-sales', modules: ['sales', 'value', 'finance'], linked: ['livestock', 'growth', 'accounting'], sections: [
        S('Overview', '/sales', 'All Sales'),
        S('Operations', '/sales/readiness', 'Sale Readiness & Clearance'), // D-232
        S('Operations', '/sales/orders', 'Sale Orders & Dispatch'),
        S('Operations', '/transport', 'Transport & Delivery'),
        S('Configuration', '/transport?tab=vehicles', 'Approved Vehicles'),
        S('Operations', '/sales/orders?tab=new', 'New Price Quote'),
        S('Records', '/sales/orders?tab=done', 'Invoices & Collections'),
        S('Operations', '/movements?kind=exit', 'Other Exits'),
        S('Operations', '/sales', 'Sell Products'),
        S('Records', '/sales?tab=customers', 'Customers'),
        S('Records', '/value', 'Herd Value & Pricing'),
        S('Reporting', '/sales?tab=monthly', 'Monthly Sales'),
        S('Reporting', '/reports?section=exits', 'Sales & Exits'),
        S('Configuration', '/value', 'Market Rates'),
    ] },
    { key: 'accounting', no: '13', label: 'Accounting', art: 'app-accounting', modules: ['finance'], linked: ['sales', 'purchase', 'employees', 'reports'], sections: [
        S('Operations', '/finance', 'Income & Farm Expenses'),
        S('Operations', '/finance/books?tab=journal', 'Journal Entries'),
        S('Records', '/finance/books?tab=ledger', 'General Ledger'),
        S('Reporting', '/finance/books?tab=trial', 'Trial Balance'),
        S('Reporting', '/finance/books?tab=pl', 'Profit & Loss'),
        S('Reporting', '/finance/books?tab=balance', 'Balance Sheet'),
        S('Reporting', '/feed-cost', 'Feed Cost per KG Gained'),
        S('Configuration', '/finance/books?tab=accounts', 'Chart of Accounts'),
    ] },
    { key: 'employees', no: '14', label: 'Employees', art: 'app-employees', modules: ['employees'], linked: ['operations', 'accounting'], sections: [
        S('Overview', '/employees', 'Employees & Departments'),
        S('Operations', '/hr?tab=attendance', 'Attendance Register'),
        S('Operations', '/hr?tab=leave', 'Leave Requests'),
        S('Operations', '/hr/training', 'Training & Compliance'),
        S('Records', '/hr/training?tab=skills', 'Skills & Authorisations'),
        S('Records', '/hr?tab=roster', 'Shift Roster'),
        S('Records', '/activity', 'Staff Activity'),
        S('Reporting', '/hr?tab=report', 'Attendance Report'),
        S('Reporting', '/hr/training?tab=matrix', 'Skills Compliance'),
        S('Reporting', '/tasks?tab=closed', 'Task Completion'),
        S('Configuration', '/settings/users', 'Users & Permissions'),
    ] },
    { key: 'maintenance', no: '15', label: 'Maintenance', art: 'app-maintenance', modules: ['maintenance', 'sensors'], linked: ['housing', 'operations', 'accounting', 'inventory'], sections: [
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
    { key: 'reports', no: '16', label: 'Reports', art: 'app-reports', modules: ['reports'], linked: ['dashboard', 'accounting', 'breeding', 'veterinary'], sections: [
        S('Overview', '/reports', 'Reports & Analytics'),
        S('Operations', '/', 'CEO Dashboard'),
        S('Reporting', '/reports?section=herd', 'Livestock Reports'),
        S('Reporting', '/breeding?tab=performance', 'Breeding Reports'),
        S('Reporting', '/finance/books?tab=pl', 'Financial Reports'),
        S('Configuration', '/settings/access', 'Report Access'),
    ] },
    // D-184: V3.0 apps 17–21 (owner's blueprint). Each lists the screens that exist today; their own screens follow.
    { key: 'dairy', no: '17', label: 'Dairy', art: 'app-dairy', modules: ['milking'], linked: ['maternity', 'veterinary', 'feeding', 'inventory', 'sales', 'accounting'], sections: [
        S('Overview', '/dairy?tab=sessions', 'Close AM / PM Sessions'),
        S('Operations', '/milking', 'Log Litres per Doe'),
        S('Operations', '/dairy?tab=lots', 'Milk Lots, QA & Release'),
        S('Operations', '/dairy?tab=orders', 'Milk Orders'),
        S('Operations', '/dairy?tab=trace', 'Dispatch & Trace'),
        S('Operations', '/dairy/processing', 'Milk Processing Batches'),
        S('Records', '/milking', 'Lactations'),
        S('Reporting', '/dairy?tab=money', 'Milk Invoices & Profit'),
        S('Reporting', '/dairy?tab=trace', 'Lot Traceability & Revenue'),
        S('Configuration', '/milking', 'Milking Settings'),
    ] },
    { key: 'fattening', no: '18', label: 'Fattening', art: 'app-fattening', modules: ['fattening'], linked: ['livestock', 'veterinary', 'feeding', 'growth', 'housing', 'sales', 'accounting'], sections: [
        S('Overview', '/fattening?tab=batches', 'Finishing Batches'),
        S('Operations', '/fattening?tab=selection', 'Meat Selection'),
        S('Operations', '/fattening?tab=new', 'New Batch (Select Feeders)'),
        S('Operations', '/feeding?tab=now', 'Daily Feed & Water'),
        S('Records', '/value', 'Herd Value & Market Rates'),
        S('Reporting', '/fattening?tab=profit', 'Batch Profitability'),
        S('Configuration', '/value', 'Market Rates'),
    ] },
    { key: 'welfare', no: '19', label: 'Welfare', art: 'app-welfare', modules: ['welfare'], linked: ['veterinary', 'housing', 'operations', 'employees', 'quality'], sections: [
        S('Overview', '/welfare?tab=cases', 'Welfare Cases'),
        S('Operations', '/welfare?tab=check', 'Daily Welfare & Mobility Check'),
        S('Operations', '/health?tab=reports', 'Vet Referrals (Reported Concerns)'),
        S('Records', '/welfare?tab=checks', 'Check Records'),
        S('Records', '/welfare?tab=closed', 'Closed Cases'),
        S('Operations', '/welfare/end-of-life', 'Humane End-of-Life Review'),
        S('Reporting', '/health?tab=mortality', 'Mortality'),
        S('Configuration', '/health?tab=diseases', 'Condition List'),
    ] },
    { key: 'environment', no: '20', label: 'Environment', art: 'app-environment', modules: ['environment'], linked: ['housing', 'welfare', 'veterinary', 'maintenance', 'quality', 'accounting'], sections: [
        S('Overview', '/environment?tab=sheds', 'Shed Rounds'),
        S('Operations', '/environment?tab=issues', 'Water, Heat & Waste Issues'),
        S('Operations', '/environment?tab=tests', 'Water Lab Tests'),
        S('Operations', '/sensors?tab=alerts', 'Sensor Alarms'),
        S('Operations', '/waste', 'Waste & Safe Disposal'),
        S('Configuration', '/waste?tab=routes', 'Waste Routes (Adviser-Set)'),
        S('Records', '/environment?tab=records', 'Round Records'),
        S('Records', '/sensors?tab=readings', 'Sensor Readings'),
        S('Reporting', '/environment?tab=records', 'Water & Heat Compliance'),
        S('Configuration', '/environment?tab=setup', 'Water Points & Heat Limit'),
    ] },
    { key: 'quality', no: '21', label: 'Quality', art: 'app-quality', modules: ['quality'], linked: ['dairy', 'inventory', 'veterinary', 'sales', 'operations', 'feeding'], sections: [
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
    { key: 'fodder', no: '22', label: 'Fodder', art: 'app-fodder', modules: ['fodder'], linked: ['feeding', 'inventory', 'quality', 'accounting'], sections: [
        S('Overview', '/fodder?tab=crops', 'Crops & Fields'),
        S('Operations', '/fodder?tab=lots', 'Harvest Lots: Test & Release'),
        S('Records', '/fodder?tab=fields', 'Field Registry'),
        S('Reporting', '/fodder?tab=report', 'Yield & Cost per KG DM'),
        S('Configuration', '/fodder?tab=setup', 'Fodder Settings'),
    ] },
    { key: 'settings', no: '', label: 'Settings', art: 'app-settings', modules: ['settings'], linked: ['employees', 'housing', 'feeding', 'breeding'], sections: [
        S('Overview', '/settings', 'Master Settings'),
        S('Configuration', '/settings/users', 'Users'),
        S('Configuration', '/settings/access', 'Access Rights by Role'),
        S('Configuration', '/settings/breeds', 'Breeds'),
        S('Configuration', '/settings/ages', 'Age Groups'),
        S('Configuration', '/settings/fields', 'Register Fields'),
        S('Configuration', '/settings/system', 'System'),
    ] },
];

/** D-241: the person's page rules (shared by the server), so a menu only lists pages the server will open. */
type Rule = [string, Record<string, string> | null, boolean];
let RULES: Rule[] = [];
export const setPageRules = (rules: Rule[] | undefined) => { RULES = rules ?? []; };

/** Same matching as App\Support\Pages::rule: exact path with the most matching query values, else the nearest parent path. */
export function canOpen(href: string): boolean {
    if (!RULES.length) return true;
    const [main] = href.split('#');
    const [rawPath, qs = ''] = main.split('?');
    const path = `/${rawPath.replace(/^\/+|\/+$/g, '')}`;
    const query = Object.fromEntries(new URLSearchParams(qs));
    let best: Rule | null = null;
    let score = -1;
    for (const r of RULES) {
        if (r[0] !== path) continue;
        const q = r[1] ?? {};
        if (Object.entries(q).every(([k, v]) => query[k] === v) && Object.keys(q).length > score) { best = r; score = Object.keys(q).length; }
    }
    if (best) return best[2];
    if (path === '/') return true;
    const parts = path.replace(/^\//, '').split('/');
    while (parts.length > 1) {
        parts.pop();
        const parent = `/${parts.join('/')}`;
        const r = RULES.find((x) => x[0] === parent && !x[1]);
        if (r) return r[2];
    }
    return true;
}

/** An app's menu entries this person may open. */
export const sectionsFor = (a: ErpApp) => a.sections.filter((s) => canOpen(s.href));

/** D-215: a menu link to a ?tab= section opens it as its own page (no other tabs); see Tabs in ui.tsx. */
export const menuHref = (href: string) => (href.includes('tab=') ? `${href}${href.includes('?') ? '&' : '?'}solo=1` : href);

/** D-218: opening an app goes straight to its first page; the left menu lists the rest (no master page). */
export const masterHref = (key: string) => { const a = APPS.find((x) => x.key === key); const first = a ? sectionsFor(a)[0] : undefined; return first ? menuHref(first.href) : '/apps'; };

/** D-241: an app shows only when the person holds one of its modules and may open at least one of its pages (RBAC §8.1). */
export const visibleApps = (modules: string[]) => APPS.filter((a) => a.modules.some((m) => modules.includes(m)) && sectionsFor(a).length > 0);

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
    const owns = (a: ErpApp) => sectionsFor(a).some((x) => base(x.href) === path || (path.startsWith(`${base(x.href)}/`) && base(x.href) !== '/'));
    const remembered = apps.find((a) => a.key === last);
    if (remembered && owns(remembered)) return remembered;
    return apps.find((a) => sectionsFor(a).some((x) => isHere(x.href, url))) ?? apps.find(owns) ?? null;
}
