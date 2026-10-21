<?php

use App\Http\Controllers\VehicleQrController;
use Illuminate\Support\Facades\Route;

// O QR da ficha da viatura (A4): público, sem sessão nem cookies, com limite de pedidos por IP
// (o IP só serve ao limite, na cache; nunca é gravado).
Route::get('/q/{code}', [VehicleQrController::class, 'show'])
    ->where('code', '[A-Za-z0-9]{6,16}')
    ->middleware('throttle:vehicle-qr');
