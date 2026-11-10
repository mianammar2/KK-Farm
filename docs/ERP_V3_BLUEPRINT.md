# KK Farms ERP V3.0 – Blueprint tracker

Source: owner's "KK Farms Enterprise Goat Farming ERP – Final 360° Functional Blueprint & SRS, Version 3.0, 11 Nov 2026"
(21 core Odoo-style apps + optional Fodder & Pasture + System Settings; 41 flowcharts; BR-001…BR-032; UAT-01…UAT-34).
Full handbook with the 41 flowcharts: KK_Farms_ERP_V3_FINAL_360_Handbook.pdf (owner's copy in /Users/MAC/goat, not in git); flow summaries in ERP_V3_FLOWS.md. Each app is built to its flowchart page.

Status: **Built** (live), **Partial** (some screens/rules), **To do**. Decision numbers refer to `DECISIONS_LOG.md`.

Every app follows: app launch → Overview → Operations → Records → Reporting → Configuration (D-170, D-171).

## Three production streams (§2)

| Stream | Lifecycle | Status |
|---|---|---|
| Breeding & Genetics | selection → heat → mating → ultrasound → pregnancy → kidding → kid care → replacement/sale | Partial (heat, mating, ultrasound, outcomes, kid care built; relatedness check, replacement selection to do) |
| Fattening & Meat | feeder selection → quarantine → finishing lot → TMR → weight/ADG → welfare & vet clearance → live sale | To do (batches); herd value, ADG, sales exist |
| Dairy & Milk | kidding/lactation → udder screening → AM/PM milking → kid/colostrum allocation → QA → chilling → batch dispatch | Partial (lactations, milk records); allocation, QA, lots, dispatch to do |

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
| 08 | Growth & Performance | Weighing; Growth Plans; Body Condition; Performance; Breeder Selection; Fattening Integration; Reports | Partial (outlier/duplicate weight flags to do) |
| 09 | Farm Operations & Tasks | Daily Routines; Task Planner; Work Orders; Checklists; Incidents; Approvals; Reports | Built (D-180) |
| 10 | Inventory & Stores | Products; Stock Locations; Operations; Batch & Expiry; Reordering; Consumption; Valuation; Reporting | Partial (D-178) |
| 11 | Purchase & Procurement | Suppliers; Purchase Requests; Purchase Orders; Animal Purchase; Materials Purchase; Receiving; Vendor Bills; Reporting | Partial (D-178) |
| 12 | Sales & Livestock Trading | Animal Catalogue; Customers; Sales Pipeline; Sales Orders; Health Clearance; Dispatch; Billing; Reporting | Partial (D-178) |
| 13 | Accounting & Finance | Accounting; Cash & Bank; Payables; Receivables; Farm Costing; Production Economics; Budgets; Reports | Partial |
| 14 | Employees & Workforce | People; Shifts; Training; Skills; Leave & Payroll; Safety; Reports | Partial (D-181; training/skills to do) |
| 15 | Assets & Maintenance | Assets; Preventive Plan; Breakdowns; Dairy Equipment; Water Equipment; Parts & Cost; Reporting | Built (D-182) |
| 16 | Reports & Analytics | Operational; Breeding; Fattening; Dairy; Finance; Compliance; Self-Service BI; KPI Definitions | Partial |
| 17 | Dairy & Milk Production | Lactation; Milk Sessions; Milk Allocation; Udder & Milk Health; Quality Assurance; Storage & Processing; Dairy Sales; Economics | Partial (lactations, milk records D-117) |
| 18 | Fattening & Meat Production | Fattening Dashboard; Batch Setup; Animal Selection; Finishing Plans; Daily Monitoring; Market Readiness; Sales Handover; Profitability | To do |
| 19 | Animal Welfare & Husbandry | Daily Welfare; Hoof & Mobility; Physical Care; Group Welfare; Welfare Cases; Humane End of Life; Inspection & Audits; Reporting | Built – flow 19 (D-185); humane end-of-life review to do |
| 20 | Water, Climate & Environment | Drinking Water; Water Quality; Water Assets; Climate & Heat; Ventilation; Waste & Manure; Utilities; Reports | To do (sensors exist) |
| 21 | Quality, Compliance & Safety | Quality Dashboard; SOP Library; Feed & Milk Safety; Regulatory Register; Incidents & CAPA; Traceability & Recall; Emergency Readiness; Compliance Reporting | To do (incident register D-180) |
| 22 | Fodder & Pasture (Optional) | Land & Field Registry; Crop Planning; Cultivation; Crop Monitoring; Harvest; Preservation; Feed Store Transfer; Costing | To do (off by default) |
| – | System Settings | Farm Configuration; Master Data; Animal Identity; Clinical Protocols; Production Settings; Equipment & Integrations; Security & Permissions; Data Governance; Automation; Finance | Partial |

## Business rules (§9)

| Rule | Requirement | Status |
|---|---|---|
| BR-001 | Immutable Animal ID, never reused | Partial (UUID PK; readable permanent series to do) |
| BR-002 | One active animal per EPC | Built |
| BR-003 | Intake quarantine before regular pen | Built (D-172) |
| BR-004 | Block pen overcapacity unless authorised | To do |
| BR-005 | Breeding selection: maturity, BCS, health, relatedness | Partial (shared-ancestor check exists) |
| BR-006 | Ultrasound result, fetus count, viability independent | Built (D-166) |
| BR-007 | Kidding: event + each kid record, mixed outcomes | Built (D-161) |
| BR-008 | Infectious abortion → isolation + vet | Built (D-161, D-163) |
| BR-009 | Colostrum never auto-saleable | To do (dairy allocation) |
| BR-010 | Milk withdrawal blocks milk | Partial |
| BR-011 | Meat withdrawal blocks sale/dispatch | Built (D-073) |
| BR-012 | Only Released milk lots dispatch | To do |
| BR-013 | Milk lot genealogy | To do |
| BR-014 | Milk mass balance | To do |
| BR-015 | Daily feed trace 3 shifts + stock | Built |
| BR-016 | Rejected feed lots not mixed into TMR | To do |
| BR-017 | DM vs as-fed, kg vs litres | Partial |
| BR-018 | Duplicate/impossible weight flags | To do |
| BR-019 | Fattening eligibility | To do |
| BR-020 | No double-counted fattening feed cost | To do |
| BR-021 | No drinking water = critical alert | To do |
| BR-022 | Configurable heat-stress threshold | To do |
| BR-023 | Major welfare concern → inspection/medical task | Built (D-185) |
| BR-024 | Every dose consumes a traceable lot | Partial |
| BR-025 | Held stock/milk/feed never sold | Partial |
| BR-026 | Dispatch verifies tag/RFID + approved sale | To do |
| BR-027 | Creator cannot self-approve restricted changes | Partial |
| BR-028 | Offline sync without silent overwrite | Partial |
| BR-029 | Audit of key changes (old/new) | Partial |
| BR-030 | Emergencies create assigned incident | Partial (D-180) |
| BR-031 | Access control, retention, backups, restore test | Partial |
| BR-032 | Cost allocation with basis/period, post once | Partial |

## Deployment roadmap (§15) – build order

Foundation → P0 Safety & Core (quarantine, clinical, welfare, water, pen allocation, inventory trace, daily feeding)
→ P1 Breeding & Maternity → P2 Fattening & Growth → P3 Dairy & Quality → P4 Commercial integration → P5 Optimisation.

UAT-01…UAT-34 (§14) are the acceptance tests; each new feature adds automated tests for the UAT items it covers.
