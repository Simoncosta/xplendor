<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Mailbox;
use App\Services\Mailbox\MailboxHosts;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * E-mail 1 · A minha caixa de correio. Os modelos (Gmail, domínio próprio) preenchem os servidores
 * no ecrã; aqui valida-se o que chega. A palavra-passe só é obrigatória na primeira vez (depois,
 * vazia mantém a gravada).
 */
class MyMailboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['email', 'smtp_host', 'imap_host', 'username'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim((string) $this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        $host = function (string $attribute, mixed $value, Closure $fail) {
            if (! is_string($value) || ! MailboxHosts::acceptable($value)) {
                $fail('Indique o nome do servidor de e-mail (por exemplo, mail.oseudominio.pt).');
            }
        };
        $hasPassword = Mailbox::personal((int) $this->route('id'), (int) $this->user()?->id)->exists();

        return [
            'account_type' => ['required', 'string', Rule::in(Mailbox::TYPES)],
            'email' => ['required', 'email', 'max:255'],
            'smtp_host' => ['required', 'string', 'max:253', $host],
            'smtp_port' => ['required', 'integer', Rule::in(config('mailboxes.smtp_ports'))],
            'smtp_encryption' => ['required', 'string', Rule::in(['ssl', 'starttls'])],
            'imap_host' => ['required', 'string', 'max:253', $host],
            'imap_port' => ['required', 'integer', Rule::in(config('mailboxes.imap_ports'))],
            'imap_encryption' => ['required', 'string', Rule::in(['ssl', 'starttls'])],
            'username' => ['required', 'string', 'max:255'],
            'password' => [$hasPassword ? 'nullable' : 'required', 'string', 'max:255'],
            'save_to_sent' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'account_type.in' => 'Escolha Gmail, Domínio próprio (cPanel) ou Outro (manual).',
            'smtp_port.in' => 'A porta SMTP tem de ser 465, 587, 25 ou 2525.',
            'imap_port.in' => 'A porta IMAP tem de ser 993 ou 143.',
            'password.required' => 'Indique a palavra-passe da caixa.',
        ];
    }
}
