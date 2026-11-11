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
| 05 | Veterinary & Biosecurity | Health Monitoring; Medical Cases; Treatment; Preventive Care; Quarantine; Reproduction Health; Withdrawal & Disposal; Reporting | Partial (D-172–D-174; antimicrobial use report to do) |
| 06 | Feeding & Nutrition | Feed Master; Nutrition Standards; Ration Formulas; Daily Feed Plans; Feed Production; Consumption; Feed Safety; Cost & Reports | Partial (D-177; feed safety lot holds to do) |
| 07 | Housing & Pen Management | Farm Structure; Unit Categories; Pen Operations; Biosecure Areas; Housing Conditions; Cleaning; Traceability; Reports | Partial (pen status, capacity block, cleaning to do) |
| 08 | Growth & Performance | Weighing; Growth Plans; Body Condition; Performance; Breeder Selection; Fattening Integration; Reports | Partial (growth plans, interventions, breeder selection D-191; outlier/duplicate weight flags outside fattening to do) |
| 09 | Farm Operations & Tasks | Daily Routines; Task Planner; Work Orders; Checklists; Incidents; Approvals; Reports | Built (D-180) |
| 10 | Inventory & Stores | Products; Stock Locations; Operations; Batch & Expiry; Reordering; Consumption; Valuation; Reporting | Partial (D-178) |
| 11 | Purchase & Procurement | Suppliers; Purchase Requests; Purchase Orders; Animal Purchase; Materials Purchase; Receiving; Vendor Bills; Reporting | Partial (D-178; animal purchase lots D-190) |
| 12 | Sales & Livestock Trading | Animal Catalogue; Customers; Sales Pipeline; Sales Orders; Health Clearance; Dispatch; Billing; Reporting | Partial (D-178) |
| 13 | Accounting & Finance | Accounting; Cash & Bank; Payables; Receivables; Farm Costing; Production Economics; Budgets; Reports | Partial |
| 14 | Employees & Workforce | People; Shifts; Training; Skills; Leave & Payroll; Safety; Reports | Partial (D-181; training/skills to do) |
| 15 | Assets & Maintenance | Assets; Preventive Plan; Breakdowns; Dairy Equipment; Water Equipment; Parts & Cost; Reporting | Built (D-182) |
| 16 | Reports & Analytics | Operational; Breeding; Fattening; Dairy; Finance; Compliance; Self-Service BI; KPI Definitions | Partial |
| 17 | Dairy & Milk Production | Lactation; Milk Sessions; Milk Allocation; Udder & Milk Health; Quality Assurance; Storage & Processing; Dairy Sales; Economics | Built – flow 17 (D-117, D-188); processing products to do |
| 18 | Fattening & Meat Production | Fattening Dashboard; Batch Setup; Animal Selection; Finishing Plans; Daily Monitoring; Market Readiness; Sales Handover; Profitability | Built – flow 18 (D-187); feeder purchase lots and quotations to do |
| 19 | Animal Welfare & Husbandry | Daily Welfare; Hoof & Mobility; Physical Care; Group Welfare; Welfare Cases; Humane End of Life; Inspection & Audits; Reporting | Built – flow 19 (D-185); humane end-of-life review to do |
| 20 | Water, Climate & Environment | Drinking Water; Water Quality; Water Assets; Climate & Heat; Ventilation; Waste & Manure; Utilities; Reports | Built – flow 20 (D-186); water-quality lab tests, manure/compost logs to do |
| 21 | Quality, Compliance & Safety | Quality Dashboard; SOP Library; Feed & Milk Safety; Regulatory Register; Incidents & CAPA; Traceability & Recall; Emergency Readiness; Compliance Reporting | Built – flow 21 (D-180, D-189) |
| 22 | Fodder & Pasture (Optional) | Land & Field Registry; Crop Planning; Cultivation; Crop Monitoring; Harvest; Preservation; Feed Store Transfer; Costing | To do (off by default) |
| – | System Settings | Farm Configuration; Master Data; Animal Identity; Clinical Protocols; Production Settings; Equipment & Integrations; Security & Permissions; Data Governance; Automation; Finance | Partial |

## Cross-app journeys A–Q (flows 24–40)

| Journey | Flow | Status |
|---|---|---|
| A – Purchase-to-Quarantine | 24 | Built (D-172, D-190): purchase request → approval by another person → receive, count, inspect → IDs/EPC → dedicated quarantine pen → vet clearance or extension → transfer to operating pen; no mixing before clearance; lot cost and supplier bill posted once |
| B – Birth-to-Breeder | 25 | Built (D-161–D-176, D-191): kidding with live / stillborn per kid (no IDs for stillborns), colostrum and kid care, viable and stable? → neonatal care unit and vet, kid ID + RFID, weaning, growth vs plan → nutrition / vet intervention with follow-up, BR-005 replacement breeder or sale selection |
| C – Feed-to-True-Cost | 26 | Built (D-054–D-177, D-192): forecast vs stock → draft PO for the shortfall, TMR batch with weighed output (mixing loss), 3 meals offered / refused, stock counts with shrinkage, pen cost split to the goats present, gain, cost per KG gained, FCR proxy, margin, poor-response review; assumptions shown |
| D–Q | 27–40 | To do |

## Business rules (§9)

| Rule | Requirement | Status |
|---|---|---|
| BR-001 | Immutable Animal ID, never reused | Partial (UUID PK; readable permanent series to do) |
| BR-002 | One active animal per EPC | Built |
| BR-003 | Intake quarantine before regular pen | Built (D-172) |
| BR-004 | Block pen overcapacity unless authorised | To do |
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
| BR-026 | Dispatch verifies tag/RFID + approved sale | Built for fattening dispatch (D-187) |
| BR-027 | Creator cannot self-approve restricted changes | Partial (milk release, CAPA approval and verification, SOP approval: D-188, D-189) |
| BR-028 | Offline sync without silent overwrite | Partial |
| BR-029 | Audit of key changes (old/new) | Partial |
| BR-030 | Emergencies create assigned incident | Partial (D-180) |
| BR-031 | Access control, retention, backups, restore test | Partial |
| BR-032 | Cost allocation with basis/period, post once | Partial (feed cost to goats by head per meal, basis shown, not re-posted: D-192) |

## Deployment roadmap (§15) – build order

Foundation → P0 Safety & Core (quarantine, clinical, welfare, water, pen allocation, inventory trace, daily feeding)
→ P1 Breeding & Maternity → P2 Fattening & Growth → P3 Dairy & Quality → P4 Commercial integration → P5 Optimisation.

UAT-01…UAT-34 (§14) are the acceptance tests; each new feature adds automated tests for the UAT items it covers.
