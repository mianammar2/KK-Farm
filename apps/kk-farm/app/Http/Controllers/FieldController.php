<?php

namespace App\Http\Controllers;

use App\Models\FieldDefinition;
use App\Support\Farm;
use App\Support\FieldCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Manage the goat registration fields of this organization: relabel, edit dropdown options, add custom fields (D-040). */
class FieldController extends Controller
{
    private const TYPES = ['text', 'long_text', 'number', 'date', 'yes_no', 'select'];

    public function index(): Response
    {
        $fields = FieldCatalog::forAnimal(includeInactive: true);

        return Inertia::render('Settings/Fields', [
            'fields' => $fields->map(fn (FieldDefinition $f) => [
                'id' => $f->id, 'key' => $f->key, 'label' => $f->label, 'type' => $f->type, 'section' => $f->section, 'help' => $f->help,
                'builtin' => $f->builtin, 'active' => $f->active, 'structural' => in_array($f->key, FieldCatalog::STRUCTURAL, true),
                'required' => $f->required || in_array($f->key, FieldCatalog::STRUCTURAL, true), 'options' => collect($f->options ?? [])->pluck('label')->implode("\n"),
            ])->values(),
            'sections' => $fields->pluck('section')->unique()->values(),
            'types' => self::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'type' => ['required', Rule::in(self::TYPES)],
            'options' => ['required_if:type,select', 'nullable', 'string', 'max:2000'],
            'section' => ['required', 'string', 'max:60'],
            'required' => ['boolean'],
            'help' => ['nullable', 'string', 'max:200'],
        ]);

        $options = $this->parseOptions($data['options'] ?? '', $data['type']);
        if ($data['type'] === 'select' && count($options) < 2) {
            return back()->withErrors(['options' => 'A dropdown needs at least two options, one per line.']);
        }

        $field = FieldDefinition::create([
            'entity' => 'animal', 'key' => FieldCatalog::keyFor($data['label']), 'label' => $data['label'], 'type' => $data['type'], 'options' => $options ?: null,
            'section' => $data['section'], 'sort' => (int) FieldDefinition::where('entity', 'animal')->max('sort') + 10,
            'required' => (bool) ($data['required'] ?? false), 'builtin' => false, 'active' => true, 'help' => $data['help'] ?? null,
        ]);
        Farm::log($request->user(), "Added registration field {$field->label}", '/settings/fields');

        return back()->with('success', "Field “{$field->label}” added to the goat register.");
    }

    public function update(Request $request, FieldDefinition $field): RedirectResponse
    {
        $structural = in_array($field->key, FieldCatalog::STRUCTURAL, true);
        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'help' => ['nullable', 'string', 'max:200'],
            'options' => ['nullable', 'string', 'max:2000'],
            'required' => ['boolean'],
            'active' => ['boolean'],
            'section' => ['nullable', 'string', 'max:60'],
        ]);
        abort_if($structural && array_key_exists('active', $data) && ! $data['active'], 422, 'This field is needed to register a goat and cannot be switched off.');

        $changes = ['label' => $data['label'], 'help' => $data['help'] ?? null];
        if ($field->type === 'select') {
            $options = $this->parseOptions($data['options'] ?? '', 'select', $field);
            if (count($options) < 2) {
                return back()->withErrors(['options' => 'A dropdown needs at least two options, one per line.']);
            }
            $changes['options'] = $options;
        }
        if (! $structural) {
            $changes += ['required' => (bool) ($data['required'] ?? $field->required), 'active' => (bool) ($data['active'] ?? $field->active)];
        }
        if (! $field->builtin && ! empty($data['section'])) {
            $changes['section'] = $data['section'];
        }
        $field->update($changes);
        Farm::log($request->user(), "Updated registration field {$field->label}", '/settings/fields');

        return back()->with('success', 'Field saved.');
    }

    /**
     * One option per line. Existing options keep their stored value when the label is kept, so goats already
     * registered with that value are not orphaned; renamed labels keep their value only by line position.
     *
     * @return list<array{value: string, label: string}>
     */
    private function parseOptions(string $text, string $type, ?FieldDefinition $existing = null): array
    {
        if ($type !== 'select') {
            return [];
        }

        $previous = collect($existing?->options ?? []);
        $labels = collect(preg_split('/\R/', $text))->map(fn ($l) => trim($l))->filter()->unique()->values();
        $used = [];

        return $labels->map(function (string $label, int $i) use ($previous, $labels, &$used) {
            // Keep the stored value when the label is unchanged. A renamed line keeps its value only when the
            // list has the same length and the old label is gone, so an insert never re-labels existing data.
            $value = $previous->firstWhere('label', $label)['value'] ?? null;
            if ($value === null && $previous->count() === $labels->count() && ! $labels->contains($previous[$i]['label'] ?? null)) {
                $value = $previous[$i]['value'] ?? null;
            }
            if ($value === null || in_array($value, $used, true)) {
                $value = \Illuminate\Support\Str::slug($label, '_') ?: 'option_'.($i + 1);
                for ($n = 2; in_array($value, $used, true); $n++) {
                    $value .= "_$n";
                }
            }
            $used[] = $value;

            return ['value' => $value, 'label' => $label];
        })->all();
    }
}
