# D-229: Unified Animal Classification and Lifecycle Automation (owner framework, 2026-11-12)

The owner's "KK Farms – Unified Animal Classification & Automated Lifecycle Management" framework replaces the
earlier age/teeth table (D-221). Principle: **farm workers record events; the system calculates classifications,
flags eligibility and recommends the next action; breeding approval, castration and sale release stay with an
authorised person.** (Matches the project rule: the system records and recommends, people and vets decide.)

## Six classification dimensions (separate fields, never one long dropdown)
| Dimension | Source |
| --- | --- |
| Age stage | Automatic from the birth date |
| Teeth (dental code) + dental condition | A recorded mouth check, never assumed from age |
| Sex | Registration; audited correction |
| Production purpose | Manager-approved assignment |
| Lifecycle status (breeding, lactation, fattening) | Verified events and rules; several can be active at once |
| Commercial status (sale) | Weight, health, withdrawal and approval checks |

## Phase 1: done in D-229
- Age stages: YKD 0–14 days, KID from day 15, GRW from day 60, ADT from 14 calendar months, MAT from 48 calendar
  months. Each stage lasts until the next starts (no overlaps or gaps). MAT is never "old" or "cull" by itself.
- Dental codes MLT, T02, T04, T06, T08 (local terms Kheeri, Donda, Choga / Char Dant, Chhay Dant, Aath Dant); no
  check = UNK. Dental condition (normal, worn, broken, missing, abnormal) is kept apart from the count.
- Combined display code from the two facts: GRW-T02, ADT-T04, MAT-T08 (age only when no check; a 13-month goat with
  2 teeth stays GRW-T02).
- Every teeth check is its own row in `dental_exams` (append-only); the goat keeps the latest as a cache.
- Applied to: registration, goat page (record a teeth check, history), goat list, scan, field work, sheds, dashboard
  (By Age, new By Teeth), age-wise and teeth reports, CSV export, care protocol targeting, feeding group codes.
- Migration: old teeth keys map to codes (milk→MLT, 2→T02 … worn→T08 + condition worn); the old 8 stages become the
  5 (ADT-T0x→ADT, ADT-WBT→MAT), including care protocols aimed at a stage.

## Next phases (planned, not built)
2. **Done in D-230. Production purpose and sex-wise labels**: BK, GB, RB, BB, PS, FM, WTH, CM / DK, GD, RD, BD, PD, DD, FF, CF as
   generated designations (several can apply, e.g. Breeding Doe + Proven Dam + Dairy Doe). Castration event →
   wether, blocks natural breeding.
3. **Done in D-231. Eligibility engine**: qualification ≠ approval. Per-breed configurable thresholds (minimum age, minimum weight,
   % of mature weight, body condition, health clearance, genetic approval). Meeting thresholds creates an approval
   task; only approval sets "Ready for Breeding". 2 teeth or 35 kg alone raise a Maturity Review alert only.
4. **Done in D-232. Lifecycle statuses and sale readiness**: independent breeding / lactation / fattening / health / sale statuses
   with a status history table (previous, current, time, rule). Target weight → "Market Weight Achieved", never
   "Sale Ready" by itself; sale clearance blocked by sickness or withdrawal.
5. **CEO dashboard KPI groups and UAT**: the framework's KPI groups (population, age, dental, breeding stock,
   eligibility, reproduction, dairy, fattening, commercial, health, productivity, profitability), overlapping
   statuses counted separately from the herd total; UC-01 … UC-15 as automated tests (UC-01, 02, 12, 13 done).
