<?php

declare(strict_types=1);

namespace App\Services\Cars;

use App\Models\Car;
use App\Models\CarQrScan;
use App\Models\Company;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * O QR da ficha da viatura. A folha leva um endereço curto da plataforma ({base}/q/{código}); a
 * leitura conta (sem IP, sem agente do browser, sem cookies na base de dados; os robôs conhecidos
 * não contam; o mesmo aparelho conta uma vez em 30 minutos, por uma memória temporária em cache)
 * e reencaminha para o endereço da viatura no site da empresa, com os parâmetros da origem.
 */
final class VehicleQr
{
    public const PARAMS = ['origem' => 'qr', 'utm_source' => 'qr', 'utm_medium' => 'offline', 'utm_campaign' => 'ficha_stand'];
    public const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';
    public const CODE_LENGTH = 8;
    public const REPEAT_MINUTES = 30;
    /** Agentes de robôs e de pré-visualização de ligações (não são pessoas a ler o QR). */
    public const BOT_PATTERN = '/bot|crawl|spider|slurp|preview|facebookexternalhit|facebot|whatsapp|telegram|discord|skype|embedly|quora|pinterest|vkshare|w3c_validator|headless|lighthouse|pingdom|uptime|monitor|curl|wget|python|httpclient|go-http|java\/|okhttp|axios|node-fetch|postman|insomnia|scrapy/i';

    /** A mensagem de erro do modelo, ou null se for válido (vazio também é válido: sem QR). */
    public static function templateError(string $template): ?string
    {
        $t = trim($template);
        if ($t === '') {
            return null;
        }
        if (! str_starts_with(strtolower($t), 'https://')) {
            return 'O endereço da viatura tem de começar por https://.';
        }
        if (! str_contains($t, '{id}')) {
            return 'O endereço da viatura tem de ter {id} no sítio do número da viatura (ex.: https://quebom.pt/vehicles/{id}).';
        }
        if (filter_var(str_replace('{id}', '137', $t), FILTER_VALIDATE_URL) === false || substr_count($t, '{id}') > 1) {
            return 'O endereço da viatura não é válido (ex.: https://quebom.pt/vehicles/{id}).';
        }

        return null;
    }

    public static function ensureCode(Car $car): string
    {
        if ($car->qr_code) {
            return $car->qr_code;
        }
        do {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (Car::where('qr_code', $code)->exists());
        // Só a coluna (o modelo pode trazer atributos calculados, como o contacto do vendedor).
        Car::whereKey($car->id)->update(['qr_code' => $code]);
        $car->setAttribute('qr_code', $code);
        $car->syncOriginalAttribute('qr_code');

        return $code;
    }

    public static function shortUrl(string $code): string
    {
        return rtrim((string) config('services.vehicle_qr.base_url'), '/') . '/q/' . $code;
    }

    /** O endereço da viatura no site, sem parâmetros (null sem modelo). */
    public static function siteUrl(?Company $company, int $carId): ?string
    {
        $t = trim((string) ($company?->vehicle_url_template ?? ''));

        return $t !== '' && self::templateError($t) === null ? str_replace('{id}', (string) $carId, $t) : null;
    }

    /** O destino do reencaminhamento: o endereço da viatura com os parâmetros da origem. */
    public static function targetUrl(string $siteUrl): string
    {
        return $siteUrl . (str_contains($siteUrl, '?') ? '&' : '?') . http_build_query(self::PARAMS);
    }

    /** "https://quebom.pt/vehicles/137" → "quebom.pt/vehicles/137" (para escrever à mão). */
    public static function shortLabel(string $url): string
    {
        $u = preg_replace('#^https?://(www\.)?#i', '', $url) ?? $url;

        return rtrim(explode('?', $u)[0], '/');
    }

    /** O QR em SVG: correção de erros média, preto sobre branco, com a margem branca à volta. */
    public static function svg(string $data): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'quietzoneSize' => 4,
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'drawLightModules' => true,
            'moduleValues' => [],
        ]);

        return (new QRCode($options))->render($data);
    }

    public static function isBot(?string $userAgent): bool
    {
        $ua = trim((string) $userAgent);

        return $ua === '' || preg_match(self::BOT_PATTERN, $ua) === 1;
    }

    /**
     * Conta a leitura, salvo robô ou repetição do mesmo aparelho em 30 minutos. O aparelho só
     * existe na cache, como um resumo irreversível com a chave da aplicação, e expira sozinho.
     */
    public static function recordScan(Car $car, Request $request): bool
    {
        if (self::isBot($request->userAgent())) {
            return false;
        }
        $device = hash_hmac('sha256', $request->ip() . '|' . $request->userAgent() . '|' . $car->id, (string) config('app.key'));
        if (! Cache::add('vehicle-qr:' . $device, 1, now()->addMinutes(self::REPEAT_MINUTES))) {
            return false;
        }
        CarQrScan::create(['company_id' => $car->company_id, 'car_id' => $car->id, 'scanned_at' => now()]);

        return true;
    }

    /**
     * As leituras de uma viatura para "Tráfego & Canais": desde sempre (como o resto do
     * separador), em 7 dias e em 24 horas, por dia nos últimos 30 dias e a última. São leituras,
     * não pessoas. As visitas do site com utm_source=qr (script de seguimento) são outra medida.
     */
    public static function stats(Car $car): array
    {
        $q = fn () => CarQrScan::where('car_id', $car->id)->where('company_id', $car->company_id);
        $from = now()->subDays(29)->startOfDay();
        $byDay = $q()->where('scanned_at', '>=', $from)->get(['scanned_at'])->groupBy(fn ($s) => $s->scanned_at->toDateString())->map->count();
        $days = [];
        for ($d = $from->copy(); $d->lte(now()); $d->addDay()) {
            $days[] = ['date' => $d->toDateString(), 'total' => (int) ($byDay[$d->toDateString()] ?? 0)];
        }
        $last = $q()->max('scanned_at');

        return [
            'total' => $q()->count(),
            'last_7d' => $q()->where('scanned_at', '>=', now()->subDays(7))->count(),
            'last_24h' => $q()->where('scanned_at', '>=', now()->subDay())->count(),
            'last_at' => $last ? \Carbon\Carbon::parse($last)->toIso8601String() : null,
            'by_day' => $days,
            'site_visits_from_qr' => \Illuminate\Support\Facades\DB::table('car_views')->where('car_id', $car->id)->where('utm_source', 'qr')->count(),
        ];
    }
}
