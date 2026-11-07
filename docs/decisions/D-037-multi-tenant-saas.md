# D-037: Multi-tenant SaaS from day one
2026-11-07 · Status: **Proposed** (direction given by the project lead; owner to confirm) · Supersedes P-7 in [PROJECT_PRINCIPLES.md](../PROJECT_PRINCIPLES.md) and OWN-6 / the "not a requirement" note in [REQUIREMENTS.md](../REQUIREMENTS.md)

## Context
P-7 said: serve KK Farm first, keep a `farm_id`-ready model, and build multi-tenancy only when the business asks. The project lead now wants a SaaS product that any farm can install, regardless of size or type, with a hierarchy of users. Adding tenancy to a single-farm schema later means touching every table, every query and every test, and risks cross-customer data leaks.

## Options considered
1. **Stay single-farm, add `farm_id` later.** Cheapest now, most expensive and riskiest later.
2. **Database per tenant.** Strong isolation, heavy to operate for many small farms.
3. **Shared database, `org_id` on every tenant-owned table, enforced by PostgreSQL Row-Level Security.** One schema, strong isolation, cheap to run.

## Decision
Option 3.
- Hierarchy: **Organization → Farm → Site/Shed → Pen**. Roles (owner, manager, vet, operator, worker, viewer, plus tenant-defined) are granted on a node and inherit downward.
- Every tenant-owned table has `org_id` (and `farm_id` where relevant). RLS policies key off a per-request session setting (`app.current_org`), set by middleware inside each transaction. App-level scoping is a second layer, not the only one.
- Uniqueness is per tenant: `(org_id, tag_id)`, `(org_id, rfid_epc)`, `(org_id, pen_name)`.
- Vets may work for several farms: users are global identities with memberships per organization.
- Reference data (breeds, vocabularies) is seeded per tenant from templates and is tenant-editable.
- Queue jobs, broadcasts (Reverb channels), cache keys, object-storage paths and logs carry the tenant.
- A test must prove tenant A cannot read or write tenant B through every route, job and channel.

## Consequences
- The prototype's schema (no tenant columns, integer ids) needs a migration pass before real data goes in.
- Connection handling must set the tenant per transaction (Octane/queue workers must reset it between requests).
- Analytics across tenants (benchmarks) are only allowed on anonymised, opt-in aggregates.
- Billing, plans and onboarding become real work items, but can follow the first customer.
- Does not change: no hard deletes (D-005), audit trail, RFID tag/assignment model (D-004).
