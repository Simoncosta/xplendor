<?php

use App\Models\ContentSector;
use Illuminate\Database\Migrations\Migration;

/**
 * Linha Editorial e Perfil da Marca: dois ramos novos, folhas selecionáveis debaixo do
 * Universal, "Software (SaaS)" e "Agência de comunicação" (com modelos próprios em
 * BrandProfileTemplates). Herdam as âncoras do Universal; as próprias criam-se depois, na
 * curadoria. Idempotente (updateOrCreate pelo slug); não mexe nos ramos que já existem.
 */
return new class extends Migration
{
    public function up(): void
    {
        $universal = ContentSector::where('slug', 'universal')->first();
        if (! $universal) {
            return;
        }
        ContentSector::updateOrCreate(['slug' => 'software-saas'],
            ['name' => 'Software (SaaS)', 'parent_id' => $universal->id, 'type' => 'folha', 'is_selectable' => true, 'sort' => 4]);
        ContentSector::updateOrCreate(['slug' => 'agencia-comunicacao'],
            ['name' => 'Agência de comunicação', 'parent_id' => $universal->id, 'type' => 'folha', 'is_selectable' => true, 'sort' => 5]);
    }

    public function down(): void
    {
        // Só se não houver empresas nestes ramos (a chave estrangeira das empresas é nullOnDelete).
        foreach (['software-saas', 'agencia-comunicacao'] as $slug) {
            $sector = ContentSector::where('slug', $slug)->first();
            if ($sector && ! \App\Models\Company::where('content_sector_id', $sector->id)->exists()) {
                $sector->delete();
            }
        }
    }
};
