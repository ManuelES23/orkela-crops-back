<?php

namespace App\Traits\CRM;

use Illuminate\Support\Facades\Auth;

trait FiltraPorEmpresa
{
    /**
     * Devuelve el enterprise_id de contexto: primero intenta leerlo del
     * header X-Enterprise-Id (numérico), luego busca en el user autenticado.
     *
     * Los controladores CRM deben llamar a getEmpresaId() para obtener el
     * scope de empresa y aplicarlo en todos los queries.
     */
    protected function getEmpresaId(): ?int
    {
        $request = request();

        // Opción 1: header numérico directo (máxima eficiencia, sin lookup)
        $fromHeader = $request->header('X-Enterprise-Id');
        if ($fromHeader && ctype_digit((string) $fromHeader)) {
            return (int) $fromHeader;
        }

        // Opción 2: resolver empresa por slug desde header
        $slug = $request->header('X-Enterprise-Slug');
        if ($slug) {
            $enterprise = \App\Models\Enterprise::where('slug', $slug)->value('id');
            if ($enterprise) {
                return $enterprise;
            }
        }

        // Opción 3: empresa del usuario autenticado (primer acceso encontrado)
        $user = Auth::user();
        if ($user) {
            $enterpriseId = \App\Models\UserEnterpriseAccess::where('user_id', $user->id)
                ->where('is_active', true)
                ->value('enterprise_id');
            return $enterpriseId ? (int) $enterpriseId : null;
        }

        return null;
    }

    /**
     * Aplica el scope de empresa_id a un query builder.
     * Aborta con 403 si no se puede determinar la empresa.
     */
    protected function scopeEmpresa(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        $empresaId = $this->getEmpresaId();

        if (! $empresaId) {
            abort(403, 'No se pudo determinar el contexto de empresa.');
        }

        return $query->where('empresa_id', $empresaId);
    }
}
