# How every workbook sheet is managed in the app

2026-11-08 · Source: `KK_Farms_360_Goat_Management_System.xlsx` (25 sheets, read column by column) · Status of each sheet checked against the app as deployed (commit a769462).

**The one big change from Excel:** the workbook asks people to type the same fact in several places and keep them in sync by hand ("after kidding, manually update the doe's status in Goat Register", "animal sales must also update Goat Register and Animal Movements", "never duplicate Feed Usage as an OUT movement"). In the app **each fact is entered once, as an event, and everything else is calculated from it**: status, stock, headcount, alerts, dashboards. Grey "calculated" columns in Excel become automatic. "Tag Audit" columns disappear, because the app only lets you pick goats that exist (by scan or search).

Legend: ✅ built · 🟡 partly built (gaps listed) · ❌ not built yet

## Summary

| # | Sheet | Who / when (from START HERE) | App screen | Status |
|---|---|---|---|---|
| 1 | Goat Register | Farm Manager · on intake | Scan → Goat Register form | ✅ |
| 2 | Goat 360 Profile | Manager · any time | Goat page (scan opens it) | 🟡 |
| 3 | Executive Dashboard | CEO · daily | Dashboard | 🟡 |
| 4 | Breeding | Breeding Lead · on mating | Breeding → cycle | ✅ D-075 |
| 5 | Pregnancy Checks | Vet · at checks | Breeding → mating → Pregnancy check | ✅ D-075 |
| 6 | Kidding | Breeding Lead · at birth | Breeding → Kidding | ✅ D-075 |
| 7 | Weaning | Breeding Lead · at weaning | Breeding → Weaning | ✅ D-075 |
| 8 | Weight Log | Supervisor · scheduled | Weight & Growth → Weight log | ✅ D-078 |
| 9 | Health Cases | Vet · onset and review | Health → Cases | ✅ D-073 |
| 10 | Treatments | Vet / staff · each dose | Health → case → Add treatment | ✅ D-073 |
| 11 | Preventive Care | Vet · per health plan | Health → Preventive care (batch) | ✅ D-078 |
| 12 | Feed Items | Feed store · setup | Feeding → Feed store | ✅ D-054 |
| 13 | Ration Plans | Nutritionist · diet change | Feeding → Rations | ✅ D-054 |
| 14 | Feed Usage | Feed lead · 3 meals daily | Feeding → Feed now | ✅ D-054 |
| 15 | Feeding Compliance | Feed lead · 3 meals daily | automatic (Feed now, dashboard) | ✅ D-054 |
| 16 | Stock Movements | Storekeeper · each transaction | Feeding → Feed store | ✅ D-054 |
| 17 | Medicine Stock | Vet store · setup | Health → Medicine store | ✅ D-074 |
| 18 | Medicine Movements | Vet store · each issue | Medicine store + treatments | ✅ D-074 |
| 19 | Animal Movements | Farm Manager · per event | Movements (single or batch) | ✅ D-076 |
| 20 | Expenses | Accounts · each transaction | Finance (payment status) | ✅ D-078 |
| 21 | Sales | Accounts · each sale | Movements (goats) + Finance → Sales (products) | ✅ D-076 |
| 22 | Tasks | Farm Manager · daily | Tasks & Daily Work | ✅ D-078 |
| 23 | Action Center | Farm Manager · every morning | Action Center + dashboard banner | ✅ D-077 |
| 24 | Settings | Owner · as needed | Settings | 🟡 |
| 25 | START HERE | — | not needed (the app guides) | — |

---

## 1. Goat Register ✅
**Entered:** Tag ID (auto from breed), RFID EPC (from the scan), name, breed, sex, birth date (optional if bought, D-053), origin, acquired date, sire and dam (pick or type tag), category, female status, purpose, kid stage, purity, lifecycle status, pen, source farm, birth weight, purchase cost PKR, ownership, notes, photo, custom fields.
**Automatic:** age in months, age category, Birth Event ID (link to the kidding), latest weight (from Weight Log), health flag (from open health cases), "Tag Audit" (duplicate tags and self-parent are refused at entry).
**Change from Excel:** repro status, health flag, lifecycle status and pen are *not* typed by hand later; they change when a breeding, health, movement or exit event is saved.
**Gap:** an "Edit goat" screen for correcting registration mistakes (as a correction record, not overwriting history).

## 2. Goat 360 Profile 🟡
Scan a tag (or search) → goat page with identity, photo, pedigree, offspring, weights, health and timeline.
**Gap:** two-generation pedigree card, and sections for treatments, preventive care, weaning and withdrawal status once those exist.

## 3. Executive Dashboard 🟡
**Have:** registered/active goats, herd by category, health, breeding counts, tasks, finance summary.
**Add:** pregnant does, sick + quarantined, feed consumed today vs plan (kg and PKR), feed cost per head, medicine/feed stock alerts, kidding next 14 days, goats under withdrawal.

## 4. Breeding 🟡
**Entered (per mating):** doe (scan), buck (scan or pick), service date, method (Natural / AI / Pasture Exposure), heat observed (Yes/No/Unknown), handler/vet, notes.
**Automatic:** Mating ID, service cycle (count of this doe's matings since her last kidding), expected kidding = date + gestation days (setting, 150), pregnancy check due = date + 30 (setting), outcome (from checks and kidding), alerts CHECK OVERDUE / KIDDING SOON (14 days) / EDD PASSED.
**Have:** doe, buck, date, method (natural/AI), expected kidding, status.
**Add:** Pasture Exposure method, heat observed, handler, notes, service cycle, outcome list (Pending, Confirmed Pregnant, Not Pregnant, Inconclusive, Kidded, Aborted, Closed).

## 5. Pregnancy Checks 🟡
**Entered:** doe (scan), check date, method (Ultrasound / Blood Test / Vet Exam / Other), result (Pregnant / Not Pregnant / Inconclusive / Pregnancy Loss), fetuses estimated, next check date (for Inconclusive), examiner (signed-in vet), notes.
**Automatic:** linked to the doe's open mating; mating outcome and the doe's female status update; RECHECK DUE / OVERDUE alerts and a task for the vet.
**Have:** one pregnant/not-pregnant result stored on the mating. **Add:** a separate check record (several per mating), method, fetuses, inconclusive + recheck.

## 6. Kidding 🟡
**Entered:** dam (scan), date, born alive, stillborn, assisted (No / Yes / Veterinary Emergency), attendant, notes; then register each live kid (already pre-fills sire, dam, breed, birth date and links the Birth Event ID).
**Automatic:** Birth Event ID, total born, litter type (Single…5+), sire (from the mating), "kids tagged" count, dam's status → Postpartum/Lactating, mating outcome → Kidded.
**Have:** alive, stillborn, kid registration and linking. **Add:** assisted, attendant, notes, litter type and kids-tagged shown.

## 7. Weaning ❌
**Entered (per kid, scan):** weaning date, weaning weight kg, method (Gradual / Separate Pen / Other), status (Planned / Completed / Postponed), handler, notes.
**Automatic:** dam and birth date from the kid's record, age at weaning in days, the weight also saved to Weight Log, kid stage → Weaned, a "Planned" weaning task when a kid reaches the target age (setting, 84 days). Optional: move the kid to a weaner pen in the same step.

## 8. Weight Log 🟡
**Entered (scan goat):** date, weight kg, body condition score 1–5, notes. Recorder = signed-in user.
**Automatic:** previous weight and days since previous (from the last record, no manual typing as in Excel), gain kg, ADG g/day, growth by breed and sire in reports.
**Have:** weight and date. **Add:** body score, previous/gain/ADG shown on every entry. Later: a Bluetooth scale fills the weight (source = sensor).

## 9. Health Cases 🟡
**Entered (scan goat):** onset date, disease/condition, symptoms, severity (Low / Moderate / High / Emergency), isolated (Yes/No), status (Open / Under Treatment / Monitoring / Recovered / Closed / Deceased), vet, diagnosis/test, resolved date, notes.
**Automatic:** Case ID, case age in days, goat health flag (Sick / Quarantine / Recovering / Healthy) from the open case, "Deceased" leads to the death exit.
**Have:** clinical record with title, severity (3 levels), notes, close. **Add:** symptoms, isolated, the 6 statuses and 4 severities, diagnosis, onset vs resolved dates, disease list per farm (for disease-by-breed research).

## 10. Treatments ❌ (safety-critical)
**Entered (by vet, from a case):** date, medicine (pick from Medicine Stock or type), dose + unit, route (Oral / Injectable / Topical / Other), frequency, days prescribed, next dose date, **meat clear date**, **milk clear date** (both typed by the vet, never calculated, D-006), cost PKR, notes.
**Automatic:** Treatment ID, link to case and goat, DOSE SOON / OVERDUE alerts and a task for the next dose, the goat's **withdrawal lock = latest meat and milk clear dates of all treatments**: the app warns and blocks selling or milking that goat before those dates. Optional: issues the quantity from Medicine Stock (see 18).

## 11. Preventive Care 🟡
**Entered:** care type (Vaccine / Deworming / Hoof Care / Routine Examination / Other), product/vaccine, date, next due date, batch/lot, vet, cost PKR, status (Administered / Planned / Cancelled), notes.
**Batch entry:** choose a pen or group, or scan goats one by one with the gun, and save one record per goat in one go (vaccinating 100 goats must not mean 100 forms).
**Automatic:** DUE SOON (14 days, setting) / OVERDUE / NO DUE DATE alerts, tasks for due dates.
**Have:** vaccination/deworming records with next due. **Add:** hoof care, batch/lot, cost, status, batch entry by pen.

## 12. Feed Items ❌
**Entered once per feed:** code, name, category (Roughage / Concentrate / TMR / Mineral / Milk Replacer / Supplement / Other), unit (kg), cost PKR per kg, reorder level kg, supplier, notes. Opening stock is entered as the first stock movement.
**Automatic:** current stock = receipts − issues − feed offered; stock value; status OK / LOW / OUT / NEGATIVE; days of stock left at current use.

## 13. Ration Plans 🟡
**Entered (manager drafts, vet/nutritionist approves):** feeding group (Kids, Replacement Does, Breeding Does, Pregnant Does, Lactating Does, Breeding Bucks, Quarantine, Other, plus farm-defined), one line per feed: feed code + kg/head/day; meal split % for Morning / Midday / Evening (must total 100%), effective from.
**Automatic:** approval stamp (who, when); a new version replaces the old from its date (old versions kept, so past costs stay correct); which goats are in which group follows their category and status.
**Have:** groups with a ration list and approval. **Add:** feed codes from Feed Items, 3-meal split with the 100% check, effective date and versions.

## 14. Feed Usage 🟡 (how feeding will work day to day)
The feeder opens **Feed now** on the phone or gun, picks the pen (or scans the pen's QR/RFID label), and the meal is chosen from the clock.
- **The app shows the plan:** for each feed, *planned kg = Σ over goats in the pen of their group's kg/head/day × this meal's %*. Headcount comes from the goats actually in the pen (movement events), never typed.
- **Feeder enters:** offered kg per feed (pre-filled with the plan, edit if different). Later the same day: refused/leftover kg.
- **Automatic:** consumed = offered − refused, kg per head, cost = offered × cost/kg, stock reduced by offered (refused feed still counts as used, as in Excel), fed by and time.
- **Flags:** offered far from plan (e.g. ±10%), refused > offered, unknown feed, pen with no entry for a meal.
**Have:** per group, morning/evening, done/partial/missed. **Add:** per pen and per feed kg, midday meal, refused, cost, link to stock.

## 15. Feeding Compliance ❌ → automatic
No separate sheet. Planned meal times are a farm setting (e.g. 07:00 / 12:00 / 17:00). The time a Feed Usage entry is saved is the actual time, so variance in minutes, Done / Late / Missed (no entry by the end of the meal window), goats served and staff are all calculated. A late-meal alert goes to the feed lead.

## 16. Stock Movements ❌
**Entered:** date, feed, type (Purchase / Receipt / Return / Write-off / Adjustment / Other), quantity kg, rate PKR/kg, supplier, invoice/ref, notes.
**Automatic:** direction from the type (adjustment asks IN/OUT), signed quantity, new stock level. **A purchase also creates the matching Expense** (category Feed Purchase), so it is entered once. Feeding is never entered here (it comes from Feed Usage).

## 17. Medicine Stock ❌
**Entered once per medicine:** code, name, category, stock unit (ml, tablet, dose, vial), reorder level, storage, supplier, notes.
**Per batch:** batch/lot, expiry date, quantity (from receipts).
**Automatic:** current quantity per medicine and per batch, status OK / REORDER / EXPIRING (45 days) / EXPIRED / NEGATIVE.

## 18. Medicine Movements ❌
**Entered:** date, medicine, type (Purchase / Issue to Patient / Disposal / Return / Adjustment / Other), quantity, batch/lot, reference/case, notes.
**Automatic:** direction and signed quantity; a Purchase creates the Expense (Medicine). **Issue to Patient is created from the Treatment** when the vet picks a stock item and quantity, so it is not typed twice. *Decision needed:* the workbook says "no automatic medicine deduction"; the proposal is automatic issue from treatments with an option to switch it off per farm.

## 19. Animal Movements 🟡
**Entered:** goat (scan), date, type (Arrival / Pen Transfer / Sale / Death / Culling / Transferred Out / Other), from and to (pens or "outside"), reason, authorised by, amount PKR (sale price or purchase cost), evidence ref/photo, notes.
**Automatic:** lifecycle status (Sold / Dead / Culled / Transferred), pen headcounts and feed plans of both pens, **a Sale creates the Sales entry**, an arrival comes from registration. Sale is blocked while the goat is under withdrawal (see 10).
**Have:** pen transfer (Sheds), exit with reason. **Add:** movement types in one screen, amount, authorised by, evidence, scan-to-move many goats at once (scan the batch, choose the pen).

## 20. Expenses 🟡
**Entered:** date, category (Feed Purchase, Medicine, Veterinary, Labor, Electricity, Maintenance, Equipment, Transport, Utilities, Other), goat (optional), related ref, vendor, amount PKR, payment status (Paid / Pending / Partial), paid by, receipt photo, notes.
**Automatic:** feed and medicine purchases arrive from Stock/Medicine movements; cost per goat and per month in reports.
**Have:** in/out ledger with category, counterparty, goat, reversal. **Add:** fixed category list, payment status, paid by, receipt photo; money stored as integer paisa (CLAUDE.md rule; the ledger still uses decimals).

## 21. Sales 🟡
**Entered:** date, goat (optional, scan), category (Live Goat / Breeding Goat / Goat Product / Other), buyer, gross PKR, fee/discount PKR, receipt ref, notes.
**Automatic:** net = gross − fee; a goat sale also records the Sale movement and sets the goat to Sold (one entry, not three).

## 22. Tasks 🟡
**Entered:** type (Feeding, Health, Breeding, Pregnancy Check, Kidding, Weaning, Inventory, Maintenance, Biosecurity, Other), related goat (optional), description, department or person (D-052), due date, priority (Low / Medium / High / Critical), notes.
**Automatic:** created date, status flow (Open → In Progress → Completed, or Blocked / Cancelled), closed date, OVERDUE / DUE SOON (7 days). **Tasks created by rules:** pregnancy check due, recheck, kidding soon, next dose, preventive due, weaning due, low stock.
**Have:** title, type (5), pen, department/person, urgent flag, done toggle, due today only. **Add:** due date, related goat, the full type, priority and status lists.

## 23. Action Center ❌
A **Today** screen for the manager (and a count on the dashboard), each line opening the filtered list:
duplicate/self-parent tags (cannot happen in the app) · pregnancy checks overdue · kidding overdue · kidding soon · preventive care overdue · treatment dose overdue · open/treated cases · feed low/out/negative · feeding missed · medicine expired/reorder · overdue tasks · goats under withdrawal · **headcount mismatch (system count vs last RFID pen count)**.
Later: push notification on the phone or gun (the workbook could not do this).

## 24. Settings 🟡
**Have:** breeds and tag codes, age categories, register fields, users and departments, sheds and pens.
**Add (per farm):** gestation days (150), pregnancy check after mating (30), kidding alert horizon (14), preventive care horizon (14), weaning target age (84), meal times and window, feed-plan tolerance %, medicine expiry warning (45), auto-issue medicine from treatments on/off, disease list, expense categories.

---

## Proposed build order
Each step is useful on its own and follows the workbook's daily sequence.

1. **Feed** (12, 13, 14, 15, 16): feed items and stock, ration plans with 3 meals, "Feed now" per pen with planned vs offered vs refused, automatic compliance. Fixes the headcount → feed problem the farm raised first.
2. **Treatments and withdrawal** (10, 9 gaps): treatment doses under a case, vet-entered meat/milk clear dates, sale/milking lock. Safety rule from CLAUDE.md.
3. **Medicine stock** (17, 18) linked to treatments, plus preventive care batch entry (11).
4. **Breeding completion** (4, 5, 6, 7): separate pregnancy checks, kidding details, weaning. Completes the research data (sire × dam → kids → growth).
5. **Weight** body score and ADG (8), **Movements** in one screen with sale → sales (19, 21), **Expenses** payment status (20).
6. **Tasks** due dates and rule-created tasks (22), **Action Center** (23), **Settings** parameters (24), dashboard additions (3).

## Questions for the farm before building
1. Feed: which feeds are used today (names, cost per kg), and is feed weighed per pen per meal, or per shed?
2. Meals: 2 or 3 meals a day, and at what times?
3. Medicine: should a treatment deduct stock automatically, or is stock counted separately as in the workbook?
4. Who approves ration plans: the vet, a nutritionist, or the manager?
5. Are expenses paid by staff and reimbursed (so "paid by" and "pending" matter)?
