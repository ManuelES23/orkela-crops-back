<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permite null en embedding/photo_path: el comando biometrics:purge los
     * limpia cuando una plantilla revocada supera su plazo de retención
     * (LFPDPPP) — dejaban de existir físicamente, la columna debe reflejarlo.
     */
    public function up(): void
    {
        Schema::table('sf_employee_face_templates', function (Blueprint $table) {
            $table->json('embedding')->nullable()->change();
            $table->string('photo_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sf_employee_face_templates', function (Blueprint $table) {
            $table->json('embedding')->nullable(false)->change();
            $table->string('photo_path')->nullable(false)->change();
        });
    }
};
