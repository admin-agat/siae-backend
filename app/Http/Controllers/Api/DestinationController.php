<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Destination;
use Illuminate\Http\Request;

class DestinationController extends Controller
{
    public function index(Request $request)
    {
        $query = Destination::query();

        if ($request->has('status')) {
            $query->where('status', $request->boolean('status'));
        }

        $destinations = $query->orderBy('name')->get();

        return response()->json($destinations);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $validated['status'] = true;

        $destination = Destination::create($validated);

        return response()->json($destination, 201);
    }

    public function update(Request $request, $id)
    {
        $destination = Destination::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $destination->update($validated);

        return response()->json($destination);
    }

    public function deactivate($id)
    {
        $destination = Destination::findOrFail($id);
        $destination->update(['status' => false]);

        return response()->json($destination);
    }

    public function reactivate($id)
    {
        $destination = Destination::findOrFail($id);
        $destination->update(['status' => true]);

        return response()->json($destination);
    }
}