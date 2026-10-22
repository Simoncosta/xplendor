<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** As contagens anónimas de um aviso num dia: vistas, cliques e fechos (só números). */
class SitePopupDailyCount extends Model
{
    public $timestamps = false;

    protected $fillable = ['site_popup_id', 'day', 'views', 'clicks', 'closes'];

    protected $casts = ['day' => 'date', 'views' => 'integer', 'clicks' => 'integer', 'closes' => 'integer'];
}
