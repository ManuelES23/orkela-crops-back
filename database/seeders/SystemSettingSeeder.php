<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingSeeder extends Seeder
{
    /**
     * Crea los settings por defecto del panel de administración si no
     * existen todavía (idempotente vía updateOrCreate por `key`).
     */
    public function run(): void
    {
        $settings = [
            // Seguridad
            ['key' => 'security.two_factor_enabled', 'group' => 'security', 'label' => 'Autenticación de dos factores', 'type' => 'boolean', 'value' => '0', 'order' => 1],
            ['key' => 'security.session_timeout_minutes', 'group' => 'security', 'label' => 'Tiempo de sesión (minutos)', 'type' => 'integer', 'value' => '120', 'order' => 2],
            ['key' => 'security.max_login_attempts', 'group' => 'security', 'label' => 'Intentos de login permitidos', 'type' => 'integer', 'value' => '5', 'order' => 3],

            // Base de datos
            ['key' => 'database.auto_backup_enabled', 'group' => 'database', 'label' => 'Backup automático', 'type' => 'boolean', 'value' => '1', 'order' => 1],
            ['key' => 'database.backup_frequency_days', 'group' => 'database', 'label' => 'Frecuencia de backup (días)', 'type' => 'integer', 'value' => '1', 'order' => 2],
            ['key' => 'database.backup_retention_days', 'group' => 'database', 'label' => 'Retención de backups (días)', 'type' => 'integer', 'value' => '30', 'order' => 3],

            // Notificaciones
            ['key' => 'notifications.email_enabled', 'group' => 'notifications', 'label' => 'Notificaciones por email', 'type' => 'boolean', 'value' => '1', 'order' => 1],
            ['key' => 'notifications.system_enabled', 'group' => 'notifications', 'label' => 'Notificaciones del sistema', 'type' => 'boolean', 'value' => '1', 'order' => 2],
            ['key' => 'notifications.security_alerts_enabled', 'group' => 'notifications', 'label' => 'Alertas de seguridad', 'type' => 'boolean', 'value' => '1', 'order' => 3],

            // Email
            ['key' => 'email.smtp_host', 'group' => 'email', 'label' => 'Servidor SMTP', 'type' => 'string', 'value' => 'smtp.gmail.com', 'order' => 1],
            ['key' => 'email.smtp_port', 'group' => 'email', 'label' => 'Puerto SMTP', 'type' => 'integer', 'value' => '587', 'order' => 2],
            ['key' => 'email.from_address', 'group' => 'email', 'label' => 'Email remitente', 'type' => 'email', 'value' => 'noreply@orkelacrops.com', 'order' => 3],
        ];

        foreach ($settings as $setting) {
            SystemSetting::updateOrCreate(
                ['key' => $setting['key']],
                $setting,
            );
        }
    }
}
