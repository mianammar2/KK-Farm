# D-040: Scan-first goat registration from the workbook's Goat Register, with custom fields
2026-11-07 · Status: **Accepted** (project lead direction) · Implemented on branch `feat/scan-register`

## Context
The project lead asked for the first working piece to start at the RFID scanner: scan a tag; if the goat exists, show it; if not, open the goat registration. The form must follow the "Goat Register" sheet of `KK_Farms_360_Goat_Management_System.xlsx`, with its fields and dropdown options, and the farm must be able to add its own fields.

## Decision
- **Scan → profile or register.** `/scan?tag=CODE` opens the goat when the tag is on one. A tag the farm has never seen, or a tag in stock that was never used, redirects to `/goats/register?epc=CODE` with the code filled in. A tag released by a goat that left still shows its history (with the option to assign it), and a lost tag is blocked. Codes are trimmed and upper-cased everywhere, so a lower-case keyboard-wedge read matches.
- **The form is the Goat Register sheet**, same order and dropdown options: RFID EPC, Tag ID, Animal Name, Breed, Sex, Birth Date (+ estimate), Birth Weight, Origin Type, Acquired Date, Source Farm, Sire Tag, Dam Tag, Animal Class, Repro Status, Lifecycle Status, Health Flag, Shed / Pen, Latest Weight, Purchase Cost PKR, Ownership, Notes. Calculated sheet columns (Age Months, Tag Audit, Last Health Review, Birth Event ID) are not inputs: the system derives them.
- **Field definitions per organization** (`field_definitions`, RLS-protected). Every new organization is seeded with the standard fields. The owner can rename fields, edit dropdown options, require or hide fields, and add custom fields (short text, long text, number, date, yes/no, dropdown) at **Settings → Goat register fields**. Validation is generated from the definitions.
- **Storage.** Workbook fields the app lacked are typed columns on `animals` (`animal_class`, `repro_status`, `health_flag`, `ownership`, `source_farm`, `acquired_on`, `birth_weight_kg`, `purchase_cost_minor` in paisa, `notes`). Custom fields go in `animals.custom_fields` (jsonb).
- **Rules kept:** Sex, Breed, Shed / Pen, Origin and Birth Date are always required and cannot be hidden. Sire must be male and dam female. A tag already on a goat or reported lost is refused. Only roles with the `assign_tags` gate can register with a tag; others can register without one. Latest Weight becomes the first weight record. Blank Tag ID issues the next farm number.
- Changing dropdown options keeps stored values for unchanged labels, and for a renamed line only when the list length is unchanged, so editing a list never relabels existing goats' data.
- A tag scanned on the register page can instead be put on an already registered goat without a tag (for goats entered before they were tagged).

## Consequences / follow-ups
- Workbook vocabularies and the prototype's older states overlap: Health Flag is mapped to `health_status` (Monitor→watch, Sick/Quarantine→treatment, Recovering→watch), Repro Status Pregnant/Lactating to `repro_state`, and a non-Active Lifecycle Status to the exit fields. These become derived state under D-038.
- Editing the Sex or Origin options can break code that expects their stored values; restrict structural options to label changes in a later pass.
- The herd list's "Register goat" button now opens the full form.
- Tests: `tests/Feature/GoatRegistrationTest.php` (15 tests). Suite: 40 passing.
