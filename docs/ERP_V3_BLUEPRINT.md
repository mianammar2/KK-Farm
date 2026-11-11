# KK Farms ERP V3.0 – Blueprint tracker

Source: owner's "KK Farms Enterprise Goat Farming ERP – Final 360° Functional Blueprint & SRS, Version 3.0, 11 Nov 2026"
(21 core Odoo-style apps + optional Fodder & Pasture + System Settings; 41 flowcharts; BR-001…BR-032; UAT-01…UAT-34).
Full handbook with the 41 flowcharts: KK_Farms_ERP_V3_FINAL_360_Handbook.pdf (owner's copy in /Users/MAC/goat, not in git); flow summaries in ERP_V3_FLOWS.md. Each app is built to its flowchart page.

Status: **Built** (live), **Partial** (some screens/rules), **To do**. Decision numbers refer to `DECISIONS_LOG.md`.

Every app follows: app launch → Overview → Operations → Records → Reporting → Configuration (D-170, D-171).

## Three production streams (§2)

| Stream | Lifecycle | Status |
|---|---|---|
| Breeding & Genetics | selection → heat → mating → ultrasound → pregnancy → kidding → kid care → replacement/sale | Built (heat, mating, ultrasound, outcomes, kid care, growth plan and replacement selection D-191; relatedness check at mating) |
| Fattening & Meat | feeder selection → quarantine → finishing lot → TMR → weight/ADG → welfare & vet clearance → live sale | Built (D-187) |
| Dairy & Milk | kidding/lactation → udder screening → AM/PM milking → kid/colostrum allocation → QA → chilling → batch dispatch | Built (D-117, D-188) |

## Apps (§3–4)

| No. | App | Primary menus (SRS) | Status |
|---|---|---|---|
| 01 | Farm Dashboard | Overview; Herd Intelligence; Production Intelligence; Care & Risk; Cost & Revenue; Decisions & Alerts; Reporting | Partial |
| 02 | Livestock Registry & Traceability | Animals; Intake; Identification; Classification; Pedigree; Movements; Exit; Reporting | Partial (permanent Animal ID series separate from descriptive tag: to do) |
| 03 | Breeding & Genetics | Breeding Stock; Heat Detection; Mating; Pregnancy; Genetics; Reproduction Issues; Performance; Configuration | Partial (D-161, D-166, D-175) |
| 04 | Maternity & Kid Care | Pre-Kidding; Kidding; Pregnancy Outcomes; Newborn Registry; Mother Care; Kid Nutrition; Weaning; Reporting | Built (D-161–D-164, D-171, D-176) |
| 05 | Veterinary & Biosecurity | Health Monitoring; Medical Cases; Treatment; Preventive Care; Quarantine; Reproduction Health; Withdrawal & Disposal; Reporting | Partial (D-172–D-174, D-193 disease control, restrictions, outbreaks; antimicrobial use report to do) |
| 06 | Feeding & Nutrition | Feed Master; Nutrition Standards; Ration Formulas; Daily Feed Plans; Feed Production; Consumption; Feed Safety; Cost & Reports | Partial (D-177; feed safety lot holds to do) |
| 07 | Housing & Pen Management | Farm Structure; Unit Categories; Pen Operations; Biosecure Areas; Housing Conditions; Cleaning; Traceability; Reports | Built (D-207: pen status, capacity block with authorised override, cleaning verified by someone else) |
| 08 | Growth & Performance | Weighing; Growth Plans; Body Condition; Performance; Breeder Selection; Fattening Integration; Reports | Partial (growth plans, interventions, breeder selection D-191; outlier/duplicate weight flags outside fattening to do) |
| 09 | Farm Operations & Tasks | Daily Routines; Task Planner; Work Orders; Checklists; Incidents; Approvals; Reports | Built (D-180) |
| 10 | Inventory & Stores | Products; Stock Locations; Operations; Batch & Expiry; Reordering; Consumption; Valuation; Reporting | Partial (D-178) |
| 11 | Purchase & Procurement | Suppliers; Purchase Requests; Purchase Orders; Animal Purchase; Materials Purchase; Receiving; Vendor Bills; Reporting | Partial (D-178; animal purchase lots D-190) |
| 12 | Sales & Livestock Trading | Animal Catalogue; Customers; Sales Pipeline; Sales Orders; Health Clearance; Dispatch; Billing; Reporting | Built (D-178, D-194 sale orders, RFID dispatch, invoices, receivables) |
| 13 | Accounting & Finance | Accounting; Cash & Bank; Payables; Receivables; Farm Costing; Production Economics; Budgets; Reports | Partial |
| 14 | Employees & Workforce | People; Shifts; Training; Skills; Leave & Payroll; Safety; Reports | Partial (D-181; training/skills to do) |
| 15 | Assets & Maintenance | Assets; Preventive Plan; Breakdowns; Dairy Equipment; Water Equipment; Parts & Cost; Reporting | Built (D-182) |
| 16 | Reports & Analytics | Operational; Breeding; Fattening; Dairy; Finance; Compliance; Self-Service BI; KPI Definitions | Partial |
| 17 | Dairy & Milk Production | Lactation; Milk Sessions; Milk Allocation; Udder & Milk Health; Quality Assurance; Storage & Processing; Dairy Sales; Economics | Built – flow 17 (D-117, D-188, D-195 orders, invoices, milk profit); processing products to do |
| 18 | Fattening & Meat Production | Fattening Dashboard; Batch Setup; Animal Selection; Finishing Plans; Daily Monitoring; Market Readiness; Sales Handover; Profitability | Built – flow 18 (D-187); feeder purchase lots and quotations to do |
| 19 | Animal Welfare & Husbandry | Daily Welfare; Hoof & Mobility; Physical Care; Group Welfare; Welfare Cases; Humane End of Life; Inspection & Audits; Reporting | Built – flow 19 (D-185); humane end-of-life review to do |
| 20 | Water, Climate & Environment | Drinking Water; Water Quality; Water Assets; Climate & Heat; Ventilation; Waste & Manure; Utilities; Reports | Built – flow 20 (D-186), water lab tests and incidents (D-198), waste and manure disposal (D-204) |
| 21 | Quality, Compliance & Safety | Quality Dashboard; SOP Library; Feed & Milk Safety; Regulatory Register; Incidents & CAPA; Traceability & Recall; Emergency Readiness; Compliance Reporting | Built – flow 21 (D-180, D-189) |
| 22 | Fodder & Pasture (Optional) | Land & Field Registry; Crop Planning; Cultivation; Crop Monitoring; Harvest; Preservation; Feed Store Transfer; Costing | Built (D-202), off by default; the owner turns it on |
| – | System Settings | Farm Configuration; Master Data; Animal Identity; Clinical Protocols; Production Settings; Equipment & Integrations; Security & Permissions; Data Governance; Automation; Finance | Partial |

## Cross-app journeys A–Q (flows 24–40)

| Journey | Flow | Status |
|---|---|---|
| A – Purchase-to-Quarantine | 24 | Built (D-172, D-190): purchase request → approval by another person → receive, count, inspect → IDs/EPC → dedicated quarantine pen → vet clearance or extension → transfer to operating pen; no mixing before clearance; lot cost and supplier bill posted once |
| B – Birth-to-Breeder | 25 | Built (D-161–D-176, D-191): kidding with live / stillborn per kid (no IDs for stillborns), colostrum and kid care, viable and stable? → neonatal care unit and vet, kid ID + RFID, weaning, growth vs plan → nutrition / vet intervention with follow-up, BR-005 replacement breeder or sale selection |
| C – Feed-to-True-Cost | 26 | Built (D-054–D-177, D-192): forecast vs stock → draft PO for the shortfall, TMR batch with weighed output (mixing loss), 3 meals offered / refused, stock counts with shrinkage, pen cost split to the goats present, gain, cost per KG gained, FCR proxy, margin, poor-response review; assumptions shown |
| D – Disease-to-Recovery | 27 | Built (D-073–D-174, D-193): worker report → vet triage (severity, contagion) → isolate + emergency alert, notifiable task → case with lab (fee posted once) → treatment from the medicine lot with vet-set withdrawal → pen movement/sale restriction until cleared → extend or clear into suitable housing → case cost (medicine + lab + labour) → outbreak report |
| E – Sale-to-Cash & Exit | 28 | Built (D-073, D-178, D-187, D-194): sale orders for any goat: clear-to-sell check (withdrawal, RFID, hold, restriction, health, other order) → quote per goat or per KG with market value → approval by someone else → reserved + dispatch checklist → RFID scan at loading (stop on substitute / duplicate / unknown) → invoice, exit, sale posted once (paid or receivable) → collection → cost basis and margin |
| F – Lactation-to-Milk-to-Cash | 29 | Built (D-117, D-188, D-195): colostrum and withdrawal held, doe scan in the milking round, AM/PM yield, allocation (kids, colostrum, discard), QA and release, customer milk orders, storage / transport checks at loading, milk invoices (cash or credit, receivable, collection), feed cost and contribution per litre, monthly milk profit |
| G – Milk Quality-Hold-to-Recall | 30 | Built (D-188, D-189, D-196): mastitis / late milk-withdrawal treatment (look-back), storage temperature excursion, positive residue or customer complaint → quality case holding the affected lots (or recall by the quality lead), recipient trace lot → does → customers / orders / invoices, refund or credit of each recalled sale with returned litres, CAPA through the case; KPIs rejected L, detection time, traceability, open cases |
| H – Fattening-to-Market | 31 | Built (D-187, D-197): meat selection (breeder cull, feeder, slow grower) proposed by any fattening user and approved by the owner or a manager other than the proposer; pregnant does refused; breeding stock cannot join a batch without approval → finishing batch, target, ration, weighing, exceptions, vet clearance → per goat cost: entry + feed actually fed (ration estimate labelled when no feeding recorded) + health (treatments, lab) → any exit (batch dispatch, Sale Order, direct sale, death) closes the batch record with costs fixed and revenue; Sale Hold blocks sale orders and sales; KPIs cost per KG gained, return per head, days on feed |
| I – Water-to-Animal Health | 32 | Built (D-186, D-198): round failure, report at any time (interruption, shortage, contamination) or unsafe lab test → one critical incident per shed with owned management and vet tasks, a repair / disinfection job on each linked pump or tank, and a welfare case for the goats exposed → emergency supply with its cost posted once, approved as safe by someone other than who arranged it → supply restored (contamination needs a safe retest) → vet health check (goats ill, action) → verified by another supervisor only when restored, checked, approved and repaired, closing the welfare case with evidence; KPIs outage minutes, time to safe supply, verified corrective actions, incident cost |
| J – Welfare-to-Treatment | 33 | Built (D-185, D-199): welfare check or report → case (a repeat of the same problem within 90 days is flagged and raised to high) → humane handling first: a serious case holds the goat's routine tasks and refuses routine weighing, moving, mating, sale and finishing until the vet clears handling → vet referral; the vet's health case is linked as the diagnosis, its recovery moves the welfare case to follow-up, and the case cannot close while it is active → care housing move, assigned hoof / handling / habitat actions with due dates → follow-up and closure evidence; KPIs closure time, recurrence, lameness incidents, waiting for diagnosis |
| K – Parasite Surveillance-to-Control | 34 | Built (D-200): FEC (goat or pooled pen) and FAMACHA / BCS samples → vet decision per sample (treat only the goats the vet selects, product issued from the medicine store, vet-entered meat and milk clear dates or "no withdrawal", retest date; or monitor / no treatment) → retest scores the egg-count reduction, under 95 % flags possible dewormer resistance for the vet; FAMACHA 4–5 or BCS ≤ 2 opens a welfare case; vet guide threshold only highlights; KPIs FEC trend, targeted treatment rate vs blind doses, retests due, resistance alerts |
| L – Genetic Selection-to-Replacement | 35 | Built (D-201): sire and dam progeny performance (litter size, stillborn, kid survival to weaning, kid ADG, kid illness, kidding interval) as a merit index from ≥ 3 kids; COI of the planned kid from the pedigree on every mating and pairing, above the farm limit only with an owner / manager / vet reason; replacement review with own growth, vet and welfare checks (pedigree completeness shown as information, never a gate), planned buck with its COI, breeding-plan task and move to the replacement pen; KPIs replacements selected, inbreeding alerts, average COI, pedigree completeness |
| M – Fodder-to-TMR | 36 | Built (D-202, with App 22): crop inputs costed once (Fodder Production), chemical pre-harvest interval blocks cutting → harvest lot held → measured DM (oven, microwave, lab, NIR), nitrate, mould, smell, aflatoxin for silage / haylage → released by someone other than the harvester into feed stock as an own-fodder feed item with the lot's DM, CP and cost per KG (no second expense) → TMR formulas use it; rejected lots never enter stock; KPIs cost per KG DM vs bought roughage, yield per acre, reject rate, lots released |
| N – Transport-to-Delivery | 37 | Built (D-203): vehicles inspected on six points and approved for 90 days → trip per approved sale order on a vehicle big enough → fitness to travel per goat by the vet or a caretaker (unfit goats come off the order) → dispatch refused without the trip, capacity and fitness; gate pass at dispatch → welfare checks on the road (down, injured, dead, water, temperature) → buyer's receipt; death, shortage and injury open customer claims, decided by the owner or manager with a credit posted once; KPIs loss in transit, dispatch mismatches, open claims |
| O – Waste-to-Safe-Disposal | 38 | Built (D-204): waste routes (manure, wastewater, sharps, medical, carcass, afterbirth, spoiled feed, containers) usable only with a method, instructions, adviser name, role and date from a qualified local adviser → disposals with evidence (manifest, pit), protection and disinfection confirmed for biological waste, reason for any other method, cost posted once → a death raises a carcass task, one disposal per carcass → contractor pickup interval with missed-pickup count → second-person audit; KPIs missed pickups, audited %, carcasses pending, routes without adviser |
| P – Incident-to-Corrective Action | 39 | Built (D-180, D-189, D-205): incident categories (product quality, animal safety, financial control, operations, people safety) and SOP version in force → high, critical or repeat (same kind, same goat or pen, 90 days) incidents go to a quality case, contained at once if critical on a goat or a hold / isolate / recall is chosen; they close only through root cause, CAPA, independent verification and case closure, which records the learning and the revised SOP version; minor incidents need a root cause; a quality hold blocks routine work on the goat; KPIs CAPA closure %, repeat incidents, waiting on CAPA |
| Q – Reproductive Loss Investigation | 40 | Built (D-161, D-206): abortion, early loss, stillbirth, premature or mixed kidding opens a case → isolate the doe (time to containment) → aborted material handled only by the vet or staff marked trained, with protection → samples and lab fee → the vet alone diagnoses; a suspected zoonotic cause opens a contagious vet case that restricts the pen, with a staff safety task → the vet's breeding decision (rebreed, delay to a date, do not breed) holds mating; mating also waits while a loss is under investigation; 2+ losses in a pen within 30 days raise a cluster alert; KPIs loss incidence, time to containment, clusters, losses by buck |

## Business rules (§9)

| Rule | Requirement | Status |
|---|---|---|
| BR-001 | Immutable Animal ID, never reused | Partial (UUID PK; readable permanent series to do) |
| BR-002 | One active animal per EPC | Built |
| BR-003 | Intake quarantine before regular pen | Built (D-172) |
| BR-004 | Block pen overcapacity unless authorised | Done (D-207; care moves exempt) |
| BR-005 | Breeding selection: maturity, BCS, health, relatedness | Built (D-191 selection checks maturity, growth, BCS, health, pedigree; shared-ancestor check at mating) |
| BR-006 | Ultrasound result, fetus count, viability independent | Built (D-166) |
| BR-007 | Kidding: event + each kid record, mixed outcomes | Built (D-161) |
| BR-008 | Infectious abortion → isolation + vet | Built (D-161, D-163) |
| BR-009 | Colostrum never auto-saleable | Built (D-188) |
| BR-010 | Milk withdrawal blocks milk | Built (D-188) |
| BR-011 | Meat withdrawal blocks sale/dispatch | Built (D-073) |
| BR-012 | Only Released milk lots dispatch | Built (D-188) |
| BR-013 | Milk lot genealogy | Built (D-188) |
| BR-014 | Milk mass balance | Built (D-188) |
| BR-015 | Daily feed trace 3 shifts + stock | Built |
| BR-016 | Rejected feed lots not mixed into TMR | Built (D-189): a quality hold on a feed blocks daily feeding and TMR mixing |
| BR-017 | DM vs as-fed, kg vs litres | Partial (feed conversion uses DM where the feed has a DM %, D-192) |
| BR-018 | Duplicate/impossible weight flags | Built (D-187) – fattening weighings |
| BR-019 | Fattening eligibility | Built (D-187) |
| BR-020 | No double-counted fattening feed cost | Built (D-187) |
| BR-021 | No drinking water = critical alert | Built (D-186) |
| BR-022 | Configurable heat-stress threshold | Built (D-186) |
| BR-023 | Major welfare concern → inspection/medical task | Built (D-185) |
| BR-024 | Every dose consumes a traceable lot | Partial |
| BR-025 | Held stock/milk/feed never sold | Built (D-188, D-189): quality holds block goat sale/meat exit, milk release/dispatch, feed use and medicine batch issue |
| BR-026 | Dispatch verifies tag/RFID + approved sale | Built (fattening D-187, every sale order D-194) |
| BR-027 | Creator cannot self-approve restricted changes | Partial (milk release, CAPA approval and verification, SOP approval: D-188, D-189) |
| BR-028 | Offline sync without silent overwrite | Partial |
| BR-029 | Audit of key changes (old/new) | Partial |
| BR-030 | Emergencies create assigned incident | Partial (D-180; disease emergencies raise critical tasks D-193) |
| BR-031 | Access control, retention, backups, restore test | Partial |
| BR-032 | Cost allocation with basis/period, post once | Partial (feed cost to goats by head per meal, basis shown, not re-posted: D-192) |

## Deployment roadmap (§15) – build order

Foundation → P0 Safety & Core (quarantine, clinical, welfare, water, pen allocation, inventory trace, daily feeding)
→ P1 Breeding & Maternity → P2 Fattening & Growth → P3 Dairy & Quality → P4 Commercial integration → P5 Optimisation.

UAT-01…UAT-34 (§14) are the acceptance tests; each new feature adds automated tests for the UAT items it covers.
