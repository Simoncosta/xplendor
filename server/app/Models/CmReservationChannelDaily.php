<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * XPLENDOR — F2: agregado de reservas do CoverManager (tabela cm_reservation_channel_daily). SÓ números, nunca
 * dados pessoais; tirado da leitura que já se faz (CoverManagerService::details).
 */
class CmReservationChannelDaily extends Model
{
    protected $table = 'cm_reservation_channel_daily';

    protected $guarded = ['id'];
}
