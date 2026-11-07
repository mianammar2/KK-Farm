# Product & Delivery Plan

Status: draft v1 (requirements gathered from the KK Farms stakeholder, the draft SRS and the current workbook).

## 1. Vision
A SaaS that any farm can install and run, from a hobby farm to 5,000+ goats. It turns paper registers and spreadsheets into one system of record, then adds RFID, sensors and cameras, then automation. It also builds a growing history that answers breeding and health research questions.

## 2. Stakeholders and users
| Who | Needs |
|---|---|
| Owner | Mobile and web access, full visibility, manual entry, reports, controls the hierarchy |
| Farm manager | Daily operations, tasks, alerts, movements, feed |
| Vets (several, visiting) | Own login, visit/case/treatment entry, withdrawal dates, vaccination schedules |
| Workers | Fast scan-first entry (feed, move, weigh), simple UI |
| Researcher/analyst | Breeding and disease analytics from history |
| SaaS operator (us) | Tenant onboarding, plans, support, device registry |

## 3. Problems being solved (priority order)
1. **Headcount drift.** Goats move or go missing but feed is calculated on old counts, so consumption per head looks wrong. Fix: derived headcount from movement events, then RFID pen scans to verify.
2. **Double entry.** Staff and vets write in registers, then the owner retypes into Excel. Fix: enter once in the system.
3. **Disconnected devices** (ammonia, EKM meters, cameras). Fix: one sensor layer and one dashboard.
4. **No research history.** Fix: structured, append-only breeding, birth, growth and health data from day one.
5. **Vets work on paper.** Fix: vet portal with cases, treatments and automatic lockouts.

## 4. Domain model (summary)
Starting vocabularies come from the KK Farms workbook and are tenant-editable.

**Tenancy and access:** Organization, Farm, Site, Pen, User, Role assignment (scoped to a tree node), Device.

**Animals:** Animal (tag_id, rfid_epc, breed(s), sex, birth_date, origin, sire_id, dam_id, class, repro_status, lifecycle_status, current_pen), TagHistory.
- Breeds seeded: Beetal, Teddy, Makhi Cheeni, Kamori, Nachi, Dera Din Panah, Mixed, Other
- Class: Kid, Replacement Doe, Breeding Doe, Breeding Buck, Wether, Other
- Repro: N/A, Open, In Heat, Bred, Pregnant, Lactating, Dry, Postpartum
- Lifecycle: Active, Sold, Dead, Culled, Transferred
- Health flag: Healthy, Monitor, Sick, Quarantine, Recovering
- Origin: Farm Born, Purchased, Transferred In

**Event history (append-only, each with source, actor, occurred_at, device):**
- Movement (arrival, pen transfer, sale, death, cull, transferred out)
- Mating/service (doe, buck, method, heat observed)
- PregnancyCheck (result, fetuses estimated, examiner)
- Kidding (dam, sire, born alive, stillborn, litter type, assisted); each kid becomes an Animal linked to the birth event
- Weaning, Weight (with body score)
- HealthCase (condition, severity, isolation, status), Treatment (medicine, dose, route, vet, `meat_clear_date`, `milk_clear_date`), PreventiveCare (vaccine/deworming, batch, next due)

**Feed and stock:** RationPlan (group, feed, kg/head/day, meal split), FeedLog (pen, meal, headcount, planned/offered/refused/consumed), FeedItem, StockMovement, FeedingCompliance; MedicineItem, MedicineMovement (batch, expiry).

**Operations and money:** Task, Alert, Expense, Sale.

**Sensors:** DeviceType, Device, SensorChannel (ammonia, temperature, humidity, energy kWh, silo weight, water...), Reading (time-series), AlertRule.

**Extensibility:** CustomFieldDefinition, CustomEventType, EnumValue per tenant. Core columns stay typed; custom data lives in validated JSONB.

## 5. Research / analytics questions (designed for from day one)
- Litter size and kid survival by **sire × dam** and by breed cross
- Conception rate per service, kidding interval, abortion/stillbirth rate
- Growth: birth/weaning weight and average daily gain by breed, sire, season
- Disease incidence by breed, age, pen and season; treatment outcomes and cost
- Which breeds are "moving faster" (growth rate, time to sale weight) and which slower
- Feed conversion by group; consumption per head vs plan
- Environment correlation (ammonia, temperature) with disease/growth, once sensors are live

Method: warehouse views over the event history (materialised views first, a separate analytics store only if needed). Needs enough data to be meaningful: capturing it correctly in Stage 1 is the work that matters.

## 6. Stages

### Stage 0: Foundation (and tagging)
- Review previous developer's repo and research; decide keep/discard
- Repo, CI, Docker dev environment, ADR process
- Tenancy, auth, roles, RLS, audit log (with tests)
- Physical: RFID ear-tag all goats, record tag ↔ RFID mapping, first census (goat → pen)
- Exit: tenant can log in, hierarchy exists, animals imported from the census

### Stage 1: Manual → System of record (replaces the Excel sheets)
- Web (responsive) and Android app, offline-first
- Registry, movements (headcount derived, feed target recomputed), breeding → pregnancy → kidding → weaning, weights
- Vet portal: cases, treatments, preventive care, withdrawal dates and lockouts
- Medicine and feed stock, rations, feed logs, tasks, alerts, sales and expenses
- Import from the KK Farms workbook format, export back out
- Dashboards: herd, pregnancies, sick, feed per head, stock
- Exit: KK Farms stops using the workbook

### Stage 2: Semi-automation
- C72 scan flows: identify, move, weigh, treat, pen census with discrepancy report
- Sensor ingestion (ammonia, EKM, temperature, silo weight) with dashboards and threshold alerts
- Camera integration: photos attached to cases and animals; evaluate counting/health AI
- First research reports (breeding and growth by breed)
- Exit: most records are created by scan or sensor, not typing

### Stage 3: Full automation
- Fixed RFID portals (gates, feeders) for continuous counting and tracking
- Auto-feeders driven by live headcount and plan; milking gate routing with lockouts
- Ventilation/alerts driven by ammonia; rule engine creates tasks automatically
- Camera AI for counting and early illness detection
- Edge fail-safes, manual override, safety audits
- Exit: routine operations run unattended; humans handle exceptions

### Always on: SaaS and platform
Plans/billing, onboarding, i18n (English/Urdu), data export, backups, monitoring.

## 7. Key risks
| Risk | Mitigation |
|---|---|
| Tagging campaign delays everything | Stage 1 works without RFID using visible tags; RFID is layered on |
| Staff don't enter data | Scan-first flows, big buttons, offline, Urdu, training |
| Cross-vendor sensors | Adapter layer, start with whatever the farm already has |
| UHF reads neighbours | Single-nearest-tag mode, RSSI filtering |
| Actuator safety | Edge fail-safe and manual override before any Stage 3 hardware |
| Research needs lots of data | Structured capture from day one; immutable history |
| Over-building for SaaS before first customer works | Build for KK Farms, keep tenancy and configuration clean |

## 8. Next actions
1. Get access to the previous developer's GitHub repo (URL) and review.
2. Confirm stack and open items in `CLAUDE.md`.
3. Write ADR-0001 (tenancy + RLS) and ADR-0002 (append-only events).
4. Draft Stage 1 schema and API from §4 and the workbook sheets.
5. Prototype the C72 trigger and EPC read in a tiny Android test app.
