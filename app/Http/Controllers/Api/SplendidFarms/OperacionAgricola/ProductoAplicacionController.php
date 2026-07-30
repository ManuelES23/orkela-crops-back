<?php

namespace App\Http\Controllers\Api\SplendidFarms\OperacionAgricola;

use App\Http\Controllers\Controller;
use App\Models\ProductoAplicacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductoAplicacionController extends Controller
{
    /**
     * GET /productos-aplicacion?search=X&tipo=X
     * Lista para selects/autocomplete del modal.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ProductoAplicacion::activos();

        $query->when($request->filled('tipo'), function ($q) use ($request) {
            $q->byTipo($request->tipo);
        });

        $query->when($request->filled('search'), function ($q) use ($request) {
            $search = $request->search;
            $q->where(function ($q2) use ($search) {
                $q2->where('nombre', 'like', "%{$search}%")
                   ->orWhere('marca', 'like', "%{$search}%")
                   ->orWhere('ingrediente_activo', 'like', "%{$search}%");
            });
        });

        $productos = $query
            ->select('id', 'nombre', 'ingrediente_activo', 'marca', 'tipo', 'activo')
            ->orderBy('nombre')
            ->get();

        return response()->json(['success' => true, 'data' => $productos]);
    }

    /**
     * POST /productos-aplicacion
     * Registro rápido desde el modal de aplicaciones.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre'             => 'required|string|max:200',
            'ingrediente_activo' => 'nullable|string|max:200',
            'marca'              => 'nullable|string|max:150',
            'tipo'               => 'required|in:agroquimico,fertilizante',
            'activo'             => 'boolean',
        ]);

        $producto = ProductoAplicacion::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Producto registrado correctamente.',
            'data'    => $producto,
        ], 201);
    }

    /**
     * PUT /productos-aplicacion/{producto}
     */
    public function update(Request $request, ProductoAplicacion $productoAplicacion): JsonResponse
    {
        $validated = $request->validate([
            'nombre'             => 'sometimes|string|max:200',
            'ingrediente_activo' => 'nullable|string|max:200',
            'marca'              => 'nullable|string|max:150',
            'tipo'               => 'sometimes|in:agroquimico,fertilizante',
            'activo'             => 'boolean',
        ]);

        $productoAplicacion->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Producto actualizado.',
            'data'    => $productoAplicacion,
        ]);
    }
}
