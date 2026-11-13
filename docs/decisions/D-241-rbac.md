# D-241: Role-based access from KK_FARMS_RBAC.md

Status: implemented locally, all 305 feature tests pass, **not deployed**. Source of truth: `KK_FARMS_RBAC.md` (owner, 2026-11-13).

## What changed

| Area | Now |
|---|---|
| Role defaults (§4, §5) | `config/kkfarm.php` modules and actions rewritten from the matrices. New action **Record weights** (ADM, MGR, CTK): VET and FDE view weights only. |
| Vet-locked (🔒) | Diagnose and treat, Open health cases, Approve breeding: VET only. The role screen cannot change them; the administrator can give one to **one person** on that person's page. |
| Page access (§8) | `config/kkfarm_pages.php` lists every page and tab with its roles; `EnsurePage` checks every GET on the server; menus, the All Apps grid and in-page tabs use the same rules, so a menu never shows a page that answers 403. An app with no page left for the person disappears. |
| Linked Apps (§8.1) | Removed from every app sidebar. |
| Role homes (§6) | Sign-in lands on the role's home: ADM/MGR the Executive Dashboard, everyone else **My Home** (`/home`): their tasks today, alerts routed to them, a few numbers (zeros hidden), their usual pages. CEO Scorecard ADM only. |
| 403 (§8.1.10) | "You don't have access to this page" with **Go to my home page**. Every refused request (page or action) is written to the Activity Log as a security entry, visible to the administrator only. |
| Tasks (§9) | Everyone except ADM/MGR sees only tasks given to them or their department; others can only create tasks for themselves; changing someone else's task is refused. |
| Notifications (§10) | `ActionCenter::ROUTES`: each alert type goes only to its roles, and only if the person has the module **and** may open the page it links to. Bell, Action Center and My Home use the same filter. |
| Inventory (§4 notes) | Scoped by store: VET medicine, FDE feed, CTK/CLN general store, FIN all (valuation). |
| Field hiding (§7) | Goat page and Health page send diagnoses, cases, treatments, protocols and mortality only to ADM/MGR/VET (or a person granted Health). "Search goats" hidden for roles without the goat register. |
| Overrides (§11) | Personal ticks show **Custom: given / refused**. Changing a role shows "will gain X, lose Y" before saving and logs it. The last active Administrator cannot be demoted or switched off. |
| Migration | `2026_11_13_000800_rbac_defaults`: each farm's saved role ticks are set aside (kept in `organizations.role_access_before_rbac`, reversible) so the new defaults apply; personal ticks are kept and listed in the Activity Log for review. No user changes role; no data deleted. |

## Choices where the document contradicts itself

1. **Same page, different values.** §8.1 says the destination page decides. 20 menu entries open a page that another entry also opens with different ticks (e.g. `/movements` = Animal Exit and Other Exits; `/sheds` = Farms, Units & Pens and Unit & Pen Setup; `/milking` = Lactations and Milking Settings). One rule per page was used, generally the wider one; actions inside the page still follow §5.
2. **Stock Count & Shrinkage**: §8.3 gives FDE ●, §4 hides Feed true cost from FDE. FDE gets the stock-count tab only; the rest of Feed true cost stays closed.
3. **Transport** was tied to the goat register; Finance (no goat register) plans sale trips, so the area now opens with Goats **or** Sales.
4. **Owner and Manager see all tasks** (oversight), the rest see own and department only.
5. **Fodder** stays switched off until the owner turns it on (D-202), whatever the role.

## Rules that changed from earlier decisions (please confirm)

- **Feeding plans are approved by ADM/MGR, no longer the vet** (was R-6 / D-054). Vets now view plans; nutrition standards and TMR formulas are drafted by ADM/MGR/FDE.
- **Medicine store**: ADM and VET only (Manager removed).
- **Opening health cases**: VET only; the Administrator can no longer open a case from a checkup (was D-095). Caretakers report a sick goat and do routine checkups.
- **Breeding approval and its rules**: VET only (Manager, Administrator removed; D-231).
- **Milking settings / dry-off**: ADM, MGR (vet removed). **Device keys**: ADM only.
- **Registering a purchased goat**: ADM, MGR only; scanning an unknown tag as another role no longer opens the register.
- Finance loses the goat register and Livestock; Cleaning loses Welfare; Vet loses Sensors.

## Still open (§14 and not yet built)

- Owner decisions from §14: may the Manager see financial summaries (now: view), payroll (now: no), may Finance see goat-level sale data (now: no goat register)?
- Report hub: section links are not yet filtered by domain inside `/reports` (blocked sections answer 403).
- Activity Log for the Manager is not yet limited to "own team".
- Caretaker notifications are not yet limited to "own pens"; there is no email or push yet, only in-app.
- Other pages with several tabs still send all tabs' data (hidden tabs are blocked by URL and not shown). Health and the goat page are done; the rest should follow the same pattern.
- Salaries: the Employees module has no salary fields today, so nothing to hide yet.

## Tests (§13)

`tests/Feature/RbacTest.php`, one user per role, covers §13.1 to §13.12: Finance apps and refused pages, Executive Dashboard and Scorecard for every role, no Linked Apps, Cleaning, Feeding, Caretaker, Vet-only actions, Manager limits, last Administrator, 403 + audit entry, notifications and task scoping, personal grant and role-change diff. 21 existing test files were updated where they used a role the document no longer allows (listed in the commit). Full suite: **305 / 305 passing**. `GeneticSelectionTest::test_merit_from_progeny...` failed once in one full run and passed on three reruns: a pre-existing ordering tie, not caused by D-241.

## D-243: page access editable on screen (2026-11-13)

- **Registry:** `config/kkfarm_pages.php` now has one row per menu destination (179 pages): stable key (`health.medical_cases`), label, app, group, address, default roles, lock, and the apps whose menus show it. Rows were generated from D-241's rules and the app menus, so the defaults did not change; menus that shared one rule (every `/breeding` tab) now have their own row starting from the same roles.
- **Storage:** `role_page_access` and `user_page_access` (per farm, row-level security). Only differences from the default are stored; ticking a box back to its default deletes the row.
- **Effective access** = the page's app is open to the person AND (person's own tick, else the role's tick, else the default). A role given an app it did not have starts with that app's everyday pages (those open to the Farm Manager by default); the administrator narrows them.
- **Locks:** Settings (every page) and the CEO Scorecard: Administrator only, cannot be changed, so the last Administrator cannot lose them. Vet-locked pages keep their role ticks; the administrator may give one to a single person. Addresses without a tab (`/health`) open when any of their tabs does. A page missing from the registry is Administrator only.
- **One resolver everywhere:** server page check, menus, All Apps grid, in-page tabs (a closed tab is skipped), role homes, report hub (only permitted reports are sent and shown), finance books (closed tabs' rows are not sent), goat search, and notifications (sent only when the person can open the alert's link).
- **Audit:** every changed cell is written to the Activity Log as "Page access: role or person · page: old → new" (Administrator only).
- **Tests:** `tests/Feature/PageAccessTest.php` (8 tests) covers default, role and person ticks, locks, the last Administrator, unticking a page for Finance (menu, 403, data, notifications), ticking a page for Cleaning (only with its app), reset to defaults, and the editor's data. Full suite **313 / 313**.
- **Still open:** pages other than Health, the goat page, Finance books and Reports still send all tabs' data even when a tab is closed (the tab is hidden and its address refused).

## Audit: every menu entry and who may open it

● = the entry shows and the page opens (module **and** page rule). Generated from the code, not typed by hand.

| App | Menu entry | Page | ADM | MGR | VET | CTK | FDE | CLN | FIN |
|---|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Fieldwork | Choose a Job | `/worker` | ● | ● | ● | ● | ● | — | — |
| Fieldwork | Weigh · Health Check · Move · Look Up | `/worker` | ● | ● | ● | ● | ● | — | — |
| Fieldwork | RFID Scan | `/scan` | ● | ● | ● | ● | ● | — | — |
| Fieldwork | Feed Pens | `/feeding?tab=now` | ● | ● | ● | ● | ● | — | — |
| Fieldwork | My Tasks | `/tasks?tab=mine` | ● | ● | ● | ● | ● | — | — |
| Fieldwork | Activity Log | `/activity` | ● | ● | — | — | — | — | — |
| Fieldwork | Gun & App Settings | `/account/app` | ● | ● | ● | ● | ● | — | — |
| Dashboard | Executive Dashboard | `/` | ● | ● | — | — | — | — | — |
| Dashboard | My Home | `/home` | ● | ● | ● | ● | ● | ● | ● |
| Dashboard | CEO Scorecard | `/dashboard/scorecard` | ● | — | — | — | — | — | — |
| Dashboard | Alerts & Action Center | `/actions` | ● | ● | ● | ● | ● | ● | ● |
| Dashboard | Activity Log | `/activity` | ● | ● | — | — | — | — | — |
| Dashboard | Weight Gain & Loss | `/dashboard/weight-change` | ● | ● | ● | ● | ● | — | — |
| Dashboard | Farm Reports | `/reports` | ● | ● | ● | — | ● | — | ● |
| Dashboard | Users & Roles | `/settings/users` | ● | — | — | — | — | — | — |
| Livestock | All Goats | `/goats` | ● | ● | ● | ● | ● | — | — |
| Livestock | Register Purchased Goat | `/goats/register` | ● | ● | — | — | — | — | — |
| Livestock | Intake & Quarantine | `/intake` | ● | ● | ● | ● | — | — | — |
| Livestock | Newborns to Complete | `/goats?show=newborn` | ● | ● | ● | ● | — | — | — |
| Livestock | RFID Scan | `/scan` | ● | ● | ● | ● | ● | — | — |
| Livestock | Animal Exit | `/movements` | ● | ● | ● | ● | — | — | — |
| Livestock | Pregnant Does | `/goats?show=pregnant` | ● | ● | ● | ● | — | — | — |
| Livestock | Need Attention | `/goats?show=attention` | ● | ● | ● | ● | — | — | — |
| Livestock | Missing Data | `/goats?show=missing` | ● | ● | ● | ● | — | — | — |
| Livestock | Herd Census | `/reports?section=herd` | ● | ● | ● | — | — | — | — |
| Livestock | Age-Wise Report | `/reports?section=age` | ● | ● | ● | — | — | — | — |
| Livestock | Breed-Wise Population | `/reports?section=breeds` | ● | ● | ● | — | — | — | — |
| Livestock | Exits & Mortality | `/reports?section=exits` | ● | ● | ● | — | — | — | — |
| Livestock | Breed Master | `/settings/breeds` | ● | — | — | — | — | — | — |
| Livestock | Age Groups | `/settings/ages` | ● | — | — | — | — | — | — |
| Livestock | Automation Rules | `/settings/automation` | ● | — | — | — | — | — | — |
| Livestock | Register Fields | `/settings/fields` | ● | — | — | — | — | — | — |
| Breeding | Heat Detection | `/breeding?tab=heat` | ● | ● | ● | ● | — | — | — |
| Breeding | Mating, Pregnancy & Ultrasound | `/breeding?tab=cycle` | ● | ● | ● | ● | — | — | — |
| Breeding | Pregnancy Calendar | `/breeding?tab=calendar` | ● | ● | ● | ● | — | — | — |
| Breeding | Replacement Review (Genetic Merit) | `/genetics?tab=review` | ● | ● | ● | — | — | — | — |
| Breeding | Sire & Dam Progeny Performance | `/genetics?tab=progeny` | ● | ● | ● | — | — | — | — |
| Breeding | Inbreeding Check | `/genetics?tab=mating` | ● | ● | ● | — | — | — | — |
| Breeding | Replacement Breeder Selection | `/growth?tab=selection` | ● | ● | ● | — | — | — | — |
| Breeding | Breeding Eligibility & Approval | `/breeding/eligibility` | ● | ● | ● | — | — | — | — |
| Breeding | Genetics & Family Tree | `/breeding?tab=pedigree` | ● | ● | ● | — | — | — | — |
| Breeding | Pregnant Does | `/goats?show=pregnant` | ● | ● | ● | ● | — | — | — |
| Breeding | Doe Performance | `/breeding?tab=performance` | ● | ● | ● | — | — | — | — |
| Breeding | Buck Performance | `/reports?section=sires` | ● | ● | ● | — | — | — | — |
| Breeding | Gestation & Check Days | `/breeding?tab=settings` | ● | ● | ● | — | — | — | — |
| Breeding | Breeding Eligibility Rules | `/breeding/eligibility#rules` | ● | ● | ● | — | — | — | — |
| Maternity | Upcoming Deliveries | `/breeding?tab=calendar` | ● | ● | ● | ● | — | — | — |
| Maternity | Kidding & Pregnancy Outcomes | `/breeding?tab=kidding` | ● | ● | ● | ● | — | — | — |
| Maternity | Pregnancy Loss Investigation | `/repro-loss` | ● | ● | ● | — | — | — | — |
| Maternity | Newborns to Complete | `/goats?show=newborn` | ● | ● | ● | ● | — | — | — |
| Maternity | Kid Care | `/breeding?tab=kidcare` | ● | ● | ● | ● | — | — | — |
| Maternity | Weaning | `/breeding?tab=weaning` | ● | ● | ● | ● | — | — | — |
| Maternity | Kid Growth vs Plan | `/growth?tab=growth` | ● | ● | ● | ● | — | — | — |
| Maternity | Mother Care & Lactation | `/milking` | ● | ● | ● | ● | — | — | — |
| Maternity | Maternity Unit | `/sheds` | ● | ● | ● | ● | — | — | — |
| Maternity | Kidding Rate & Litter Size | `/breeding?tab=performance` | ● | ● | ● | — | — | — | — |
| Maternity | Weaning & Kidding Alerts | `/breeding?tab=settings` | ● | ● | ● | — | — | — | — |
| Veterinary | Daily Health & Checkups | `/health?tab=checkups` | ● | ● | ● | ● | — | — | — |
| Veterinary | Medical Cases | `/health?tab=cases` | ● | ● | ● | — | — | — | — |
| Veterinary | Disease Control & Recovery | `/disease?tab=cases` | ● | ● | ● | — | — | — | — |
| Veterinary | Triage Reported Symptoms | `/disease?tab=triage` | ● | ● | ● | — | — | — | — |
| Veterinary | Reported Concerns | `/health?tab=reports` | ● | ● | ● | ● | — | — | — |
| Veterinary | Restricted Pens | `/disease?tab=restricted` | ● | ● | ● | ● | — | — | — |
| Veterinary | Outbreak Reports | `/disease?tab=outbreaks` | ● | ● | ● | — | — | — | — |
| Veterinary | Parasite Testing & Control | `/parasites` | ● | ● | ● | — | — | — | — |
| Veterinary | Vaccination & Deworming Due | `/health?tab=calendar` | ● | ● | ● | ● | — | — | — |
| Veterinary | Preventive Care Given | `/health?tab=care` | ● | ● | ● | ● | — | — | — |
| Veterinary | Quarantine & Clearance | `/intake` | ● | ● | ● | ● | — | — | — |
| Veterinary | Withdrawal & Biosecurity | `/health?tab=withdrawal` | ● | ● | ● | ● | — | — | — |
| Veterinary | Antimicrobial Use | `/health/antimicrobials` | ● | ● | ● | — | — | — | — |
| Veterinary | Sick & Under Watch | `/goats?show=attention` | ● | ● | ● | ● | — | — | — |
| Veterinary | Mortality & Postmortem | `/health?tab=mortality` | ● | ● | ● | — | — | — | — |
| Veterinary | Mortality by Cause | `/health?tab=mortality` | ● | ● | ● | — | — | — | — |
| Veterinary | Vaccine & Deworming Protocols | `/health?tab=protocols` | ● | — | ● | — | — | — | — |
| Veterinary | Disease Master | `/health?tab=diseases` | ● | — | ● | — | — | — | — |
| Veterinary | Medicines & Vaccines | `/health?tab=store` | ● | ● | ● | — | — | — | — |
| Feeding | Daily Feeding | `/feeding?tab=now` | ● | ● | ● | ● | ● | — | — |
| Feeding | TMR Mixing Batches | `/feeding?tab=tmr` | ● | ● | ● | — | ● | — | — |
| Feeding | Feed Safety: Lots, Tests & Holds | `/feeding/lots` | ● | ● | ● | — | ● | — | — |
| Feeding | Feeding History | `/feeding?tab=history` | ● | ● | ● | ● | ● | — | — |
| Feeding | Feeding Plans & Rations | `/feeding?tab=rations` | ● | ● | ● | ● | ● | — | — |
| Feeding | Feed True Cost (per Goat & KG Gain) | `/feed-cost` | ● | ● | — | — | — | — | — |
| Feeding | Stock Count & Shrinkage | `/feed-cost?tab=stock` | ● | ● | — | — | ● | — | — |
| Feeding | Feed Spending | `/reports?section=spending` | ● | ● | — | — | — | — | — |
| Feeding | Feed Offered, Refused & Wasted | `/feeding?tab=waste` | ● | ● | ● | ● | ● | — | — |
| Feeding | Nutrition Standards | `/feeding?tab=nutrition` | ● | ● | ● | — | ● | — | — |
| Feeding | TMR Formulas | `/feeding?tab=tmr` | ● | ● | ● | — | ● | — | — |
| Feeding | Feed Master | `/feeding?tab=store` | ● | ● | — | — | ● | — | — |
| Feeding | Feeding Times | `/feeding?tab=times` | ● | ● | — | — | ● | — | — |
| Housing | Farms, Units & Pens | `/sheds` | ● | ● | ● | ● | ● | — | — |
| Housing | Animal Movements | `/movements` | ● | ● | ● | ● | — | — | — |
| Housing | Goats Without a Pen | `/goats?show=no_pen` | ● | ● | — | ● | — | — | — |
| Housing | Pen Status, Capacity & Cleaning | `/housing` | ● | ● | ● | ● | ● | ● | — |
| Housing | Pen Occupancy | `/housing` | ● | ● | ● | ● | ● | ● | — |
| Housing | Unit & Pen Setup | `/sheds` | ● | ● | ● | ● | ● | — | — |
| Growth | Growth vs Plan | `/growth?tab=growth` | ● | ● | ● | ● | ● | — | — |
| Growth | Record Weights | `/weights` | ● | ● | ● | ● | — | — | — |
| Growth | Held Weights to Review | `/weights/review` | ● | ● | ● | — | — | — | — |
| Growth | Nutrition & Vet Interventions | `/growth?tab=interventions` | ● | ● | ● | ● | ● | — | — |
| Growth | Breeder / Sale Selection | `/growth?tab=selection` | ● | ● | ● | — | — | — | — |
| Growth | No Weight Yet | `/goats?show=no_weight` | ● | ● | ● | ● | — | — | — |
| Growth | Selection Records | `/growth?tab=selections` | ● | ● | ● | — | — | — | — |
| Growth | Daily Gain (ADG) | `/weights` | ● | ● | ● | ● | — | — | — |
| Growth | Growth Plans & Selection Age | `/growth?tab=plan` | ● | ● | — | — | — | — | — |
| Growth | Breed Weight Lines | `/settings/breeds` | ● | — | — | — | — | — | — |
| Operations | My Tasks | `/tasks?tab=mine` | ● | ● | ● | ● | ● | ● | ● |
| Operations | Incident Register | `/operations?tab=incidents` | ● | ● | ● | ● | ● | ● | ● |
| Operations | Shift Handover | `/operations?tab=handover` | ● | ● | ● | ● | ● | ● | — |
| Operations | Checklist History | `/operations?tab=history` | ● | ● | ● | ● | ● | ● | ● |
| Operations | Done This Week | `/tasks?tab=closed` | ● | ● | ● | ● | ● | ● | ● |
| Operations | Daily Activity Log | `/activity` | ● | ● | — | — | — | — | — |
| Operations | Delayed Tasks | `/tasks?tab=overdue` | ● | ● | ● | ● | ● | ● | ● |
| Operations | Pending Actions | `/actions` | ● | ● | ● | ● | ● | ● | ● |
| Operations | Checklist Setup | `/operations?tab=templates` | ● | ● | — | — | — | — | — |
| Operations | Departments & Staff | `/employees` | ● | ● | — | — | — | — | ● |
| Inventory | All Stock | `/inventory` | ● | ● | ● | ● | ● | ● | ● |
| Inventory | General Store | `/inventory?tab=general` | ● | ● | — | ● | — | ● | ● |
| Inventory | Feed Inventory | `/feeding?tab=store` | ● | ● | — | — | ● | — | — |
| Inventory | Medicine Inventory | `/health?tab=store` | ● | ● | ● | — | — | — | — |
| Inventory | TMR Mixing | `/feeding?tab=tmr` | ● | ● | ● | — | ● | — | — |
| Inventory | Stock Moves | `/inventory?tab=moves` | ● | ● | ● | ● | ● | ● | ● |
| Inventory | Reorder List | `/inventory?tab=reorder` | ● | ● | ● | ● | ● | ● | — |
| Inventory | Consumption & Spending | `/reports?section=spending` | ● | ● | — | — | — | — | ● |
| Inventory | Store Items | `/inventory?tab=general` | ● | ● | — | ● | — | ● | ● |
| Purchase | Purchase Orders | `/purchase` | ● | ● | ● | — | ● | — | ● |
| Purchase | New Purchase Order | `/purchase` | ● | ● | ● | — | ● | — | ● |
| Purchase | Goat Purchase Lots | `/purchase/animals` | ● | ● | ● | — | — | — | ● |
| Purchase | Goat Purchase Request | `/purchase/animals?tab=new` | ● | ● | — | — | — | — | ● |
| Purchase | Animal Intake & Quarantine | `/intake` | ● | ● | ● | ● | — | — | — |
| Purchase | Suppliers | `/purchase?tab=suppliers` | ● | ● | — | — | — | — | ● |
| Purchase | Purchase Analysis | `/reports?section=spending` | ● | ● | — | — | — | — | ● |
| Purchase | Purchase Accounts | `/finance/books?tab=accounts` | ● | — | — | — | — | — | ● |
| Sales | All Sales | `/sales` | ● | ● | — | — | — | — | ● |
| Sales | Sale Readiness & Clearance | `/sales/readiness` | ● | ● | — | — | — | — | ● |
| Sales | Sale Orders & Dispatch | `/sales/orders` | ● | ● | — | — | — | — | ● |
| Sales | Transport & Delivery | `/transport` | ● | ● | — | — | — | — | ● |
| Sales | Approved Vehicles | `/transport?tab=vehicles` | ● | ● | — | — | — | — | — |
| Sales | New Price Quote | `/sales/orders?tab=new` | ● | ● | — | — | — | — | ● |
| Sales | Invoices & Collections | `/sales/orders?tab=done` | ● | ● | — | — | — | — | ● |
| Sales | Other Exits | `/movements` | ● | ● | — | — | — | — | ● |
| Sales | Sell Products | `/sales` | ● | ● | — | — | — | — | ● |
| Sales | Customers | `/sales?tab=customers` | ● | ● | — | — | — | — | ● |
| Sales | Herd Value & Pricing | `/value` | ● | ● | — | — | — | — | ● |
| Sales | Monthly Sales | `/sales?tab=monthly` | ● | ● | — | — | — | — | ● |
| Sales | Sales & Exits | `/reports?section=exits` | ● | ● | — | — | — | — | ● |
| Sales | Market Rates | `/value` | ● | ● | — | — | — | — | ● |
| Accounting | Income & Farm Expenses | `/finance` | ● | ● | — | — | — | — | ● |
| Accounting | Journal Entries | `/finance/books?tab=journal` | ● | — | — | — | — | — | ● |
| Accounting | General Ledger | `/finance/books?tab=ledger` | ● | — | — | — | — | — | ● |
| Accounting | Trial Balance | `/finance/books?tab=trial` | ● | — | — | — | — | — | ● |
| Accounting | Profit & Loss | `/finance/books?tab=pl` | ● | ● | — | — | — | — | ● |
| Accounting | Balance Sheet | `/finance/books?tab=balance` | ● | ● | — | — | — | — | ● |
| Accounting | Feed Cost per KG Gained | `/feed-cost` | ● | ● | — | — | — | — | ● |
| Accounting | Chart of Accounts | `/finance/books?tab=accounts` | ● | — | — | — | — | — | ● |
| Employees | Employees & Departments | `/employees` | ● | ● | — | — | — | — | ● |
| Employees | Attendance Register | `/hr?tab=attendance` | ● | ● | — | — | — | — | ● |
| Employees | Leave Requests | `/hr?tab=leave` | ● | ● | — | — | — | — | ● |
| Employees | Training & Compliance | `/hr/training` | ● | ● | — | — | — | — | — |
| Employees | Skills & Authorisations | `/hr/training?tab=skills` | ● | ● | — | — | — | — | — |
| Employees | Shift Roster | `/hr?tab=roster` | ● | ● | — | — | — | — | — |
| Employees | Staff Activity | `/activity` | ● | ● | — | — | — | — | — |
| Employees | Attendance Report | `/hr?tab=report` | ● | ● | — | — | — | — | ● |
| Employees | Skills Compliance | `/hr/training?tab=matrix` | ● | ● | — | — | — | — | — |
| Employees | Task Completion | `/tasks?tab=closed` | ● | ● | — | — | — | — | ● |
| Employees | Users & Permissions | `/settings/users` | ● | — | — | — | — | — | — |
| Maintenance | Open Jobs & Breakdowns | `/maintenance?tab=jobs` | ● | ● | — | ● | ● | ● | — |
| Maintenance | Preventive Services Due | `/maintenance?tab=due` | ● | ● | — | — | — | — | — |
| Maintenance | Sensor Alerts | `/sensors?tab=alerts` | ● | ● | — | — | — | — | — |
| Maintenance | Asset Register | `/maintenance?tab=assets` | ● | ● | — | — | — | — | — |
| Maintenance | Sensors & Devices | `/sensors?tab=devices` | ● | ● | — | — | — | — | — |
| Maintenance | Repair History & Cost | `/maintenance?tab=history` | ● | ● | — | — | — | — | — |
| Maintenance | Sensor Readings | `/sensors?tab=readings` | ● | ● | — | — | — | — | — |
| Maintenance | Asset Categories & Intervals | `/maintenance?tab=assets` | ● | ● | — | — | — | — | — |
| Maintenance | Connect a Device | `/sensors?tab=connect` | ● | — | — | — | — | — | — |
| Reports | Reports & Analytics | `/reports` | ● | ● | ● | — | ● | — | ● |
| Reports | CEO Dashboard | `/` | ● | ● | — | — | — | — | — |
| Reports | Livestock Reports | `/reports?section=herd` | ● | ● | ● | — | — | — | — |
| Reports | Breeding Reports | `/breeding?tab=performance` | ● | ● | ● | — | — | — | — |
| Reports | Financial Reports | `/finance/books?tab=pl` | ● | ● | — | — | — | — | ● |
| Reports | Report Access | `/settings/access` | ● | — | — | — | — | — | — |
| Dairy | Close AM / PM Sessions | `/dairy?tab=sessions` | ● | ● | — | ● | — | — | — |
| Dairy | Log Litres per Doe | `/milking` | ● | ● | ● | ● | — | — | — |
| Dairy | Milk Lots, QA & Release | `/dairy?tab=lots` | ● | ● | ● | ● | — | — | — |
| Dairy | Milk Orders | `/dairy?tab=orders` | ● | ● | — | — | — | — | ● |
| Dairy | Dispatch & Trace | `/dairy?tab=trace` | ● | ● | — | — | — | — | ● |
| Dairy | Milk Processing Batches | `/dairy/processing` | ● | ● | — | ● | — | — | — |
| Dairy | Lactations | `/milking` | ● | ● | ● | ● | — | — | — |
| Dairy | Milk Invoices & Profit | `/dairy?tab=money` | ● | ● | — | — | — | — | ● |
| Dairy | Lot Traceability & Revenue | `/dairy?tab=trace` | ● | ● | — | — | — | — | ● |
| Dairy | Milking Settings | `/milking` | ● | ● | ● | ● | — | — | — |
| Fattening | Finishing Batches | `/fattening?tab=batches` | ● | ● | ● | ● | ● | — | — |
| Fattening | Meat Selection | `/fattening?tab=selection` | ● | ● | — | ● | — | — | — |
| Fattening | New Batch (Select Feeders) | `/fattening?tab=new` | ● | ● | — | ● | — | — | — |
| Fattening | Daily Feed & Water | `/feeding?tab=now` | ● | ● | ● | ● | ● | — | — |
| Fattening | Herd Value & Market Rates | `/value` | ● | ● | — | — | — | — | — |
| Fattening | Batch Profitability | `/fattening?tab=profit` | ● | ● | — | — | — | — | — |
| Fattening | Market Rates | `/value` | ● | ● | — | — | — | — | — |
| Welfare | Welfare Cases | `/welfare?tab=cases` | ● | ● | ● | ● | — | — | — |
| Welfare | Daily Welfare & Mobility Check | `/welfare?tab=check` | ● | ● | ● | ● | — | — | — |
| Welfare | Vet Referrals (Reported Concerns) | `/health?tab=reports` | ● | ● | ● | ● | — | — | — |
| Welfare | Check Records | `/welfare?tab=checks` | ● | ● | ● | ● | — | — | — |
| Welfare | Closed Cases | `/welfare?tab=closed` | ● | ● | ● | — | — | — | — |
| Welfare | Humane End-of-Life Review | `/welfare/end-of-life` | ● | ● | ● | — | — | — | — |
| Welfare | Mortality | `/health?tab=mortality` | ● | ● | ● | — | — | — | — |
| Welfare | Condition List | `/health?tab=diseases` | ● | — | ● | — | — | — | — |
| Environment | Shed Rounds | `/environment?tab=sheds` | ● | ● | ● | ● | — | ● | — |
| Environment | Water, Heat & Waste Issues | `/environment?tab=issues` | ● | ● | ● | ● | — | ● | — |
| Environment | Water Lab Tests | `/environment?tab=tests` | ● | ● | ● | — | — | ● | — |
| Environment | Sensor Alarms | `/sensors?tab=alerts` | ● | ● | — | — | — | — | — |
| Environment | Waste & Safe Disposal | `/waste` | ● | ● | ● | — | — | ● | — |
| Environment | Waste Routes (Adviser-Set) | `/waste?tab=routes` | ● | ● | — | — | — | — | — |
| Environment | Round Records | `/environment?tab=records` | ● | ● | ● | ● | — | ● | — |
| Environment | Sensor Readings | `/sensors?tab=readings` | ● | ● | — | — | — | — | — |
| Environment | Water & Heat Compliance | `/environment?tab=records` | ● | ● | ● | ● | — | ● | — |
| Environment | Water Points & Heat Limit | `/environment?tab=setup` | ● | ● | — | — | — | — | — |
| Quality | Quality Dashboard | `/quality?tab=dashboard` | ● | ● | ● | — | — | — | — |
| Quality | Raise a Quality Alarm | `/quality?tab=new` | ● | ● | ● | — | — | — | — |
| Quality | Cases & CAPA | `/quality?tab=cases` | ● | ● | ● | — | — | — | — |
| Quality | Holds, Isolation & Recall | `/quality?tab=holds` | ● | ● | ● | — | — | — | — |
| Quality | Incident Register | `/operations?tab=incidents` | ● | ● | ● | — | — | — | — |
| Quality | SOP Library | `/quality?tab=sops` | ● | ● | ● | — | — | — | — |
| Quality | Regulatory Register | `/quality?tab=register` | ● | ● | ● | — | — | — | — |
| Quality | Emergency Readiness | `/quality?tab=drills` | ● | ● | ● | — | — | — | — |
| Quality | Withdrawal Holds | `/health?tab=withdrawal` | ● | ● | ● | — | — | — | — |
| Quality | Closed Cases & Learning | `/quality?tab=closed` | ● | ● | ● | — | — | — | — |
| Quality | Audit Trail | `/activity` | ● | ● | — | — | — | — | — |
| Quality | Checklists | `/operations?tab=templates` | ● | ● | — | — | — | — | — |
| Fodder | Crops & Fields | `/fodder?tab=crops` | ● | ● | — | ● | ● | — | — |
| Fodder | Harvest Lots: Test & Release | `/fodder?tab=lots` | ● | ● | — | — | ● | — | — |
| Fodder | Field Registry | `/fodder?tab=fields` | ● | ● | — | — | ● | — | — |
| Fodder | Yield & Cost per KG DM | `/fodder?tab=report` | ● | ● | — | — | ● | — | — |
| Fodder | Fodder Settings | `/fodder?tab=setup` | ● | ● | — | — | — | — | — |
| Settings | Master Settings | `/settings` | ● | — | — | — | — | — | — |
| Settings | Users | `/settings/users` | ● | — | — | — | — | — | — |
| Settings | Access Rights by Role | `/settings/access` | ● | — | — | — | — | — | — |
| Settings | Breeds | `/settings/breeds` | ● | — | — | — | — | — | — |
| Settings | Age Groups | `/settings/ages` | ● | — | — | — | — | — | — |
| Settings | Register Fields | `/settings/fields` | ● | — | — | — | — | — | — |
| Settings | System | `/settings/system` | ● | — | — | — | — | — | — |
