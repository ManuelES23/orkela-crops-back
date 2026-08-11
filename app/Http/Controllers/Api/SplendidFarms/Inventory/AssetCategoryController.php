<?php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Http\Controllers\Controller;
use App\Models\AssetCategory;
use App\Models\AssetCharacteristicDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class AssetCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AssetCategory::with(['parent:id,name,code', 'children:id,parent_id,name,code,icon,is_active'])
            ->withCount(['assetsAsCategory', 'assetsAsSubcategory']);

        if ($request->boolean('active_only')) {
            $query->active();
        }

        if ($request->boolean('root_only')) {
            $query->root();
        }

        if ($request->has('parent_id')) {
            $parentId = $request->input('parent_id');
            if ($parentId === 'null' || $parentId === '') {
                $query->whereNull('parent_id');
            } else {
                $query->where('parent_id', $parentId);
            }
        }

        $categories = $query->orderBy('order')->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * Get categories in tree structure.
     */
    public function tree(): JsonResponse
    {
        $categories = AssetCategory::with('allChildren')
            ->withCount(['assetsAsCategory', 'assetsAsSubcategory'])
            ->whereNull('parent_id')
            ->active()
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'nullable|string|max:50|unique:asset_categories,code',
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:asset_categories,slug',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:asset_categories,id',
            'icon' => 'nullable|string|max:100',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'metadata' => 'nullable|array',
        ]);

        // Generar código automático si no se proporciona
        if (empty($validated['code'])) {
            $prefix = 'TAC';

            $lastCategory = AssetCategory::withTrashed()
                ->where('code', 'like', $prefix.'-%')
                ->orderByRaw('CAST(SUBSTRING(code, '.(strlen($prefix) + 2).') AS UNSIGNED) DESC')
                ->first();

            $nextNumber = $lastCategory
                ? ((int) substr($lastCategory->code, strlen($prefix) + 1)) + 1
                : 1;

            $validated['code'] = $prefix.'-'.str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        }

        $category = AssetCategory::create($validated);
        $category->load('parent:id,name,code');
        $category->loadCount(['assetsAsCategory', 'assetsAsSubcategory']);

        return response()->json([
            'success' => true,
            'message' => 'Tipo de activo creado exitosamente',
            'data' => $category,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(AssetCategory $tipoActivo): JsonResponse
    {
        $tipoActivo->load(['parent', 'children']);
        $tipoActivo->loadCount(['assetsAsCategory', 'assetsAsSubcategory']);

        return response()->json([
            'success' => true,
            'data' => $tipoActivo,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, AssetCategory $tipoActivo): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'nullable|string|max:255|unique:asset_categories,slug,'.$tipoActivo->id,
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:asset_categories,id',
            'icon' => 'nullable|string|max:100',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'metadata' => 'nullable|array',
        ]);

        if (isset($validated['parent_id']) && $validated['parent_id'] == $tipoActivo->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Un tipo de activo no puede ser su propio padre',
            ], 422);
        }

        $tipoActivo->update($validated);

        $tipoActivo = $tipoActivo->fresh(['parent:id,name,code', 'children:id,parent_id,name,code']);
        $tipoActivo->loadCount(['assetsAsCategory', 'assetsAsSubcategory']);

        return response()->json([
            'success' => true,
            'message' => 'Tipo de activo actualizado exitosamente',
            'data' => $tipoActivo,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AssetCategory $tipoActivo): JsonResponse
    {
        if ($tipoActivo->children()->count() > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar el tipo de activo porque tiene subtipos',
            ], 422);
        }

        if ($tipoActivo->assetsAsCategory()->count() > 0 || $tipoActivo->assetsAsSubcategory()->count() > 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se puede eliminar el tipo de activo porque tiene activos fijos asociados',
            ], 422);
        }

        $tipoActivo->delete();

        return response()->json([
            'success' => true,
            'message' => 'Tipo de activo eliminado exitosamente',
        ]);
    }

    /**
     * Catálogo de características sugeridas para un Tipo/Subtipo de Activo.
     */
    public function characteristics(AssetCategory $tipoActivo): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $tipoActivo->characteristicDefinitions()->get(),
        ]);
    }

    /**
     * Registrar una nueva característica sugerida para un Tipo/Subtipo.
     */
    public function storeCharacteristic(Request $request, AssetCategory $tipoActivo): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:150',
                // Solo valida contra definiciones activas: si el nombre
                // pertenece a una que se borró (soft delete), se permite
                // reciclarla en vez de bloquear por duplicado.
                Rule::unique('asset_characteristic_definitions', 'name')
                    ->where('category_id', $tipoActivo->id)
                    ->whereNull('deleted_at'),
            ],
        ]);

        // withTrashed(): si el nombre ya existía pero se había borrado del
        // catálogo, se recicla (restore) en vez de intentar crear un
        // duplicado y chocar con la restricción única category_id+name.
        $definition = AssetCharacteristicDefinition::withTrashed()
            ->where('category_id', $tipoActivo->id)
            ->where('name', $validated['name'])
            ->first();

        if ($definition) {
            $definition->restore();
        } else {
            $order = (int) ($tipoActivo->characteristicDefinitions()->max('order') ?? 0) + 1;

            $definition = AssetCharacteristicDefinition::create([
                'category_id' => $tipoActivo->id,
                'name' => $validated['name'],
                'order' => $order,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Característica agregada al catálogo',
            'data' => $definition,
        ], 201);
    }

    /**
     * Quitar una característica del catálogo de sugerencias. No borra los
     * valores ya capturados en activos (quedan como campo libre).
     */
    public function destroyCharacteristic(AssetCharacteristicDefinition $characteristic): JsonResponse
    {
        $characteristic->delete();

        return response()->json([
            'success' => true,
            'message' => 'Característica eliminada del catálogo',
        ]);
    }
}
