# D-266: RBAC Phase 2 (fixes from the D-261 audit)

Status: slices 1 and 2 built; slice 1 is live, slice 2 is **not deployed** (awaiting review). Slice 3 (Access Tools) is built, not deployed. The rest of step 5 (levels and scopes, field and notification registries, scope data, the other panel features) has not started.

## Slice 1 (live since 2026-11-14)
F-02 books (`manage_books`: Administrator, Finance) · F-03 staff, attendance, leave (`manage_staff`: Administrator, Manager) · F-04 end-of-life request (`request_end_of_life`: Administrator, Manager, Vet; Q8) · F-05 contain a pregnancy loss (vet or trained staff) · F-06 goat purchase request (`request_goat_purchase`: Administrator, Manager) · F-08 Settings is the Administrator's alone · F-16 vet steps follow `clinical` · F-26 malformed weight-review id is 404 · F-27 module check runs before record lookup · F-28 deploy excludes `storage/inertia-devtools/`.

## Slice 2 (this change)
- **F-01 write enforcement.** `config/kkfarm_actions.php` has one row for each of the 298 write routes. `App\Http\Middleware\EnforceAction` runs before route-model binding: a write route with no row, or a row the person does not satisfy, answers 403 and is logged. Row kinds: `module`, `gate` (any of), `open` (needs a reason), `inline` (the controller checks further), `why`. `tests/Feature/ActionRegistryTest.php` fails on a write route without a row, a row without a route, a malformed row, and an `inline` row whose action has no check. Generator: `docs/decisions/D-266/scripts/gen_actions.py`.
- **F-07 and the six unfinished probes.** 22 new actions (`config/kkfarm_action_gates.php`, labelled in the Access Rights screen). View-only roles can no longer write: finance and vet close milking sessions, feeding and caretaker open interventions or record feeding, caretaker proposes meat selections, vet records water rounds and sets up water points, finance writes shift handovers, feeding disarms tags or adds photos. Tests: `RbacEnforcementTest`.
- **F-10 (Q10).** `move_goats` = Administrator, Manager, Caretaker.
- **F-12.** Settled by S-1: `/movements` is one page; recording an exit is the right `exit_animal`. Finance's sale side is Sales. Test added.
- **F-13.** The feeding staff's Reports hub lists Feed offered, refused and wasted and Feeding history (no costs). Not checked in a browser.
- **F-14, F-15.** (The other session's later code, the goat day details and the field-records report, had copied the old hard-coded role list; both now use the rights too.) `see_goat_pedigree` (Administrator, Manager, Vet, Caretaker) and `see_health_history` (Administrator, Manager, Vet) replace hard-coded role lists on the goat page. The feeding staff get no parentage, offspring or day records.
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

## Slice 3: Access Tools (`/settings/access/tools`, Administrator only; linked from Access Rights)
- **History and undo.** Table `access_changes` (migration `2026_11_15_000100`, row-level security like every farm table). Every save on Access Rights by Role and on a person's Access rights writes one row per changed app, action or page: who, when, which role or person, old, new. A save is one batch. **Undo** puts a batch back and writes its own rows (the history only grows). Vet-locked actions and Settings are never touched by an undo. Class `App\Support\AccessChanges`.
- **Why?** Pick a person and an app, action or page: the steps are listed in the order they are applied (own tick, role setting, built-in default, locks, the app being off) and the one that decides is marked. The final answer is read from the real resolver, and a test checks that for 18 combinations. Class `App\Support\AccessTrace`.
- **Health checks.** Active Administrators (warns on one), people with no valid role, accounts that never signed in, vet-locked rights held by one person, page ticks older than 90 days (ticks for apps and actions carry no date, so those are not covered), people who differ from their role, apps no role can open, roles that open nothing, write routes with no rule, and the count of actions still decided by role names in the code.
- **Tests:** `AccessToolsTest` (5). **Not checked in a browser.** Not covered by a test: the History screen's Undo button and confirm box.

## Step 5b: role-name lists become actions
About 90 places decided by naming roles (`in_array($user->role, ['ceo', 'manager'])` and helpers such as `manager()`, `supervisor()`, `lead()`, `approver()`, `reviewer()`) now ask a right instead. Script and mapping: `docs/decisions/D-266/scripts/role_lists_to_actions.py`; the actions and their default roles: `docs/decisions/D-266/role_list_gates.json` (merged into `config/kkfarm_action_gates.php` by `gen_actions.py`). Each action's default roles are exactly the list it replaced, so nobody gains or loses anything today (the crawl baseline did not change). What changes is that the Administrator can now give or take each one for a role or a person, the history records it, and Why? explains it.
- **Separation of duties** ("someone other than the person who made it approves it") keeps its rule; the Administrator's exception is now the action `override_separation` instead of the role name.
- **A dead clause removed:** `SaleOrderController` asked for a right called `scan` that never existed (since D-194), so it never allowed anyone. Removed; who may load goats onto a sale is unchanged (Administrator, Manager, Finance). Whether floor staff should load goats is an open question for you.
- **Guard:** `ActionRegistryTest::test_every_right_the_code_asks_for_exists` fails if code asks for a right that does not exist.
- **Left on purpose:** display choices that only change a layout (the Finance home), animal roles (Lifecycle, Eligibility, Genetics) and the Fodder on/off flag.

## After D-285 (the other session split Movements into two pages)
Pen Movements had been opened to the feeding staff and Animal Exit kept the vet and the caretaker, against the spec (Animal Movements: Feeding —; Animal Exit: Vet and Caretaker —). Pen Movements is now Administrator, Manager, Vet, Caretaker and Animal Exit is Administrator, Manager, Finance (Finance still reaches the sale side through Sales, as it holds no Housing app). Recording an exit stays the action `exit_animal`.

## Step 5c: levels (Access Tools → Levels)
- **What it is.** For each role and app, one of None, View, Contribute, Edit, Manage. `config/kkfarm_levels.php` says which app each of 90 actions belongs to and the level it needs (generated once by `docs/decisions/D-266/scripts/gen_levels.py`, then edited by hand). A level is a way to tick many things at once and to read them back; the server still enforces apps, pages and actions exactly as before, so **nothing changed on day one** and the crawl baseline did not change.
- **Reading.** The level of a cell is the lowest level whose ticks equal what the role holds. Where the ticks match none (the defaults follow each job, not a ladder, so many cells do) the cell reads **Custom**, and hovering says which actions differ from the nearest level.
- **Picking.** Choosing a level shows what is gained and lost, how many active people follow the role and whose own ticks stay, then ticks exactly that level's actions in one history batch (note "Level: Contribute in Feeding"); Undo restores it (tested: the roles are exactly as before).
- **Left out of levels:** Settings; the Administrator; the three vet-locked actions; and the nine actions that are Administrator-only by default (they would make every role read Custom). Edit goats and Remove goats are among them (D-252): set them on the role screen.
- **Not done:** **scopes** (Own, Department, Store, All). Only tasks (own and department) and stores are scoped in the data today; "own pens" and "own team" need pen assignment per person and a team per manager first (your assignments). Levels for a single person (the person page still has plain ticks). Tests: `LevelsTest` (6).
