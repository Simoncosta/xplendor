<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ConsultantSite;
use App\Models\ConsultantSiteHost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Site do consultor: os hosts do site de uma empresa (só o root, no /admin). Um host é único em
 * toda a plataforma (o site reconhece o consultor pelo host); o principal é o canónico.
 */
class ConsultantSiteHostsController extends Controller
{
    public function show(int $company)
    {
        $site = ConsultantSite::with('hosts')->where('company_id', $company)->first();

        return ApiResponse::success(['hosts' => $site ? $site->hosts->map(fn ($h) => ['host' => $h->host, 'is_primary' => $h->is_primary])->values()->all() : []], 'Hosts do site.');
    }

    public function update(Request $request, int $company)
    {
        Company::findOrFail($company);
        $data = $request->validate([
            'hosts' => ['present', 'array', 'max:5'],
            'hosts.*.host' => ['required', 'string', 'max:190', 'regex:/^(?=.{1,190}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i'],
            'hosts.*.is_primary' => ['boolean'],
        ], ['hosts.*.host.regex' => 'Indique um domínio válido, sem https:// nem caminho (por exemplo, nome.xplendor.tech).']);
        $site = ConsultantSite::firstOrCreate(['company_id' => $company], ['network_label' => 'Consultor Chave Nova']);
        $hosts = collect($data['hosts'])->map(fn ($h) => ['host' => ConsultantSiteHost::normalize($h['host']), 'is_primary' => (bool) ($h['is_primary'] ?? false)])->unique('host')->values();
        $taken = ConsultantSiteHost::whereIn('host', $hosts->pluck('host'))->where('consultant_site_id', '!=', $site->id)->pluck('host')->all();
        if ($taken !== []) {
            throw ValidationException::withMessages(['hosts' => 'Já em uso noutro site: ' . implode(', ', $taken) . '.']);
        }
        if ($hosts->isNotEmpty() && ! $hosts->contains('is_primary', true)) {
            $hosts = $hosts->map(fn ($h, $i) => ['host' => $h['host'], 'is_primary' => $i === 0]);
        }
        DB::transaction(function () use ($site, $hosts) {
            $site->hosts()->delete();
            $primaryDone = false;
            foreach ($hosts as $h) {
                $site->hosts()->create(['host' => $h['host'], 'is_primary' => $h['is_primary'] && ! $primaryDone]);
                $primaryDone = $primaryDone || $h['is_primary'];
            }
        });
        if ($hosts->isEmpty() && $site->site_status === 'publicado') {
            $site->forceFill(['site_status' => 'rascunho'])->save(); // sem host não há site
        } else {
            $site->touch(); // revalida o site (canónicos)
        }

        return $this->show($company);
    }
}
