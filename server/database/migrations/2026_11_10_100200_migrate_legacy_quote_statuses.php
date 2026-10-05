<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Passa os orçamentos antigos para o modelo novo, sem perder nada:
 *   pending → sent (validade = data desta migração + 30 dias, hora de Lisboa)
 *   approved → accepted · rejected → refused · paid, completed → accepted
 * O estado original fica em legacy_status. Cada orçamento antigo ganha uma linha
 * personalizada de VALOR ÚNICO com a descrição e o valor que tinha, e um número
 * ORC-AAAA-NNN pela ordem de criação (ano da criação). Idempotente: só toca nos
 * que ainda têm um estado antigo.
 */
return new class extends Migration
{
    private const MAP = [
        'pending'   => 'sent',
        'approved'  => 'accepted',
        'rejected'  => 'refused',
        'paid'      => 'accepted',
        'completed' => 'accepted',
    ];

    public function up(): void
    {
        $validUntilForOpen = CarbonImmutable::now('Europe/Lisbon')->addDays(30)->toDateString();

        $legacy = DB::table('quotes')->whereIn('status', array_keys(self::MAP))->orderBy('created_at')->orderBy('id')->get();

        foreach ($legacy as $quote) {
            DB::transaction(function () use ($quote, $validUntilForOpen) {
                $createdAt = CarbonImmutable::parse($quote->created_at ?? now())->setTimezone('Europe/Lisbon');
                $newStatus = self::MAP[$quote->status];

                $number = $quote->number;
                if ($number === null) {
                    $year = (int) $createdAt->format('Y');
                    $row = DB::table('quote_number_sequences')->where('year', $year)->lockForUpdate()->first();
                    $next = ($row->last_number ?? 0) + 1;
                    $row
                        ? DB::table('quote_number_sequences')->where('year', $year)->update(['last_number' => $next, 'updated_at' => now()])
                        : DB::table('quote_number_sequences')->insert(['year' => $year, 'last_number' => $next, 'created_at' => now(), 'updated_at' => now()]);
                    $number = sprintf('ORC-%d-%03d', $year, $next);
                    $numberFields = ['number' => $number, 'number_year' => $year, 'number_seq' => $next];
                } else {
                    $numberFields = [];
                }

                if (! DB::table('quote_lines')->where('quote_id', $quote->id)->exists()) {
                    DB::table('quote_lines')->insert([
                        'quote_id' => $quote->id, 'position' => 0, 'catalog_item_id' => null,
                        'name' => mb_substr((string) $quote->description, 0, 120) ?: 'Serviço',
                        'description' => mb_strlen((string) $quote->description) > 120 ? $quote->description : null,
                        'unit' => 'project', 'billing_type' => 'one_off', 'quantity' => 1,
                        'unit_price' => $quote->amount, 'discount_type' => null, 'discount_value' => null,
                        'line_total' => $quote->amount, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                DB::table('quotes')->where('id', $quote->id)->update($numberFields + [
                    'status'        => $newStatus,
                    'legacy_status' => $quote->status,
                    'total_monthly' => 0,
                    'total_one_off' => $quote->amount,
                    'sent_at'       => $quote->created_at,
                    'valid_until'   => $newStatus === 'sent' ? $validUntilForOpen : $createdAt->addDays(30)->toDateString(),
                    'decided_at'    => in_array($newStatus, ['accepted', 'refused'], true) ? ($quote->updated_at ?? $quote->created_at) : null,
                ]);
            });
        }
    }

    public function down(): void
    {
        // Volta aos estados antigos a partir de legacy_status (as linhas e os números ficam; são aditivos).
        DB::table('quotes')->whereNotNull('legacy_status')->update(['status' => DB::raw('legacy_status')]);
    }
};
