<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Memória por fornecedor (NIF) e descrição normalizada: o artigo da última ligação à mão de uma linha
 * sem código do fornecedor (DescriptionMemoryService).
 */
class SupplierDescriptionMemory extends Model
{
    protected $table = 'supplier_description_memory';

    protected $fillable = ['company_id', 'supplier_nif', 'description_norm', 'article_id', 'learned_by'];
}
