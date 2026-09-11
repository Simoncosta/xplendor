<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DMS sub-fase 1c.2a — validação da Categoria de Despesa.
 * Só o `name` é obrigatório.
 */
class ExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // `name` é obrigatório ao CRIAR, mas opcional ao ATUALIZAR — permite
        // updates parciais (arquivar/restaurar ou só a cor) sem reenviar o nome.
        // (BUG 1c.2a: arquivar enviava só `archived` e o `required` rebentava 422.)
        $isUpdate = in_array($this->method(), ['PUT', 'PATCH'], true);

        return [
            'name'     => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'color'    => ['nullable', 'string', 'max:20'],
            'archived' => ['nullable', 'boolean'],
        ];
    }
}
