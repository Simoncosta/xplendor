<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Site do consultor: contagem diária agregada de um evento (sem IP, cookies nem identificador do visitante). */
class SiteDailyCount extends Model
{
    public const EVENTS = ['view', 'whatsapp_click', 'video_open', 'chave_nova_click'];
    public const SUBJECTS = ['property', 'development', 'site'];

    public $timestamps = false;

    protected $fillable = ['company_id', 'subject_type', 'subject_id', 'day', 'event', 'count'];

    protected $casts = ['day' => 'date', 'count' => 'integer'];
}
