# D-261 Module and action matrices (generated)

Cell = spec / code default. ● full, ◐ limited, ✔ allowed, — none. **Bold** = disagreement.

## Modules (§4 vs `config/kkfarm.php` modules)

| Module | key | ADM | MGR | VET | CTK | FDE | CLN | FIN |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Dashboard | `dashboard` | ●/✔ | ●/✔ | ◐/✔ | ◐/✔ | ◐/✔ | ◐/✔ | ◐/✔ |
| Livestock (goat register) | `goats` | ●/✔ | ●/✔ | ●/✔ | ◐/✔ | ◐/✔ | —/— | —/— |
| Fieldwork & RFID scan | `scan` | ●/✔ | ●/✔ | ●/✔ | ●/✔ | ◐/✔ | —/— | —/— |
| Breeding & Maternity | `breeding` | ●/✔ | ●/✔ | ●/✔ | ◐/✔ | —/— | —/— | —/— |
| Veterinary | `health` | ◐/✔ | ◐/✔ | ●/✔ | ◐/✔ | —/— | —/— | —/— |
| Growth & weights | `weights` | ●/✔ | ●/✔ | ◐/✔ | ●/✔ | ◐/✔ | —/— | —/— |
| Feeding | `feeding` | ●/✔ | ●/✔ | ◐/✔ | ◐/✔ | ●/✔ | —/— | —/— |
| Housing & pens | `sheds` | ●/✔ | ●/✔ | ◐/✔ | ●/✔ | ◐/✔ | ◐/✔ | —/— |
| Operations & tasks | `tasks` | ●/✔ | ●/✔ | ◐/✔ | ◐/✔ | ◐/✔ | ◐/✔ | ◐/✔ |
| Employees | `employees` | ●/✔ | ◐/✔ | —/— | —/— | —/— | —/— | ◐/✔ |
| Accounting & finance | `finance` | ●/✔ | ◐/✔ | —/— | —/— | —/— | —/— | ●/✔ |
| Herd value | `value` | ●/✔ | ◐/✔ | —/— | —/— | —/— | —/— | ●/✔ |
| Dairy | `milking` | ●/✔ | ●/✔ | ◐/✔ | ●/✔ | —/— | —/— | ◐/✔ |
| Reports | `reports` | ●/✔ | ●/✔ | ◐/✔ | —/— | ◐/✔ | —/— | ◐/✔ |
| Settings | `settings` | ●/✔ | —/— | —/— | —/— | —/— | —/— | —/— |
| Sensors & devices | `sensors` | ●/✔ | ◐/✔ | —/— | —/— | —/— | —/— | —/— |
| Inventory | `inventory` | ●/✔ | ●/✔ | ◐/✔ | ◐/✔ | ◐/✔ | ◐/✔ | ◐/✔ |
| Purchase | `purchase` | ●/✔ | ●/✔ | ◐/✔ | —/— | ◐/✔ | —/— | ●/✔ |
| Sales | `sales` | ●/✔ | ●/✔ | —/— | —/— | —/— | —/— | ●/✔ |
| Welfare | `welfare` | ●/✔ | ●/✔ | ●/✔ | ◐/✔ | —/— | —/— | —/— |
| Fattening | `fattening` | ●/✔ | ●/✔ | ◐/✔ | ●/✔ | ◐/✔ | —/— | —/— |
| Environment (water & climate) | `environment` | ●/✔ | ●/✔ | ◐/✔ | ◐/✔ | —/— | ◐/✔ | —/— |
| Quality | `quality` | ●/✔ | ●/✔ | ●/✔ | —/— | —/— | —/— | —/— |
| Goat purchase lots | `animal_purchase` | ●/✔ | ●/✔ | ◐/✔ | —/— | —/— | —/— | ◐/✔ |
| Feed true cost | `feed_cost` | ●/✔ | ●/✔ | —/— | —/— | **—/✔** | —/— | ●/✔ |
| Fodder | `fodder` | ●/✔ | ●/✔ | —/— | ◐/✔ | ●/✔ | —/— | —/— |
| Maintenance | `maintenance` | ●/✔ | ●/✔ | —/— | ◐/✔ | ◐/✔ | ◐/✔ | —/— |

## Actions (§5 vs `config/kkfarm.php` gates)

| Action | gate | ADM | MGR | VET | CTK | FDE | CLN | FIN |
|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Diagnose and treat 🔒 | `clinical` | —/— | —/— | ✔/✔ | —/— | —/— | —/— | —/— |
| Open health cases 🔒 | `open_cases` | —/— | —/— | ✔/✔ | **✔ (report sick only)/—** | —/— | —/— | —/— |
| Approve goats for breeding 🔒 | `approve_breeding` | —/— | —/— | ✔/✔ | —/— | —/— | —/— | —/— |
| Routine health checkups | `health_checkup` | ✔/✔ | **✔/—** | ✔/✔ | **✔/—** | —/— | —/— | —/— |
| Medicine store | `manage_medicine_store` | ✔/✔ | —/— | ✔/✔ | —/— | —/— | —/— | —/— |
| Approve feeding plans | `approve_feeding_plan` | ✔/✔ | ✔/✔ | —/— | —/— | —/— | —/— | —/— |
| Draft rations | `plan_rations` | ✔/✔ | ✔/✔ | —/— | —/— | ✔/✔ | —/— | —/— |
| Feed store items and stock | `manage_feed_store` | ✔/✔ | ✔/✔ | —/— | —/— | ✔/✔ | —/— | —/— |
| General store | `manage_general_store` | ✔/✔ | ✔/✔ | —/— | ✔/✔ | —/— | ✔/✔ | —/— |
| Assign RFID tags | `assign_tags` | ✔/✔ | ✔/✔ | ✔/✔ | ✔/✔ | —/— | —/— | —/— |
| Add sheds and pens | `manage_sheds` | ✔/✔ | ✔/✔ | —/— | —/— | —/— | —/— | —/— |
| Edit goat details | `edit_goats` | ✔/✔ | **✔/—** | **✔ (health fields)/—** | —/— | —/— | —/— | —/— |
| Remove or restore goat entries | `remove_goats` | ✔/✔ | **✔/—** | —/— | —/— | —/— | —/— | —/— |
| Record goats leaving the farm | `exit_animal` | ✔/✔ | ✔/✔ | —/— | —/— | —/— | —/— | ✔ (sale/payment side)/✔ |
| Record milk | `record_milk` | ✔/✔ | ✔/✔ | —/— | ✔/✔ | —/— | —/— | —/— |
| Milking settings | `manage_milking` | ✔/✔ | ✔/✔ | —/— | —/— | —/— | —/— | —/— |
| Suppliers and purchase orders | `manage_purchase` | ✔/✔ | ✔/✔ | —/— | —/— | —/— | —/— | ✔/✔ |
| Customers and sales | `manage_sales` | ✔/✔ | ✔/✔ | —/— | —/— | —/— | —/— | ✔/✔ |
| Market rates | `manage_market` | ✔/✔ | ✔/✔ | —/— | —/— | —/— | —/— | ✔/✔ |
| Devices and keys | `manage_devices` | ✔/✔ | —/— | —/— | —/— | —/— | —/— | —/— |

### Gates in code with no §5 row

| gate | label | roles |
|---|---|---|
| `health_entry` | New health entry | VET |
| `report_concern` | Report concern | ADM, VET |
| `record_heat` | Record heat | ADM, MGR, VET, CTK |
| `record_mating` | Record mating | ADM, MGR, VET, CTK |
| `give_care` | Give vaccines and deworming | ADM, VET |
| `move_goats` | Move goats between pens | ADM, MGR, VET, CTK, FDE |
| `report_death` | Report a death | VET |
| `approve_death` | Approve a death (the goat leaves the farm) | ADM |
| `cull_breeding` | Cull from breeding | ADM, VET |
| `record_weights` | Record weights | ADM, MGR, CTK |
