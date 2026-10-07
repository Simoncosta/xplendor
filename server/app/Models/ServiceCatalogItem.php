<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Serviço da tabela padrão dos orçamentos (preço SEM IVA). Gerido pela equipa XPLENDOR. */
class ServiceCatalogItem extends Model
{
    protected $fillable = ['name', 'description', 'unit_price', 'unit', 'billing_type', 'active', 'sort', 'onboarding_checklist'];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'active'     => 'boolean',
        'sort'       => 'integer',
        // Lista de arranque: tarefas copiadas para o ticket de arranque quando um orçamento é aceite.
        'onboarding_checklist' => 'array',
    ];

    /**
     * A lista de arranque normalizada: cada linha com o título e a chave fixa (ou null). Aceita
     * ainda linhas antigas só com texto.
     *
     * @return array<int, array{title: string, key: ?string}>
     */
    public function checklist(): array
    {
        return self::normalizeChecklist((array) ($this->onboarding_checklist ?? []));
    }

    public static function normalizeChecklist(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $title = trim(is_array($line) ? (string) ($line['title'] ?? '') : (is_string($line) ? $line : ''));
            if ($title === '') {
                continue;
            }
            $key = is_array($line) ? ($line['key'] ?? null) : null;
            $out[] = ['title' => mb_substr($title, 0, 200), 'key' => is_string($key) && array_key_exists($key, SupportTicketTask::KEYS) ? $key : null];
        }

        return $out;
    }
}
