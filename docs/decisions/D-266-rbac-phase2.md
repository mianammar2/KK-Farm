# D-266: RBAC Phase 2 (fixes from the D-261 audit)

Status: slices 1 and 2 built; slice 1 is live, slice 2 is **not deployed** (awaiting review). Step 5 (levels and scopes, field and notification registries, the Access Rights panel) has not started.

## Slice 1 (live since 2026-11-14)
F-02 books (`manage_books`: Administrator, Finance) · F-03 staff, attendance, leave (`manage_staff`: Administrator, Manager) · F-04 end-of-life request (`request_end_of_life`: Administrator, Manager, Vet; Q8) · F-05 contain a pregnancy loss (vet or trained staff) · F-06 goat purchase request (`request_goat_purchase`: Administrator, Manager) · F-08 Settings is the Administrator's alone · F-16 vet steps follow `clinical` · F-26 malformed weight-review id is 404 · F-27 module check runs before record lookup · F-28 deploy excludes `storage/inertia-devtools/`.

## Slice 2 (this change)
- **F-01 write enforcement.** `config/kkfarm_actions.php` has one row for each of the 298 write routes. `App\Http\Middleware\EnforceAction` runs before route-model binding: a write route with no row, or a row the person does not satisfy, answers 403 and is logged. Row kinds: `module`, `gate` (any of), `open` (needs a reason), `inline` (the controller checks further), `why`. `tests/Feature/ActionRegistryTest.php` fails on a write route without a row, a row without a route, a malformed row, and an `inline` row whose action has no check. Generator: `docs/decisions/D-266/scripts/gen_actions.py`.
- **F-07 and the six unfinished probes.** 22 new actions (`config/kkfarm_action_gates.php`, labelled in the Access Rights screen). View-only roles can no longer write: finance and vet close milking sessions, feeding and caretaker open interventions or record feeding, caretaker proposes meat selections, vet records water rounds and sets up water points, finance writes shift handovers, feeding disarms tags or adds photos. Tests: `RbacEnforcementTest`.
- **F-10 (Q10).** `move_goats` = Administrator, Manager, Caretaker.
- **F-12.** Settled by S-1: `/movements` is one page; recording an exit is the right `exit_animal`. Finance's sale side is Sales. Test added.
- **F-13.** The feeding staff's Reports hub lists Feed offered, refused and wasted and Feeding history (no costs). Not checked in a browser.
- **F-14, F-15.** `see_goat_pedigree` (Administrator, Manager, Vet, Caretaker) and `see_health_history` (Administrator, Manager, Vet) replace hard-coded role lists on the goat page. The feeding staff get no parentage, offspring or day records.
- **F-17.** The Quality CAPA owner must belong to the farm business. **Row-Level Security on `users` is not done:** sign-in looks a person up before any farm business is bound, so a policy there needs its own design.
- **F-18.** Cleaning opens `/sensors?tab=alerts` only, sees air, temperature and water alarms, and receives no devices, readings or goats-out data.
- **F-19.** The vet no longer has Feed Pens or TMR Mixing Batches.
- **Step 3.** The crawl covers every route (made-up ids for records the demo farm lacks; refused roles answer 403 before the lookup). `SpecMatrixTest` asserts the page registry against the spec (155 pages, 5 differences, each with a reason in `tests/Feature/rbac/spec_exceptions.json`). `RbacOverrideTest` has the three per-person cases.

## Older tests changed because they encoded the old access
Caretaker no longer requests an end-of-life review, contains a loss untrained, raises or approves purchases (slice 1) · caretaker no longer opens growth interventions or proposes meat selections (the vet and Manager do; follow-up weights stay with the caretaker) · vet and feeding no longer move goats · the vet is no longer on Feed Pens; two page-access tests now use Feeding History · cleaning now holds the Sensors app (alarms tab) · water lab tests are recorded by Administrator, Manager and Vet.

## Decisions I made that you may want to change
1. **Caretakers still record parasite samples** (`record_parasite_samples`, with the vet deciding). The spec row says CTK —. I kept the working flow; closing it is one line in `gen_actions.py`.
2. **Caretakers can still open a welfare case** (a report of pain) and record transit checks on the road.
3. **Water lab tests** are Administrator, Manager, Vet only (the spec shows Cleaning and Caretaker without write rights).
4. **Presence marking** (`/presence`, goats out in an open area) now needs `move_goats`.

## Still hard-coded (the panel cannot change these yet)
About 40 actions still check a list of role names inside the controller (for example `in_array($request->user()->role, ['ceo', 'vet'])` on antimicrobials, feed lot limits, intake settings, heat-stress limit). The registry rows say `inline` for them. Replacing each with an action is part of step 5.
