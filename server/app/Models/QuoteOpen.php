<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Uma abertura real da página pública de um orçamento (sinal enviado pelo browser
 * depois de a página carregar). Sem IP: só um identificador aleatório do browser em
 * hash, o tipo de dispositivo e as datas. Recarregar dentro de 30 minutos atualiza a
 * mesma abertura.
 */
class QuoteOpen extends Model
{
    public const DEVICE_MOBILE = 'mobile';
    public const DEVICE_DESKTOP = 'desktop';
    public const SAME_VISIT_MINUTES = 30;

    public $timestamps = false;

    protected $fillable = ['quote_id', 'quote_version_id', 'quote_public_link_id', 'visitor_hash', 'device', 'opened_at', 'last_seen_at'];

    protected $hidden = ['visitor_hash'];

    protected $casts = ['opened_at' => 'datetime', 'last_seen_at' => 'datetime'];
}
