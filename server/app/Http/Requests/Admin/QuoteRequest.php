<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\QuoteLine;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Formulário do orçamento de serviços (criar e alterar). O cliente é um dos
 * Clientes da XPLENDOR (customer_id) ou um novo (new_customer). Os totais são
 * calculados no servidor (QuoteCalculator), nunca vêm do ecrã.
 *
 * Compatível com o editor anterior (linhas sem "Opcional" e "Do pacote": ficam false)
 * e com pedidos multipart (os booleanos chegam como "true"/"false"/"1"/"0"). Os erros
 * das linhas são legíveis: "Linha 1: o valor de 'Opcional' não é válido."
 */
class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // o grupo /admin e o controller garantem root
    }

    public function rules(): array
    {
        return [
            'customer_id'            => ['nullable', 'integer'],
            'new_customer'           => ['nullable', 'array'],
            'new_customer.name'      => ['required_with:new_customer', 'string', 'max:255'],
            'new_customer.phone'     => ['nullable', 'string', 'max:50'],
            'new_customer.email'     => ['nullable', 'email', 'max:255'],
            'company_id'             => ['nullable', 'integer', 'exists:companies,id'],
            'title'                  => ['nullable', 'string', 'max:255'],
            'intro'                  => ['nullable', 'string', 'max:5000'],
            'notes'                  => ['nullable', 'string', 'max:5000'],
            'minimum_contract_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'monthly_start_terms'    => ['nullable', 'string', 'max:2000'],
            'payment_terms_monthly'  => ['nullable', 'string', 'max:2000'],
            'payment_terms_one_off'  => ['nullable', 'string', 'max:2000'],
            'global_discount_type'   => ['nullable', Rule::in(['percent', 'amount'])],
            'global_discount_value'  => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'global_discount_target' => ['nullable', Rule::in(QuoteLine::BILLING_TYPES)],
            'global_discount_label'  => ['nullable', 'string', 'max:120'],
            'lines'                  => ['sometimes', 'array', 'max:100'],
            'lines.*.catalog_item_id' => ['nullable', 'integer', 'exists:service_catalog_items,id'],
            'lines.*.name'           => ['required', 'string', 'max:255'],
            'lines.*.description'    => ['nullable', 'string', 'max:2000'],
            'lines.*.unit'           => ['required', Rule::in(QuoteLine::UNITS)],
            'lines.*.billing_type'   => ['required', Rule::in(QuoteLine::BILLING_TYPES)],
            'lines.*.quantity'       => ['required', 'numeric', 'gt:0', 'max:99999'],
            'lines.*.unit_price'     => ['required', 'numeric', 'min:0', 'max:9999999'],
            'lines.*.discount_type'  => ['nullable', Rule::in(['percent', 'amount'])],
            'lines.*.discount_value' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'lines.*.is_optional'    => ['boolean'],
            'lines.*.in_package'     => ['boolean'],
        ];
    }

    /** Campos novos das linhas: ausentes ou null valem false; "true"/"false"/"1"/"0"/"on"/"off" viram booleanos. */
    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');
        if (! is_array($lines)) {
            return;
        }
        foreach ($lines as $i => $line) {
            if (! is_array($line)) {
                continue;
            }
            foreach (['is_optional', 'in_package'] as $flag) {
                $lines[$i][$flag] = self::toBool($line[$flag] ?? null);
            }
        }
        $this->merge(['lines' => $lines]);
    }

    /** null/ausente → false; valores booleanos reconhecidos → bool; o resto fica como veio (e a regra recusa-o). */
    public static function toBool(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $value;
    }

    public function attributes(): array
    {
        return [
            'new_customer.name' => 'nome do cliente', 'new_customer.phone' => 'telefone do cliente', 'new_customer.email' => 'email do cliente',
            'company_id' => 'empresa ligada', 'title' => 'título', 'intro' => 'introdução', 'notes' => 'notas internas',
            'minimum_contract_months' => 'contrato mínimo', 'monthly_start_terms' => 'início dos serviços mensais',
            'payment_terms_monthly' => 'pagamento dos serviços mensais', 'payment_terms_one_off' => 'pagamento do valor único',
            'global_discount_type' => 'tipo de desconto de pacote', 'global_discount_value' => 'valor do desconto de pacote',
            'global_discount_target' => 'total do desconto de pacote', 'global_discount_label' => 'texto do desconto de pacote',
            'lines' => 'linhas',
        ];
    }

    /** Mensagens das linhas sem o nome técnico do campo (o número da linha entra em failedValidation). */
    public function messages(): array
    {
        return [
            'lines.*.catalog_item_id.integer' => "o serviço do catálogo não é válido.",
            'lines.*.catalog_item_id.exists' => "o serviço do catálogo já não existe.",
            'lines.*.name.required' => "indique o nome do serviço.",
            'lines.*.name.string' => "o nome do serviço não é válido.",
            'lines.*.name.max' => "o nome do serviço é demasiado longo (máximo 255 caracteres).",
            'lines.*.description.string' => "a descrição não é válida.",
            'lines.*.description.max' => "a descrição é demasiado longa (máximo 2000 caracteres).",
            'lines.*.unit.required' => "escolha a unidade.",
            'lines.*.unit.in' => "o valor de 'Unidade' não é válido.",
            'lines.*.billing_type.required' => "escolha a cobrança (mensal ou valor único).",
            'lines.*.billing_type.in' => "o valor de 'Cobrança' não é válido.",
            'lines.*.quantity.required' => "indique a quantidade.",
            'lines.*.quantity.numeric' => "a quantidade tem de ser um número.",
            'lines.*.quantity.gt' => "a quantidade tem de ser maior do que zero.",
            'lines.*.quantity.max' => "a quantidade é demasiado grande.",
            'lines.*.unit_price.required' => "indique o preço.",
            'lines.*.unit_price.numeric' => "o preço tem de ser um número.",
            'lines.*.unit_price.min' => "o preço não pode ser negativo.",
            'lines.*.unit_price.max' => "o preço é demasiado grande.",
            'lines.*.discount_type.in' => "o valor de 'Desconto da linha' não é válido (% ou €).",
            'lines.*.discount_value.numeric' => "o desconto tem de ser um número.",
            'lines.*.discount_value.min' => "o desconto não pode ser negativo.",
            'lines.*.discount_value.max' => "o desconto é demasiado grande.",
            'lines.*.is_optional.boolean' => "o valor de 'Opcional' não é válido.",
            'lines.*.in_package.boolean' => "o valor de 'Do pacote' não é válido.",
            'lines.max' => 'Um orçamento pode ter no máximo 100 linhas.',
        ];
    }

    /** "lines.0.x" → "Linha 1: …" (a chave mantém-se, para o ecrã saber onde está o erro). */
    protected function failedValidation(Validator $validator): void
    {
        $errors = [];
        foreach ($validator->errors()->messages() as $key => $messages) {
            if (preg_match('/^lines\.(\d+)\./', $key, $m)) {
                $messages = array_map(fn ($msg) => 'Linha ' . ((int) $m[1] + 1) . ': ' . $msg, $messages);
            }
            $errors[$key] = $messages;
        }

        throw ValidationException::withMessages($errors);
    }
}
