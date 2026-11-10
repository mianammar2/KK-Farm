<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Sale;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** D-178: sales of goats and products in one place, with customers. Goats are sold from Movements (an exit), products here. */
class SalesController extends Controller
{
    public function index(Request $request): Response
    {
        $sales = Sale::with(['animal', 'customer'])->orderByDesc('sold_on')->orderByDesc('id')->limit(300)->get();
        $year = $sales->filter(fn ($s) => $s->sold_on->gte(now()->subMonths(12)->startOfMonth()));
        $net = fn ($c) => $c->sum(fn ($s) => $s->netMinor()) / 100;

        return Inertia::render('Sales', [
            'sales' => $sales->map(fn (Sale $s) => [
                'id' => $s->id, 'on' => $s->sold_on->format('j M Y'), 'category' => Sale::CATEGORIES[$s->category] ?? $s->category, 'goatId' => $s->animal_id, 'goat' => $s->animal?->farm_number,
                'item' => $s->item, 'buyer' => $s->customer?->name ?? $s->buyer, 'customerId' => $s->customer_id, 'gross' => $s->gross_minor / 100, 'fee' => $s->fee_minor / 100, 'net' => $s->netMinor() / 100, 'receipt' => $s->receipt_ref,
            ]),
            'kpis' => [
                'month' => $net($sales->filter(fn ($s) => $s->sold_on->isSameMonth(now()))), 'year' => $net($year),
                'goats' => $year->whereNotNull('animal_id')->count(), 'products' => $net($year->whereNull('animal_id')),
            ],
            'monthly' => collect(range(11, 0))->map(fn ($m) => now()->startOfMonth()->subMonths($m))->map(fn ($d) => [
                'month' => $d->format('Y-m'), 'net' => $net($sales->filter(fn ($s) => $s->sold_on->format('Y-m') === $d->format('Y-m'))),
            ])->values(),
            'customers' => Customer::orderByDesc('active')->orderBy('name')->get()->map(fn (Customer $c) => ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'city' => $c->city, 'type' => $c->type, 'notes' => $c->notes, 'active' => $c->active,
                'sales' => $sales->where('customer_id', $c->id)->count(), 'total' => $net($sales->where('customer_id', $c->id))]),
            'types' => Customer::TYPES,
            'can' => ['manage' => $request->user()->passes('manage_sales')],
        ]);
    }

    private function customerData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'], 'phone' => ['nullable', 'string', 'max:30'], 'city' => ['nullable', 'string', 'max:60'],
            'type' => ['required', Rule::in(array_keys(Customer::TYPES))], 'notes' => ['nullable', 'string', 'max:300'], 'active' => ['sometimes', 'boolean'],
        ]);
    }

    public function storeCustomer(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sales'), 403);
        $c = Customer::create($this->customerData($request));
        Farm::log($request->user(), "Added customer {$c->name}", '/sales');

        return back()->with('success', "{$c->name} added.");
    }

    public function updateCustomer(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sales'), 403);
        $customer->update($this->customerData($request));

        return back()->with('success', "{$customer->name} saved.");
    }

    /** Link an existing sale (for example a goat sold from Movements) to a customer. */
    public function linkCustomer(Request $request, Sale $sale): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sales'), 403);
        $data = $request->validate(['customer_id' => ['nullable', 'integer', 'exists:customers,id']]);
        $sale->update(['customer_id' => $data['customer_id'] ?? null]);

        return back()->with('success', 'Sale updated.');
    }

    /** A product sale (milk, manure, skins…) to a customer; it records the income like Expenses & Finance does. */
    public function product(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_sales'), 403);
        $data = $request->validate([
            'sold_on' => ['required', 'date', 'before_or_equal:today'], 'category' => ['required', Rule::in(['product', 'other'])],
            'item' => ['required', 'string', 'max:120'], 'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'gross' => ['required', 'numeric', 'gt:0', 'max:100000000'], 'fee' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'receipt_ref' => ['nullable', 'string', 'max:60'], 'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['gross' => 'price', 'fee' => 'fee or discount', 'customer_id' => 'customer']);
        $data['buyer'] = ! empty($data['customer_id']) ? Customer::find($data['customer_id'])?->name : null;
        $sale = \App\Support\Movements::sale($data, $request->user());
        Farm::log($request->user(), "Sale: {$data['item']} PKR ".number_format($sale->netMinor() / 100), '/sales');

        return back()->with('success', 'Sale recorded: PKR '.number_format($sale->netMinor() / 100).' net.');
    }
}
