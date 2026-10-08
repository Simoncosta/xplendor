<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * XPLENDOR — F2: agregado de reservas do CoverManager (tabela cm_reservation_hourly). SÓ números, nunca
 * dados pessoais; tirado da leitura que já se faz (CoverManagerService::details).
 */
class CmReservationHourly extends Model
{
    protected $table = 'cm_reservation_hourly';

    protected $guarded = ['id'];
}
