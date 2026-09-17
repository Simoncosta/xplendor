<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * XPLENDOR — validação de orçamentos avulsos (super-admin). A autorização real
 * é o middleware EnsureSuperAdmin no grupo /admin; aqui só validamos o payload.
 * No create os campos obrigatórios são exigidos; no update (PATCH) são todos
 * "sometimes" para permitir edições parciais (ex.: só mudar o estado).
 */
class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $req = $creating ? 'required' : 'sometimes';

        return [
            // Campo creatable: OU uma empresa cadastrada (company_id), OU nome livre.
            // No create exige-se pelo menos um dos dois (client_name é obrigatório
            // sem company_id; com company_id o nome é derivado da empresa).
            'company_id'     => ['nullable', 'integer', 'exists:companies,id'],
            'client_name'    => [$creating ? 'required_without:company_id' : 'sometimes', 'nullable', 'string', 'max:255'],
            'client_contact' => ['nullable', 'string', 'max:255'],
            'description'    => [$req, 'string', 'max:5000'],
            'amount'         => [$req, 'numeric', 'min:0', 'max:99999999.99'],
            'status'         => ['sometimes', Rule::in(Quote::STATUSES)],
            'notes'          => ['nullable', 'string', 'max:5000'],
        ];
    }
}
