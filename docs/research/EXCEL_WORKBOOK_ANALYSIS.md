# Excel Workbook Analysis: `KK_Farms_360_Goat_Management_System.xlsx`

**Status:** Analysed 2026-11-07 from the file supplied by the project lead. The workbook is **not** committed (`.gitignore` blocks `*.xlsx`); only this analysis is. The file is an **empty template**: the Goat Register has no animals in it, so there is no data to migrate yet. Formulas are recorded as `[ASSUMED]` rules until the CEO and vets confirm them.

## Sheet inventory (25 sheets)

| Group | Sheet | Capacity (rows) | Key columns | Maps to |
|---|---|---|---|---|
| Identity | Goat Register | 130 | Tag ID, RFID EPC, name, breed, sex, birth date, origin, acquired date, sire tag, dam tag, class, repro status, lifecycle status, shed/pen, source farm, birth event id, birth weight, latest weight, health flag, last health review, purchase cost PKR, ownership, notes | `animals`, `rfid_tags`/`rfid_assignments` |
| Identity | Goat 360 Profile | n/a | Select a tag → identity, two-generation pedigree, linked events | Goat profile page |
| Breeding | Breeding | 60 | Mating id, doe, buck, service date, method, heat observed, service cycle, expected kidding, pregnancy check due, outcome, handler/vet | `matings` |
| Breeding | Pregnancy Checks | 60 | Check id, doe, date, method, result, fetuses estimated, mating id, next check, examiner | pregnancy check events (partly in `matings.status`) |
| Breeding | Kidding | 60 | Birth event id, dam, date, born alive, stillborn, litter type, total born, sire, kids tagged, assisted, attendant | `kiddings` |
| Breeding | Weaning | 60 | Kid, dam, birth date, weaning date, age at wean, weaning weight, method, status, handler | not in prototype |
| Growth | Weight Log | 90 | Goat, date, weight, previous weight, days since previous, gain, ADG g/day, body score 1-5, recorder | `weight_records` (no body score) |
| Health | Health Cases | 80 | Case id, goat, onset, condition, symptoms, severity, isolated, status, vet, diagnosis/test, resolved date | `health_records` |
| Health | Treatments | 100 | Treatment id, case id, goat, date, product, dose + unit, route, frequency, days prescribed, vet, next dose, **meat clear date**, **milk clear date**, cost | partly in `health_records` |
| Health | Preventive Care | 100 | Goat, care type, product/vaccine, date, next due, batch/lot, vet, cost, status | not in prototype |
| Health | Medicine Stock / Medicine Movements | 60 / 75 | Code, name, category, unit, opening qty, reorder level, expiry, current qty; movements in/out with batch and case ref | not in prototype |
| Feed | Ration Plans | 35 | Group, feed code, target kg/head/day, meal 1/2/3 %, effective from, approved by | `feeding_groups` |
| Feed | Feed Usage | 90 | Date, meal, pen, group, feed, headcount, planned/offered/refused/consumed kg, kg per head, unit cost | `feeding_logs` |
| Feed | Feed Items / Stock Movements | 45 / 65 | Feed code, name, category, unit, cost/kg, reorder level, opening stock, supplier; signed movements | not in prototype |
| Feed | Feeding Compliance | 90 | Date, pen, meal, planned vs actual time, variance min, status, goats served, staff | not in prototype |
| Operations | Animal Movements | 65 | Goat, date, type (arrival, pen transfer, sale, death, culling, transferred out, other), from, to, reason, authorised by, amount PKR, evidence ref | `animal_events` (summary only) |
| Operations | Tasks | 75 | Created, type, goat, description, owner, due, priority, status, closed | `tasks` |
| Money | Expenses, Sales | 65 each | Category, goat (optional), vendor/buyer, amounts PKR, payment status, receipt | `ledger_entries` |
| Control | Settings, START HERE, Action Center, Executive Dashboard | n/a | Parameters, guided index, exception counts, KPIs | Settings, dashboard |

## Business rules found in the formulas `[ASSUMED]`
| Rule | Where | Logic |
|---|---|---|
| Expected kidding = service date + gestation days | Breeding | Gestation 150 (Settings; "typical 145–155") |
| Pregnancy check due = service date + 30 days | Breeding | Settings |
| Alert states | Breeding | `CHECK OVERDUE` (pending and due date past), `EDD PASSED`, `KIDDING SOON` (within 14 days) |
| Re-check alert | Pregnancy Checks | Inconclusive result with next check overdue or within 7 days |
| Weaning target age | Settings | 84 days (planning only) |
| Average daily gain | Weight Log | `1000 × (weight − previous) / days since previous` |
| Age in months | Goat Register | `DATEDIF(birth, today, "m")` |
| Tag audit | Goat Register | `DUPLICATE TAG`, `SELF-PARENT`; other sheets flag `UNKNOWN TAG` |
| Feed stock | Feed Items | opening + signed movements − offered feed (offered counts even if refused) |
| Consumed = offered − refused; per head = consumed / headcount | Feed Usage | |
| Meal split must total 100% | Ration Plans | |
| Feeding compliance | Feeding Compliance | `MISSED FEED`, `LATE` (variance > 0), `PENDING` |
| Medicine status | Medicine Stock | `NEGATIVE`, `EXPIRED`, `REORDER` (≤ reorder level), `EXPIRING` (within 45 days) |
| Preventive care alert | Preventive Care | `OVERDUE`, `DUE SOON` within 14 days, `NO DUE DATE` |
| Dose alert | Treatments | `DOSE OVERDUE`, `DOSE SOON` within 2 days |
| Task alert | Tasks | `OVERDUE`, `DUE SOON` within 7 days |

The Settings sheet states: *"This is a record and planning system. Do not prescribe medicines, determine withdrawal periods, or diagnose disease without your veterinarian."* This matches D-006.

## Vocabularies (from data-validation lists)
- Breed: Beetal, Teddy, Makhi Cheeni, Kamori, Nachi, Dera Din Panah, Mixed, Other
- Sex: Female, Male · Origin: Farm Born, Purchased, Transferred In
- Class: Kid, Replacement Doe, Breeding Doe, Breeding Buck, Wether, Other
- Repro status: N/A, Open, In Heat, Bred, Pregnant, Lactating, Dry, Postpartum
- Lifecycle status: Active, Sold, Dead, Culled, Transferred
- Health flag: Healthy, Monitor, Sick, Quarantine, Recovering
- Ownership: KK Farms, Partner, Consignment, Other
- Case severity: Low, Moderate, High, Emergency · Case status: Open, Under Treatment, Monitoring, Recovered, Closed, Deceased
- Route: Oral, Injectable, Topical, Other
- Movement type: Arrival, Pen Transfer, Sale, Death, Culling, Transferred Out, Other
- Settings: farm KK Farms, planned capacity 5,000 goats (target, not inventory), currency PKR, meals Morning / Midday / Evening

## Relationships
Everything links by **Tag ID**; each sheet re-validates tags with `COUNTIF` and shows `UNKNOWN TAG`. Treatments link to a Health Case id; Kidding links to the Goat Register through Birth Event ID (one register row per kid); Weaning and Breeding link by tag and Mating id.

## Gaps and conflicts
- **Cross-sheet writes are manual.** Notes say "also update Goat Register / Animal Movements". Headcount, repro status and lifecycle status can silently disagree with the event sheets, which is the cause of the feed-per-head confusion.
- **Withdrawal is two typed dates** (meat, milk) per treatment, not a computed lock.
- **No lactation or milk-yield sheet**, although the farm is meat + dairy + breeding stock (Q-AM-5).
- **No users, roles or audit trail**, and no photos.
- **No sensors** (ammonia, energy, climate) and no cameras.
- **Pen/shed is a single text field** on the register; headcount per pen is typed on Feed Usage.
- Table capacities (30–130 rows) are pilot-sized, far below the 5,000 target.

## Entities the prototype lacks
Weaning, preventive care (vaccines, deworming), treatments as their own records with meat/milk clearance, medicine stock and movements, feed items and stock movements, feeding compliance, pregnancy checks as events, body condition score, lactation/milk, tenant and sensor entities (D-037, D-038).

## Migration assessment
- **Seed data today:** none (template is empty). Import tooling should accept this workbook's column layout for when the farm starts entering data, and export back to it.
- **Vocabularies and settings** can seed a tenant's reference data directly.
- Validate on import: duplicate tags, self-parent, unknown tags, sire/dam existing, dates in the future. Never silently correct source data (see risk 5 in PROJECT_STATUS.md).

## Questions for the CEO (append to OPEN_QUESTIONS.md)
1. Are lactation and milk yield recorded anywhere today? In which form?
2. Do vets decide meat/milk clear dates per product, or from a fixed table?
3. Is "Partner" and "Consignment" ownership needed in v1, with separate accounting?
4. Which of the six listed breeds are actually kept, and are crosses recorded as "Mixed" or by parentage?
5. Is the 5,000-goat capacity a real target date, or aspirational?
