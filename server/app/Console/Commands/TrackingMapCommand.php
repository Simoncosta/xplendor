<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\TrackingEventMap;
use App\Services\Tracking\TrackingHealthService;
use Illuminate\Console\Command;

/**
 * XPLENDOR — Auditoria (tracking dos sites): a correspondência entre os eventos do GA4 de um site e
 * os tipos da XPLENDOR, sem SQL à mão. Por omissão os nomes são iguais; um tipo com linhas próprias
 * usa só essas.
 *
 *   php artisan tracking:map {empresa} --list
 *   php artisan tracking:map {empresa} --ga4=whatsapp_click --type=whatsapp_click --exclude-page-context=floating_button
 *   php artisan tracking:map {empresa} --ga4=whatsapp_click --type=whatsapp_click --remove
 *
 * {empresa}: o id ou o slug da empresa.
 */
class TrackingMapCommand extends Command
{
    protected $signature = 'tracking:map
        {empresa : O id ou o slug da empresa}
        {--ga4= : O nome do evento no GA4 (ex.: whatsapp_click)}
        {--type= : O tipo na XPLENDOR (ex.: whatsapp_click)}
        {--exclude-page-context= : Os page_context a não contar na XPLENDOR, separados por vírgulas (ex.: floating_button)}
        {--list : Mostra a correspondência que a auditoria usa para a empresa}
        {--remove : Apaga a correspondência --ga4 → --type}';

    protected $description = 'Auditoria (tracking): a correspondência entre os eventos do GA4 de um site e os tipos da XPLENDOR.';

    public function handle(TrackingHealthService $tracking): int
    {
        $key = (string) $this->argument('empresa');
        $company = ctype_digit($key) ? Company::find((int) $key) : Company::where('slug', $key)->first();
        if (! $company) {
            $this->error("Empresa não encontrada: {$key}.");

            return self::FAILURE;
        }

        if ($this->option('list')) {
            return $this->list($company, $tracking);
        }

        $ga4 = trim((string) $this->option('ga4'));
        $type = trim((string) $this->option('type'));
        if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $ga4)) {
            $this->error('Indique --ga4 com o nome do evento no GA4 (letras, números e _, até 64).');

            return self::FAILURE;
        }
        if (! in_array($type, TrackingHealthService::TYPES, true)) {
            $this->error('Indique --type com um tipo da XPLENDOR: ' . implode(', ', TrackingHealthService::TYPES) . '.');

            return self::FAILURE;
        }

        if ($this->option('remove')) {
            $n = TrackingEventMap::where('company_id', $company->id)->where('ga4_event', $ga4)->where('xplendor_type', $type)->delete();
            $n ? $this->info("Apagada a correspondência {$ga4} → {$type} de {$company->fiscal_name}.") : $this->warn("Não havia a correspondência {$ga4} → {$type} em {$company->fiscal_name}.");

            return self::SUCCESS;
        }

        $exclude = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $this->option('exclude-page-context'))))));
        foreach ($exclude as $ctx) {
            if (! preg_match('/^[A-Za-z0-9_\-\/]{1,100}$/', $ctx)) {
                $this->error("page_context inválido: «{$ctx}» (letras, números, _, - e /, até 100).");

                return self::FAILURE;
            }
        }
        $map = TrackingEventMap::updateOrCreate(
            ['company_id' => $company->id, 'ga4_event' => $ga4, 'xplendor_type' => $type],
            ['filters' => $exclude ? ['exclude_page_context' => $exclude] : null, 'is_active' => true],
        );
        $this->info(sprintf('%s: %s → %s%s.', $map->wasRecentlyCreated ? 'Criada' : 'Atualizada', $ga4, $type,
            $exclude ? ' (sem os page_context ' . implode(', ', $exclude) . ')' : ''));

        return $this->list($company, $tracking);
    }

    private function list(Company $company, TrackingHealthService $tracking): int
    {
        $own = TrackingEventMap::where('company_id', $company->id)->get();
        $this->line("Correspondência GA4 → XPLENDOR de {$company->fiscal_name} (#{$company->id}):");
        $this->table(['Evento no GA4', 'Tipo na XPLENDOR', 'Sem os page_context', 'Origem'], array_map(function ($m) use ($own) {
            $isOwn = $own->contains(fn ($o) => $o->ga4_event === $m['ga4_event'] && $o->xplendor_type === $m['xplendor_type']);

            return [$m['ga4_event'], $m['xplendor_type'], implode(', ', (array) ($m['filters']['exclude_page_context'] ?? [])) ?: '—', $isOwn ? 'própria' : 'nomes iguais'];
        }, $tracking->maps($company->id)));

        return self::SUCCESS;
    }
}
