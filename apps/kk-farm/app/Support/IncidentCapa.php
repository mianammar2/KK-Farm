<?php

namespace App\Support;

use App\Models\Incident;
use App\Models\QualityCapa;
use App\Models\QualityCase;
use App\Models\SopRevision;
use App\Models\User;

/**
 * D-205: journey P Incident-to-Corrective Action (V3 flow 39). Quality → Owner → Operations → Analytics: detect,
 * contain, investigate and verify a preventive control. A high or critical incident, or a repeat of the same kind
 * at the same place within 90 days, goes to a quality case (root cause, CAPA, independent verification) and can only
 * be closed through it; closing the case closes the incident with the learning and any new SOP version.
 */
final class IncidentCapa
{
    public const REPEAT_DAYS = 90;

    /** The earlier incident this repeats: same kind, same goat or pen (or both unset), within 90 days. */
    public static function repeatOf(Incident $i): ?Incident
    {
        return Incident::where('id', '!=', $i->id)->where('kind', $i->kind)->where('occurred_at', '>=', $i->occurred_at->copy()->subDays(self::REPEAT_DAYS))
            ->where('occurred_at', '<=', $i->occurred_at)
            ->where(fn ($q) => $i->animal_id ? $q->where('animal_id', $i->animal_id) : ($i->pen_id ? $q->where('pen_id', $i->pen_id) : $q->whereNull('animal_id')->whereNull('pen_id')))
            ->orderByDesc('occurred_at')->first();
    }

    public static function mustEscalate(Incident $i): bool
    {
        return in_array($i->severity, ['high', 'critical'], true) || $i->recurrence_of_id !== null;
    }

    /** Open the quality case (contained at once when critical or a containment is chosen). */
    public static function escalate(Incident $i, User $by, ?string $containment = null): QualityCase
    {
        if ($i->quality_case_id) {
            return QualityCase::findOrFail($i->quality_case_id);
        }
        $label = Incident::KINDS[$i->kind] ?? $i->kind;
        $case = QualityCases::open([
            'source' => 'incident', 'title' => ($i->recurrence_of_id ? 'Repeat incident: ' : 'Incident: ').$label, 'severity' => $i->severity,
            'description' => $i->description.($i->recurrence_of_id ? ' (repeat of incident #'.$i->recurrence_of_id.')' : ''),
            'subject' => $i->animal_id ? 'animal' : 'process', 'animal_id' => $i->animal_id, 'process' => $i->animal_id ? null : $label.($i->pen ? " · {$i->pen->fullName()}" : ''),
            'incident_id' => $i->id, 'immediate_risk' => $containment !== null || ($i->severity === 'critical' && $i->animal_id), 'containment' => $containment ?? ($i->severity === 'critical' && $i->animal_id ? 'isolate' : null),
        ], $by);
        $i->update(['quality_case_id' => $case->id, 'status' => 'escalated']);

        return $case;
    }

    /** The case closed: its incidents close with the learning and the SOP version now in force. */
    public static function caseClosed(QualityCase $case): void
    {
        foreach (Incident::where('quality_case_id', $case->id)->where('status', '!=', 'resolved')->get() as $i) {
            $rev = SopRevision::where('case_id', $case->id)->whereNotNull('approved_at')->when($i->sop_id, fn ($q) => $q->where('sop_id', $i->sop_id))->orderByDesc('version')->first();
            $i->update(['status' => 'resolved', 'resolved_by' => $case->closed_by, 'resolved_at' => now(), 'root_cause' => $i->root_cause ?? $case->root_cause,
                'resolution' => mb_substr("Closed through {$case->label()}: {$case->learning}", 0, 1000), 'sop_version_after' => $rev?->version]);
        }
    }

    /** @return array<string, mixed> CAPA closure %, repeat incidents, escalations still open */
    public static function kpis(): array
    {
        $since = now()->subDays(180);
        $cases = QualityCase::whereIn('id', Incident::whereNotNull('quality_case_id')->pluck('quality_case_id'))->where('created_at', '>=', $since)->get();
        $capas = QualityCapa::whereIn('case_id', $cases->modelKeys())->get()->reject(fn ($c) => $c->status === 'failed');
        $recent = Incident::where('occurred_at', '>=', now()->subDays(90))->get();

        return [
            'capaClosure' => $capas->count() ? (int) round($capas->where('status', 'passed')->count() / $capas->count() * 100) : null,
            'casesClosed' => $cases->where('status', 'closed')->count(), 'cases' => $cases->count(),
            'repeats' => $recent->whereNotNull('recurrence_of_id')->count(), 'repeatRate' => $recent->count() ? (int) round($recent->whereNotNull('recurrence_of_id')->count() / $recent->count() * 100) : null,
            'escalatedOpen' => Incident::where('status', 'escalated')->count(),
        ];
    }
}
