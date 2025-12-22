<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = in_array($this->method(), ['PUT', 'PATCH']);
        $companyId = $this->route('company') ?? null;

        return [
            // New User
            'name_user' => [$isUpdate ? 'nullable' : 'required', 'string', 'max:255'],
            'email_user' => [$isUpdate ? 'nullable' : 'required', 'email', 'max:255', 'unique:users,email'],
            // Identificação
            'nipc' => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                'max:20',
                Rule::unique('companies', 'nipc')->ignore($companyId),
            ],

            'fiscal_name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'responsible_name' => ['nullable', 'string', 'max:255'],

            // Endereço
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'district_id' => ['nullable', 'exists:districts,id'],
            'municipality_id' => ['nullable', 'exists:municipalities,id'],
            'parish_id' => ['nullable', 'exists:parishes,id'],

            // Contatos
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'invoice_email' => ['nullable', 'email', 'max:255'],

            // Dados fiscais / legais
            'registry_office' => ['nullable', 'string', 'max:255'],
            'registry_office_number' => ['nullable', 'string', 'max:255'],
            'capital_social' => ['nullable', 'string', 'max:255'],
            'nib' => ['nullable', 'string', 'max:255'],
            'registration_fees' => ['nullable', 'integer', 'min:0'],
            'export_promotion_price' => ['boolean'],

            'credit_intermediation_link' => ['nullable', 'url'],

            // Marketing / Integrações
            'vat_value' => ['nullable', 'integer', 'min:0'],
            'facebook_page_id' => ['nullable', 'string', 'max:255'],
            'facebook_pixel_id' => ['nullable', 'string', 'max:255'],
            'facebook_access_token' => ['nullable', 'string'],
            'website' => ['nullable', 'string', 'max:255'],

            // Leads
            'lead_hours_pending' => ['nullable', 'string', 'max:50'],
            'lead_distribution' => [
                'required',
                Rule::in(['manual', 'automatic_latest', 'automatic_less']),
            ],

            // Conteúdo
            'ad_text' => ['nullable', 'string'],

            // Arquivos
            'pdf_path' => ['nullable', 'string', 'max:255'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'carmine_logo_path' => ['nullable', 'string', 'max:255'],

            // Plano
            'plan_id' => [$isUpdate ? 'sometimes' : 'required', 'exists:plans,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'nipc.required' => 'O NIPC é obrigatório.',
            'nipc.unique' => 'Este NIPC já está registado.',
            'fiscal_name.required' => 'A designação fiscal é obrigatória.',
            'email.email' => 'O email informado não é válido.',
            'invoice_email.email' => 'O email de faturação não é válido.',
            'lead_distribution.in' => 'Tipo de distribuição de leads inválido.',
            'plan_id.required' => 'É obrigatório associar um plano.',
            'plan_id.exists' => 'O plano selecionado não existe.',
        ];
    }
}
