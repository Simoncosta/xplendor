<?php

namespace Tests\Unit;

use App\Models\ContentSector;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O ramo da empresa (o primeiro nó do setor que é um dos ramos com módulos próprios), devolvido em
 * /access para o menu mostrar os cadastros e o calendário da restauração só a esse ramo.
 */
class ModuleBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_ramo_pelo_setor_ou_por_um_subsetor(): void
    {
        $restauracao = ContentSector::where('slug', 'restauracao')->firstOrFail();
        $this->assertSame('restauracao', ModuleRegistry::branchForSector($restauracao->id));

        $child = ContentSector::where('parent_id', $restauracao->id)->first()
            ?? ContentSector::create(['name' => 'Sub', 'slug' => 'sub-restauracao-teste', 'parent_id' => $restauracao->id, 'type' => $restauracao->type, 'is_selectable' => true, 'sort' => 0]);
        $this->assertSame('restauracao', ModuleRegistry::branchForSector($child->id));

        $automovel = ContentSector::where('slug', 'automovel')->firstOrFail();
        $this->assertSame('automovel', ModuleRegistry::branchForSector($automovel->id));
    }

    public function test_sem_setor_ou_fora_dos_ramos_nao_tem_ramo(): void
    {
        $this->assertNull(ModuleRegistry::branchForSector(null));
        $this->assertNull(ModuleRegistry::branchForSector(999999));
        $root = ContentSector::whereNull('parent_id')->firstOrFail();
        $this->assertNull(ModuleRegistry::branchForSector($root->id));
    }
}
