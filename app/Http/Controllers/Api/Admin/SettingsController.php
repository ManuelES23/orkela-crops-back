<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class SettingsController extends Controller
{
    /**
     * Listar los settings agrupados + información real del sistema.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'groups' => $this->groupedSettings(),
                'system_info' => $this->systemInfo(),
            ],
        ]);
    }

    /**
     * Actualizar en bloque uno o varios settings.
     *
     * Body esperado: { "settings": [{ "key": "security.max_login_attempts", "value": "10" }, ...] }
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settings' => 'required|array|min:1',
            'settings.*.key' => 'required|string|exists:system_settings,key',
            'settings.*.value' => 'nullable',
        ]);

        foreach ($validated['settings'] as $item) {
            $setting = SystemSetting::where('key', $item['key'])->first();

            $value = $item['value'];
            if ($setting->type === 'boolean') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            }

            $setting->update(['value' => (string) $value]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Configuración actualizada exitosamente',
            'data' => [
                'groups' => $this->groupedSettings(),
                'system_info' => $this->systemInfo(),
            ],
        ]);
    }

    /**
     * Todos los settings, agrupados por `group` y ordenados, con el valor
     * ya interpretado según su tipo (`casted_value`).
     */
    private function groupedSettings(): array
    {
        return SystemSetting::orderBy('order')
            ->get()
            ->map(fn (SystemSetting $s) => [
                'id' => $s->id,
                'key' => $s->key,
                'group' => $s->group,
                'label' => $s->label,
                'type' => $s->type,
                'value' => $s->casted_value,
                'order' => $s->order,
            ])
            ->groupBy('group')
            ->toArray();
    }

    /**
     * Información real del entorno donde corre la aplicación — nunca
     * valores fijos como "Producción" o una fecha inventada.
     */
    private function systemInfo(): array
    {
        return [
            'version' => config('app.version', '3.0.0'),
            'environment' => app()->environment(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'last_schema_change' => $this->latestMigrationDate(),
        ];
    }

    /**
     * Fecha del cambio de esquema más reciente, tomada del nombre del
     * archivo de migración más nuevo (las migraciones de Laravel no
     * guardan un timestamp de "aplicado en" en la tabla `migrations`).
     */
    private function latestMigrationDate(): ?string
    {
        $files = collect(File::files(database_path('migrations')))
            ->map(fn ($file) => $file->getFilename())
            ->sort()
            ->values();

        $latest = $files->last();

        if (! $latest || ! preg_match('/^(\d{4})_(\d{2})_(\d{2})_(\d{2})(\d{2})(\d{2})_/', $latest, $m)) {
            return null;
        }

        return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
    }
}
