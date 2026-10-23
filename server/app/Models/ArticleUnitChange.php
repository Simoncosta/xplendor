<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Uma mudança da unidade base ou de compra de um artigo com histórico de compras: as linhas antigas ficam
 * na unidade anterior. O artigo (Cadastros) e o Guião de unidades mostram o aviso durante RECENT_DAYS.
 */
class ArticleUnitChange extends Model
{
    public const RECENT_DAYS = 90;
    public const FIELDS = ['base' => 'Unidade base', 'purchase' => 'Unidade de compra'];

    protected $fillable = ['company_id', 'product_pingwin_id', 'field', 'from_unit_id', 'from_name', 'to_unit_id', 'to_name', 'history_lines', 'changed_at'];

    protected $casts = ['changed_at' => 'datetime', 'history_lines' => 'integer'];

    /** O aviso de uma mudança, para o ecrã. */
    public function warning(): string
    {
        return sprintf('A %s mudou de %s para %s a %s: as %d linhas de compra anteriores estão em %s.',
            mb_strtolower(self::FIELDS[$this->field] ?? 'unidade'), $this->from_name ?? '—', $this->to_name ?? '—',
            $this->changed_at->timezone('Europe/Lisbon')->format('d/m/Y'), $this->history_lines, $this->from_name ?? 'outra unidade');
    }

    /** As mudanças recentes dos artigos de uma empresa (por id no PingWin, a mais recente primeiro). @return array<string, list<array>> */
    public static function recentFor(int $companyId, ?array $pingwinIds = null): array
    {
        return self::where('company_id', $companyId)->where('changed_at', '>=', now()->subDays(self::RECENT_DAYS))
            ->when($pingwinIds !== null, fn ($q) => $q->whereIn('product_pingwin_id', array_map('strval', $pingwinIds)))
            ->orderByDesc('changed_at')->orderByDesc('id')->get()
            ->groupBy('product_pingwin_id')
            ->map(fn ($rows) => $rows->map(fn (self $c) => ['field' => $c->field, 'from' => $c->from_name, 'to' => $c->to_name,
                'changed_at' => $c->changed_at->toIso8601String(), 'history_lines' => $c->history_lines, 'warning' => $c->warning()])->values()->all())
            ->all();
    }
}
