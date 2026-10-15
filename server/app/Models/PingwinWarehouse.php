<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * XPLENDOR — Um armazém do PingWin: nome, código, valorização (0 = PCM, 1 = PCU), as lojas servidas
 * ([{store_id, code, name, served}]) e a loja principal (Fatia 7a).
 */
class PingwinWarehouse extends Model
{
    public const COSTMETHODS = [0 => 'PCM', 1 => 'PCU'];

    protected $fillable = [
        'company_id', 'pingwin_id', 'code', 'description', 'deleted', 'inv_costmethod', 'exit_costmethod',
        'stores', 'main_store_pingwin_id', 'synced_at',
    ];

    protected $casts = [
        'deleted' => 'boolean',
        'inv_costmethod' => 'integer',
        'exit_costmethod' => 'integer',
        'stores' => 'array',
        'synced_at' => 'datetime',
    ];

    /** @return list<string> os ids das lojas que este armazém serve */
    public function servedStoreIds(): array
    {
        return array_values(array_map(fn ($s) => (string) $s['store_id'], array_filter((array) $this->stores, fn ($s) => ! empty($s['served']))));
    }
}
