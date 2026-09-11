<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;
use Carbon\Carbon;

class BookingController extends Controller
{
    public function index()
    {
        $bookings = Booking::with(['shippingLine', 'vessel', 'destination', 'port', 'client'])
            ->orderByDesc('id')
            ->get();

        return response()->json($bookings);
    }

    public function show($id)
    {
        $booking = Booking::with(['shippingLine', 'vessel', 'destination', 'port', 'client'])->findOrFail($id);
        return response()->json($booking);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'booking_number' => 'required|string|max:255',
            'shipping_line_id' => 'required|exists:shipping_lines,id',
            'vessel_id' => 'nullable|exists:vessels,id',
            'voyage_number' => 'nullable|string|max:255',
            'departure_week' => 'nullable|integer|min:1|max:53',
            'departure_year' => 'nullable|integer',
            'eta' => 'nullable|date',
            'destination_id' => 'nullable|exists:destinations,id',
            'port_id' => 'nullable|exists:ports,id',
            'client_id' => 'nullable|exists:customers,id',
            'weekly_quota' => 'nullable|integer|min:0',
            'estimated_departure' => 'nullable|date',
            'boxes_quantity' => 'nullable|integer|min:0',
        ]);

        // Fecha y semana de registro: automáticas, hoy
        $hoy = Carbon::now();
        $data['entry_date'] = $hoy->toDateString();
        $data['week'] = (int) $hoy->isoWeek();
        $data['year'] = (int) $hoy->year;

        $booking = Booking::create($data);
        $booking->load(['shippingLine', 'vessel', 'destination', 'port', 'client']);

        return response()->json($booking, 201);
    }

    public function update(Request $request, $id)
    {
        $booking = Booking::findOrFail($id);

        $data = $request->validate([
            'booking_number' => 'required|string|max:255',
            'shipping_line_id' => 'required|exists:shipping_lines,id',
            'vessel_id' => 'nullable|exists:vessels,id',
            'voyage_number' => 'nullable|string|max:255',
            'departure_week' => 'nullable|integer|min:1|max:53',
            'departure_year' => 'nullable|integer',
            'eta' => 'nullable|date',
            'destination_id' => 'nullable|exists:destinations,id',
            'port_id' => 'nullable|exists:ports,id',
            'client_id' => 'nullable|exists:customers,id',
            'weekly_quota' => 'nullable|integer|min:0',
            'estimated_departure' => 'nullable|date',
            'boxes_quantity' => 'nullable|integer|min:0',
        ]);

        $booking->update($data);
        $booking->load(['shippingLine', 'vessel', 'destination', 'port', 'client']);

        return response()->json($booking);
    }

    public function deactivate($id)
    {
        $booking = Booking::findOrFail($id);
        $booking->update(['status' => false]);
        return response()->json($booking);
    }

    public function reactivate($id)
    {
        $booking = Booking::findOrFail($id);
        $booking->update(['status' => true]);
        return response()->json($booking);
    }
}