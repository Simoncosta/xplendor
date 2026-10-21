<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Car;
use App\Services\Cars\VehicleQr;
use Illuminate\Http\Request;

/**
 * O endereço curto do QR da ficha (/q/{código}): público, sem sessão, com limite de pedidos.
 * Conta a leitura e reencaminha (302) para a viatura no site da empresa, com os parâmetros da
 * origem. Sem modelo na empresa: o site da empresa, ou a página "Viatura indisponível". Código
 * desconhecido: a mesma página.
 */
class VehicleQrController extends Controller
{
    public function show(Request $request, string $code)
    {
        $car = Car::with('company')->where('qr_code', strtolower($code))->first();
        if (! $car) {
            return response()->view('vehicle-qr.unavailable', [], 404)->header('Cache-Control', 'no-store');
        }
        VehicleQr::recordScan($car, $request);
        $site = VehicleQr::siteUrl($car->company, (int) $car->id);
        if ($site !== null) {
            return redirect()->away(VehicleQr::targetUrl($site), 302)->header('Cache-Control', 'no-store');
        }
        $website = trim((string) ($car->company?->website ?? ''));
        if ($website !== '') {
            return redirect()->away(preg_match('#^https?://#i', $website) ? $website : "https://{$website}", 302)->header('Cache-Control', 'no-store');
        }

        return response()->view('vehicle-qr.unavailable', [], 200)->header('Cache-Control', 'no-store');
    }
}
