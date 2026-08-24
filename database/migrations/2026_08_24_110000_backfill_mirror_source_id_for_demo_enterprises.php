<?php

use App\Models\Enterprise;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $mirrors = [
        'finca-modelo-demo' => 'splendidfarms',
        'agroverde-demo' => 'grupoesplendido',
        'exportadora-valle-demo' => 'splendidbyporvenir',
    ];

    public function up(): void
    {
        foreach ($this->mirrors as $mirrorSlug => $rootSlug) {
            $root = Enterprise::where('slug', $rootSlug)->first();
            $mirror = Enterprise::where('slug', $mirrorSlug)->first();

            if ($root && $mirror && ! $mirror->mirror_source_id) {
                $mirror->update(['mirror_source_id' => $root->id]);
            }
        }
    }

    public function down(): void
    {
        Enterprise::whereIn('slug', array_keys($this->mirrors))->update(['mirror_source_id' => null]);
    }
};
