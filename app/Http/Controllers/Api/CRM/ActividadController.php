<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\ActividadUpdated;
use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmEmpresaExterna;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use App\Traits\CRM\FiltraPorEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ActividadController
 * Actividades comerciales polimórficas (timeline por entidad).
 * Se pueden asociar a: prospecto, cliente, oportunidad o empresa_externa.
 *
 * Payload standard:
 *  - entidad_tipo: 'prospecto' | 'cliente' | 'oportunidad' | 'empresa_externa' (alias corto)
 *  - entidad_id:   ID del recurso relacionado
 * Internamente se traduce a entidad_type (nombre completo del modelo).
 */
class ActividadController extends CrmBaseController
{
    use FiltraPorEmpresa;

    /**
     * Alias corto → clase del modelo padre.
     */
    protected const TIPOS = [
        'prospecto'       => CrmProspecto::class,
        'cliente'         => CrmCliente::class,
        'oportunidad'     => CrmOportunidad::class,
        'empresa_externa' => CrmEmpresaExterna::class,
    ];

    /**
     * Tipos de actividad permitidos (coinciden con el enum de la migración).
     */
    protected const TIPOS_ACTIVIDAD = ['llamada', 'whatsapp', 'correo', 'visita', 'reunion', 'nota'];

    protected const RELACIONES = ['vendedor:id,nombre,email'];

    /**
     * GET /crm/actividades?entidad_tipo=&entidad_id=&tipo=&vendedor_id=&search=
     */
    public function index(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);

        $request->validate([
            'entidad_tipo' => ['nullable', Rule::in(array_keys(self::TIPOS))],
            'entidad_id'   => 'nullable|integer',
            'tipo'         => ['nullable', Rule::in(self::TIPOS_ACTIVIDAD)],
            'vendedor_id'  => 'nullable|integer',
        ]);

        $query = CrmActividad::query()
            ->with(self::RELACIONES)
            ->where('empresa_id', $empresaId)
            ->when($request->entidad_tipo, function ($q, $tipo) {
                $q->where('entidad_type', self::TIPOS[$tipo]);
            })
            ->when($request->entidad_id, fn ($q, $id) => $q->where('entidad_id', $id))
            ->when($request->tipo, fn ($q, $tipo) => $q->where('tipo', $tipo))
            ->when($request->vendedor_id, fn ($q, $id) => $q->where('vendedor_id', $id))
            ->when($request->search, function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('descripcion', 'like', "%{$search}%")
                        ->orWhere('resultado', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('fecha_actividad')
            ->orderByDesc('id');

        $perPage = (int) $request->query('per_page', 50);
        $paginated = $query->paginate($perPage);

        $items = collect($paginated->items())->map(function ($a) {
            $a->entidad_tipo = array_search($a->entidad_type, self::TIPOS, true) ?: null;
            return $a;
        });

        return response()->json([
            'success' => true,
            'message' => 'Operación exitosa',
            'data'    => $items,
            'meta'    => [
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * GET /crm/actividades/{actividad}
     */
    public function show(Request $request, CrmActividad $actividad): JsonResponse
    {
        $this->verificarEmpresa($request, $actividad);
        $actividad->load(self::RELACIONES);
        $actividad->entidad_tipo = array_search($actividad->entidad_type, self::TIPOS, true) ?: null;

        return $this->jsonSuccess($actividad);
    }

    /**
     * POST /crm/actividades
     */
    public function store(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId($request);

        $validated = $request->validate([
            'entidad_tipo'     => ['required', Rule::in(array_keys(self::TIPOS))],
            'entidad_id'       => 'required|integer',
            'tipo'             => ['required', Rule::in(self::TIPOS_ACTIVIDAD)],
            'descripcion'      => 'required|string',
            'fecha_actividad'  => 'required|date',
            'vendedor_id'      => 'nullable|integer|exists:crm_vendedores,id',
            'duracion_minutos' => 'nullable|integer|min:0',
            'resultado'        => 'nullable|string',
        ]);

        $modelClass = self::TIPOS[$validated['entidad_tipo']];
        $entidad = $modelClass::where('empresa_id', $empresaId)->find($validated['entidad_id']);
        if (!$entidad) {
            return $this->jsonError('La entidad relacionada no existe o no pertenece a la empresa.', 404);
        }

        $actividad = CrmActividad::create([
            'empresa_id'       => $empresaId,
            'entidad_type'     => $modelClass,
            'entidad_id'       => $validated['entidad_id'],
            'tipo'             => $validated['tipo'],
            'descripcion'      => $validated['descripcion'],
            'fecha_actividad'  => $validated['fecha_actividad'],
            'vendedor_id'      => $validated['vendedor_id'] ?? null,
            'duracion_minutos' => $validated['duracion_minutos'] ?? null,
            'resultado'        => $validated['resultado'] ?? null,
            'fuente'           => 'manual',
        ]);

        $actividad->load(self::RELACIONES);
        $actividad->entidad_tipo = $validated['entidad_tipo'];

        broadcast(new ActividadUpdated('created', $actividad->toArray()));

        return $this->jsonSuccess($actividad, 'Actividad registrada correctamente', 201);
    }

    /**
     * PATCH /crm/actividades/{actividad}
     */
    public function update(Request $request, CrmActividad $actividad): JsonResponse
    {
        $this->verificarEmpresa($request, $actividad);

        $validated = $request->validate([
            'tipo'             => ['sometimes', 'required', Rule::in(self::TIPOS_ACTIVIDAD)],
            'descripcion'      => 'sometimes|required|string',
            'fecha_actividad'  => 'sometimes|required|date',
            'vendedor_id'      => 'sometimes|nullable|integer|exists:crm_vendedores,id',
            'duracion_minutos' => 'sometimes|nullable|integer|min:0',
            'resultado'        => 'sometimes|nullable|string',
        ]);

        $actividad->update($validated);
        $actividad->load(self::RELACIONES);
        $actividad->entidad_tipo = array_search($actividad->entidad_type, self::TIPOS, true) ?: null;

        broadcast(new ActividadUpdated('updated', $actividad->toArray()));

        return $this->jsonSuccess($actividad, 'Actividad actualizada correctamente');
    }

    /**
     * DELETE /crm/actividades/{actividad}
     */
    public function destroy(Request $request, CrmActividad $actividad): JsonResponse
    {
        $this->verificarEmpresa($request, $actividad);

        $data = $actividad->toArray();
        $actividad->delete();

        broadcast(new ActividadUpdated('deleted', $data));

        return $this->jsonSuccess(null, 'Actividad eliminada correctamente');
    }

    /**
     * Aborta con 404 si la actividad no pertenece a la empresa del request.
     */
    protected function verificarEmpresa(Request $request, CrmActividad $actividad): void
    {
        $empresaId = $this->getEmpresaId($request);
        if ((int) $actividad->empresa_id !== (int) $empresaId) {
            abort(404, 'Actividad no encontrada');
        }
    }
}
