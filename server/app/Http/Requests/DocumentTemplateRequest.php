<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * DMS — Caminho B: validação da EDIÇÃO do modelo (nome/arquivar).
 * O upload (ficheiro) é validado no controller::store (multipart).
 */
class DocumentTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'     => ['sometimes', 'string', 'max:255'],
            'archived' => ['nullable', 'boolean'],
        ];
    }
}
