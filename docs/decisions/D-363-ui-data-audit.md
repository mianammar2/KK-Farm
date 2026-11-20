# D-363: UI, UX and front-end data audit (20/11/2026)

## How it was audited
- **Every page in the page registry** (about 200 pages and tabs), plus goat profile, goat edit, users and settings pages, loaded in frames at **phone (375/390 px), tablet (768 px) and desktop (1280 px)** widths as the Administrator. On each page the auditor checked:
  - content wider than the screen
  - text crushed into a narrow column
  - "NaN", "undefined", "null", "Invalid Date"
  - dates with month or weekday names
  - lowercase "pen"
  - font sizes off the type scale
  - more than one typeface
  - unlabelled fields and nameless buttons
  - empty dropdowns
  - broken images
  - duplicate element ids
  - script errors and error pages
- **Caretaker and Vet** (the roles that mostly use phones): every page at phone width, plus every link in their menus fetched to prove none leads to a refused page (Caretaker 28 links, Vet 33, none refused).
- **Data consistency:** the same figures were read from different pages and compared. They all agree:

  | Figure | Pages compared | Value |
  |---|---|---|
  | Goats on the farm | Dashboard, Goat Registry, Herd by Age, Herd by Breed, Sheds and PENs, Feed Requirement | 120 |
  | Goats per PEN | Sheds and PENs | 118 in PENs + 2 not in a PEN |
  | Feed Requirement | Feed Requirement | 117 planned + 1 on milk + 2 not planned |
  | Health | Dashboard, Goat Registry | healthy 101; need attention 19 (watch 14 + treatment 4 + critical 1) |
  | Pregnant | Dashboard, Breeding | 14 |
  | Deaths | Farm Reports, dashboard Mortality | same count |

  Veterinary → Mortality did not match; it is fixed below.
- **Live database (read only):** no stored movement or task text still holds a month-name date.
- **By hand at phone size:** the More menu, account menu, notifications, search, forms and pop-ups.

## Found and fixed
| # | Where | Problem | Fix |
|---|---|---|---|
| 1 | Breeding → Pregnancies to Follow (also the Maternity menu) | **The whole page crashed** whenever a doe was within 3 days of kidding: the server sends the alert "kidding_imminent" and the page did not know it | Alert added ("Kidding in 3 days"); an unknown alert can no longer break the page |
| 2 | Phone → More menu | For the Administrator (20 apps) the sheet was taller than the phone: the top row was cut off, and it could neither scroll nor close | Height capped, list scrolls, Close button, drag-to-close only from the handle |
| 3 | Phone → notifications (bell) | The panel ran off the left edge of the screen | Full width under the top bar on phones; anchored to the bell on wider screens |
| 4 | Dashboard → Reproduction | Tiles crushed to a 25 px column ("1.28 kids/doe" one letter per line) | Tiles size to their panel |
| 5 | Breeding → Pregnancies to Follow on phones | Status chips ran 120 px past the screen | Chips wrap |
| 6 | Sales → Monthly on phones | The 12-month chart widened the page | The chart scrolls inside its panel |
| 7 | Settings → Users on phones | The summary strip stuck out 4 px | Matches the page padding |
| 8 | Veterinary → Mortality | Goats marked died without an exit record were missing, so it disagreed with the dashboard and Farm Reports | They are counted, with their postmortem |
| 9 | Pop-up cards | Close scrolled away on long cards | Close stays pinned at the top |
| 10 | Account menu | Phones did not show who is signed in; the desktop badge used role initials (AD) and phones used name initials (DA); Escape did not close it | Name and role at the top, the same initials everywhere, Escape closes |
| 11 | Stat tiles everywhere | A zero was shown (e.g. "Not weighed in 45 days 0") | A zero tile is hidden (owner's rule); an all-zero strip is hidden |
| 12 | Type scale | The base text was 15 px; table headings 13 px; badges 10, 11, 13 and 19 px | Base text 14, table headings 12, badges 12, buttons 14, calendar day 18 |
| 13 | Dashboard → Next Kiddings | The date wrapped ("18/1" over "1") | Wider date badge, one line |
| 14 | Lowercase "pen" in screen text | Dashboard ("7 pens"), PEN movement export, feed cost export, two access labels, the stored housing field label "Shed / Pen" | All say PEN; the field label changes by a reversible migration that touches only the untouched old default |
| 15 | Month names | Milking "Milk in November"; Checkups hint "e.g. 21 Oct" | 11/2026; 21/10 |
| 16 | Field labels | 13 table inputs (goat purchase rows, growth plan targets, sale order prices, feed counts) plus 2 date pickers had no label | Labels added (screen readers, autofill, tests) |
| 17 | Data tables on phones | Sort buttons were 16 px tall | 32 px tap target, same header height |
| 18 | Breeding → Mating check | An empty doe or buck list gave a blank dropdown | Says "No doe to choose" / "No breeding buck to choose" |
| 19 | Phone search | The keyboard showed Enter instead of Search | Search key |

## Checked and fine (no change)
- **Every status map** that could crash the same way as #1 was checked against the server: medicine stock, feed meals, feed stock. They all match.
- **Dates on screen** use digits everywhere in the front end.
- **The two "Not Found" registry entries** (/dashboard/app, /lookup/goat) are internal rules, not menu links.
- **Remaining lowercase "pen" text is farm data, not program text**: demo task titles ("Clean Pen D"), PEN type names ("Kidding Pen") and checklist templates. The farm can rename them in Settings.
- **Endpoints:**
  - No route, action or permission was changed. All 428 feature tests and the 13 front-end tests pass.
  - The role crawl (every route as each of the 7 roles) was compared with its saved baseline.

## Not changed (needs a decision)
- **56 dropdowns** list a master (breeds, feeds, PENs …) with no "none yet" line. They only look blank when that master is empty, which does not happen on the live farm.
- **The demo data's lowercase "Pen"** in task and checklist texts is seed data only.
