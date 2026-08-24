<?php

namespace App\Http\Middleware;

use App\Models\Enterprise;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve la empresa actual a partir del primer segmento de la URL
 * (ej. /api/finca-modelo-demo/... -> slug 'finca-modelo-demo') y la deja
 * disponible en el container como 'currentEnterprise'. Los modelos que usan
 * el trait BelongsToEnterprise leen de acá para su global scope — no hay
 * que resolver la empresa de nuevo en cada controller.
 */
class ResolveCurrentEnterprise
{
    public function handle(Request $request, Closure $next): Response
    {
        // segment(1) es 'api' (el apiPrefix global, ver bootstrap/app.php) —
        // el slug de empresa es el segmento siguiente: /api/{empresa}/...
        $slug = $request->segment(2);

        if ($slug) {
            $enterprise = Enterprise::where('slug', $slug)->first();

            if ($enterprise) {
                app()->instance('currentEnterprise', $enterprise);
            }
        }

        return $next($request);
    }
}
