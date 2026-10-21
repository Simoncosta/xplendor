<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Uma leitura do QR da ficha da viatura: só a empresa, a viatura e a hora (nada sobre quem leu). */
class CarQrScan extends Model
{
    public $timestamps = false;

    protected $fillable = ['company_id', 'car_id', 'scanned_at'];

    protected $casts = ['scanned_at' => 'datetime'];
}
