<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * D-307/D-309: the Operations Log of every app, read from each app's own records (append-only history), so nothing is
 * typed twice. Each operation is described once: its table, the date of the work, who did it, the goat (if any) and
 * the details. An app's log shows only its own operations (D-310).
 */
class OpsLog
{
    /** app key => [label, modules (any opens it)] */
    public const APPS = [
        'livestock' => ['Livestock', ['goats']], 'breeding' => ['Breeding', ['breeding']], 'maternity' => ['Maternity', ['breeding']],
        'veterinary' => ['Veterinary', ['health']], 'feeding' => ['Feeding', ['feeding']], 'housing' => ['Housing', ['sheds']],
        'growth' => ['Growth', ['weights']], 'operations' => ['Operations', ['tasks']], 'inventory' => ['Inventory', ['inventory', 'feeding', 'health']],
        'purchase' => ['Purchase', ['purchase', 'animal_purchase', 'finance']], 'sales' => ['Sales', ['sales', 'finance']], 'accounting' => ['Accounting', ['finance']],
        'employees' => ['Employees', ['employees']], 'maintenance' => ['Maintenance', ['maintenance', 'sensors']], 'dairy' => ['Dairy', ['milking']],
        'fattening' => ['Fattening', ['fattening']], 'welfare' => ['Welfare', ['welfare']], 'environment' => ['Environment', ['environment']],
        'quality' => ['Quality', ['quality']], 'fodder' => ['Fodder', ['fodder']], 'settings' => ['Settings', ['settings']],
    ];

    /** D-314: the transaction ID of each operation: this code and the record's number (WT-1523). */
    public const CODES = ['register' => 'RG', 'weight' => 'WT', 'height' => 'HT', 'move' => 'MV', 'exit' => 'EX', 'photo' => 'PH', 'edit' => 'ED', 'rfid' => 'RF', 'checkup' => 'CK', 'case' => 'HC', 'treatment' => 'TR', 'dose' => 'DS', 'care' => 'VC', 'teeth' => 'TT', 'death' => 'DR', 'parasite' => 'PS', 'outbreak' => 'OB', 'heat' => 'HE', 'mating' => 'MT', 'ultrasound' => 'US', 'breeding_ok' => 'BA', 'selection' => 'RS', 'kidding' => 'KD', 'kid_care' => 'KC', 'weaning' => 'WN', 'repro_loss' => 'PL', 'feed_given' => 'FG', 'feeding_round' => 'FR', 'feed_stock' => 'FS', 'feed_count' => 'FC', 'tmr' => 'TM', 'feed_lot' => 'FL', 'ration' => 'RP', 'pen_clean' => 'PC', 'growth_case' => 'GF', 'task_done' => 'TK', 'checklist' => 'CL', 'incident' => 'IN', 'handover' => 'SH', 'medicine_stock' => 'MS', 'store_move' => 'SM', 'purchase_order' => 'PO', 'goat_lot' => 'GL', 'sale' => 'SL', 'sale_order' => 'SO', 'clearance' => 'SC', 'trip' => 'TP', 'journal' => 'JE', 'money' => 'MN', 'attendance' => 'AT', 'leave' => 'LV', 'training' => 'TN', 'skill' => 'SK', 'repair' => 'MJ', 'sensor_alert' => 'SA', 'milking' => 'MK', 'milk_session' => 'MSS', 'milk_dispatch' => 'MD', 'processing' => 'MP', 'lactation' => 'LC', 'fattening_batch' => 'FB', 'fattening_goat' => 'FA', 'welfare_check' => 'WC', 'welfare_case' => 'WCS', 'welfare_action' => 'WA', 'end_of_life' => 'EL', 'env_round' => 'ER', 'env_issue' => 'EI', 'waste' => 'WD', 'water_test' => 'WTT', 'quality_case' => 'QC', 'capa' => 'CA', 'hold' => 'QH', 'fodder_crop' => 'CR', 'fodder_input' => 'CI', 'fodder_lot' => 'HV', 'access' => 'AC'];

    /** Health operations: only for the right to see health history (F-15). */
    public const HEALTH = ['checkup', 'case', 'treatment', 'dose', 'care', 'teeth', 'death', 'parasite', 'outbreak', 'repro_loss'];

    /**
     * op => [label, apps (first = where it belongs), table, date column, by column, goat column, details]
     * details: fn (object $r, OpsLog $l): string
     */
    public static function ops(): array
    {
        $kg = fn ($v) => $v === null ? null : rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.').' KG';
        $pkr = fn ($minor) => $minor === null ? null : 'PKR '.number_format($minor / 100);
        $t = fn (...$parts) => implode(' · ', array_filter(array_map(fn ($p) => is_string($p) ? trim($p) : $p, $parts), fn ($p) => $p !== null && $p !== '' && $p !== false));
        $w = fn ($v) => $v === null ? null : ucfirst(str_replace('_', ' ', (string) $v));

        return [
            // Livestock
            'register' => ['Registered', ['livestock', 'purchase'], 'animals', 'created_at', null, 'id', fn ($r) => $t($w($r->origin), $r->source_farm ? "from {$r->source_farm}" : null)],
            'weight' => ['Weight', ['livestock', 'growth'], 'weight_records', 'measured_on', 'measured_by', 'animal_id', fn ($r) => $t($kg($r->weight_kg), $r->body_score ? "body score {$r->body_score}" : null)],
            'height' => ['Height', ['livestock', 'growth'], 'height_records', 'measured_on', 'measured_by', 'animal_id', fn ($r) => round($r->height_cm / 2.54, 1).' in'],
            'move' => ['PEN move', ['livestock', 'housing'], 'animal_movements', 'moved_on', 'recorded_by', 'animal_id', fn ($r, $l) => $t(($l->pen($r->from_pen_id) ?? '?').' → '.($l->pen($r->to_pen_id) ?? '?'), $r->reason)],
            'exit' => ['Exit', ['livestock', 'sales'], 'animal_movements', 'moved_on', 'recorded_by', 'animal_id', fn ($r) => $t($w($r->type), $r->reason, $r->destination)],
            'photo' => ['Photo', ['livestock'], 'animal_photos', 'created_at', 'added_by', 'animal_id', fn ($r) => $r->is_profile ? 'Profile photo' : ($r->notes ?: 'Photo')],
            'edit' => ['Details edited', ['livestock'], 'animal_events', 'occurred_at', 'recorded_by', 'animal_id', fn ($r) => $r->summary],
            'rfid' => ['RFID linked', ['livestock'], 'rfid_assignments', 'valid_from', 'assigned_by', 'animal_id', fn ($r) => $w($r->reason_assigned) ?: 'Tag linked'],
            // Veterinary
            'checkup' => ['Checkup', ['veterinary'], 'health_checkups', 'checked_on', 'examiner', 'animal_id', fn ($r) => $t($r->temperature_f ? "{$r->temperature_f} °F" : null, $kg($r->weight_kg), $w($r->outcome), $r->findings)],
            'case' => ['Health case', ['veterinary'], 'health_cases', 'onset_on', 'recorded_by', 'animal_id', fn ($r) => $t("Case H-{$r->case_no}", $r->condition, $w($r->severity), $w($r->status))],
            'treatment' => ['Treatment', ['veterinary'], 'treatments', 'given_on', 'recorded_by', 'animal_id', fn ($r) => $t($r->product, $r->dose, $r->days_prescribed ? "{$r->days_prescribed} days" : null)],
            'dose' => ['Dose', ['veterinary'], 'treatment_doses', 'done_at', 'done_by', 'animal_id', fn ($r, $l) => $t($l->product($r->treatment_id), "dose {$r->number}", $w($r->status), $r->adverse ? 'reaction' : null, $r->notes)],
            'care' => ['Vaccination / deworming', ['veterinary'], 'health_records', 'occurred_on', 'recorded_by', 'animal_id', fn ($r) => $t($w($r->kind), $r->title)],
            'teeth' => ['Teeth check', ['veterinary'], 'dental_exams', 'examined_on', 'recorded_by', 'animal_id', fn ($r) => $t($r->dental_code, $w($r->condition))],
            'death' => ['Death report', ['veterinary'], 'death_reports', 'died_at', 'reported_by', 'animal_id', fn ($r) => $t($r->cause, $w($r->status))],
            'parasite' => ['Parasite test', ['veterinary'], 'parasite_samples', 'sampled_on', 'recorded_by', 'animal_id', fn ($r) => $t($r->sample_no, $r->epg !== null ? "EPG {$r->epg}" : null, $r->famacha ? "FAMACHA {$r->famacha}" : null, $w($r->decision))],
            'outbreak' => ['Outbreak', ['veterinary'], 'outbreaks', 'opened_on', 'opened_by', null, fn ($r) => $t($r->code, $r->condition, $w($r->status))],
            // Breeding and Maternity
            'heat' => ['Heat', ['breeding'], 'heat_records', 'observed_on', 'observed_by', 'dam_id', fn ($r) => $w($r->intensity)],
            'mating' => ['Mating', ['breeding'], 'matings', 'mated_on', 'recorded_by', 'dam_id', fn ($r, $l) => $t('With '.($l->goat($r->sire_id) ?? 'unknown buck'), $w($r->method))],
            'ultrasound' => ['Ultrasound', ['breeding'], 'pregnancy_checks', 'checked_on', 'examiner', 'dam_id', fn ($r) => $t($w($r->result), $r->fetuses ? "{$r->fetuses} fetus(es)" : null, $r->pregnancy_day ? "day {$r->pregnancy_day}" : null)],
            'breeding_ok' => ['Breeding approval', ['breeding'], 'breeding_approvals', 'decided_on', 'decided_by', 'animal_id', fn ($r) => $t($w($r->decision), $r->reason)],
            'selection' => ['Replacement review', ['breeding'], 'breeder_selections', 'decided_on', 'decided_by', 'animal_id', fn ($r) => $t($w($r->decision), $r->reason)],
            'kidding' => ['Kidding', ['maternity'], 'kiddings', 'kidded_on', 'recorded_by', 'dam_id', fn ($r) => $t("{$r->born_alive} alive", $r->stillborn ? "{$r->stillborn} stillborn" : null, $w($r->delivery_method))],
            'kid_care' => ['Kid care', ['maternity'], 'kid_care_records', 'given_at', 'recorded_by', 'kid_id', fn ($r) => $t($w($r->kind), $r->amount_ml ? "{$r->amount_ml} ml" : null, $r->notes)],
            'weaning' => ['Weaning', ['maternity'], 'weanings', 'weaned_on', 'handler', 'kid_id', fn ($r) => $t($w($r->status), $kg($r->weight_kg))],
            'repro_loss' => ['Pregnancy loss', ['maternity'], 'repro_loss_cases', 'occurred_on', null, 'dam_id', fn ($r) => $t($r->case_no, $w($r->loss_type), $r->kids_lost ? "{$r->kids_lost} lost" : null, $w($r->status))],
            // Feeding
            'feed_given' => ['Feed given', ['feeding'], 'feed_usages', 'fed_on', 'fed_by', null, fn ($r, $l) => $t($l->pen($r->pen_id), $w($r->meal), $l->feed($r->feed_item_id), 'offered '.$kg($r->offered_kg), $r->refused_kg ? 'refused '.$kg($r->refused_kg) : null)],
            'feeding_round' => ['Feeding round', ['feeding'], 'feeding_logs', 'fed_on', 'fed_by', null, fn ($r, $l) => $t($l->group($r->feeding_group_id), $w($r->session), $w($r->status))],
            'feed_stock' => ['Feed stock move', ['feeding', 'inventory'], 'feed_stock_movements', 'moved_on', 'recorded_by', null, fn ($r, $l) => $t($l->feed($r->feed_item_id), $w($r->type), $kg($r->qty_kg), $r->party)],
            'feed_count' => ['Feed stock count', ['feeding', 'inventory'], 'feed_stock_counts', 'counted_on', 'counted_by', null, fn ($r, $l) => $t($l->feed($r->feed_item_id), 'counted '.$kg($r->counted_kg), 'difference '.$kg($r->diff_kg))],
            'tmr' => ['TMR batch', ['feeding'], 'tmr_batches', 'mixed_on', 'mixed_by', null, fn ($r) => $t($r->batch_no, $kg($r->total_kg))],
            'feed_lot' => ['Feed received', ['feeding', 'inventory'], 'feed_lots', 'received_on', 'received_by', null, fn ($r, $l) => $t($r->lot_no, $l->feed($r->feed_item_id), $kg($r->qty_kg), $w($r->status))],
            'ration' => ['Ration plan', ['feeding'], 'ration_plans', 'effective_from', 'created_by', null, fn ($r, $l) => $t($l->group($r->feeding_group_id), $w($r->status))],
            // Housing
            'pen_clean' => ['PEN cleaning', ['housing'], 'pen_cleanings', 'started_at', 'done_by', null, fn ($r, $l) => $t($l->pen($r->pen_id), $w($r->kind), $r->disinfectant)],
            // Growth
            'growth_case' => ['Growth follow-up', ['growth'], 'growth_interventions', 'opened_on', 'opened_by', 'animal_id', fn ($r) => $t($w($r->kind), $r->reason, $w($r->status))],
            // Operations
            'task_done' => ['Task done', ['operations'], 'tasks', 'completed_at', 'completed_by', 'animal_id', fn ($r) => $t($r->title, $w($r->type))],
            'checklist' => ['Checklist', ['operations'], 'checklist_runs', 'run_on', 'completed_by', null, fn ($r, $l) => $t($l->checklist($r->template_id), $r->completed_at ? 'completed' : 'started')],
            'incident' => ['Incident', ['operations'], 'incidents', 'occurred_at', 'reported_by', 'animal_id', fn ($r) => $t($w($r->kind), $w($r->severity), $r->description, $w($r->status))],
            'handover' => ['Shift handover', ['operations'], 'shift_handovers', 'handed_on', 'from_name', null, fn ($r) => $t($w($r->shift), "to {$r->to_name}", $r->summary)],
            // Inventory
            'medicine_stock' => ['Medicine stock move', ['inventory'], 'medicine_movements', 'moved_on', 'recorded_by', null, fn ($r, $l) => $t($l->medicine($r->medicine_id), $w($r->type), (float) $r->qty, $r->batch ? "batch {$r->batch}" : null)],
            'store_move' => ['Store item move', ['inventory'], 'store_moves', 'moved_on', 'recorded_by', null, fn ($r, $l) => $t($l->store($r->store_item_id), $w($r->type), (float) $r->qty, $r->issued_to)],
            // Purchase
            'purchase_order' => ['Purchase order', ['purchase'], 'purchase_orders', 'ordered_on', 'created_by', null, fn ($r, $l) => $t($r->po_no, $l->supplier($r->supplier_id), $w($r->status))],
            'goat_lot' => ['Goat purchase lot', ['purchase'], 'animal_purchase_lots', 'created_at', 'requested_by', null, fn ($r, $l) => $t($r->lot_no, $l->supplier($r->supplier_id), $r->expected_head ? "{$r->expected_head} goats" : null, $w($r->status))],
            // Sales
            'sale' => ['Sale', ['sales'], 'sales', 'sold_on', 'recorded_by', 'animal_id', fn ($r) => $t($w($r->category), $r->item, $r->buyer, $pkr($r->gross_minor))],
            'sale_order' => ['Sale order', ['sales'], 'sale_orders', 'created_at', 'created_by', null, fn ($r, $l) => $t($r->order_no, $l->customer($r->customer_id) ?? $r->buyer, $w($r->status))],
            'clearance' => ['Sale clearance', ['sales'], 'sale_clearances', 'decided_on', 'decided_by', 'animal_id', fn ($r) => $t($w($r->decision), $r->reason)],
            'trip' => ['Transport trip', ['sales'], 'transport_trips', 'created_at', 'created_by', null, fn ($r) => $t($r->trip_no, $r->destination, $r->head_loaded ? "{$r->head_loaded} goats" : null, $w($r->status))],
            // Accounting
            'journal' => ['Journal entry', ['accounting'], 'journal_entries', 'posted_on', 'recorded_by', null, fn ($r) => $t("#{$r->entry_no}", $r->memo, $r->reference)],
            'money' => ['Money in / out', ['accounting'], 'ledger_entries', 'occurred_on', 'recorded_by', 'animal_id', fn ($r) => $t($r->direction === 'in' ? 'In' : 'Out', $w($r->category), 'PKR '.number_format((float) $r->amount), $r->description)],
            // Employees
            'attendance' => ['Attendance', ['employees'], 'attendances', 'work_on', 'recorded_by', null, fn ($r, $l) => $t($l->employee($r->employee_id), $w($r->status))],
            'leave' => ['Leave request', ['employees'], 'leave_requests', 'created_at', 'requested_by', null, fn ($r, $l) => $t($l->employee($r->employee_id), $w($r->kind), Carbon::parse($r->from_on)->format('d/m/Y').' to '.Carbon::parse($r->to_on)->format('d/m/Y'), $w($r->status))],
            'training' => ['Training', ['employees'], 'staff_trainings', 'trained_on', 'recorded_by', null, fn ($r, $l) => $t($l->employee($r->employee_id), $r->title ?: $r->topic)],
            'skill' => ['Skill assessed', ['employees'], 'staff_skills', 'assessed_on', 'assessed_by', null, fn ($r, $l) => $t($l->employee($r->employee_id), $w($r->skill), $w($r->level))],
            // Maintenance
            'repair' => ['Maintenance job', ['maintenance'], 'maintenance_jobs', 'requested_on', 'requested_by', null, fn ($r, $l) => $t($l->asset($r->asset_id), $w($r->kind), $r->problem, $w($r->status))],
            'sensor_alert' => ['Sensor alert', ['maintenance'], 'sensor_alerts', 'created_at', null, 'animal_id', fn ($r) => $t($r->title, $w($r->severity), $w($r->status))],
            // Dairy
            'milking' => ['Milking', ['dairy'], 'milk_records', 'milked_on', 'recorded_by', 'animal_id', fn ($r) => $t($w($r->session), (float) $r->litres.' L', $r->discarded ? 'discarded' : null)],
            'milk_session' => ['Milking session', ['dairy'], 'milk_sessions', 'milked_on', 'operator', null, fn ($r) => $t($w($r->session), $r->produced_l !== null ? (float) $r->produced_l.' L' : null)],
            'milk_dispatch' => ['Milk dispatch', ['dairy'], 'milk_dispatches', 'dispatched_on', 'recorded_by', null, fn ($r, $l) => $t($l->customer($r->customer_id) ?? $r->buyer, (float) $r->litres.' L', $r->invoice_no)],
            'processing' => ['Milk processing', ['dairy'], 'milk_processing_batches', 'made_on', 'made_by', null, fn ($r) => $t($r->code, $w($r->product), $r->output_qty ? (float) $r->output_qty.' '.$r->output_unit : null)],
            'lactation' => ['Lactation', ['dairy'], 'lactations', 'started_on', 'recorded_by', 'animal_id', fn ($r) => $t("lactation {$r->number}", $r->dried_on ? 'dried' : 'started')],
            // Fattening
            'fattening_batch' => ['Fattening batch', ['fattening'], 'fattening_batches', 'started_on', 'created_by', null, fn ($r) => $t($r->code, $r->name, $w($r->status))],
            'fattening_goat' => ['Goat into batch', ['fattening'], 'fattening_batch_animals', 'joined_on', null, 'animal_id', fn ($r) => $t($kg($r->entry_weight_kg), $w($r->status))],
            // Welfare
            'welfare_check' => ['Welfare check', ['welfare'], 'welfare_checks', 'checked_on', 'checked_by', 'animal_id', fn ($r) => $t($r->bcs ? "BCS {$r->bcs}" : null, $w($r->behaviour), $r->injury ? 'injury' : null, $r->lameness ? 'lame' : null)],
            'welfare_case' => ['Welfare case', ['welfare'], 'welfare_cases', 'opened_on', 'opened_by', 'animal_id', fn ($r) => $t($r->case_no, $w($r->issue), $w($r->severity), $w($r->status))],
            'welfare_action' => ['Welfare action', ['welfare'], 'welfare_actions', 'done_on', 'done_by', null, fn ($r) => $t($w($r->kind), $r->notes)],
            'end_of_life' => ['End-of-life review', ['welfare'], 'end_of_life_reviews', 'created_at', 'requested_by', 'animal_id', fn ($r) => $t($w($r->reason), $w($r->status))],
            // Environment
            'env_round' => ['Environment round', ['environment'], 'environment_rounds', 'round_at', 'recorded_by', null, fn ($r, $l) => $t($l->shed($r->shed_id), $r->temperature_c !== null ? "{$r->temperature_c} °C" : null, $r->thi ? "THI {$r->thi}" : null, $w($r->climate_risk))],
            'env_issue' => ['Environment issue', ['environment'], 'environment_issues', 'created_at', null, null, fn ($r, $l) => $t($l->shed($r->shed_id), $w($r->kind), $w($r->severity), $w($r->status))],
            'waste' => ['Waste disposal', ['environment'], 'waste_disposals', 'disposed_on', 'recorded_by', 'animal_id', fn ($r) => $t((float) $r->qty.' '.$r->unit, $w($r->method))],
            'water_test' => ['Water test', ['environment'], 'water_tests', 'sampled_on', 'recorded_by', null, fn ($r) => $t($r->lab, $w($r->verdict))],
            // Quality
            'quality_case' => ['Quality case', ['quality'], 'quality_cases', 'opened_on', 'opened_by', 'animal_id', fn ($r) => $t($r->case_no, $r->title, $w($r->severity), $w($r->status))],
            'capa' => ['Corrective action', ['quality'], 'quality_capas', 'created_at', 'planned_by', null, fn ($r) => $t($w($r->kind), $r->action, $w($r->status))],
            'hold' => ['Quality hold', ['quality'], 'quality_holds', 'placed_at', 'placed_by', 'animal_id', fn ($r) => $t($w($r->kind), $r->subject, $w($r->outcome))],
            // Fodder
            'fodder_crop' => ['Crop sown', ['fodder'], 'fodder_crops', 'sown_on', 'created_by', null, fn ($r) => $t($r->crop, $r->variety, $r->area_acres ? (float) $r->area_acres.' acres' : null)],
            'fodder_input' => ['Crop input', ['fodder'], 'fodder_inputs', 'applied_on', 'recorded_by', null, fn ($r) => $t($w($r->kind), $r->product, $r->qty ? (float) $r->qty.' '.$r->unit : null)],
            'fodder_lot' => ['Harvest', ['fodder'], 'fodder_lots', 'harvested_on', 'harvested_by', null, fn ($r) => $t($r->lot_no, $kg($r->fresh_kg), $w($r->status))],
            // Settings
            'access' => ['Access change', ['settings'], 'access_changes', 'created_at', 'actor', null, fn ($r) => $t($r->subject_label, $r->item_label, ($r->old_value ?? '—').' → '.($r->new_value ?? '—'))],
        ];
    }

    /**
     * D-313: what Correct may change for every other operation (column => label). The record itself changes, so every
     * page and report shows it; the old values stay as a revision. Fields that feed other records are left to their own
     * screens (a move's PENs, a sale's amount, stock rates), and the books are corrected with a reversing entry.
     */
    public const FIX = [
        'move' => ['moved_on' => 'Date', 'reason' => 'Reason', 'notes' => 'Notes'],
        'exit' => ['moved_on' => 'Date', 'reason' => 'Reason', 'destination' => 'Destination', 'notes' => 'Notes'],
        'photo' => ['notes' => 'Notes'],
        'case' => ['onset_on' => 'Started on', 'condition' => 'Condition', 'diagnosis' => 'Diagnosis', 'notes' => 'Notes'],
        'treatment' => ['given_on' => 'Date given', 'dose' => 'Dose', 'meat_clear_on' => 'Meat clear on', 'milk_clear_on' => 'Milk clear on', 'notes' => 'Notes'],
        'dose' => ['notes' => 'Notes'],
        'care' => ['occurred_on' => 'Date', 'title' => 'What was given', 'batch_lot' => 'Batch', 'notes' => 'Notes'],
        'teeth' => ['examined_on' => 'Date', 'dental_code' => 'Teeth code', 'notes' => 'Notes'],
        'death' => ['died_at' => 'Date of death', 'cause' => 'Cause', 'circumstances' => 'Circumstances'],
        'parasite' => ['sampled_on' => 'Date', 'epg' => 'EPG', 'famacha' => 'FAMACHA', 'notes' => 'Notes'],
        'outbreak' => ['opened_on' => 'Opened on', 'condition' => 'Condition'],
        'heat' => ['observed_on' => 'Date', 'signs' => 'Signs', 'notes' => 'Notes'],
        'mating' => ['mated_on' => 'Mating date', 'notes' => 'Notes'],
        'ultrasound' => ['checked_on' => 'Date', 'fetuses' => 'Fetuses', 'notes' => 'Notes'],
        'breeding_ok' => ['reason' => 'Reason'],
        'selection' => ['reason' => 'Reason'],
        'kidding' => ['kidded_on' => 'Kidding date', 'complications' => 'Complications', 'notes' => 'Notes'],
        'kid_care' => ['amount_ml' => 'Amount (ml)', 'notes' => 'Notes'],
        'weaning' => ['weaned_on' => 'Weaning date', 'weight_kg' => 'Weight (KG)', 'notes' => 'Notes'],
        'repro_loss' => ['occurred_on' => 'Date', 'kids_lost' => 'Kids lost'],
        'feed_given' => ['refused_kg' => 'Refused (KG)', 'wasted_kg' => 'Wasted (KG)'],
        'feeding_round' => ['fed_on' => 'Date'],
        'feed_stock' => ['moved_on' => 'Date', 'qty_kg' => 'Quantity (KG)', 'party' => 'Party', 'reference' => 'Reference', 'notes' => 'Notes'],
        'feed_count' => ['notes' => 'Notes'],
        'tmr' => ['output_kg' => 'Output (KG)', 'notes' => 'Notes'],
        'feed_lot' => ['moisture_pct' => 'Moisture %', 'sample_ref' => 'Sample'],
        'ration' => ['notes' => 'Notes'],
        'pen_clean' => ['disinfectant' => 'Disinfectant', 'notes' => 'Notes'],
        'growth_case' => ['reason' => 'Reason', 'action' => 'Action'],
        'task_done' => ['notes' => 'Notes'],
        'checklist' => ['notes' => 'Notes'],
        'incident' => ['description' => 'What happened', 'resolution' => 'Resolution'],
        'handover' => ['summary' => 'Summary', 'pending' => 'Pending'],
        'medicine_stock' => ['moved_on' => 'Date', 'qty' => 'Quantity', 'batch' => 'Batch', 'party' => 'Party', 'reference' => 'Reference', 'notes' => 'Notes'],
        'store_move' => ['moved_on' => 'Date', 'qty' => 'Quantity', 'party' => 'Party', 'issued_to' => 'Issued to', 'reference' => 'Reference', 'notes' => 'Notes'],
        'purchase_order' => ['expected_on' => 'Expected on', 'notes' => 'Notes'],
        'goat_lot' => ['expected_head' => 'Goats expected'],
        'sale' => ['buyer' => 'Buyer', 'notes' => 'Notes'],
        'sale_order' => ['buyer' => 'Buyer', 'notes' => 'Notes'],
        'clearance' => ['reason' => 'Reason'],
        'trip' => ['destination' => 'Destination', 'driver' => 'Driver', 'distance_km' => 'Distance (km)'],
        'attendance' => ['notes' => 'Notes'],
        'leave' => ['reason' => 'Reason'],
        'training' => ['title' => 'Title', 'trainer' => 'Trainer', 'certificate_ref' => 'Certificate'],
        'skill' => ['evidence' => 'Evidence'],
        'repair' => ['problem' => 'Problem', 'work_done' => 'Work done'],
        'milking' => ['litres' => 'Litres', 'discard_reason' => 'Discard reason'],
        'milk_session' => ['notes' => 'Notes'],
        'milk_dispatch' => ['litres' => 'Litres', 'buyer' => 'Buyer', 'invoice_no' => 'Invoice'],
        'processing' => ['output_qty' => 'Output', 'notes' => 'Notes'],
        'lactation' => ['dry_reason' => 'Dry reason'],
        'fattening_batch' => ['name' => 'Name', 'notes' => 'Notes'],
        'fattening_goat' => ['entry_weight_kg' => 'Entry weight (KG)'],
        'welfare_check' => ['bcs' => 'BCS', 'notes' => 'Notes'],
        'welfare_case' => ['handling_note' => 'Handling note'],
        'welfare_action' => ['notes' => 'Notes'],
        'end_of_life' => ['details' => 'Details'],
        'env_round' => ['temperature_c' => 'Temperature (°C)', 'humidity_pct' => 'Humidity %', 'notes' => 'Notes'],
        'env_issue' => ['description' => 'Description', 'resolution' => 'Resolution'],
        'waste' => ['qty' => 'Quantity', 'notes' => 'Notes'],
        'water_test' => ['ph' => 'pH', 'tds_ppm' => 'TDS (ppm)', 'notes' => 'Notes'],
        'quality_case' => ['title' => 'Title', 'description' => 'Description', 'root_cause' => 'Root cause', 'learning' => 'Learning'],
        'capa' => ['action' => 'Action', 'execution_notes' => 'Execution notes'],
        'hold' => ['release_note' => 'Release note'],
        'fodder_crop' => ['variety' => 'Variety', 'notes' => 'Notes'],
        'fodder_input' => ['product' => 'Product', 'qty' => 'Quantity'],
        'fodder_lot' => ['fresh_kg' => 'Fresh (KG)', 'decision_note' => 'Note'],
    ];

    private const NUMBER = ['epg', 'famacha', 'fetuses', 'amount_ml', 'weight_kg', 'kids_lost', 'refused_kg', 'wasted_kg', 'qty_kg', 'output_kg', 'moisture_pct', 'qty', 'expected_head',
        'distance_km', 'litres', 'output_qty', 'entry_weight_kg', 'bcs', 'temperature_c', 'humidity_pct', 'ph', 'tds_ppm', 'fresh_kg'];

    public static function fieldType(string $col): string
    {
        return in_array($col, self::NUMBER, true) ? 'number' : (preg_match('/(_on|_at)$/', $col) ? 'date' : 'text');
    }

    /** D-311: operations whose record can be corrected from the log, with the values the correction form starts from. */
    public static function fixValues(string $op, object $r): ?array
    {
        $d = fn ($v) => $v ? substr((string) $v, 0, 10) : null;

        return match ($op) {
            'weight' => ['id' => $r->id, 'measured_on' => $d($r->measured_on), 'weight_kg' => $r->weight_kg, 'body_score' => $r->body_score],
            'height' => ['id' => $r->id, 'measured_on' => $d($r->measured_on), 'height_in' => round($r->height_cm / 2.54, 1)],
            'checkup' => ['id' => $r->id, 'checked_on' => $d($r->checked_on), 'temperature_f' => $r->temperature_f, 'weight_kg' => $r->weight_kg, 'body_score' => $r->body_score,
                'findings' => $r->findings, 'outcome' => $r->outcome, 'ultrasound' => $r->ultrasound, 'fetuses' => $r->fetuses, 'ultrasound_status' => $r->ultrasound_status,
                'viability' => $r->viability, 'days_pregnant' => $r->days_pregnant, 'follow_up_on' => $d($r->follow_up_on), 'follow_up_note' => $r->follow_up_note],
            'register' => ['id' => $r->id, 'link' => "/goats/{$r->id}/edit"], // the goat's details: the edit page (Administrator)
            default => isset(self::FIX[$op]) ? ['id' => $r->id, 'fields' => collect(self::FIX[$op])->filter(fn ($l, $c) => property_exists($r, $c))
                ->map(fn ($label, $col) => ['key' => $col, 'label' => $label, 'type' => self::fieldType($col), 'value' => self::fieldType($col) === 'date' ? $d($r->{$col}) : $r->{$col}])->values()->all()] : null,
        };
    }

    /** The operations an app shows, that this person may see. */
    public static function forApp(?string $app, bool $health): array
    {
        return collect(self::ops())->filter(fn ($o, $k) => ($app === null || in_array($app, $o[1], true)) && ($health || ! in_array($k, self::HEALTH, true)))->all();
    }

    public static function rows(array $ops): Collection
    {
        $l = new self;
        $tz = 'Asia/Karachi';
        $out = collect();
        foreach ($ops as $op => [$label, $apps, $table, $dateCol, $byCol, $goatCol, $detail]) {
            $q = DB::table($table);
            if ($table === 'animal_movements') {
                $op === 'move' ? $q->whereNotNull('from_pen_id')->whereNotNull('to_pen_id') : $q->where(fn ($x) => $x->whereNull('from_pen_id')->orWhereNull('to_pen_id'));
            }
            match ($op) {
                'register' => $q->whereNull('voided_at'),
                'edit' => $q->where('type', 'edit'),
                'dose' => $q->where('status', '<>', 'due')->where('number', '>', 1),
                'care' => $q->whereIn('kind', ['vaccination', 'deworming']),
                'task_done' => $q->where('status', 'done')->whereNotNull('completed_at'),
                default => null,
            };
            foreach ($q->get() as $r) {
                $raw = $r->{$dateCol} ?? $r->created_at ?? null;
                if (! $raw) {
                    continue;
                }
                $on = strlen((string) $raw) <= 10 ? Carbon::parse($raw)->toDateString() : Carbon::parse($raw, 'UTC')->setTimezone($tz)->toDateString();
                $entered = isset($r->created_at) ? Carbon::parse($r->created_at, 'UTC')->setTimezone($tz) : null;
                $goatId = $goatCol ? ($r->{$goatCol} ?? null) : null;
                $g = $goatId ? $l->animal($goatId) : null;
                $out->push([
                    'key' => "$op-".($r->id ?? $out->count()), 'op' => $op,
                    'txn' => (self::CODES[$op] ?? strtoupper(substr($op, 0, 2))).'-'.(is_numeric($r->id ?? null) ? $r->id : strtoupper(substr((string) ($r->id ?? ''), -6))), // D-314 'app' => self::APPS[$apps[0]][0], 'operation' => $label, 'on' => $on,
                    'entered' => $entered?->format('d/m/Y g:i A'), 'sort' => $on.' '.($entered?->format('H:i:s') ?? '00:00:00'),
                    'goatId' => $goatId, 'number' => $g?->farm_number, 'detail' => $detail($r, $l) ?: null, 'by' => $byCol ? ($r->{$byCol} ?? null) : ($op === 'register' ? $l->registeredBy($r->id) : null),
                    'source' => isset($r->source) && $r->source ? ucfirst((string) $r->source) : 'Manual',
                    'breed' => $g ? $l->breed($g->breed_id) : null, 'sex' => $g ? ($g->sex === 'female' ? 'Female' : 'Male') : null, 'pen' => $g ? $l->pen($g->pen_id) : null,
                    'status' => $g ? ($g->status === 'active' ? 'On farm' : 'Left') : null,
                    'fix' => self::fixValues($op, $r), // D-311: the record's values, for Correct
                ]);
            }
        }

        return $out->sortByDesc('sort')->values();
    }

    // name lookups, loaded once each
    private array $cache = [];

    private function map(string $table, string $col = 'name'): Collection
    {
        return $this->cache["$table.$col"] ??= DB::table($table)->pluck($col, 'id');
    }

    public function animal(?string $id): ?object
    {
        $this->cache['animals'] ??= DB::table('animals')->get(['id', 'farm_number', 'breed_id', 'sex', 'pen_id', 'status'])->keyBy('id');

        return $id ? $this->cache['animals'][$id] ?? null : null;
    }

    public function registeredBy(string $id): ?string
    {
        $this->cache['origin'] ??= DB::table('animal_events')->where('type', 'origin')->pluck('recorded_by', 'animal_id');

        return $this->cache['origin'][$id] ?? null;
    }

    public function goat(?string $id): ?string { return $this->animal($id)?->farm_number; }
    public function pen($id): ?string { return $id ? $this->map('pens')[$id] ?? null : null; }
    public function breed($id): ?string { return $id ? $this->map('breeds')[$id] ?? null : null; }
    public function feed($id): ?string { return $id ? $this->map('feed_items')[$id] ?? null : null; }
    public function group($id): ?string { return $id ? $this->map('feeding_groups')[$id] ?? null : null; }
    public function medicine($id): ?string { return $id ? $this->map('medicines')[$id] ?? null : null; }
    public function store($id): ?string { return $id ? $this->map('store_items')[$id] ?? null : null; }
    public function supplier($id): ?string { return $id ? $this->map('suppliers')[$id] ?? null : null; }
    public function customer($id): ?string { return $id ? $this->map('customers')[$id] ?? null : null; }
    public function employee($id): ?string { return $id ? $this->map('employees')[$id] ?? null : null; }
    public function asset($id): ?string { return $id ? $this->map('assets')[$id] ?? null : null; }
    public function shed($id): ?string { return $id ? $this->map('sheds')[$id] ?? null : null; }
    public function checklist($id): ?string { return $id ? $this->map('checklist_templates')[$id] ?? null : null; }
    public function product($id): ?string { return $id ? $this->map('treatments', 'product')[$id] ?? null : null; }
}
