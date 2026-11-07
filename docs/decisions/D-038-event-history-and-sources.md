# D-038: Immutable event history, one event model for every input source
2026-11-07 · Status: **Proposed** · Extends D-005 (no hard deletes); informs [DATABASE_DESIGN_DRAFT.md](../DATABASE_DESIGN_DRAFT.md) §4

## Context
The farm will move from manual entry, to scanning, to sensors and cameras, to automation. It also wants research answers over time: litter size and kid survival by sire × dam, growth by breed, disease by breed/age/season. The prototype keeps mutable status columns (for example `matings.status`) and `timestamps()` on history tables, and `animal_events` has only a summary string, so history can be rewritten and research queries must parse text.

## Decision
1. **Life events are immutable rows.** Mating, pregnancy check, kidding, weaning, weight, movement, health case, treatment, preventive care, sale, death. No UPDATE or DELETE on them (DB role without those grants; trigger as backstop). A correction is a new row with `supersedes_id`; readers use the latest non-superseded row.
2. **Common envelope on every event:** `id` (client-generated UUIDv7), `org_id`, `farm_id`, `animal_id` (or pen/group), `occurred_at` (when it happened), `recorded_at` (server time), `recorded_by`, `device_id`, `source` = `manual | rfid | sensor | camera | rule | import`, `supersedes_id`, typed detail columns for the fields research needs, and `attributes jsonb` for tenant-defined fields validated against tenant field definitions.
3. **Current state is derived**, not hand-edited: pen headcount, repro status, health flag, lifecycle status, withdrawal clearance (max of meat and milk clear dates across treatments). Cached copies are allowed only if they can be rebuilt from events and have a test that proves it.
4. **All input channels write through the same service.** A form, a C72 scan, a sensor rule or an import creates the same event type; only `source` differs. Automation therefore adds sources and never needs a new model.
5. **Research fields are first-class typed columns**, not JSON: sire, dam, breed(s) (animal), service date and method (mating), result (pregnancy check), born alive, stillborn, litter size, assisted (kidding), weight and date, case condition/severity/outcome, treatment product and clear dates.
6. **Sensor readings** are not events. They go to a time-series store with a `device`/`channel` registry. Rules turn readings into alerts and, when meaningful, events with `source = sensor`.
7. **Idempotency:** every write carries the client UUID; replays return the original result. This is required for offline queues and for retrying scanners.

## Consequences
- Needs `org_id`/tenant columns (D-037), UUID primary keys, and a migration of the prototype's mutable tables.
- Reports read from materialised views over events; the corrections rule makes reports reproducible as of any date.
- Slightly more complex writes and reads than a CRUD model, in exchange for trustworthy history.
- Financial entries stay append-only with reversing entries (D-012).
