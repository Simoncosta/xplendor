<?php

use App\Models\ContentSector;
use Illuminate\Database\Migrations\Migration;

/**
 * Bússola Master, R3: o ramo "Imobiliário", folha selecionável debaixo do Universal, com modelo
 * próprio no perfil da marca (BrandProfileTemplates). Herda as âncoras do Universal. Idempotente
 * (updateOrCreate pelo slug); não mexe nos ramos que já existem.
 */
return new class extends Migration
{
    public function up(): void
    {
        $universal = ContentSector::where('slug', 'universal')->first();
        if (! $universal) {
            return;
        }
        ContentSector::updateOrCreate(['slug' => 'imobiliario'],
            ['name' => 'Imobiliário', 'parent_id' => $universal->id, 'type' => 'folha', 'is_selectable' => true, 'sort' => 6]);
    }

    public function down(): void
    {
        $sector = ContentSector::where('slug', 'imobiliario')->first();
        if ($sector && ! \App\Models\Company::where('content_sector_id', $sector->id)->exists()) {
            $sector->delete();
        }
    }
};
