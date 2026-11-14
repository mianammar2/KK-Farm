# D-261 Page × role matrix (generated)

Cell = spec mark / crawl result. F = full, v = view, s = scoped, - = none. **Bold** = disagreement (spec "-" but the page opens = OVER; spec F/v/s but refused = UNDER). v and s cannot be told apart from F by status code: see the report for view-only and scope findings.

| Module | Spec row | Registry key | ADM | MGR | VET | CTK | FDE | CLN | FIN |
|---|---|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Fieldwork | Choose a Job (hub) | `scan.choose_a_job` | F/open | F/open | F/open | F/open | F/open | -/shut | -/shut |
| Fieldwork | Weigh Goats | **NONE** | F | F | - | F | - | - | - |
| Fieldwork | Health Check (scan, mark fine / report concern) | **NONE** | F | F | F | F | - | - | - |
| Fieldwork | Move to a Pen | **NONE** | F | F | - | F | - | - | - |
| Fieldwork | Look Up Goats | **NONE** | F | F | F | F | v | - | - |
| Fieldwork | RFID Scan | `scan.rfid_scan` | F/open | F/open | F/open | F/open | v/open | -/shut | -/shut |
| Fieldwork | Feed Pens (record given / leftover) | `feeding.feed_pens` | F/open | F/open | -/shut | v/open | F/open | -/shut | -/shut |
| Fieldwork | My Tasks | `tasks.my_tasks` | F/open | F/open | F/open | F/open | F/open | F/open | F/open |
| Fieldwork | Activity Log | `dashboard.activity_log` | F/open | s/open | -/shut | -/shut | -/shut | -/shut | -/shut |
| Fieldwork | Gun & App Settings | `scan.gun_app_settings` | F/open | F/open | F/open | F/open | F/open | -/shut | -/shut |
| Dashboard | Executive Dashboard | `dashboard.executive_dashboard` | F/open | F/open | **-/open** | **-/open** | **-/open** | **-/open** | **-/open** |
| Dashboard | CEO Scorecard | `dashboard.ceo_scorecard` | F/open | -/shut | -/shut | -/shut | -/shut | -/shut | -/shut |
| Dashboard | Role home (own dashboard, Section 6) | **NONE** | F | F | F | F | F | F | F |
| Dashboard | Alerts & Action Center | `dashboard.alerts_action_center` | F/open | F/open | s/open | s/open | s/open | s/open | s/open |
| Dashboard | Activity Log | `dashboard.activity_log` | F/open | s/open | -/shut | -/shut | -/shut | -/shut | -/shut |
| Dashboard | Weight Gain & Loss | `dashboard.weight_gain_loss` | F/open | F/open | v/open | F/open | v/open | -/shut | -/shut |
| Dashboard | Farm Reports | `reports.farm_reports` | F/open | F/open | s/open | -/shut | s/open | -/shut | s/open |
| Dashboard | Users & Roles | **NONE** | F | - | - | - | - | - | - |
| Livestock | All Goats | `goats.all_goats` | F/open | F/open | F/open | s/open | s/open | -/shut | -/shut |
| Livestock | Register Purchased Goat | `goats.register_purchased_goat` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | -/shut |
| Livestock | Intake & Quarantine | `goats.intake_quarantine` | F/open | F/open | F/open | v/open | -/shut | -/shut | -/shut |
| Livestock | Newborns to Complete | `goats.newborns_to_complete` | F/open | F/open | F/open | F/open | -/shut | -/shut | -/shut |
| Livestock | RFID Scan | `scan.rfid_scan` | F/open | F/open | F/open | F/open | v/open | -/shut | -/shut |
| Livestock | Animal Exit | `sheds.animal_exit` | F/? | F/? | -/? | -/? | -/? | -/? | s/? |
| Livestock | Pregnant Does | `goats.pregnant_does` | F/open | F/open | F/open | v/open | -/shut | -/shut | -/shut |
| Livestock | Need Attention | `goats.need_attention` | v/open | v/open | F/open | v/open | -/shut | -/shut | -/shut |
| Livestock | Missing Data | `goats.missing_data` | F/open | F/open | v/open | v/open | -/shut | -/shut | -/shut |
| Livestock | Herd Census / Age-Wise / Breed-Wise reports | `reports.herd_census` | F/open | F/open | v/open | -/shut | -/shut | -/shut | -/shut |
| Livestock | Exits & Mortality report | **NONE** | F | F | v | - | - | - | - |
| Livestock | Breed Master / Age Groups / Automation Rules / Register Fields () | **NONE** | F | - | - | - | - | - | - |
| Breeding | Heat Detection | `breeding.heat_detection` | F/open | F/open | F/open | F/open | -/shut | -/shut | -/shut |
| Breeding | Mating, Pregnancy & Ultrasound | `breeding.mating_pregnancy_ultrasound` | F/open | F/open | F/open | v/open | -/shut | -/shut | -/shut |
| Breeding | Replacement Review (Genetic Merit) | `breeding.replacement_review_genetic_merit` | F/open | F/open | F/open | -/shut | -/shut | -/shut | -/shut |
| Breeding | Inbreeding Check | `breeding.inbreeding_check` | F/open | F/open | F/open | -/shut | -/shut | -/shut | -/shut |
| Breeding | Replacement Breeder Selection | `weights.replacement_breeder_selection` | F/open | F/open | v/open | -/shut | -/shut | -/shut | -/shut |
| Breeding | Breeding Eligibility & Approval | `breeding.breeding_eligibility_approval` | v/open | v/open | F/open | -/shut | -/shut | -/shut | -/shut |
| Breeding | Pregnancy Calendar | `breeding.pregnancy_calendar` | F/open | F/open | F/open | v/open | -/shut | -/shut | -/shut |
| Breeding | Genetics & Family Tree | `breeding.genetics_family_tree` | F/open | F/open | v/open | -/shut | -/shut | -/shut | -/shut |
| Breeding | Pregnant Does | `goats.pregnant_does` | F/open | F/open | F/open | v/open | -/shut | -/shut | -/shut |
| Breeding | Sire & Dam Progeny / Doe Performance / Buck Performance | `breeding.doe_performance` | F/open | F/open | v/open | -/shut | -/shut | -/shut | -/shut |
| Breeding | Sire & Dam Progeny / Doe Performance / Buck Performance | `reports.buck_performance` | F/open | F/open | v/open | -/shut | -/shut | -/shut | -/shut |
| Breeding | Gestation & Check Days | `breeding.gestation_check_days` | F/open | v/open | F/open | -/shut | -/shut | -/shut | -/shut |
| Breeding | Breeding Eligibility Rules | **NONE** | v | v | F | - | - | - | - |
| Maternity | Upcoming Deliveries | **NONE** | F | F | F | F | - | - | - |
| Maternity | Kidding & Pregnancy Outcomes | `breeding.kidding_pregnancy_outcomes` | F/open | F/open | F/open | F/open | -/shut | -/shut | -/shut |
| Maternity | Pregnancy Loss Investigation | `breeding.pregnancy_loss_investigation` | v/open | v/open | F/open | -/shut | -/shut | -/shut | -/shut |
| Maternity | Newborns to Complete | `goats.newborns_to_complete` | F/open | F/open | F/open | F/open | -/shut | -/shut | -/shut |
| Maternity | Kid Care | `breeding.kid_care` | F/open | F/open | F/open | F/open | -/shut | -/shut | -/shut |
| Maternity | Weaning | `breeding.weaning` | F/open | F/open | v/open | F/open | -/shut | -/shut | -/shut |
| Maternity | Kid Growth vs Plan | `weights.kid_growth_vs_plan` | F/open | F/open | v/open | F/open | v/open | -/shut | -/shut |
| Maternity | Mother Care & Lactation | `milking.mother_care_lactation` | F/open | F/open | v/open | F/open | -/shut | -/shut | -/shut |
| Maternity | Maternity Unit | `sheds.maternity_unit` | F/open | F/open | v/open | F/open | **-/open** | -/shut | -/shut |
| Maternity | Kidding Rate & Litter Size | **NONE** | F | F | v | - | - | - | - |
| Maternity | Weaning & Kidding Alerts (config) | **NONE** | F | F | v | - | - | - | - |
| Veterinary | Disease Control & Recovery | `health.disease_control_recovery` | v/open | v/open | F/open | -/shut | -/shut | -/shut | -/shut |
| Veterinary | Daily Health & Checkups | `health.daily_health_checkups` | F/open | F/open | F/open | F/open | -/shut | -/shut | -/shut |
| Veterinary | Medical Cases | `health.medical_cases` | v/open | v/open | F/open | -/shut | -/shut | -/shut | -/shut |
| Veterinary | Triage Reported Symptoms | `health.triage_reported_symptoms` | v/open | v/open | F/open | -/shut | -/shut | -/shut | -/shut |
| Veterinary | Reported Concerns (create a report) | `health.reported_concerns` | v/open | F/open | F/open | F/open | -/shut | -/shut | -/shut |
| Veterinary | Parasite Testing & Control | `health.parasite_testing_control` | v/open | v/open | F/open | -/shut | -/shut | -/shut | -/shut |
| Veterinary | Vaccination & Deworming Due | `health.vaccination_deworming_due` | v/open | F/open | F/open | v/open | -/shut | -/shut | -/shut |
| Veterinary | Quarantine & Clearance | `goats.intake_quarantine` | F/open | v/open | F/open | v/open | -/shut | -/shut | -/shut |
| Veterinary | Restricted Pens | `health.restricted_pens` | v/open | v/open | F/open | v/open | -/shut | -/shut | -/shut |
| Veterinary | Preventive Care Given | `health.preventive_care_given` | v/open | v/open | F/open | v/open | -/shut | -/shut | -/shut |
| Veterinary | Withdrawal & Biosecurity | `health.withdrawal_biosecurity` | v/open | v/open | F/open | v/open | -/shut | -/shut | -/shut |
| Veterinary | Sick & Under Watch | **NONE** | v | v | F | v | - | - | - |
| Veterinary | Mortality & Postmortem | `health.mortality_postmortem` | v/open | v/open | F/open | -/shut | -/shut | -/shut | -/shut |
| Veterinary | Outbreak Reports / Antimicrobial Use / Mortality by Cause | `health.outbreak_reports` | v/open | v/open | F/open | -/shut | -/shut | -/shut | -/shut |
| Veterinary | Outbreak Reports / Antimicrobial Use / Mortality by Cause | `health.antimicrobial_use` | v/open | v/open | F/open | -/shut | -/shut | -/shut | -/shut |
| Veterinary | Vaccine & Deworming Protocols | `health.vaccine_deworming_protocols` | v/open | -/shut | F/open | -/shut | -/shut | -/shut | -/shut |
| Veterinary | Disease Master | `health.disease_master` | v/open | -/shut | F/open | -/shut | -/shut | -/shut | -/shut |
| Veterinary | Medicines & Vaccines (medicine store) | `health.medicines_vaccines` | F/open | v/open | F/open | -/shut | -/shut | -/shut | -/shut |
| Feeding | Daily Feeding | **NONE** | F | F | v | v | F | - | - |
| Feeding | Today's Requirement | **NONE** | F | F | v | v | F | - | - |
| Feeding | TMR Mixing Batches | `feeding.tmr_mixing_batches` | F/open | F/open | -/shut | -/shut | F/open | -/shut | -/shut |
| Feeding | Feed Safety: Lots, Tests & Holds | `feeding.feed_safety_lots_tests_holds` | F/open | F/open | v/open | -/shut | F/open | -/shut | -/shut |
| Feeding | Stock Count & Shrinkage (stock tab of) | `feed_cost.stock_count_shrinkage` | F/open | F/open | -/shut | -/shut | F/open | -/shut | v/open |
| Feeding | Feeding History | `feeding.feeding_history` | F/open | F/open | v/open | v/open | F/open | -/shut | -/shut |
| Feeding | Feeding Plans & Rations | `feeding.feeding_plans_rations` | F/open | F/open | v/open | v/open | F/open | -/shut | -/shut |
| Feeding | Feed True Cost (per Goat & KG Gain) cost tabs | `feed_cost.feed_true_cost_per_goat_kg_gain` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | F/open |
| Feeding | Feed Spending | `reports.feed_spending` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | F/open |
| Feeding | Feed Offered, Refused & Wasted | `feeding.feed_offered_refused_wasted` | F/open | F/open | v/open | v/open | F/open | -/shut | -/shut |
| Feeding | Nutrition Standards | `feeding.nutrition_standards` | F/open | F/open | v/open | -/shut | v/open | -/shut | -/shut |
| Feeding | TMR Formulas | **NONE** | F | F | v | - | F | - | - |
| Feeding | Feed Master | `feeding.feed_master` | F/open | F/open | -/shut | -/shut | F/open | -/shut | -/shut |
| Feeding | Feeding Times | `feeding.feeding_times` | F/open | F/open | -/shut | -/shut | v/open | -/shut | -/shut |
| Housing | Farms, Units & Pens | **NONE** | F | F | v | v | v | - | - |
| Housing | Animal Movements | `sheds.movements_opening_page` | F/? | F/? | v/? | F/? | -/? | -/? | -/? |
| Housing | Pen Status, Capacity & Cleaning | `sheds.pen_status_capacity_cleaning` | F/open | F/open | v/open | F/open | v/open | s/open | -/shut |
| Housing | Goats Without a Pen | `goats.goats_without_a_pen` | F/open | F/open | -/shut | F/open | -/shut | -/shut | -/shut |
| Housing | Pen Occupancy | **NONE** | F | F | v | F | v | s | - |
| Housing | Unit & Pen Setup (add sheds and pens) | **NONE** | F | F | - | - | - | - | - |
| Growth | Growth vs Plan | **NONE** | F | F | v | F | v | - | - |
| Growth | Record Weights | `weights.record_weights` | F/open | F/open | v/open | F/open | -/shut | -/shut | -/shut |
| Growth | Held Weights to Review | `weights.held_weights_to_review` | F/open | F/open | v/open | -/shut | -/shut | -/shut | -/shut |
| Growth | Nutrition & Vet Interventions | `weights.nutrition_vet_interventions` | F/open | F/open | F/open | v/open | v/open | -/shut | -/shut |
| Growth | Breeder / Sale Selection | **NONE** | F | F | v | - | - | - | - |
| Growth | No Weight Yet | `goats.no_weight_yet` | F/open | F/open | v/open | F/open | -/shut | -/shut | -/shut |
| Growth | Selection Records | `weights.selection_records` | F/open | F/open | v/open | -/shut | -/shut | -/shut | -/shut |
| Growth | Daily Gain (ADG) | **NONE** | F | F | v | F | v | - | - |
| Growth | Growth Plans & Selection Age | `weights.growth_plans_selection_age` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | -/shut |
| Growth | Breed Weight Lines () | **NONE** | F | - | - | - | - | - | - |
| Operations | Today's Checklists | **NONE** | F | F | s | s | s | s | s |
| Operations | Today's Tasks (all open) | **NONE** | F | F | s | s | s | s | s |
| Operations | My Tasks | `tasks.my_tasks` | F/open | F/open | F/open | F/open | F/open | F/open | F/open |
| Operations | Incident Register | `tasks.incident_register` | F/open | F/open | s/open | s/open | s/open | s/open | s/open |
| Operations | Shift Handover | `tasks.shift_handover` | F/open | F/open | s/open | s/open | s/open | s/open | -/shut |
| Operations | Checklist History | `tasks.checklist_history` | F/open | F/open | s/open | s/open | s/open | s/open | s/open |
| Operations | Done This Week | `tasks.done_this_week` | F/open | F/open | s/open | s/open | s/open | s/open | s/open |
| Operations | Daily Activity Log | `dashboard.activity_log` | F/open | s/open | -/shut | -/shut | -/shut | -/shut | -/shut |
| Operations | Delayed Tasks | `tasks.delayed_tasks` | F/open | F/open | s/open | s/open | s/open | s/open | s/open |
| Operations | Pending Actions | `dashboard.alerts_action_center` | F/open | F/open | s/open | s/open | s/open | s/open | s/open |
| Operations | Checklist Setup | `tasks.checklist_setup` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | -/shut |
| Operations | Departments & Staff | `employees.departments_staff` | F/open | v/open | -/shut | -/shut | -/shut | -/shut | **-/open** |
| Inventory | All Stock | `inventory.all_stock` | F/open | F/open | s/open | s/open | s/open | s/open | v/open |
| Inventory | General Store | `inventory.general_store` | F/open | F/open | -/shut | F/open | -/shut | F/open | v/open |
| Inventory | Feed Inventory | `feeding.feed_master` | F/open | F/open | -/shut | -/shut | F/open | -/shut | **v/shut** |
| Inventory | Medicine Inventory | `health.medicines_vaccines` | F/open | v/open | F/open | -/shut | -/shut | -/shut | **v/shut** |
| Inventory | TMR Mixing | **NONE** | F | F | - | - | F | - | - |
| Inventory | Stock Moves | `inventory.stock_moves` | F/open | F/open | s/open | s/open | s/open | s/open | v/open |
| Inventory | Reorder List | `inventory.reorder_list` | F/open | F/open | s/open | s/open | s/open | s/open | -/shut |
| Inventory | Consumption & Spending | `reports.feed_spending` | F/open | v/open | -/shut | -/shut | -/shut | -/shut | F/open |
| Inventory | Store Items | **NONE** | F | F | - | - | - | - | - |
| Purchase | Purchase Orders | `purchase.purchase_orders` | F/open | F/open | s/open | -/shut | s/open | -/shut | F/open |
| Purchase | New Purchase Order | **NONE** | F | F | s | - | s | - | F |
| Purchase | Goat Purchase Lots | `animal_purchase.goat_purchase_lots` | F/open | F/open | s/open | -/shut | -/shut | -/shut | v/open |
| Purchase | Goat Purchase Request | `animal_purchase.goat_purchase_request` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | v/open |
| Purchase | Animal Intake & Quarantine | `goats.intake_quarantine` | F/open | F/open | F/open | **-/open** | -/shut | -/shut | -/shut |
| Purchase | Suppliers | `purchase.suppliers` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | F/open |
| Purchase | Purchase Analysis | **NONE** | F | v | - | - | - | - | F |
| Purchase | Purchase Accounts | `finance.purchase_accounts` | F/open | -/shut | -/shut | -/shut | -/shut | -/shut | F/open |
| Sales | All Sales | `sales.all_sales` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | F/open |
| Sales | Sale Readiness & Clearance | `sales.sale_readiness_clearance` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | v/open |
| Sales | Sale Orders & Dispatch | `sales.sale_orders_dispatch` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | F/open |
| Sales | Transport & Delivery | `sales.transport_delivery` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | v/open |
| Sales | New Price Quote | `sales.new_price_quote` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | F/open |
| Sales | Other Exits | `sheds.movements_opening_page` | F/? | F/? | -/? | -/? | -/? | -/? | -/? |
| Sales | Sell Products | **NONE** | F | F | - | - | - | - | F |
| Sales | Invoices & Collections | `sales.invoices_collections` | F/open | v/open | -/shut | -/shut | -/shut | -/shut | F/open |
| Sales | Customers | `sales.customers` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | F/open |
| Sales | Herd Value & Pricing | `value.herd_value_pricing` | F/open | v/open | -/shut | -/shut | -/shut | -/shut | F/open |
| Sales | Monthly Sales | `sales.monthly_sales` | F/open | v/open | -/shut | -/shut | -/shut | -/shut | F/open |
| Sales | Sales & Exits report | **NONE** | F | F | - | - | - | - | F |
| Sales | Approved Vehicles | `sales.approved_vehicles` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | -/shut |
| Sales | Market Rates | **NONE** | F | F | - | - | - | - | F |
| Accounting | Income & Farm Expenses | `finance.income_farm_expenses` | F/open | v/open | -/shut | -/shut | -/shut | -/shut | F/open |
| Accounting | Journal Entries | `finance.journal_entries` | F/open | -/shut | -/shut | -/shut | -/shut | -/shut | F/open |
| Accounting | General Ledger | `finance.general_ledger` | F/open | -/shut | -/shut | -/shut | -/shut | -/shut | F/open |
| Accounting | Trial Balance | `finance.trial_balance` | F/open | -/shut | -/shut | -/shut | -/shut | -/shut | F/open |
| Accounting | Profit & Loss | `finance.profit_loss` | F/open | v/open | -/shut | -/shut | -/shut | -/shut | F/open |
| Accounting | Balance Sheet | `finance.balance_sheet` | F/open | v/open | -/shut | -/shut | -/shut | -/shut | F/open |
| Accounting | Feed Cost per KG Gained | `feed_cost.feed_true_cost_per_goat_kg_gain` | F/open | v/open | -/shut | -/shut | -/shut | -/shut | F/open |
| Accounting | Chart of Accounts | **NONE** | F | - | - | - | - | - | F |
| Employees | Employees & Departments | **NONE** | F | v | - | - | - | - | v |
| Employees | Attendance Register | `employees.attendance_register` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | v/open |
| Employees | Leave Requests | `employees.leave_requests` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | v/open |
| Employees | Training & Compliance | `employees.training_compliance` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | -/shut |
| Employees | Skills & Authorisations | `employees.skills_authorisations` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | -/shut |
| Employees | Shift Roster | `employees.shift_roster` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | -/shut |
| Employees | Staff Activity | `dashboard.activity_log` | F/open | v/open | -/shut | -/shut | -/shut | -/shut | -/shut |
| Employees | Attendance Report | `employees.attendance_report` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | F/open |
| Employees | Skills Compliance | `employees.skills_compliance` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | -/shut |
| Employees | Task Completion | **NONE** | F | F | - | - | - | - | - |
| Employees | Users & Permissions | **NONE** | F | - | - | - | - | - | - |
| Maintenance | Open Jobs & Breakdowns | `maintenance.open_jobs_breakdowns` | F/open | F/open | -/shut | s/open | s/open | s/open | -/shut |
| Maintenance | Preventive Services Due | `maintenance.preventive_services_due` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | -/shut |
| Maintenance | Sensor Alerts | `sensors.sensor_alerts` | F/open | v/open | -/shut | -/shut | -/shut | **-/open** | -/shut |
| Maintenance | Asset Register | `maintenance.asset_register` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | -/shut |
| Maintenance | Sensors & Devices | `sensors.sensors_devices` | F/open | v/open | -/shut | -/shut | -/shut | -/shut | -/shut |
| Maintenance | Repair History & Cost | `maintenance.repair_history_cost` | F/open | v/open | -/shut | -/shut | -/shut | -/shut | -/shut |
| Maintenance | Sensor Readings | `sensors.sensor_readings` | F/open | v/open | -/shut | -/shut | -/shut | -/shut | -/shut |
| Maintenance | Asset Categories & Intervals | **NONE** | F | F | - | - | - | - | - |
| Maintenance | Connect a Device (devices and keys) | `sensors.connect_a_device` | F/open | -/shut | -/shut | -/shut | -/shut | -/shut | -/shut |
| Reports | Reports & Analytics (hub, lists only permitted reports) | **NONE** | F | F | s | - | s | - | s |
| Reports | CEO Dashboard link () | `dashboard.executive_dashboard` | F/open | **-/open** | **-/open** | **-/open** | **-/open** | **-/open** | **-/open** |
| Reports | Livestock Reports | **NONE** | F | F | v | - | - | - | - |
| Reports | Breeding Reports | **NONE** | F | F | v | - | - | - | - |
| Reports | Financial Reports | **NONE** | F | v | - | - | - | - | F |
| Reports | Report Access () | **NONE** | F | - | - | - | - | - | - |
| Dairy | Close AM / PM Sessions | `milking.close_am_pm_sessions` | F/open | F/open | -/shut | F/open | -/shut | -/shut | -/shut |
| Dairy | Log Litres per Doe (record milk) | `milking.mother_care_lactation` | F/open | F/open | v/open | F/open | -/shut | -/shut | -/shut |
| Dairy | Milk Lots, QA & Release | `milking.milk_lots_qa_release` | F/open | F/open | v/open | v/open | -/shut | -/shut | -/shut |
| Dairy | Milk Orders | `milking.milk_orders` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | v/open |
| Dairy | Dispatch & Trace | `milking.dispatch_trace` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | v/open |
| Dairy | Milk Processing Batches | `milking.milk_processing_batches` | F/open | F/open | -/shut | v/open | -/shut | -/shut | -/shut |
| Dairy | Lactations | **NONE** | F | F | v | F | - | - | - |
| Dairy | Milk Invoices & Profit | `milking.milk_invoices_profit` | F/open | v/open | -/shut | -/shut | -/shut | -/shut | F/open |
| Dairy | Lot Traceability & Revenue | **NONE** | F | F | - | - | - | - | v |
| Dairy | Milking Settings | **NONE** | F | F | - | - | - | - | - |
| Fattening | Finishing Batches | `fattening.finishing_batches` | F/open | F/open | v/open | F/open | v/open | -/shut | -/shut |
| Fattening | Meat Selection | `fattening.meat_selection` | F/open | F/open | -/shut | v/open | -/shut | -/shut | -/shut |
| Fattening | New Batch (Select Feeders) | `fattening.new_batch_select_feeders` | F/open | F/open | -/shut | F/open | -/shut | -/shut | -/shut |
| Fattening | Daily Feed & Water | **NONE** | F | F | - | v | F | - | - |
| Fattening | Herd Value & Market Rates | `value.herd_value_pricing` | F/open | v/open | -/shut | -/shut | -/shut | -/shut | **-/open** |
| Fattening | Batch Profitability | `fattening.batch_profitability` | F/open | v/open | -/shut | -/shut | -/shut | -/shut | -/shut |
| Fattening | Market Rates | **NONE** | F | F | - | - | - | - | - |
| Welfare | Welfare Cases | `welfare.welfare_cases` | F/open | F/open | F/open | v/open | -/shut | -/shut | -/shut |
| Welfare | Daily Welfare & Mobility Check | `welfare.daily_welfare_mobility_check` | F/open | F/open | F/open | F/open | -/shut | -/shut | -/shut |
| Welfare | Vet Referrals (Reported Concerns) | `health.reported_concerns` | v/open | F/open | F/open | F/open | -/shut | -/shut | -/shut |
| Welfare | Humane End-of-Life Review | `welfare.humane_end_of_life_review` | v/open | v/open | F/open | -/shut | -/shut | -/shut | -/shut |
| Welfare | Check Records | `welfare.check_records` | F/open | F/open | F/open | v/open | -/shut | -/shut | -/shut |
| Welfare | Closed Cases | `welfare.closed_cases` | F/open | F/open | F/open | -/shut | -/shut | -/shut | -/shut |
| Welfare | Mortality | **NONE** | v | v | F | - | - | - | - |
| Welfare | Condition List | **NONE** | v | - | F | - | - | - | - |
| Environment | Shed Rounds | `environment.shed_rounds` | F/open | F/open | v/open | F/open | -/shut | F/open | -/shut |
| Environment | Water, Heat & Waste Issues | `environment.water_heat_waste_issues` | F/open | F/open | v/open | F/open | -/shut | F/open | -/shut |
| Environment | Water Lab Tests | `environment.water_lab_tests` | F/open | F/open | v/open | -/shut | -/shut | v/open | -/shut |
| Environment | Sensor Alarms | `sensors.sensor_alerts` | F/open | v/open | -/shut | -/shut | -/shut | s/open | -/shut |
| Environment | Waste & Safe Disposal | `environment.waste_safe_disposal` | F/open | F/open | v/open | -/shut | -/shut | F/open | -/shut |
| Environment | Round Records | `environment.round_records` | F/open | F/open | v/open | F/open | -/shut | s/open | -/shut |
| Environment | Sensor Readings | `sensors.sensor_readings` | F/open | v/open | -/shut | -/shut | -/shut | -/shut | -/shut |
| Environment | Water & Heat Compliance | **NONE** | F | F | v | - | - | - | - |
| Environment | Waste Routes (Adviser-Set) | `environment.waste_routes_adviser_set` | F/open | v/open | -/shut | -/shut | -/shut | -/shut | -/shut |
| Environment | Water Points & Heat Limit (setup) | `environment.water_points_heat_limit` | F/open | F/open | -/shut | -/shut | -/shut | -/shut | -/shut |
| Quality | Quality Dashboard | `quality.quality_dashboard` | F/open | F/open | F/open | -/shut | -/shut | -/shut | -/shut |
| Quality | Raise a Quality Alarm | `quality.raise_a_quality_alarm` | F/open | F/open | F/open | -/shut | -/shut | -/shut | -/shut |
| Quality | Cases & CAPA | `quality.cases_capa` | F/open | F/open | F/open | -/shut | -/shut | -/shut | -/shut |
| Quality | Holds, Isolation & Recall | `quality.holds_isolation_recall` | F/open | F/open | F/open | -/shut | -/shut | -/shut | -/shut |
| Quality | Incident Register | `tasks.incident_register` | F/open | F/open | F/open | **-/open** | **-/open** | **-/open** | **-/open** |
| Quality | SOP Library | `quality.sop_library` | F/open | F/open | F/open | -/shut | -/shut | -/shut | -/shut |
| Quality | Regulatory Register | `quality.regulatory_register` | F/open | v/open | v/open | -/shut | -/shut | -/shut | -/shut |
| Quality | Emergency Readiness | `quality.emergency_readiness` | F/open | F/open | v/open | -/shut | -/shut | -/shut | -/shut |
| Quality | Withdrawal Holds | `health.withdrawal_biosecurity` | v/open | v/open | F/open | **-/open** | -/shut | -/shut | -/shut |
| Quality | Closed Cases & Learning | `quality.closed_cases_learning` | F/open | F/open | F/open | -/shut | -/shut | -/shut | -/shut |
| Quality | Audit Trail | `dashboard.activity_log` | F/open | v/open | -/shut | -/shut | -/shut | -/shut | -/shut |
| Quality | Checklists (templates) | **NONE** | F | F | - | - | - | - | - |
| Fodder | Crops & Fields | `fodder.crops_fields` | F/open | **F/shut** | -/shut | **v/shut** | **F/shut** | -/shut | -/shut |
| Fodder | Harvest Lots: Test & Release | `fodder.harvest_lots_test_release` | F/open | **F/shut** | -/shut | -/shut | **F/shut** | -/shut | -/shut |
| Fodder | Field Registry | `fodder.field_registry` | F/open | **F/shut** | -/shut | -/shut | **v/shut** | -/shut | -/shut |
| Fodder | Yield & Cost per KG DM | `fodder.yield_cost_per_kg_dm` | F/open | **F/shut** | -/shut | -/shut | **s/shut** | -/shut | -/shut |
| Fodder | Fodder Settings | `fodder.fodder_settings` | F/open | **F/shut** | -/shut | -/shut | -/shut | -/shut | -/shut |
| Settings | Master Settings | **NONE** | F | - | - | - | - | - | - |
| Settings | Users | **NONE** | F | - | - | - | - | - | - |
| Settings | Access Rights by Role | **NONE** | F | - | - | - | - | - | - |
| Settings | Breeds / Age Groups / Register Fields / System | **NONE** | F | - | - | - | - | - | - |
