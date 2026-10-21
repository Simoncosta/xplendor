<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Pedir proposta" vindo do site (POST /api/public/car-proposal?token=…). O contrato está no
 * relatório da construção e o site usa-o tal e qual: nome, telemóvel, email e mensagem; o
 * financiamento e a retoma (marca, modelo, ano, km); o consentimento para ser contactado
 * (obrigatório) e o de receber novidades (opcional, à parte), cada um com o texto mostrado e a
 * versão da política; o event_id (um duplo clique não cria duas leads); a origem da visita.
 */
class ProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->has('email')) {
            $merge['email'] = strtolower(trim((string) $this->input('email')));
        }
        if ($this->has('name')) {
            $merge['name'] = trim((string) $this->input('name'));
        }
        $this->merge($merge);
    }

    public function rules(): array
    {
        $emailRule = app()->environment('testing') ? 'email:rfc' : 'email:rfc,dns';
        $year = (int) now()->format('Y') + 1;

        return [
            'car_id' => ['required', 'integer', Rule::exists('cars', 'id')->where('company_id', $this->publicCompanyId())],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{9,20}$/'],
            'email' => ['required', $emailRule, 'max:160'],
            'message' => ['nullable', 'string', 'max:5000'],

            'wants_financing' => ['required', 'boolean'],
            'has_trade_in' => ['required', 'boolean'],
            'trade_in' => ['nullable', 'array'],
            'trade_in.brand' => ['nullable', 'required_if_accepted:has_trade_in', 'string', 'max:60'],
            'trade_in.model' => ['nullable', 'required_if_accepted:has_trade_in', 'string', 'max:60'],
            'trade_in.year' => ['nullable', 'integer', 'min:1950', "max:{$year}"],
            'trade_in.km' => ['nullable', 'integer', 'min:0', 'max:2000000'],

            'consent_contact' => ['accepted'],
            'consent_contact_text' => ['required', 'string', 'max:2000'],
            'consent_marketing' => ['nullable', 'boolean'],
            'consent_marketing_text' => ['nullable', 'required_if_accepted:consent_marketing', 'string', 'max:2000'],
            'policy_version' => ['required', 'string', 'max:20'],
            'event_id' => ['nullable', 'uuid'],

            // A origem da visita: o origem=qr da folha da viatura e o rasto do site (como no car-lead).
            'origem' => ['nullable', 'string', 'max:20'],
            'referrer' => ['nullable', 'string', 'max:2048'],
            'landing_path' => ['nullable', 'string', 'max:2048'],
            'channel' => ['nullable', 'string', 'max:50'],
            'visitor_id' => ['nullable', 'uuid'],
            'session_id' => ['nullable', 'uuid'],
            'utm_source' => ['nullable', 'string', 'max:120'],
            'utm_medium' => ['nullable', 'string', 'max:120'],
            'utm_campaign' => ['nullable', 'string', 'max:120'],
            'utm_content' => ['nullable', 'string', 'max:255'],
            'utm_term' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'consent_contact.accepted' => 'É preciso autorizar o contacto sobre o pedido.',
            'consent_marketing_text.required_if_accepted' => 'Falta o texto do consentimento para receber novidades.',
            'trade_in.brand.required_if_accepted' => 'Indique a marca da viatura para retoma.',
            'trade_in.model.required_if_accepted' => 'Indique o modelo da viatura para retoma.',
            'phone.regex' => 'O telemóvel não parece válido.',
            'car_id.exists' => 'A viatura não existe.',
        ];
    }

    private function publicCompanyId(): int
    {
        $company = $this->input('public_api_company');

        return $company instanceof Company ? (int) $company->id : 0;
    }
}
