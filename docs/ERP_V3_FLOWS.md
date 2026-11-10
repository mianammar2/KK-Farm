# KK Farms ERP V3.0 – 41 process flows (summaries)

Extracted from KK_Farms_ERP_V3_FINAL_360_Handbook.pdf (pages 31–71). Each flow: trigger, key control, records/outputs, connected apps, owner, KPI.
The diagrams themselves are images in the handbook; view the page when building that flow.

## Flow 00 Of 40 — KK Farms Enterprise 360 - Three Production Businesses
*Permanent Animal ID connects breeding, fattening, milking and farm economics*

- **Trigger:** New animal or daily production event
- **Key control:** Immutable Animal ID; quarantine; milk & meat withdrawal; QA hold; welfare and owner approvals
- **Records / outputs:** Full animal life history, production traceability, financial margin and leadership KPIs
- **Connected apps:** All 22 apps + System Settings (21 core + 1 optional)
- **Owner:** CEO / Farm Manager
- **KPI:** Breeding success, ADG, milk yield, mortality, gross margin

## Flow 01 Of 40 — App 01 – Farm Dashboard
*Decision-to-action cycle with validated KPIs and ownership*

- **Trigger:** Stock, breeding, dairy yield, milk quality, health, feed and financial events
- **Key control:** Track stale data; restrict financial figures by permission; require alert ownership
- **Records / outputs:** Verified dashboard KPIs, assigned actions, management audit trail
- **Connected apps:** All operational apps → Dashboard → Operations
- **Owner:** CEO / Farm Manager
- **KPI:** Data freshness, open alerts, overdue rate, KPI target variance; milk yield and quality holds

## Flow 02 Of 40 — App 02 – Livestock Management
*Birth or acquisition-to-lifetime profile and traceable exit*

- **Trigger:** New birth, purchase or transfer
- **Key control:** Unique immutable ID; EPC changes recorded historically; no direct quarantine bypass
- **Records / outputs:** Active animal record, location, parentage, lifetime history, exit audit
- **Connected apps:** Purchase, Housing, Breeding, Health, Sales, Finance
- **Owner:** Livestock Registrar / Farm Manager
- **KPI:** Active animals, unresolved tags, registration lag, age and breed mix

## Flow 03 Of 40 — App 03 – Breeding & Genetics
*Eligibility-to-mating, conception assessment and breeding improvement*

- **Trigger:** Mature breeding candidate or scheduled service
- **Key control:** Block close-relative pairing per policy; vet checks for health / readiness
- **Records / outputs:** Service record, ultrasound task, gestation status, maternity handoff
- **Connected apps:** Livestock, Veterinary, Maternity, Housing, Analytics
- **Owner:** Breeding Supervisor / Veterinarian
- **KPI:** Conception %, repeat services, kidding interval, sire performance

## Flow 04 Of 40 — App 04 – Maternity & Kid Care
*Pre-kidding-to-birth outcomes, neonatal monitoring and weaning*

- **Trigger:** Expected kidding, emergency birth or suspected loss
- **Key control:** Maintain mother-child linkage; each kid has own outcome; isolate suspected infectious abortion
- **Records / outputs:** Mother event, kid records, colostrum checks, transfer and veterinary tasks; lactation start / kid milk requirement
- **Connected apps:** Breeding, Livestock, Veterinary, Housing, Feeding; Dairy
- **Owner:** Maternity Supervisor / Veterinarian
- **KPI:** Live-born %, stillbirth %, loss %, neonatal mortality, weaning success

## Flow 05 Of 40 — App 05 – Veterinary & Biosecurity
*Symptom-to-triage, treatment, monitoring and recovery or loss*

- **Trigger:** Symptoms, vaccination due, abortion concern or veterinary check
- **Key control:** Vet authorization; isolation and drug lot tracking; distinct milk and meat withholding periods
- **Records / outputs:** Medical history, inventory issue, cost, recovery clearance or mortality case
- **Connected apps:** Inventory, Housing, Livestock, Finance, Operations; Dairy (mastitis, milk holds)
- **Owner:** Veterinary Officer
- **KPI:** Case resolution %, illness prevalence, vaccine coverage, medicine cost

## Flow 06 Of 40 — App 06 – Feeding & Nutrition
*Ingredient-to-TMR, 3 daily rounds, intake and feed cost feedback*

- **Trigger:** Pen occupancy, ration standard, daily feeding plan
- **Key control:** Version-control formulas; record offered/refused separately; actual stock consumption and costs
- **Records / outputs:** Meal log, feed issued, nutrition compliance, cost per pen and animal
- **Connected apps:** Inventory, Housing, Veterinary, Growth, Finance; Dairy (lactating-doe ration)
- **Owner:** Feed Supervisor / Nutritionist
- **KPI:** Feed cost/kg gain, refusal %, CP compliance, feeding round completion; cost/litre milk

## Flow 07 Of 40 — App 07 – Housing & Pen Management
*Current location-to-authorized transfer and occupancy accuracy*

- **Trigger:** Intake, pregnancy transition, isolation, weaning, sale or capacity issue
- **Key control:** Hard capacity limits, gender / biosecurity segregation, scan-confirmed transfers
- **Records / outputs:** Current pen, occupancy, movement history, cleaning ticket
- **Connected apps:** Livestock, Maternity, Veterinary, Feeding, Operations; Dairy (lactation unit)
- **Owner:** Housing Supervisor
- **KPI:** Pen occupancy, capacity breaches, movement accuracy, cleaning compliance

## Flow 08 Of 40 — App 08 – Growth & Performance
*Scheduled measurement-to-ADG, performance review and corrective action*

- **Trigger:** Periodic weight due or treatment / sale event
- **Key control:** Reject implausible weights; preserve measurements and corrections; calibrate equipment
- **Records / outputs:** ADG, growth alerts, breeder selection, sale readiness, valuation inputs
- **Connected apps:** Feeding, Veterinary, Sales, Finance, Livestock
- **Owner:** Performance Officer / Shed Supervisor
- **KPI:** ADG by group, weight variance, target attainment, repeat weigh rate

## Flow 09 Of 40 — App 09 – Farm Operations
*Shift planning-to-task evidence, approval and daily closure*

- **Trigger:** Daily calendar, scheduled SOP or app-generated alert
- **Key control:** Assignee accountability; due times, evidence, supervisor acceptance, escalations
- **Records / outputs:** Audit-ready tasks, operational completion and shift handover
- **Connected apps:** Feeding, Housing, Veterinary, HR, Maintenance
- **Owner:** Farm Manager / Shift Supervisor
- **KPI:** On-time task %, missed feeding rounds, repeat incidents, verification delay

## Flow 10 Of 40 — App 10 – Inventory & Stores
*Demand-to-replenishment, quality-controlled lot issuance and valuation*

- **Trigger:** Feed or medicine consumption; minimum stock threshold
- **Key control:** QC on receipt; lot/expiry traceability; FEFO; approved stock adjustments
- **Records / outputs:** On-hand stock, material consumption, costs, stock expiry and reorder signals; released or held dairy product lots
- **Connected apps:** Purchase, Feeding, Veterinary, Maintenance, Finance; Dairy
- **Owner:** Storekeeper / Inventory Controller
- **KPI:** Stock-out count, shrinkage, expiring lots, inventory accuracy

## Flow 11 Of 40 — App 11 – Purchase & Procurement
*Approved demand-to-supplier receipt, 3-way match and payment*

- **Trigger:** Approved purchase request or livestock selection
- **Key control:** Segregate requisition, PO approval, receipt and invoice matching
- **Records / outputs:** Accepted goods / goat quarantine, vendor payable and cost basis
- **Connected apps:** Livestock, Inventory, Veterinary, Accounting
- **Owner:** Purchase Manager / Accounts
- **KPI:** Purchase lead time, supplier quality, price variance, matched bills

## Flow 12 Of 40 — App 12 – Sales & Livestock Trading
*Animal listing-to-healthy release, invoice, payment and dispatch*

- **Trigger:** Approved sale candidate or customer inquiry
- **Key control:** Veterinary sale clearance; accurate IDs; authorized pricing; reconcile dispatch and invoice; milk orders require QC
- **Records / outputs:** Invoice, receipts / receivable, transferred ownership and realized margin
- **Connected apps:** Livestock, Veterinary, Growth, Housing, Accounting; Dairy (milk sales)
- **Owner:** Sales Officer / Farm Manager
- **KPI:** Sale value/head, margin/head, clearance delays, dispatch accuracy

## Flow 13 Of 40 — App 13 – Accounting & Finance
*Business event-to-financial posting, reconciliation and management profitability*

- **Trigger:** Operational transactions posted by connected ERP apps
- **Key control:** Enforce chart of accounts, delegation, costing rules and audit trail
- **Records / outputs:** GL, paid / open balances, management cost per animal, cash and farm P&L
- **Connected apps:** All apps; especially Purchase, Inventory, Sales, Employees; Dairy
- **Owner:** Finance Manager / Accountant
- **KPI:** Feed cost/head, gross margin, cash burn, unpaid invoices, budget variance; cost per litre

## Flow 14 Of 40 — App 14 – Employees & HR
*Staff onboarding-to-shift duties, attendance, payroll and performance*

- **Trigger:** New worker, job change or attendance cycle
- **Key control:** Role-based access, training verification, approved timesheets
- **Records / outputs:** Scheduled workforce, task owner, payroll inputs and labor cost
- **Connected apps:** Operations, Accounting, Veterinary, Maintenance
- **Owner:** HR / Farm Manager
- **KPI:** Attendance %, task completion/headcount, cost per shift, training compliance

## Flow 15 Of 40 — App 15 – Maintenance & Assets
*Asset lifecycle-to-safe work order, testing, release and cost*

- **Trigger:** Maintenance interval, fault, inspection or incident
- **Key control:** Lock out unsafe assets; approvals for purchase; acceptance testing before release
- **Records / outputs:** Asset uptime, maintenance cost, spares consumed and next service
- **Connected apps:** Inventory, Purchase, Housing, Feeding, Accounting; Dairy (milk equipment)
- **Owner:** Maintenance Officer / Farm Manager
- **KPI:** Equipment downtime, PM compliance, repair cost, repeat faults

## Flow 16 Of 40 — App 16 – Reports & Analytics
*Governed data-to-report, exception action and performance improvement*

- **Trigger:** Weekly / monthly report schedule and current events
- **Key control:** Shared KPI definitions; immutable snapshots; separate private payroll access
- **Records / outputs:** Reliable scorecards, variance root causes and tracked interventions
- **Connected apps:** All source apps, Dashboard, Accounting
- **Owner:** CEO / BI Analyst
- **KPI:** Report freshness, data quality, action closure, target achievement; milk yield, reject and margin trends

## Flow 17 Of 40 — App 17 – Dairy & Milk Production
*Doe-to-session milk yield, kid allocation, hygienic collection, QA, batches and sales*

- **Trigger:** Kidding completed, daily AM/PM session, dry-off or milk order
- **Key control:** Colostrum is not saleable; keep kids allocation distinct; vet-set milk withdrawal, lot release and temperature logs
- **Records / outputs:** Per-doe milk sessions, kid feeding, QC, tank batches, held/approved lots, dispatch and litre costing
- **Connected apps:** Maternity → Veterinary → Feeding → Inventory → Sales → Accounting
- **Owner:** Dairy Supervisor / Veterinary Officer / Quality Lead
- **KPI:** Litres/doe/day, saleable %, loss %, mastitis %, milk gross margin

## Flow 18 Of 40 — App 18 - Fattening & Meat Production
*Selected grower to finishing batch, sale readiness and profit*

- **Trigger:** Feeder purchase, approved breeder cull or growing goat reaching eligible stage
- **Key control:** Sale not allowed during veterinary hold; feed costing includes clear allocation basis
- **Records / outputs:** Fattening batch, target gain, daily costs, sale clearance and profit
- **Connected apps:** Livestock, Veterinary, Feeding, Growth, Housing, Sales, Accounting
- **Owner:** Fattening Supervisor
- **KPI:** ADG, cost/kg gain, sale margin, mortality

## Flow 19 Of 40 — App 19 - Animal Welfare & Husbandry
*Welfare observation to care, escalation and verified recovery*

- **Trigger:** Inspection, animal report, injury or lameness alert
- **Key control:** Welfare care must not bypass medical authorisation; humane handling and end-of-life policy
- **Records / outputs:** Welfare case, action and verified closeout
- **Connected apps:** Veterinary, Housing, Operations, HR, Quality
- **Owner:** Welfare Officer / Veterinarian
- **KPI:** Lameness rate, incident close time, unresolved cases

## Flow 20 Of 40 — App 20 - Water, Climate & Environment
*Water availability, heat stress and environmental incident control*

- **Trigger:** Water outage, pen inspection, hot weather, waste report
- **Key control:** Water and heat-stress alerts are prioritized; safe waste handling and animal welfare
- **Records / outputs:** Water tests, equipment repairs, climate log, environmental actions
- **Connected apps:** Housing, Welfare, Veterinary, Maintenance, Quality, Finance
- **Owner:** Water & Environment Supervisor
- **KPI:** Water interruptions, temperature exceptions, compliance

## Flow 21 Of 40 — App 21 - Quality, Compliance & Safety
*Food/feed safety, SOPs, incidents and corrective action*

- **Trigger:** Milk/medicine/feed quality, audit, complaint, outbreak or incident
- **Key control:** Held food/animal products cannot sell; CAPA requires independent verification
- **Records / outputs:** Incident, hold, trace record, SOP revision, evidence and CAPA
- **Connected apps:** All departments, especially Dairy, Inventory, Veterinary, Sales
- **Owner:** Quality & Compliance Manager
- **KPI:** CAPA on-time %, trace completeness, reject rate

## Flow 22 Of 40 — App 22 - Fodder & Pasture (Optional)
*Own-grown fodder from planting and harvest into ration stock*

- **Trigger:** Growing own fodder, managing grazing or conserving silage/hay
- **Key control:** Chemical/pesticide residue, quality and crop traceability checked before feed use
- **Records / outputs:** Field harvest batch, feed inventory receipt and cost/kg DM
- **Connected apps:** Procurement, Feeding, Inventory, Finance, Quality
- **Owner:** Agronomy / Feed Manager
- **KPI:** Yield/acre, cost/kg DM, fodder reject rate

## Flow 23 Of 40 — Settings & Administration
*Master data, RFID integrations, approvals, permissions and safe release*

- **Trigger:** New breed / pen category, tag series, alert or permission change
- **Key control:** Never bulk-edit animal identity without audit; test workflows, backups, version rules
- **Records / outputs:** Safe configurations, documented permissions, audit logs and rollbacks
- **Connected apps:** All ERP apps + Chainway C72 / supported integrations
- **Owner:** ERP Administrator / Change Approver
- **KPI:** Failed imports, unauthorized edits, configuration defects, integration uptime

## Flow 24 Of 40 — Cross-App A – Purchase-to-Quarantine
*Purchased goat: supplier selection through quarantine release and stocking*

- **Trigger:** Purchased farm lot from market or breeder
- **Key control:** No direct mixing before clearance; preserve lot source and purchase price
- **Records / outputs:** Registered, medically cleared animals with correct pen and cost
- **Connected apps:** Purchase → Livestock → Veterinary → Housing → Accounting
- **Owner:** Purchase Lead / Farm Veterinarian
- **KPI:** Intake rejection, quarantine duration, identity coverage, initial cost/head

## Flow 25 Of 40 — Cross-App B – Birth-to-Breeder
*New kid: maternity registration through feeding, growth and breeding eligibility*

- **Trigger:** Confirmed pregnancy and delivery event
- **Key control:** No fictitious live IDs for stillborns; retain birth record; separate weak-born from mortality
- **Records / outputs:** Linked pedigree, health and growth, breeder / sale classification; mother lactation and kid milk link
- **Connected apps:** Breeding → Maternity → Livestock → Feeding → Growth → Sales; Dairy (colostrum and kid milk)
- **Owner:** Breeding / Maternity Supervisors
- **KPI:** Kid survival %, weaning %, ADG, replacement selection rate

## Flow 26 Of 40 — Cross-App C – Feed-to-True-Cost
*Ingredient procurement through TMR feeding, growth response and animal costing*

- **Trigger:** Approved ration, current headcount and available stock
- **Key control:** Allocation assumptions disclosed; weights and shrinkage captured; no double issue
- **Records / outputs:** Feed stock valuation, CP compliance, cost/head, cost/kg gained
- **Connected apps:** Feeding → Inventory → Purchase → Growth → Accounting
- **Owner:** Feed Supervisor / Finance Controller
- **KPI:** Feed conversion proxy, cost/kg gain, CP compliance, refusal %

## Flow 27 Of 40 — Cross-App D – Disease-to-Recovery
*Health alert through disease control, medication, cost and population clearance*

- **Trigger:** Suspected illness, veterinary screening or abnormal health KPI
- **Key control:** Isolate as needed; vet-set milk and meat withdrawal; restrict affected lots until cleared
- **Records / outputs:** Recovery case, secure pen, medicine consumption, true medical cost
- **Connected apps:** Operations → Veterinary → Housing → Inventory → Finance; Dairy (milk QA hold)
- **Owner:** Veterinary Officer / Biosecurity Lead
- **KPI:** Recovery time, disease attack rate, case cost, quarantine release lag

## Flow 28 Of 40 — Cross-App E – Sale-to-Cash & Exit
*Animal sale through clearance, RFID dispatch, invoice and closing profitability*

- **Trigger:** Sale-ready animal or confirmed customer quotation
- **Key control:** No sale during withdrawal; RFID at loading; no duplicate exit; approved pricing
- **Records / outputs:** Final sale status, invoice, customer receivable or payment, margin
- **Connected apps:** Sales → Veterinary → Livestock → Housing → Accounting
- **Owner:** Sales Manager / Accountant
- **KPI:** Margin per goat, dispatch error %, days to collect, unsold stock

## Flow 29 Of 40 — Cross-App F – Lactation-to-Milk-to-Cash
*Kidding → milk allocation → safe milk lot → cold storage → customer sale and cost*

- **Trigger:** Post-kidding lactation, scheduled milking shift or confirmed dairy order
- **Key control:** Exclude colostrum/held milk; no double count of kid milk; ensure batch traceability and authorised sale
- **Records / outputs:** Individual yield, milk allocation, stock lots, dispatch, invoice, quality and profit dashboards
- **Connected apps:** Maternity → Dairy → Veterinary → Feeding → Inventory → Sales → Accounting
- **Owner:** Dairy Supervisor / Quality Lead / Sales & Finance
- **KPI:** Yield/doe/day, saleable yield %, milk loss %, revenue/litre, contribution/litre

## Flow 30 Of 40 — Cross-App G – Milk Quality-Hold-to-Recall
*Mastitis / antimicrobial withdrawal / quality alarm to traceable containment and closure*

- **Trigger:** Positive residue test, mastitis case, hygiene deviation, temperature excursion or complaint
- **Key control:** No unapproved milk into sale, kid feeding or processing; preserve source-to-customer lot lineage
- **Records / outputs:** Lot held / released / rejected, recipient trace, refunds or adjustments, preventive action audit
- **Connected apps:** Veterinary → Dairy QA → Inventory → Sales → Accounting → Analytics
- **Owner:** Veterinary Officer / Dairy QA / Farm Manager
- **KPI:** Rejected L, issue detection time, traceability completeness, corrective action closeout

## Flow 31 Of 40 — Cross-App H - Fattening-to-Market
*Breeding/growing/purchase to finishing and profitable goat sale*

- **Trigger:** Approved feeder/culled breeder selected for meat business
- **Key control:** Costing and sale holds must follow animal-level records; no sale during withdrawal
- **Records / outputs:** Finishing records, dispatch, revenue and profit per animal/batch
- **Connected apps:** Livestock -> Vet -> Feeding -> Growth -> Sales -> Finance
- **Owner:** Fattening Supervisor / Sales
- **KPI:** Cost/kg gain, return per head, days on feed

## Flow 32 Of 40 — Cross-App I - Water-to-Animal Health
*Water supply monitoring to emergency and welfare resolution*

- **Trigger:** Water interruption, shortage, contamination or routine inspection
- **Key control:** Immediate welfare priority; approved safe supply and monitored resolution
- **Records / outputs:** Water log, work order, health action and cost
- **Connected apps:** Environment -> Maintenance -> Welfare -> Vet -> Finance
- **Owner:** Farm Manager / Utilities
- **KPI:** Outage minutes, verified corrective actions

## Flow 33 Of 40 — Cross-App J - Welfare-to-Treatment
*Condition assessment leads to targeted welfare and veterinary care*

- **Trigger:** Animal health or welfare observation
- **Key control:** Veterinary diagnosis and humane handling take precedence over routine tasks
- **Records / outputs:** Welfare/clinical records, assigned actions, closure evidence
- **Connected apps:** Welfare -> Veterinary -> Housing -> Operations
- **Owner:** Welfare Lead / Vet
- **KPI:** Closure time, recurrence, lameness incidents

## Flow 34 Of 40 — Cross-App K - Parasite Surveillance-to-Control
*Testing and targeted parasite control, not blind repeat dosing*

- **Trigger:** Clinical risk, seasonal schedule or suspected worm burden
- **Key control:** Tests, treatment, withdrawal and retesting set by veterinarian
- **Records / outputs:** FEC sample, selected treatment, inventory and health trend
- **Connected apps:** Veterinary -> Inventory -> Welfare -> Analytics
- **Owner:** Veterinary Officer
- **KPI:** FEC trend, targeted treatment rate

## Flow 35 Of 40 — Cross-App L - Genetic Selection-to-Replacement
*Measured progeny performance informs breeder replacement*

- **Trigger:** Replacement stock review, sire/dam performance and breeding strategy
- **Key control:** Do not treat pedigree completeness as proof of genetic merit; vet/welfare checks
- **Records / outputs:** Replacement stock decision, revised breeding plan, pedigree metrics
- **Connected apps:** Growth -> Breeding -> Livestock -> Housing
- **Owner:** Breeding / Genetics Lead
- **KPI:** Replacements selected, inbreeding alerts

## Flow 36 Of 40 — Cross-App M - Fodder-to-TMR
*Field-grown fodder enters controlled feed ingredient and ration stock*

- **Trigger:** On-farm forage production
- **Key control:** No untested unsafe ingredients may enter feed; dry matter accurately captured
- **Records / outputs:** Fodder batch, inventory receipt, formulation and feed cost
- **Connected apps:** Fodder -> Quality -> Inventory -> Feeding -> Finance
- **Owner:** Feed Manager
- **KPI:** Farm cost/kg feed, feed lots released

## Flow 37 Of 40 — Cross-App N - Transport-to-Delivery
*Sale-ready goats loaded, transported and received under welfare controls*

- **Trigger:** Confirmed livestock order and approved vehicle
- **Key control:** No loading if fitness or withdrawal fails; welfare checks during transport
- **Records / outputs:** Dispatch roster, gate pass, receipt and customer claim
- **Connected apps:** Sales -> Vet -> Housing -> Logistics -> Finance
- **Owner:** Dispatch Supervisor / Sales
- **KPI:** Loss in transit, dispatch mismatches

## Flow 38 Of 40 — Cross-App O - Waste-to-Safe-Disposal
*Manure, wastewater, sharps and mortality waste tracked safely*

- **Trigger:** Daily farm waste or veterinary/mortality event
- **Key control:** Regulatory disposition and biosecurity approach determined by local qualified advisers
- **Records / outputs:** Disposal evidence, costs, infection controls
- **Connected apps:** Environment -> Vet -> Quality -> Finance
- **Owner:** Farm Manager / Biosecurity Lead
- **KPI:** Missed pickups, disposal audit

## Flow 39 Of 40 — Cross-App P - Incident-to-Corrective Action
*Detect incident, contain, investigate and verify preventive control*

- **Trigger:** Product quality, animal safety, financial control or operation incident
- **Key control:** Independent effectiveness verification required; recall and withdrawal hold overrides normal work
- **Records / outputs:** CAPA audit trail, SOP version, linked animal/lot, KPI
- **Connected apps:** Quality -> Owner App -> Operations -> Analytics
- **Owner:** Quality Manager
- **KPI:** CAPA closure %, repeat incidents

## Flow 40 Of 40 — Cross-App Q - Reproductive Loss Investigation
*Abortion or stillbirth to containment, diagnostics and future prevention*

- **Trigger:** Abortion, stillbirth or abnormal kidding
- **Key control:** Suspected zoonotic material handled only by trained staff under vet guidance; do not self-diagnose
- **Records / outputs:** Kidding, veterinary, diagnostic, biosecurity and future breeding updates
- **Connected apps:** Maternity -> Veterinary -> Housing -> Quality -> Breeding
- **Owner:** Veterinarian / Maternity Lead
- **KPI:** Loss incidence, time to containment
