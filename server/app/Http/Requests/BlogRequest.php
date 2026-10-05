<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Conteúdo do artigo (criar e alterar). O estado NÃO vem daqui: muda só pelas ações do
 * fluxo (enviar, aprovar, devolver). A unicidade do slug por empresa e o slug fixo depois
 * de publicado são verificados no BlogService. O banner só é validado quando é um ficheiro
 * (o formulário não reenvia o caminho atual).
 */
class BlogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'               => ['required', 'string', 'max:255'],
            'subtitle'            => ['nullable', 'string', 'max:255'],
            'slug'                => ['nullable', 'string', 'max:180'],
            'banner'              => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'excerpt'             => ['nullable', 'string', 'max:500'],
            'content'             => ['nullable', 'string', 'max:200000'],
            'tags'                => ['nullable', 'array', 'max:20'],
            'tags.*'              => ['string', 'max:50'],
            'category'            => ['nullable', 'string', 'max:100'],
            'meta_title'          => ['nullable', 'string', 'max:255'],
            'meta_description'    => ['nullable', 'string', 'max:255'],
            'focus_keyword'       => ['nullable', 'string', 'max:100'],
            'seo_answer_first_ok' => ['nullable', 'boolean'],
            // Ponte da Linha Editorial: o artigo novo fica ligado à publicação do canal "Site".
            'editorial_post_id'   => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'O título é obrigatório.',
            'banner.image'   => 'O banner tem de ser uma imagem (JPG, PNG ou WebP).',
            'banner.max'     => 'O banner não pode ter mais de 4 MB.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->has('tags') && is_string($this->tags)) {
            $merge['tags'] = json_decode($this->tags, true) ?: [];
        }
        // Um banner em texto (caminho atual) não é um envio: ignora.
        if ($this->has('banner') && ! $this->hasFile('banner')) {
            $this->request->remove('banner');
        }
        if ($this->has('slug')) {
            $merge['slug'] = Str::slug((string) $this->input('slug'));
        }
        if ($this->has('seo_answer_first_ok')) {
            $merge['seo_answer_first_ok'] = filter_var($this->input('seo_answer_first_ok'), FILTER_VALIDATE_BOOLEAN);
        }
        if ($merge) {
            $this->merge($merge);
        }
    }

    /** Só os campos de conteúdo (o resto do pedido é ignorado). */
    public function articleData(): array
    {
        $data = $this->validated();
        if ($this->hasFile('banner')) {
            $data['banner'] = $this->file('banner');
        }

        return $data;
    }
}
