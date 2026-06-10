<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMarketSnapshotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Fontes válidas para ingestão de snapshots de mercado (MS2.a).
     *
     * Aperto deliberado de `required string max:100` para enum fechado:
     * o scraper actual JÁ envia source obrigatoriamente (auditado 2026-06-10),
     * logo não há retro-compatibilidade a manter. Adicionar uma fonte exige
     * actualizar este array em conjunto com os adapters Python — falha
     * controlada vs slug silente que infectaria a BD.
     */
    public const VALID_SOURCES = ['standvirtual', 'custojusto'];

    public function rules(): array
    {
        return [
            'snapshots' => ['required', 'array', 'min:1'],
            'snapshots.*.external_id' => ['required', 'string', 'max:255'],
            'snapshots.*.source' => ['required', Rule::in(self::VALID_SOURCES)],
            'snapshots.*.vehicle_type' => ['nullable', 'string', 'in:car,motorhome,caravan,motorcycle'],
            'snapshots.*.brand' => ['nullable', 'string', 'max:255'],
            'snapshots.*.model' => ['nullable', 'string', 'max:255'],
            'snapshots.*.year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'snapshots.*.title' => ['required', 'string', 'max:255'],
            'snapshots.*.url' => ['required', 'url', 'max:2048'],
            'snapshots.*.category' => ['nullable', 'string', 'max:100'],
            'snapshots.*.region' => ['nullable', 'string', 'max:255'],
            'snapshots.*.price' => ['nullable', 'numeric', 'min:0'],
            'snapshots.*.price_currency' => ['nullable', 'string', 'max:10'],
            'snapshots.*.price_evaluation' => ['nullable', 'string', 'max:100'],
            'snapshots.*.km' => ['nullable', 'integer', 'min:0'],
            'snapshots.*.fuel' => ['nullable', 'string', 'max:100'],
            'snapshots.*.gearbox' => ['nullable', 'string', 'max:100'],
            'snapshots.*.power_hp' => ['nullable', 'integer', 'min:0'],
            'snapshots.*.color' => ['nullable', 'string', 'max:100'],
            'snapshots.*.doors' => ['nullable', 'integer', 'min:0', 'max:10'],
            'snapshots.*.scraped_at' => ['nullable', 'date'],
        ];
    }
}
