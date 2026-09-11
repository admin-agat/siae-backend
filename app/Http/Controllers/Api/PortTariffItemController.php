<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PortTariffItem;
use App\Models\PortTariffItemPriceHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PortTariffItemController extends Controller
{
    public function index(Request $request)
    {
        $query = PortTariffItem::query();

        if ($request->has('port_id')) {
            $query->where('port_id', $request->port_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->boolean('status'));
        }

        $items = $query->orderBy('concept')->get();

        return response()->json($items);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'port_id' => 'required|exists:ports,id',
            'concept' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
        ]);

        $validated['status'] = true;

        $item = PortTariffItem::create($validated);

        return response()->json($item, 201);
    }

    public function show($id)
    {
        $item = PortTariffItem::with('priceHistory')->findOrFail($id);

        return response()->json($item);
    }

    public function update(Request $request, $id)
    {
        $item = PortTariffItem::findOrFail($id);

        $validated = $request->validate([
            'concept' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($item, $validated, $request) {
            if (bccomp((string) $item->amount, (string) $validated['amount'], 2) !== 0) {
                PortTariffItemPriceHistory::create([
                    'port_tariff_item_id' => $item->id,
                    'old_amount' => $item->amount,
                    'new_amount' => $validated['amount'],
                    'changed_by' => $request->user()?->id,
                    'changed_at' => now(),
                ]);
            }

            $item->update($validated);
        });

        return response()->json($item->fresh());
    }

    public function deactivate($id)
    {
        $item = PortTariffItem::findOrFail($id);
        $item->update(['status' => false]);

        return response()->json($item);
    }

    public function reactivate($id)
    {
        $item = PortTariffItem::findOrFail($id);
        $item->update(['status' => true]);

        return response()->json($item);
    }

    public function priceHistory($id)
    {
        $item = PortTariffItem::findOrFail($id);

        $history = $item->priceHistory()
            ->with('changedByUser:id,name')
            ->orderByDesc('changed_at')
            ->get();

        return response()->json($history);
    }
}