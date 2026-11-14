# D-261: RBAC deep audit (Phase 1, read-only)

**Date:** 2026-11-14 · **Status:** for the owner's review. Phase 2 (fixes and the new Access Rights panel) is **D-262** and starts only after approval.
**Numbering:** the request named D-245/D-246, but those numbers were already used (3D shed camera, day/night). D-259 and D-260 are also taken (Goat Registry filters, death reports), so this audit is D-261.

**Audited code:** commit `49305d5` plus another session's **uncommitted** D-260 work (death reports) that was in the same checkout during the audit. **Spec:** `KK_FARMS_RBAC.md` §4–§10. **Prior work audited:** D-241 (RBAC), D-243 (editable page access), D-244 (Users page), D-252/D-255/D-256 (owner changes to health and field-job rights).
**Method:** scripted inventory of every route; a crawl of every signed-in route and every registry page as each of the 7 roles on the synthetic demo farm (local test database); real-data write probes for the top findings (throwaway test, not committed); page-data inspection per role on the local demo server; code reading. **Production was not touched.**

---

## 1. Executive summary

| Severity | Count |
|---|---:|
| Critical | 2 |
| High | 6 |
| Medium | 17 |
| Low | 5 |

**Overall verdict:** the D-241 work holds up for **reading**. Page-level checks on every GET work, menus match the server, health history is hidden from non-vets, the Reports hub and Inventory are filtered by role, the Executive Dashboard and CEO Scorecard locks hold, user management is Administrator-only with a last-Administrator guard, and every tenant table has Row-Level Security. **Writing is the weak side.** Page rules apply to GET only (`EnsurePage.php:15`). **134 of 294 write endpoints** check nothing more than "the person holds this app". Any role that holds an app for viewing (◐ or v in the spec) can therefore post into it. Probes proved this with real rows:

1. **F-02 Critical:** a **Farm Manager posts journal entries and adds accounts** to the books. The spec says view only, and the Journal page itself answers 403 to the Manager.
2. **F-01 Critical (root cause):** page rules are not applied to POST/PUT/PATCH/DELETE. 134 write routes depend on the app alone.
3. **F-04 High:** a **Caretaker (and the Manager) can open a humane end-of-life review**, a vet-locked area. The later steps are correctly vet-only.
4. **F-05 High:** **any role with Breeding can "contain" a pregnancy-loss case** (vet-locked page) by posting directly; that step has no role check.
5. **F-09 High:** in the other direction, **Caretakers and the Manager cannot report a sick goat** (no Health Check or Report concern job, no right). The first hop of the sick-goat handoff is broken. This comes from owner decision D-252 and conflicts with spec §5, §8.3 and acceptance test 6.

---

## 2. Coverage

| Surface | Found | Inspected | How |
|---|---:|---:|---|
| Routes (all) | 381 | 381 | `scripts/inventory.php` → `routes.json` (middleware, checks found in the action, page key, default decision per role) |
| Signed-in GET routes | 76 | 76 | crawl as 7 roles |
| Write routes | 294 | 294 static; **233 crawled**, 61 not crawled (no demo record for the `{id}`, see §2.1) | crawl with an empty body: 403 = refused, anything else = got past authorization |
| Public routes | 11 | 11 | code read |
| Page registry entries | 180 | 180 | crawled as 7 roles (`PAGE …` rows in the baseline) |
| Spec §8.3 rows | 233 | 233 | `scripts/spec_matrix.py` → `pages-matrix.md`, `pages-diff.json` |
| Modules / actions | 27 / 31 gates | all | `scripts/spec_modules_actions.py` → `modules-actions.md` |
| Notification types | 30 (`ActionCenter::ROUTES`) | 30 | code read against §10 |
| Field rules (§7) | 7 rows | 7 | page-data inspection per role (`goats/{id}`, `/health`, `/reports`, `/inventory`, `/housing`, `/employees`, `/purchase`, `/dairy`) |
| Write probes with real data | – | 8 confirmed (row counts), 6 more written but not completed (see §2.1) | throwaway test against the test database |

### 2.1 Not covered, and why

- **61 write routes with an `{id}` were not crawled** because the demo farm has no record of that type (fodder lots, quality cases/CAPAs, pregnancy-loss cases, end-of-life reviews, dairy lots, transport trips, maintenance assets, sensor alerts and others). Their verdicts are **static only** (code read).
- **View-only ("v") and scope ("s") cannot be told apart from full access by status code.** Where they matter, page data was inspected by hand (§5). Not every page was inspected that way.
- **Write probes:** 8 completed. The run then stopped at a wrong table name in the probe script (`milk_sessions`), so the dairy, growth, feeding, handover and parasite probes show **crawl evidence only** (handler reached with an empty body, and no further check in the code).
- **Inventory store scoping** was checked through the `stores` each role receives. The demo farm has **no store items**, so item-level scoping was not observed.
- **Production configuration** (`APP_ENV`, `SESSION_SECURE_COOKIE`, Inertia devtools state) was not read, because the rules allow production access only if the owner asks.
- **Browser walkthroughs:** journeys were run as scripted requests (crawl + page data), not by clicking through the UI as each person.
- **Moving target:** another session was changing `routes/web.php`, `Access.php`, `config/kkfarm.php` and `ActionCenter.php` (D-260) during the audit, and runs tests on the same test database. One crawl check-run failed because both test runs reset the database at once (§10).
- **Not inspected:** email/push channels (none exist), queued jobs and console commands acting for users (`routes/console.php` was listed but its jobs were not traced), and the RFID gun app's Android side.

---

## 3. Findings

Evidence keys: **probe** = real-data write as that role in the test database (rows before→after); **crawl** = status from `tests/Feature/rbac/baseline.json`; **page data** = Inertia props fetched as that role from the local demo server.

| ID | Sev | Role(s) | Area | Evidence | Impact | Recommended fix | Effort |
|---|---|---|---|---|---|---|---|
| F-01 | Critical | all | Write enforcement | `app/Http/Middleware/EnsurePage.php:15` returns early for non-GET. `routes.json`: 134 of 294 write routes have only `module:` middleware and no gate, role or page check in the action. | Any role holding an app (even view-only) can post into it. Root cause of F-02…F-08. | One resolver for writes: each write route gets an action key (level ≥ Contribute/Edit/Manage on its page). Middleware denies by default; CI test fails on a route without a key. | L |
| F-02 | Critical | Manager | Accounting | **probe:** `POST /finance/journals` as manager → 302, `journal_entries` 153→154; `POST /finance/accounts` → `accounts` 24→25. **crawl:** manager also reaches `/finance/entries`, `/entries/{id}/reverse`, `/entries/{id}/paid`, `/journals/{id}/reverse`, `/accounts/{id}`, `/finance/sales`. Yet `GET /finance/books?tab=journal` as manager → **403**. `BooksController.php:61,87` have no check. | The Manager can change the books (spec §4/§8.3: view summaries only, no posting). | Gate `post_accounting` (ADM, FIN). Check it on every `/finance/*` write. | S |
| F-03 | High | Finance | Employees | **probe:** `POST /employees` as finance → `employees` 11→12; `POST /hr/attendance` → `attendances` 0→1. **crawl:** finance reaches `/hr/attendance/all-present`, `/hr/leave`. `EmployeeController.php:29`, `HrController.php:54`. | Finance edits staff and attendance (spec: view; payroll only). | Gate `manage_staff` (ADM, MGR); Finance view only. | S |
| F-04 | High | Caretaker, Manager | Welfare 🔒 | **probe:** `POST /welfare/end-of-life` as caretaker → `end_of_life_reviews` 0→1, as manager 1→2. `EndOfLifeController.php:44` has no check. The page itself is vet-locked (`welfare.humane_end_of_life_review`). The assess/authorise/perform steps are role-checked (`:63,:75,:89`). | Non-vets open vet-locked reviews and create a high-priority vet task. A request rather than a decision. | Decide (Q8): who may *request* a review? Then gate it. Keep decisions vet-only. | S |
| F-05 | High | Caretaker, Manager | Pregnancy loss 🔒 | Static: `ReproLossController.php:50` `contain()` checks only the case status. `material`/`sample` use `ReproLoss::canHandle`; `diagnose`/`close` use `clinical`. The GET page needs `clinical`. Not crawled (no demo case). | Non-vets act on a vet-locked investigation by direct POST. | Require `clinical` (or `canHandle`) on `contain`. | S |
| F-06 | High | Vet, Finance | Goat purchase lots | **probe:** `POST /purchase/animals` as vet → `animal_purchase_lots` 0→1; as finance 1→2 (with a budget). `AnimalPurchaseController.php:94` has no check. **crawl:** both reach receive/reconcile/cancel (no demo lot to crawl). | The vet (spec: incoming-lot health check only) and Finance (spec: view) create purchase requests with money. | Gate `request_goat_purchase` (ADM, MGR). Vet gets a health-check action only. | S |
| F-07 | High | Vet, Caretaker, Feeding, Finance | View-only apps that accept writes | **crawl** (handler reached, no check in code): VET/FDE/CTK `POST /weights/review/{id}` (500, see F-26); VET/CTK/FDE `/growth/{animal}/intervene`; VET/CTK/FDE `/fattening/selections`; VET/CTK `/feeding/record`, `/feeding/leftover` (spec v); VET/FIN `/dairy/sessions` (FIN holds Dairy for revenue only); CTK `/parasites/samples` (spec —); CTK `/welfare/cases` (spec v); FIN `/operations/handovers` (spec —); FDE `/goats/{animal}/photos`, `/goats/rfid/disarm`; VET `/environment/settings`, `/intake/settings`, `/feeding/lots/limits`; VET `/housing/pens/{pen}/status`. | Each lets a role change records it should only read. | Same fix as F-01: each write gets an action key and level. | M |
| F-08 | Medium | Any role given Settings | Settings | `AccessController.php:101-102` keeps any role ticked for `settings`; `:144-147` lets the admin tick Settings for any non-admin person. Settings writes (`/settings/breeds…`, `/ages`, `/stages`, `/teeth`, `/automation/*`, `/fields`) check only `module:settings`; `Masters` types `department` and `field` (gate `null`). The Settings GET pages are `lock: admin` (403). User and access management stays hard-coded to `role === 'ceo'` (`UserController.php:39`, `AccessController.php:27`), so **no account takeover**. | A person given Settings can't open the pages but can post config changes. Inconsistent, and the spec locks Settings to the Administrator. | Settings module and every `/settings/*` write locked to the Administrator (12.9). The panel shows a lock. | S |
| F-09 | High | Caretaker, Manager | Health reporting (journey break) | `config/kkfarm.php` gates: `report_concern` = ADM, VET; `health_checkup` = ADM, VET (D-252/D-256). **page data:** `/worker` jobs for caretaker = heat, lookup, mating, milk, move, weigh (no `health`, no `temp`); manager the same. | The spec's sick-goat flow starts with the caretaker's Health Check / Report concern (§5, §8.3, test 6). Today only the vet or admin can start it. | Owner decision (Q7/Q9). Recommended: give CTK and MGR `report_concern` (report only, no diagnosis). Same for FDE/CLN through a minimal "Report a concern". | S |
| F-10 | Medium | Feeding, Vet | Moving goats | `move_goats` = ADM, MGR, VET, CTK, FDE. Feeding gets the "Move to a Pen" job. Spec Housing → Animal Movements: FDE —, VET v. | Feeding staff move goats between pens. | `move_goats` = ADM, MGR, CTK (owner to confirm). | S |
| F-11 | Medium | Manager | Activity Log | `ActivityController.php:20-21`: anyone with Finance sees all entries except `security` ones. The Manager holds Finance, so sees every person's and every finance entry. Spec: MGR "own team". `?pages=` is unbounded (`:25`). | Over-broad audit visibility. "Own team" has no data behind it (F-27). | Scope by team (after F-27) or by department, and keep finance entries off the Manager. Cap `pages`. | M |
| F-12 | Medium | Vet, Caretaker / Finance | Animal Exit `/movements` | **crawl:** VET 200, CTK 200 (spec —); FIN **403** (spec s: sale/payment side). | Exits open to the wrong roles; Finance can't see the sale side it needs. | Split per tab: moves (CTK) vs exits (ADM, MGR; FIN sale side). | M |
| F-13 | Medium | Feeding | Reports | **page data:** feeding `/reports` → `allowed: []`. The hub opens but empty. Spec FDE s: feeding usage/waste reports, no costs. | Dead end. | Add feeding usage/waste report sections for FDE (no costs). | M |
| F-14 | Medium | Feeding, Caretaker | Goat detail fields | **page data:** feeding/caretaker `/goats/{id}` includes `goat.sire`, `goat.dam`, `goat.origin`, `goat.birthDate`, `goat.repro`, `offspring[]`, `records[]` (weights, BCS, temperature). Spec §8.3: FDE "tag, pen and feeding group only"; CTK "tag, name, pen, group, basic status". Health history and cost are correctly hidden (`GoatController.php:343,384`). | Pedigree and measurements reach roles meant to see a minimal card. | Field registry (Phase 2) on the server: Identity and pedigree group Hidden for FDE. Decide for CTK. | M |
| F-15 | Medium | all | Hard-coded field rules | `GoatController.php:323,343`: `$seeHealth = in_array(role, ['ceo','manager','vet']) || module_access['health']`. This bypasses role/page settings. A role given Veterinary in the panel still sees no health; a person given only the health *page* doesn't either. | Panel changes don't reach field visibility (§2.9 single source). | Field rules come from the resolver. | M |
| F-16 | Medium | Vet (per-person grants) | Vet-locked steps | `EndOfLifeController.php:63,89` check `role === 'vet'`; `:75` `in_array(role, ['ceo','manager'])`. The registry's `vet` lock lets the admin grant a person (§11), but these steps ignore it. | Per-person vet grants silently don't work there. | Use the resolver (action keys) instead of role names. | S |
| F-17 | Medium | all (multi-tenant) | Tenant isolation | `users` is the only tenant-owned table without RLS (`pg_policies`, local DB). `QualityController.php:247` validates `owner_id` with `exists:users,id` (no org filter); `:256` `User::findOrFail` echoes `$owner->name` in the success message. `TaskController.php:57` filters by org correctly. | A user id from another farm business is accepted, and its name is shown. Only one tenant today. | RLS on `users` (allowing the sign-in lookup) and an org filter on that rule. | M |
| F-18 | Medium | Cleaning | Sensor alarms | **crawl:** cleaning `/sensors?tab=alerts` → 403. Spec Environment → Sensor Alarms: CLN s (water-point alarms). Cleaning has no `sensors` module. | Under-access to water alarms. | Water-point alarms on Environment for CLN (scoped). | M |
| F-19 | Medium | Vet | Feeding pages | **crawl:** vet opens `/feeding?tab=now` (Feed Pens, spec —) and `/feeding?tab=tmr` (TMR Mixing Batches, spec —). Registry defaults include `vet`. | Minor over-access (read). | Remove vet from those two registry rows. | S |
| F-20 | Medium | all | Model | The registry (`config/kkfarm_pages.php`) stores only open/closed per page. The spec's v (view only) and s (scoped) live in scattered gates and controllers. | That is why F-02…F-07 happen: "can open" is read as "can do". | Phase 2 levels (None/View/Contribute/Edit/Manage) and scopes per page and action. | L |
| F-21 | Medium | all | Notifications (§10) | `ActionCenter::ROUTES` (30 types). **Missing types:** cleaning due / cleaning supplies low, weight due / weight-loss alert, milk anomaly, maintenance reported/resolved (reporter), PO approval, sale made, payroll/expense approval, goat sold/left farm, new user / role change / security, weekly digest. Caretaker "own pens" is not scoped (sent to all caretakers). In-app only: the bell is computed live, with no stored notifications, read state, email or push. | Roles miss signals they need (Cleaning gets only task reminders). | Notification registry with type, module, department, roles and scope (Phase 2, 12.2.4). | L |
| F-22 | Medium | Caretaker, Feeding, Cleaning, Manager | Scope data ("own") | Tasks: `Task::visibleTo` (own user or department) ✔. Stores: `/inventory` sends `stores` = medicine (vet) / store (cleaning) / all (finance) ✔. **No data** for "own pens" (no pen assignment per person) or "own team" (no team per manager). Incidents "own reports" not verified. | §9/§10 "own pens" and "own team" rules can't be enforced. | Add pen assignment per person and team per manager (Q3). | M |
| F-23 | Medium | Finance | Inventory tabs | **crawl:** finance `/feeding?tab=store` and `/health?tab=store` → 403 (spec v valuation). Finance does get all three stores on `/inventory`, so valuation is reachable there. | Inconsistent; spec rows are confusing. | Keep `/inventory` as Finance's valuation view. Correct the spec rows (spec issue S-3). | S |
| F-24 | Medium | Manager | Notification money text | `ActionCenter.php:215`: "unpaid" items show counterparty and PKR amount to the Manager. Spec §10 allows MGR for invoices. Listed so the owner can confirm Q1. | Depends on Q1. | Follow Q1. | S |
| F-25 | Medium | Manager, Vet | Spec/registry gaps on shared pages | `pages-diff.json`: 55 spec rows with no registry key (mostly sub-labels of one page: "Daily Feeding", "Lactations"…); 25 registry rows with no spec row (opening pages, App dashboards, report sections). Shared pages get contradictory spec marks (S-1…S-4). | The panel can't show a per-row decision for these. | Registry rows per tab/section with "Also appears in" (12.2.1). | M |
| F-26 | Low | Admin…Feeding | Broken handler | **crawl:** `POST /weights/review/{record}` with an empty body → **500** for every role that reaches it. | Server error instead of a validation message. | Validate input first. | S |
| F-27 | Low | all | Existence oracle | Route-model binding runs before `module:` middleware: e.g. `DELETE /settings/automation/codes/{code}` → 404 for every role on a missing id, while an existing id gives non-admins 403. | Reveals whether a record id exists. | Run permission middleware before bindings, or return 403 first. | S |
| F-28 | Low | – | Deploy hygiene | Local `storage/inertia-devtools/` holds 837 recorded page payloads (git-ignored). The rsync deploy command used so far does not exclude it, so a deploy would copy them to the server. Devtools is off unless `APP_ENV=local` (`vendor/inertiajs/inertia-laravel/src/DevTools/DevTools.php:18-21`), so they are not served. | Demo data copied to the server disk. | Add `--exclude='storage/inertia-devtools/'` to the deploy. | S |
| F-29 | Low | Manager, Feeding, Caretaker | Fodder | **crawl:** all fodder pages 403 for MGR/FDE/CTK because the Fodder app is off by default (`Fodder::enabled()`, `User.php:20`). | Expected while the app is off. | None (the owner turns it on). Listed for completeness. | – |
| F-30 | Low | – | Public routes | `/_inertia/devtools/*` and `/_boost/browser-logs` are public routes **locally**. Boost is `require-dev` and the Docker image uses `--no-dev`, so not shipped. Devtools is enabled only when `APP_ENV=local`. | None in production if `APP_ENV=production` (not verified, §2.1). | Confirm `APP_ENV` on the server (read-only, with the owner's OK). | S |

### Verified as working (no finding)

- Executive Dashboard: only ADM/MGR (others are redirected to `/home`). CEO Scorecard: only ADM. Settings pages: only ADM (crawl).
- Finance is blocked from `/goats`, `/health`, `/feeding`, `/fodder`, `/housing`, `/breeding`, `/scan` (crawl, and `RbacTest`).
- `/health` sends case, medicine and withdrawal data only for the vet's tabs. A caretaker on `/health?tab=checkups` receives `cases: []`, `medicines: []`, `medicineMoves: []` (page data).
- Goat detail hides health history, cases and cost from CTK/FDE (`health: []`, `cost: null`). Only withdrawal *dates* show (`Withdrawal::summary`).
- Reports hub sends data only for allowed sections (vet: herd/age/breeds/sires/exits, no costs; finance: exits/spending; feeding: none, see F-13).
- Cleaning's `/housing` shows pen counts and cleaning status, with no goat identities.
- User management, access rights, password reset, sign-out and bulk actions: Administrator only (`role === 'ceo'`). Last active Administrator and self-demotion guards (`UserController.php:213-222`).
- Disabled account: signed out on its next request (`EnsureActiveAccount.php:18`), and sessions are deleted and the remember token rotated on disable/reset (`UserController.php:301-305`).
- Login rate limit 5 per minute per login+IP. Temporary password with forced change. Seeded `@kkfarms.local` users get random 40-character passwords (`DatabaseSeeder.php:27`). Demo role picker only when `demo_login` and local/testing.
- Device keys are stored as SHA-256 and looked up through `device_for_key()` (`Sensors.php:58`).
- Refused requests are logged as `security` entries, visible only to the Administrator (`bootstrap/app.php`, `ActivityController.php:21`).
- Tasks: list, bell and status changes limited to own user or department (`TaskController.php:23,88`, `ActionCenter.php:218`).
- RLS on every tenant table except `users` (F-17).

---

## 4. Generated matrices

- **Pages × roles:** [`D-261/pages-matrix.md`](D-261/pages-matrix.md) (233 spec rows, joined to registry keys and crawl results). Machine-readable diffs: [`D-261/pages-diff.json`](D-261/pages-diff.json) (40 cell differences: 26 over, 14 under, many from shared pages, see §7).
- **Modules and actions:** [`D-261/modules-actions.md`](D-261/modules-actions.md). 1 module difference (Feed true cost open to FDE for the stock-count tab, a deliberate D-241 choice). 6 action differences, all from owner decisions D-252/D-256:
  - Open health cases, CTK "report sick only": spec ✔, code — (F-09)
  - Routine health checkups, MGR and CTK: spec ✔, code — (F-09)
  - Edit goat details, MGR and VET (health fields): spec ✔, code — (D-252: Administrator only)
  - Remove or restore goats, MGR: spec ✔, code — (D-252)
  - Code gates with no spec row: `health_entry`, `report_concern`, `record_heat`, `record_mating`, `give_care`, `move_goats` (F-10), `report_death`, `approve_death`, `cull_breeding`, `record_weights`.
- **Routes:** [`D-261/routes.json`](D-261/routes.json) (381 routes with checks and per-role defaults).
- **Crawl results:** `apps/kk-farm/tests/Feature/rbac/baseline.json` (428 targets × 7 roles).
- **Fields (§7), from page data:**

| §7 data | Spec | Observed | Verdict |
|---|---|---|---|
| Health history, diagnosis, treatment, medicine | VET full; ADM/MGR view; CTK status flag | goat detail and `/health` hide them from CTK/FDE; hard-coded role list (F-15) | OK, but rule in code |
| Purchase price, goat value, margins, herd value | ADM, FIN; MGR view | goat `cost` only with Finance module (ADM, MGR, FIN); `/value` FIN 200, MGR 200 | OK (MGR view not enforced as view-only: F-20) |
| Salaries / payroll | ADM, FIN | no salary fields found in `/employees` or `/hr` for any role (no payroll data in the app yet) | N/A today |
| Supplier pricing & PO totals | ADM, MGR, FIN | FDE/VET `/purchase` receive `suppliers` (empty on the demo farm) | **Not verified** with data; risk |
| Device keys / API keys | ADM | manager `/sensors?tab=devices`: `newKey: null`; Connect a Device is admin-locked | OK |
| User accounts, passwords, role settings | ADM | `/settings/*` admin-locked; user APIs `role === 'ceo'` | OK (F-08 for config writes) |
| Stock quantities by store | per store | `/inventory` `stores` scoped per role | OK (no item data to confirm) |

---

## 5. Role journeys

Results come from the crawl (status per role) and page data. "Write" steps were checked with an empty body (reaches handler) or a probe. ✔ OK · ⬆ OVER-ACCESS · ⬇ UNDER-ACCESS · ⚠ LEAK · ✖ BROKEN.

### Administrator
| Step | Route | Result | Verdict |
|---|---|---|---|
| Executive Dashboard / CEO Scorecard | `/`, `/dashboard/scorecard` | 200 / 200 | ✔ |
| Users: create, role, reset, disable | `/settings/users` (+POST) | 200, writes reach | ✔ |
| Edit access rights | `/settings/access` | 200, POST reaches | ✔ (panel gaps §9) |
| Activity Log | `/activity` | 200 (includes security entries) | ✔ |
| Approve feeding plan / register goat | `/feeding?tab=rations`, `/goats/register` | 200 / 200 | ✔ |
| Finance, sales, reports | `/finance`, `/sales`, `/reports` | 200 | ✔ |
| Report a sick goat / health round | `/worker` jobs | has `health`, `temp` | ✔ |

### Farm Manager
| Step | Route | Result | Verdict |
|---|---|---|---|
| Executive Dashboard, Alerts | `/`, `/actions` | 200, 200 | ✔ |
| Assign tasks | `POST /tasks` | reaches | ✔ |
| Approve feeding plans / register goat / intake | rations, register, intake | 200 | ✔ |
| Move goats, review held weights | `/movements`, `/weights/review` | 200 | ✔ |
| Purchase orders, quotes, sale orders | `/purchase`, `/sales/orders` | 200 | ✔ |
| Finance summaries | `/finance` 200; journal page 403 | | ✔ read |
| **Post journals, add accounts** | `POST /finance/journals`, `/finance/accounts` | **rows written** | ⬆ F-02 |
| Attendance, leave, roster | `/hr?tab=…` | 200 | ✔ |
| Maintenance jobs, incidents | `/maintenance?tab=jobs`, `/operations?tab=incidents` | 200 | ✔ |
| Activity Log (own team) | `/activity` | everyone's entries | ⬆ F-11 |
| **Report a sick goat / health check** | `/worker` | no `health`/`temp` job | ⬇ F-09 |
| Request a humane end-of-life review | `POST /welfare/end-of-life` | row written | ⬆ F-04 (owner to decide) |
| CEO Scorecard, Settings | | 403, 403 | ✔ |

### Veterinarian
| Step | Route | Result | Verdict |
|---|---|---|---|
| My Home | `/home` | 200 | ✔ |
| Sick & under watch, triage, cases | `/goats?show=attention`, `/disease?tab=triage`, `/health?tab=cases` | 200 | ✔ |
| Medicine store, vaccination due, quarantine | `/health?tab=store`, `?tab=calendar`, `/intake` | 200 | ✔ |
| Withdrawal, breeding eligibility, pregnancy, mortality, end-of-life | | 200 | ✔ |
| Medicine purchase request | `/purchase` | 200 | ✔ (supplier prices not verified with data) |
| Health reports | `/reports` | herd/age/breeds/sires/exits, no costs | ✔ |
| Feed Pens, TMR batches (spec —) | `/feeding?tab=now`, `?tab=tmr` | 200 | ⬆ F-19 |
| Record feeding, close milking session, create goat purchase lot | POST | reach / row written | ⬆ F-07, F-06 |
| Finance, sales | | 403 | ✔ |

### Caretaker
| Step | Route | Result | Verdict |
|---|---|---|---|
| My Home, Tasks hub, RFID scan | `/home`, `/worker`, `/scan` | 200 | ✔ |
| Weigh, move, look up, heat, mating, milk | `/worker` jobs | present | ✔ |
| **Health check / report a concern** | `/worker` jobs `health`, `temp` | **absent** | ⬇ F-09 |
| Kidding, newborns, kid care, weaning | `/breeding?tab=…`, `/goats?show=newborn` | 200 | ✔ |
| General store, shed rounds, report a fault | `/inventory?tab=general`, `/environment?tab=sheds`, `/maintenance?tab=jobs` | 200 | ✔ |
| Tasks, shift handover | `/tasks?tab=mine`, `/operations?tab=handover` | 200 | ✔ |
| Animal exits (spec —) | `/movements` | 200 | ⬆ F-12 |
| End-of-life review, pregnancy-loss containment, parasite samples, welfare cases | POST | row / static / reaches | ⬆ F-04, F-05, F-07 |
| Medical cases, finance | | 403 | ✔ |

### Feeding Employee
| Step | Route | Result | Verdict |
|---|---|---|---|
| My Home, Feed Pens, history, TMR, feed store, lots, stock count | `/home`, `/feeding?tab=…`, `/feeding/lots`, `/feed-cost?tab=stock` | 200 | ✔ |
| Fodder harvest lots | `/fodder?tab=lots` | 403 (app off) | – F-29 |
| Draft rations | `/feeding?tab=rations` | 200 | ✔ |
| Feed purchase request, tasks, report a fault | `/purchase`, `/tasks`, `/maintenance` | 200 | ✔ |
| Feeding reports | `/reports` | hub empty | ⬇ F-13 |
| Goat detail | `/goats/{id}` | pedigree, origin, measurements | ⚠ F-14 |
| Move goats | `/worker` `move` | present | ⬆ F-10 |
| Growth interventions, goat photos, RFID disarm | POST | reach | ⬆ F-07 |
| Health, finance | | 403 | ✔ |

### Cleaning Employee
| Step | Route | Result | Verdict |
|---|---|---|---|
| My Home, My Tasks | `/home`, `/tasks?tab=mine` | 200 | ✔ |
| Pen cleaning status (counts only) | `/housing` | 200, no goat identities | ✔ |
| Shed rounds, water, waste | `/environment?tab=sheds`, `/waste` | 200 | ✔ |
| Cleaning supplies | `/inventory?tab=general` | 200, store = general | ✔ |
| Report a fault / incident | `/maintenance?tab=jobs`, `/operations?tab=incidents` | 200 | ✔ |
| Water alarms | `/sensors?tab=alerts` | 403 | ⬇ F-18 |
| Pen restriction flag (no diagnosis) | `/housing` `status`/`reason` | present | ✔ |
| Cleaning notifications | bell | tasks only | ⬇ F-21 |
| Goats, health, finance | | 403 | ✔ |

### Finance Employee
| Step | Route | Result | Verdict |
|---|---|---|---|
| My Home, invoices, sale orders | `/home`, `/sales/orders?tab=done`, `/sales/orders` | 200 | ✔ |
| POs, suppliers, goat purchase lots | `/purchase`, `?tab=suppliers`, `/purchase/animals` | 200 | ✔ |
| Journal, ledger, trial balance, P&L, balance sheet | `/finance/books?tab=…` | 200 | ✔ |
| Herd value, attendance report, feed true cost, milk invoices | | 200 | ✔ |
| Sale side of exits | `/movements` | 403 | ⬇ F-12 |
| Add employees, mark attendance, close milking sessions, create purchase lots, shift handover | POST | rows written / reach | ⬆ F-03, F-06, F-07 |
| Goats, health, feeding | | 403 | ✔ |

### Cross-role handoffs

| # | Handoff | Hop that fails or leaks | Verdict |
|---|---|---|---|
| 1 | Sick goat | **Hop 1:** the caretaker can't report (F-09). After that: the vet is notified (`concerns`, `open_cases`), triage and case are vet-only ✔, the withdrawal date is computed from treatments ✔, Sale Readiness is FIN v ✔, Finance sees no health data ✔. | ✖ broken at hop 1 |
| 2 | Goat purchase | Manager request ✔. **The vet and Finance can also create lots** (F-06). Intake/quarantine vet ✔. Register goat ADM/MGR ✔. Caretaker assigns pen ✔. Finance herd value ✔. | ⬆ |
| 3 | Feed supply | Feeding request (`/purchase` s) ✔, PO (ADM/MGR/FIN) ✔, lot receipt FDE ✔, TMR ✔, daily feeding ✔ (**vet/caretaker can also write**, F-07), feed cost FIN ✔. | ⬆ |
| 4 | Sale / exit | Quote and order ADM/MGR/FIN ✔. Vet clearance in Withdrawal ✔. Exit page open to VET/CTK and **refused to FIN** (F-12). Invoice FIN ✔. Herd count derives from movements ✔. | ⬆/⬇ |
| 5 | Breeding and kidding | Heat CTK ✔, eligibility vet-locked ✔ (`approve_breeding`), mating ✔, pregnancy ✔, kidding/newborn/weaning CTK ✔. | ✔ |
| 6 | Maintenance fault | Any reporter can file (`POST /maintenance/jobs`: CTK/FDE/CLN reach) ✔. Closing is manager-only (`MaintenanceController.php:102`) ✔. **No "reported/resolved" notification** to the reporter (F-21). Cost on the history tab is ADM/MGR ✔. | ⬇ (notification) |
| 7 | Tasks | Manager creates a department task ✔, members see it (`visibleTo`) ✔, completion is limited to own/department ✔, the bell counts only own/department overdue tasks ✔. Finance gets no other department's tasks ✔. | ✔ |
| 8 | Milk | Caretaker records (`record_milk`) ✔, lot QA ADM/MGR (CTK v) ✔, orders FIN v ✔, invoices FIN ✔. **Finance and the vet can close milking sessions** (F-07). | ⬆ |

**"s" (scoped) meanings, and the data behind them:** own tasks = `tasks.user_id` or `tasks.department_id = users.department_id` ✔ · own department = `users.department_id` (defaults by role, `Department::forRole`) ✔ · own store = `Stores` by role (medicine/feed/general) ✔ · own reports (maintenance, incidents) = reporter name match (not verified for incidents) · **own pens: no data** · **own team: no data** (F-22).

---

## 6. Hierarchy and escalation

**Actual (code):**
```
Administrator (role = ceo)
 ├─ only role that can: manage users (create, role, reset, sign out, disable), edit access rights,
 │  open /settings/*, CEO Scorecard        [hard-coded role === 'ceo']
 ├─ can grant any app (including Settings) to any role or person, and vet-locked actions to one person
 └─ everyone else is flat: no one manages anyone; differences come only from apps/actions/pages
Vet-locked actions: clinical, open_cases, approve_breeding (role screen cannot change; per-person by admin only)
```
**Intended:** Administrator > Farm Manager > (Vet, Caretaker, Feeding, Cleaning, Finance); vet-locked outside the hierarchy. **Gap:** the Manager has no delegated rights over staff (the spec's default is "no", Q4). The admin can give non-admins Settings writes (F-08).

| Test | Result |
|---|---|
| (a) Grant self or others more | **Refused** for non-admins: `AccessController::owner()` (`role === 'ceo'`) on both saves. |
| (b) Edit own role or department | Non-admins: no route reaches user edit (403). The admin can't demote or disable themself (`guard()`). |
| (c) Change another's role or password | Admin only. |
| (d) Call settings/user APIs directly | User and access APIs: 403 for non-admins (crawl). **Settings config writes accept anyone given the Settings module** (F-08). |
| (e) Mass-assign `role`, `org_id`, `active` | `User::$guarded = []`, but every write builds an explicit array from validated input (`UserController.php:202-205, 253-269`). No path found. |
| (f) Demote or disable the last Administrator | Refused (`UserController.php:219-221`, also in bulk). |
| (g) Bypass vet-locked actions via a non-admin override | Not possible (overrides are admin-only). **But** vet-locked *pages* are bypassed by direct POST on end-of-life request (F-04) and pregnancy-loss containment (F-05). |
| IDOR, same farm | Tasks ✔ scoped. Most records are open to anyone holding the app (no per-record ownership model beyond tasks). |
| IDOR, other farm | RLS on all tenant tables blocks other-farm records (404). Exception: `users` (F-17). |
| Default deny | A page missing from the registry → Administrator only (`Pages.php:62-69`) ✔. **A write route with no gate → anyone with the app** ✖ (F-01). A new role (not in config) → no modules ✔. |
| Immediacy | Rights resolve from the database on every request (`Tenant::memo` per request), so role, page and app changes apply on the next request ✔. Disable: next request signs out, sessions deleted ✔. The RFID gun uses the same web session ✔. |
| Consistency | Same page, same decision through the registry ✔. **GET vs POST on the same area disagree** (journal page 403, posting allowed, F-02; Settings, F-08). |

---

## 7. Spec issues and owner decisions

Each item has a recommended default.

| # | Question | Recommended default |
|---|---|---|
| Q1 | May the Manager see financial summaries and payroll? | Summaries view-only (dashboard money, P&L, balance sheet), **no payroll, no posting** (fix F-02). |
| Q2 | May Finance see individual goats for sales? | Tag number and price only, through Sales (not Livestock). |
| Q3 | How are "own pens" and "own team" defined? | Add **pen assignment per person** (Caretaker, Feeding, Cleaning) and a **team per manager**. Until then, "own pens" = all pens in the person's farm, and say so in the panel. |
| Q4 | Delegated user management? | No. |
| Q5 | Custom roles now? | Build them, but hide them behind a switch until the panel is stable. |
| Q6 | Masked as well as Hidden fields? | Both available; Hidden is the default for Health and Payroll. |
| Q7 | Can Feeding and Cleaning report a sick goat or hazard? | Yes: one "Report a concern" action (no Veterinary app, no diagnosis). |
| Q8 | Who may *request* a humane end-of-life review (F-04)? | Vet and Manager request; the vet assesses; the Administrator authorises; the vet performs. The Caretaker reports a concern instead. |
| Q9 | D-252/D-256 took Health Check and Report concern from Caretaker and Manager. The spec gives both to them. Which wins? | Give `report_concern` back to CTK and MGR (report only); keep `health_checkup` (examination) with Vet/Admin as D-252 chose. |
| Q10 | Should Feeding move goats (`move_goats`, F-10)? | No: ADM, MGR, CTK (Vet on request). |
| S-1 | Shared pages with conflicting spec marks: Herd Value (Sales FIN ● vs Fattening FIN —), Incident Register (Operations s vs Quality —), Departments & Staff (Employees FIN v vs Operations FIN —), Maternity Unit `/sheds` (Housing FDE v vs Maternity FDE —) | Most generous row wins for the page; tab and field rules narrow it. |
| S-2 | "Executive Dashboard" and "CEO Dashboard link" both point to `/` | One row (ADM, MGR). |
| S-3 | Finance "v" on Feed and Medicine Inventory tabs vs "valuation only" | Finance uses `/inventory` (valuation). Drop the tab rows for FIN. |
| S-4 | Spec has no rows for code gates `record_heat`, `record_mating`, `give_care`, `move_goats`, `report_death`, `approve_death`, `cull_breeding` | Add them to §5 with the code defaults (after Q10). |

---

## 8. Panel gaps (feeds Phase 2)

What the Administrator can and cannot control today (`/settings/access`, Users → person → Access rights):

- **Can:** tick apps per role and per person; tick 23 actions per role and per person (vet-locked per person only); tick each page per role and per person (D-243); see a role change's app gain/loss (D-241); see the number of own ticks per person.
- **Only in code:** levels (view vs do) and scopes; field visibility (hard-coded role lists, F-15); notification routing (`ActionCenter::ROUTES`); vet-locked decisions by role name (F-16); write routes (F-01); role homes (`Pages::home`); which page belongs to which menu.
- **Unsafe controls:** Settings can be ticked for any role or person (F-08). Nothing warns when a page is opened for a role whose actions don't match (e.g. Journal for the Manager).
- **Missing:** impact preview, draft/publish, history and rollback (changes are logged as text only), compare, view-as, templates, import/export, health checks, temporary grants with expiry, an explanation of a decision ("Why?"), a lock icon on locked rows, a hierarchy view, custom roles.
- **Confusing:** "Tasks & RFID scan" is the module label for `scan`, but "Operations & tasks" is `tasks`. Pages show internal groups but no "also appears in" for shared pages. Module and page ticks interact (a page tick does nothing while its app is off) with no explanation.
- **Lock-out risk:** the Administrator can't lose Settings (`Access.php:74`, `AccessController.php:102,145`) ✔. No way found to lock out the last Administrator ✔.

---

## 9. Proposed Phase 2 plan (by risk)

1. **Write enforcement (F-01, F-02, F-03, F-05, F-06, F-07):** an action key on every write route, deny by default, a CI test that fails on a route without a key, and per-finding tests that fail before and pass after.
2. **Vet-locked and Settings locks (F-04, F-08, F-16):** lock Settings to the Administrator; use the resolver for vet-locked steps.
3. **Owner decisions Q1–Q10:** apply F-09, F-10, F-12, F-13, F-18, F-19 as decided.
4. **Field registry on the server (F-14, F-15) and tenant fix (F-17).**
5. **Levels and scopes model (F-20), with migration** from today's ticks so behaviour does not change on day one.
6. **Panel upgrade (§8), notification registry (F-21), scope data (F-22).**
7. **Low items (F-26…F-30).**

---

## 10. Scripts and tests

| File | What it does | How to run (from `apps/kk-farm`) |
|---|---|---|
| `docs/decisions/D-261/scripts/inventory.php` | Route inventory with checks and per-role defaults (read-only) | `php ../../docs/decisions/D-261/scripts/inventory.php > ../../docs/decisions/D-261/routes.json` |
| `docs/decisions/D-261/scripts/spec_matrix.py` | Spec §8.3 × registry × crawl → page matrix and diff | from the repo root: `python3 docs/decisions/D-261/scripts/spec_matrix.py /Users/MAC/goat/KK_FARMS_RBAC.md` |
| `docs/decisions/D-261/scripts/spec_modules_actions.py` | Spec §4/§5 × config → module and action matrix | from the repo root: `python3 docs/decisions/D-261/scripts/spec_modules_actions.py /Users/MAC/goat/KK_FARMS_RBAC.md` |
| `apps/kk-farm/tests/Feature/RbacAuditCrawlTest.php` | Crawl of every signed-in route and registry page as 7 roles, compared with the baseline; fails on any change | `RBAC_AUDIT=1 php artisan test tests/Feature/RbacAuditCrawlTest.php` (about 4 minutes; opt-in so everyday runs stay fast; use `DB_DATABASE=kkfarm_test_rbac` to avoid clashing with other sessions) |
| `apps/kk-farm/tests/Feature/rbac/baseline.json` | Today's behaviour, known findings included | regenerate on purpose: `RBAC_AUDIT=1 RBAC_AUDIT_WRITE=1 php artisan test tests/Feature/RbacAuditCrawlTest.php` |

**Limits of the crawl test (to fix in Phase 2):**
- It records **today's** behaviour (findings included), so it catches drift but doesn't yet assert the spec. Phase 2 switches it to the spec-correct matrix as findings close.
- It doesn't yet include the 3 per-person override cases asked for. The API-only negative tests per restricted endpoint come with each Phase 2 fix.
- The test database `kkfarm_test` is shared by every session in this checkout. **Two test runs at the same time break each other** (one check-run failed this way during the audit).
- **Last check-run (separate database, 2026-11-14):** it failed on exactly 14 cells, all from routes the other session added after the baseline was written: `POST /goats/{animal}/cull` (ADM and VET reach it, others 403) and `POST /goats/{animal}/death` (VET only). That is the drift detection working. **Regenerate the baseline once D-260 is committed.**
