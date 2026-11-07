# Goat SaaS: Livestock Lifecycle Platform

Multi-tenant SaaS for managing goats across their whole life cycle. First customer: **KK Farms** (Pakistan, PKR, meat + dairy + breeding stock, up to ~5,000 goats). The product must not depend on farm size, farm type or country.

Journey: **manual entry → system of record → semi-automation (RFID, sensors, cameras) → full automation.** Each stage must be useful on its own. Full plan: `docs/SAAS_PLAN.md`.

## Status
Greenfield. `/Users/MAC/goat` only holds the customer's current spreadsheet (`KK_Farms_360_Goat_Management_System.xlsx`), which is the reference for domain vocabulary. The previous team's repo (`PyroNet-Solutions/KK-Farm`, public) is cloned read-only at `/Users/MAC/goat-prev` and has been reviewed: ~4,800 lines of discovery docs plus a Laravel 13 + Inertia/React prototype with synthetic data. Its decisions conflict with this file on stack and tenancy (see Open items).

## Core principles (non-negotiable)
1. **Multi-tenant from day one.** Every tenant-owned table has `org_id` (and `farm_id` where relevant). Enforce with Postgres Row-Level Security, not only app code. Uniqueness is per tenant (tag IDs, pen names), never global.
2. **Hierarchy:** Organization → Farm → Site/Shed → Pen. Roles (owner, manager, vet, operator, worker, viewer) are scoped to a node in this tree and inherit downward.
3. **Append-only history.** Life events (birth, mating, move, weigh, treat, sell, die) are immutable rows. Corrections are new rows that supersede the old ones. Never UPDATE or DELETE history. The research use case depends on this.
4. **Expected vs actual everywhere.** Headcount (system vs scanned), feed (planned vs consumed per head), medicine (stock vs used), withdrawal (clear date vs sale/milking). Mismatches become alerts.
5. **Every input channel writes the same events.** Manual form, RFID scan, sensor, camera and automation rule all create the same event types, tagged with `source` (manual | rfid | sensor | camera | rule | import). Automation adds sources; it never redesigns the model.
6. **Extensible by configuration, not migration.** Tenants can add custom fields, event types, enums and sensor types without schema changes (typed core columns + validated JSONB `attributes` + tenant-level definitions).
7. **Device-agnostic sensor layer.** One ingestion API plus one small adapter per vendor/device type. Raw readings go to a time-series store; derived facts become events or alerts.
8. **Offline-first clients.** Farms have patchy connectivity. Clients generate UUIDs, queue events locally, sync idempotently, and the server resolves ordering.
9. **Safety.** Automated actuators (gates, augers, ventilation) need an edge-local fail-safe state and a manual override. The cloud is never the only control path. Withdrawal lockouts are computed from treatment events (max of meat/milk clear dates), never from a single status flag.
10. **The system records and recommends; vets decide.** Never auto-prescribe or auto-set withdrawal periods. The vet supplies them.

## Key domain rules
- Animal identity: visible ear tag (`tag_id`, human-facing) and RFID EPC (`rfid_epc`) are separate fields, both unique per tenant. A goat can be re-tagged; keep tag history.
- **Headcount per pen is derived from movement events**, not a hand-edited number. A cached count may exist but must be rebuildable.
- Moving an animal recomputes the feed target of both pens (headcount × ration per head by group).
- Meat withdrawal and milk withdrawal are separate dates per treatment.
- Gestation default 150 days, pregnancy check at 30 days, weaning target 84 days: all tenant-configurable settings, not constants.
- Lifecycle/repro/class vocabularies start from the KK Farms workbook (see `docs/SAAS_PLAN.md` §4) and are tenant-extensible.

## Primary research question (shapes the schema)
"Which sire × dam (and breed cross) produces how many kids, how healthy and how fast-growing, and which diseases appear where?" This needs, from day one: full pedigree (sire/dam per animal), mating events, pregnancy checks, kidding outcomes (born alive/stillborn/litter size), kid weights over time, weaning, health cases tied to animal + breed, and mortality. Capture these as structured data even in the manual stage. Analytics (litter size by cross, kid survival, ADG by breed, disease incidence by breed/age/season, kidding interval) read from the event history and are built after data accumulates.

## Tech stack (decided: build on the previous team's stack, owner choice)
Inherited from `PyroNet-Solutions/KK-Farm` (its D-023, D-024, D-025, D-027, D-029, D-030). Re-verify versions before the first spike.
- Backend: Laravel 13 (+ Octane), PHP 8.4+, Sanctum auth, Horizon queues, Pest/PHPUnit
- Web: Inertia 3 + React 19 + TypeScript, Vite, Tailwind 4 + shadcn/ui, TanStack Table/Query, ECharts
- DB: PostgreSQL 18 with Row-Level Security for tenancy; a time-series store (TimescaleDB extension or partitioned tables) for sensor readings
- Cache/queues/pub-sub: Valkey 9; realtime via Laravel Reverb (WebSockets) through a transactional outbox
- Files: S3-compatible object storage (Garage) + imgproxy for photo variants
- Mobile: installable PWA (offline queue in IndexedDB) for owners, vets and staff; **Android native bridge for the Chainway C72** (Kotlin, DeviceAPI SDK, sends scans to the PWA via keyboard-wedge/broadcast intent, or a Capacitor wrapper). Decide in an ADR after the C72 spike.
- Sensors/edge: MQTT broker + ingestion endpoint; small adapters per device type
- Infra: Docker Compose per app behind Caddy; CI on every PR
- Prototype to start from: `/Users/MAC/goat-prev/apps/kk-farm` (Laravel 13 + Inertia, 18 tables, 17 tests, synthetic data). It is single-farm; multi-tenancy must be added (ADR-0001).

## Reference hardware
Chainway **C72** handheld: Android 13 (API 33), UHF RFID reader (EPC Gen2), Zebra 2D barcode engine, camera, Wi-Fi/4G. Use Chainway's DeviceAPI (not Zebra's). The SDK is vendor-supplied and not on public Maven: it is kept out of git history if licensing forbids, with setup steps in `android/README`. Side-trigger key codes must be verified on a real unit. UHF reads many tags at once, so the app needs a single-nearest-tag mode (low power, RSSI filter) for action flows. Do not commit device serials, IMEIs or MACs.

## Repository layout (target)
```
CLAUDE.md
docs/            PLAN.md, domain model, ADRs (docs/adr/NNNN-title.md), API notes
app/             Laravel app (backend + Inertia/React web + PWA)
android/         Kotlin RFID bridge for the C72
edge/            gateway code and sensor adapters
ops/             docker-compose, CI, deploy
```
(Final layout depends on where the Laravel prototype is imported; see Open items.)

## Working conventions
- Decisions go in `docs/DECISIONS_LOG.md` (next free D-number) with longer rationale in `docs/decisions/`, matching the existing repo. Update `docs/SAAS_PLAN.md` when scope changes.
- Every migration is reversible and tested against RLS (a test must prove tenant A cannot read tenant B).
- Every write endpoint takes a client-generated idempotency key / event UUID.
- Tests: unit for rules (withdrawal, headcount, feed), integration for API + RLS, sync tests for offline queues.
- No secrets, tokens, keys or customer data in git. Use `.env.example`.
- Money is stored as integer minor units + currency code per tenant. Timestamps are UTC with the farm's timezone applied at display.
- UI text is i18n from the start (English + Urdu; RTL support).

## Open items
1. Reconcile with the previous repo. Stack: DECIDED, use Laravel (see Tech stack). Still to settle in ADRs: (a) the previous repo's P-7 says KK Farm first and multi-tenancy only when the business asks; the owner now wants SaaS from day one, so multi-tenancy is in scope and supersedes P-7. (b) Its "no code before gate G0", "$0 budget" and "no live hardware" rules were discovery-phase rules from the previous team: confirm with the owner which still apply. (c) Where the code lives: import `apps/kk-farm` into this folder, or work inside a fork of `PyroNet-Solutions/KK-Farm`. Reusable as-is: domain model, RFID tag/assignment model (EPC is never the primary key, D-004), no-hard-delete rule (D-005), advisory-only AI rule (D-006), requirements and farm-visit docs. Its reality check: the farm currently has 118 goats in one shed; 5,000 is planned capacity.
2. Confirm stack choices above (esp. web framework, whether iOS is required in v1).
3. Brands/models and data access for the existing ammonia sensors, EKM meters and cameras.
4. Obtain the Chainway DeviceAPI SDK and demo app; get UHF tags and confirm EPC format.
5. Confirm KK Farms vocabularies and workflows with the farm manager and vets.
