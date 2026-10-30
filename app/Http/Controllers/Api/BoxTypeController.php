<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BoxType;

// Tipos de caja (22XU, 208...): solo lectura por ahora (marcas y precios oficiales MAG).
class BoxTypeController extends Controller
{
    /**
     * GET /api/box-types
     */
    public function index()
    {
        return response()->json(BoxType::orderBy('name')->get());
    }
}
