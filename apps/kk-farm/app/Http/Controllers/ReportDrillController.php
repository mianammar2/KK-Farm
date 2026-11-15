<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Support\Pages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * D-322: what is behind a figure on Farm Reports, for a pop card on the same page (no jump to another app): the goats
 * of a breed / sex / age stage / teeth code / exit reason / unwell, the kiddings, a buck's kids, or a spending
 * category's payments. Each part only for those who may open that report.
 */
class ReportDrillController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $u = $request->user();
        $may = fn (string $section) => Pages::allows($u, '/reports', ['section' => $section]);
        $q = $request->validate(['what' => ['required', 'in:goats,kiddings,offspring,spending'], 'breed' => ['nullable', 'string', 'max:80'], 'sex' => ['nullable', 'in:female,male'],
            'stage' => ['nullable', 'string', 'max:20'], 'teeth' => ['nullable', 'string', 'max:20'], 'exit' => ['nullable', 'string', 'max:40'], 'health' => ['nullable', 'in:unwell'],
            'sire' => ['nullable', 'uuid'], 'category' => ['nullable', 'string', 'max:60']]);

        return match ($q['what']) {
            'goats' => $this->goats($q, $may),
            'kiddings' => $may('herd') ? response()->json(['rows' => \App\Models\Kidding::with('dam')->orderByDesc('kidded_on')->get()->map(fn ($k) => [
                'id' => $k->id, 'on' => $k->kidded_on?->format('d/m/Y'), 'goatId' => $k->dam_id, 'number' => $k->dam?->farm_number, 'alive' => (int) $k->born_alive, 'stillborn' => (int) $k->stillborn,
                'delivery' => $k->delivery_method ? ucfirst(str_replace('_', ' ', $k->delivery_method)) : null, 'by' => $k->recorded_by])->values()]) : abort(403),
            'offspring' => $may('sires') && isset($q['sire']) ? response()->json(['rows' => Animal::with(['breed', 'dam'])->where('sire_id', $q['sire'])->orderBy('birth_date')->get()
                ->map(fn ($a) => self::goatRow($a) + ['dam' => $a->dam?->farm_number])->values()]) : abort(403),
            'spending' => $may('spending') && $u->canModule('finance') && isset($q['category']) ? response()->json(['rows' => \App\Models\LedgerEntry::where('direction', 'out')->where('category', $q['category'])
                ->where('occurred_on', '>=', now()->subMonths(11)->startOfMonth())->orderByDesc('occurred_on')->get()->map(fn ($e) => [
                    'id' => $e->id, 'on' => $e->occurred_on->format('d/m/Y'), 'amount' => (float) $e->amount, 'party' => $e->counterparty, 'what' => $e->description, 'by' => $e->recorded_by])->values()]) : abort(403),
        };
    }

    private function goats(array $q, callable $may): JsonResponse
    {
        abort_unless($may('herd') || $may('age') || $may('breeds') || $may('exits'), 403);
        $goats = Animal::with(['breed', 'pen'])->whereNull('voided_at')->where('status', isset($q['exit']) ? '!=' : '=', 'active')->get()->filter(fn (Animal $a) =>
            (! isset($q['breed']) || $a->breed?->name === $q['breed']) && (! isset($q['sex']) || $a->sex === $q['sex'])
            && (! isset($q['stage']) || ($q['stage'] === 'none' ? ! $a->birth_date : $a->ageStageCode() === $q['stage']))
            && (! isset($q['teeth']) || \App\Support\Teeth::code($a->teethCode()) === $q['teeth'])
            && (! isset($q['exit']) || $a->exit_reason === $q['exit']) && (! isset($q['health']) || $a->health_status !== 'healthy'));

        return response()->json(['rows' => $goats->sortBy('farm_number', SORT_NATURAL)->map(fn ($a) => self::goatRow($a))->values()]);
    }

    private static function goatRow(Animal $a): array
    {
        return ['id' => $a->id, 'number' => $a->farm_number, 'breed' => $a->breed?->name, 'sex' => $a->sex === 'female' ? 'Female' : ($a->sex === 'male' ? 'Male' : null),
            'code' => $a->classCode(), 'age' => $a->ageLabel(), 'pen' => $a->pen?->name, 'health' => ucfirst((string) $a->health_status),
            'status' => $a->status === 'active' ? 'On farm' : ucfirst(str_replace('_', ' ', (string) $a->exit_reason)), 'left' => $a->exit_date?->format('d/m/Y')];
    }
}
