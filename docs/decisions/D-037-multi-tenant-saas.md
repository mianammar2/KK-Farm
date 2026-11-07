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

## Spike result (2026-11-07, branch `saas/foundation`)
Implemented in `apps/kk-farm` and verified by `tests/Feature/TenantIsolationTest.php` (8 tests; the whole suite is 25 tests, all passing).

- Migration `2026_11_07_000200_add_tenancy.php`: `organizations`, `app_current_org()`, `org_id uuid NOT NULL DEFAULT app_current_org()` plus FK and index on the 18 tenant tables, `ENABLE` + `FORCE ROW LEVEL SECURITY` with a `tenant_isolation` policy (`USING` and `WITH CHECK`), per-organization uniques for breed name, shed code, farm number, tag code and employee code, and `users.org_id`.
- `App\Support\Tenant` binds `app.current_org` and switches the session into the `kkfarm_app` role (NOSUPERUSER, NOBYPASSRLS). **Superusers bypass RLS, so without the role switch the policies would silently do nothing.** `SetTenant` middleware does this per request and restores the previous state at terminate, so nothing leaks between requests on long-lived workers.
- `SetTenant` runs before `SubstituteBindings`. Removing that ordering makes another organization's goat open (404 became 200 in the check), and a test guards it.
- Checks proven by tests: every table with `org_id` has forced RLS and a policy (a new table cannot be forgotten); each tenant sees only its own rows in every table; no read, update or delete of another tenant's rows by id; no insert or move into another tenant; no tenant bound means no visibility; same names and codes allowed across tenants but not within one; the app role cannot bypass RLS; no tenant or role leaks past a request. Disabling the role switch makes 6 of the 8 tests fail.

### Not done yet (follow-ups)
- `animal_number_seq` is still one global sequence, so farm numbers are unique but have gaps per tenant. Needs a per-tenant counter.
- Users belong to exactly one organization. D-037 wants memberships so a vet can serve several farms.
- Queue jobs, Reverb channels, cache keys, file paths and logs are not yet tenant-aware (the prototype uses none of them yet).
- Runtime uses `SET ROLE` on an owner/superuser connection. Production should connect as a login role without CREATE or superuser rights.
- History tables still allow UPDATE/DELETE for the app role (D-038 will tighten this).
- The role-picker sign-in is a prototype shim and must be replaced by real authentication.
