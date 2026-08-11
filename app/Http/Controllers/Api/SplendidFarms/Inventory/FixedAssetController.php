<?php

namespace App\Http\Controllers\Api\SplendidFarms\Inventory;

use App\Http\Controllers\Controller;
use App\Models\AssetCharacteristicDefinition;
use App\Models\FixedAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class FixedAssetController extends Controller
{
    private const RELATIONS = [
        'brand:id,name,code',
        'category:id,name,code,parent_id',
        'subcategory:id,name,code,parent_id',
        'branch:id,name,code',
        'entity:id,name,code,entity_type_id',
        'entity.entityType:id,code,name,icon,color',
        'area:id,name,code',
        'performanceUnit:id,name,abbreviation',
        'characteristics',
    ];

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = FixedAsset::with(self::RELATIONS);

        if ($request->boolean('active_only')) {
            $query->active();
        }

        if ($request->has('branch_id') && $request->branch_id !== '') {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->has('entity_id') && $request->entity_id !== '') {
            $query->where('entity_id', $request->entity_id);
        }

        if ($request->has('area_id') && $request->area_id !== '') {
            $query->where('area_id', $request->area_id);
        }

        // Filtro por ubicación (radio button Campo/Empaque/Oficina) vía tipo de entidad
        if ($request->filled('entity_type')) {
            $entityTypes = array_filter(explode(',', $request->input('entity_type')));
            if (!empty($entityTypes)) {
                $query->whereHas('entity.entityType', function ($q) use ($entityTypes) {
                    $q->whereIn('code', $entityTypes);
                });
            }
        }

        if ($request->has('category_id') && $request->category_id !== '') {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('subcategory_id') && $request->subcategory_id !== '') {
            $query->where('subcategory_id', $request->subcategory_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%");
            });
        }

        $query->orderBy('name');

        // Paginación opcional (igual que Artículos): solo si se pide per_page
        $assets = $request->has('per_page')
            ? $query->paginate((int) $request->input('per_page'))
            : $query->get();

        return response()->json([
            'success' => true,
            'data' => $assets,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateData($request);

        // Generar código automático si no se proporciona
        if (empty($validated['code'])) {
            $validated['code'] = $this->nextCode();
        }

        if ($request->hasFile('image')) {
            $validated['image'] = $request->file('image')->store('fixed-assets', 'public');
        }

        $asset = FixedAsset::create($validated);

        // Siempre sincroniza (aunque venga vacío): el formulario maneja la
        // lista completa, así que un envío sin filas significa "quítalas todas".
        $this->syncCharacteristics($asset, (array) $request->input('characteristics', []));

        $asset->load(self::RELATIONS);

        return response()->json([
            'success' => true,
            'message' => 'Activo fijo creado exitosamente',
            'data' => $asset,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(FixedAsset $asset): JsonResponse
    {
        $asset->load(self::RELATIONS);

        return response()->json([
            'success' => true,
            'data' => $asset,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, FixedAsset $asset): JsonResponse
    {
        $validated = $this->validateData($request, $asset->id);

        if ($request->hasFile('image')) {
            if ($asset->image) {
                Storage::disk('public')->delete($asset->image);
            }
            $validated['image'] = $request->file('image')->store('fixed-assets', 'public');
        }

        $asset->update($validated);

        // Siempre sincroniza (aunque venga vacío): el formulario maneja la
        // lista completa, así que un envío sin filas significa "quítalas todas".
        $this->syncCharacteristics($asset, (array) $request->input('characteristics', []));

        $asset = $asset->fresh(self::RELATIONS);

        return response()->json([
            'success' => true,
            'message' => 'Activo fijo actualizado exitosamente',
            'data' => $asset,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(FixedAsset $asset): JsonResponse
    {
        if ($asset->image) {
            Storage::disk('public')->delete($asset->image);
        }

        $asset->delete();

        return response()->json([
            'success' => true,
            'message' => 'Activo fijo eliminado exitosamente',
        ]);
    }

    /**
     * Siguiente código disponible (para mostrarlo en el formulario antes de guardar).
     */
    public function nextCode(): string
    {
        $prefix = 'AF';

        $last = FixedAsset::withTrashed()
            ->where('code', 'like', $prefix.'-%')
            ->orderByRaw('CAST(SUBSTRING(code, '.(strlen($prefix) + 2).') AS UNSIGNED) DESC')
            ->first();

        $nextNumber = $last
            ? ((int) substr($last->code, strlen($prefix) + 1)) + 1
            : 1;

        return $prefix.'-'.str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Endpoint auxiliar: próximo código disponible.
     */
    public function nextCodeEndpoint(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['code' => $this->nextCode()],
        ]);
    }

    private function validateData(Request $request, ?int $assetId = null): array
    {
        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:50', Rule::unique('fixed_assets', 'code')->ignore($assetId)],
            'name' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:2048',
            'serial_number' => 'nullable|string|max:150',
            'model' => 'nullable|string|max:150',
            'year' => 'nullable|integer|min:1900|max:'.(date('Y') + 1),
            'brand_id' => 'nullable|exists:brands,id',

            'category_id' => 'required|exists:asset_categories,id',
            'subcategory_id' => 'nullable|exists:asset_categories,id',

            'branch_id' => 'required|exists:branches,id',
            'entity_id' => 'required|exists:entities,id',
            'area_id' => 'nullable|exists:areas,id',

            'status' => ['nullable', Rule::in(array_keys(\App\Models\FixedAsset::STATUSES))],
            'useful_life_years' => 'nullable|integer|min:0|max:100',
            'performance_unit_id' => 'nullable|exists:units_of_measure,id',
            'description' => 'nullable|string',
            'observations' => 'nullable|string',

            'purchase_date' => 'nullable|date',
            'invoice_number' => 'nullable|string|max:100',
            'purchase_value' => 'nullable|numeric|min:0',

            'is_active' => 'boolean',
            'metadata' => 'nullable|array',

            'characteristics' => 'nullable|array',
            'characteristics.*.name' => 'required_with:characteristics.*.value|nullable|string|max:150',
            'characteristics.*.value' => 'nullable|string|max:500',
            'characteristics.*.definition_id' => 'nullable|integer|exists:asset_characteristic_definitions,id',
        ]);

        unset($validated['characteristics']);

        // La entidad debe pertenecer a la sucursal seleccionada
        if (!empty($validated['entity_id']) && !empty($validated['branch_id'])) {
            $belongs = \App\Models\Entity::where('id', $validated['entity_id'])
                ->where('branch_id', $validated['branch_id'])
                ->exists();

            if (!$belongs) {
                abort(response()->json([
                    'status' => 'error',
                    'message' => 'La entidad seleccionada no pertenece a la sucursal indicada',
                ], 422));
            }
        }

        // El área debe pertenecer a la entidad seleccionada
        if (!empty($validated['area_id']) && !empty($validated['entity_id'])) {
            $belongs = \Illuminate\Support\Facades\DB::table('entity_area')
                ->where('entity_id', $validated['entity_id'])
                ->where('area_id', $validated['area_id'])
                ->exists();

            if (!$belongs) {
                abort(response()->json([
                    'status' => 'error',
                    'message' => 'El área seleccionada no pertenece a la entidad indicada',
                ], 422));
            }
        }

        return $validated;
    }

    /**
     * Reemplaza las características capturadas de un activo. Las filas sin
     * nombre o sin valor se ignoran (una característica sin valor no aporta
     * nada, el usuario simplemente no la incluyó). Cuando una fila no viene
     * ligada a una definición existente, se registra automáticamente en el
     * catálogo de la categoría del activo para poder reutilizarla después.
     */
    private function syncCharacteristics(FixedAsset $asset, array $characteristics): void
    {
        $asset->characteristics()->delete();

        $categoryId = $asset->subcategory_id ?: $asset->category_id;
        $order = 0;

        foreach ($characteristics as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            $value = trim((string) ($item['value'] ?? ''));

            if ($name === '' || $value === '') {
                continue;
            }

            $definitionId = $item['definition_id'] ?? null;

            if (!$definitionId && $categoryId) {
                // withTrashed(): si el nombre ya existía pero se había
                // borrado del catálogo, se recicla (restore) en vez de
                // intentar crear un duplicado y chocar con la restricción
                // única category_id+name.
                $definition = AssetCharacteristicDefinition::withTrashed()
                    ->where('category_id', $categoryId)
                    ->where('name', $name)
                    ->first();

                if ($definition) {
                    if ($definition->trashed()) {
                        $definition->restore();
                    }
                } else {
                    $definition = AssetCharacteristicDefinition::create([
                        'category_id' => $categoryId,
                        'name' => $name,
                        'order' => 0,
                    ]);
                }

                $definitionId = $definition->id;
            }

            $asset->characteristics()->create([
                'definition_id' => $definitionId,
                'name' => $name,
                'value' => $value,
                'order' => $order++,
            ]);
        }
    }
}
