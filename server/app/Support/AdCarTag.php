<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MetaAd;

/**
 * XPLENDOR — Tag [id:N] no nome de um anúncio Meta (funções PURAS).
 *
 * N é o cars.id da XPLENDOR. Aceita [id:89], [id: 89], [ID:89], [id:89,113],
 * [id: 89 , 113] e várias tags no mesmo nome (os IDs juntam-se, sem repetir).
 *
 *   parse()   → lê o nome (sem base de dados);
 *   resolve() → cruza os IDs lidos com os IDs válidos da empresa e devolve o
 *               tag_status: untagged | matched | split | invalid.
 *
 * Regras (decisões do utilizador):
 *   · sem tag → untagged (vai para o stock geral, sem aviso);
 *   · 1 viatura válida → matched; várias → split (partes iguais);
 *   · IDs inválidos (inexistentes ou de outra empresa) NUNCA são atribuídos e geram
 *     aviso; se houver válidos e inválidos, reparte-se só pelos válidos;
 *   · tag sem nenhuma viatura válida → invalid (aviso; nunca cai em silêncio no
 *     stock geral).
 */
final class AdCarTag
{
    /** Uma tag: "[" id ":" conteúdo "]" (maiúsculas/minúsculas, espaços tolerados). */
    private const TAG_REGEX = '/\[\s*id\s*:([^\[\]]*)\]/i';

    /** IDs acima disto não são cars.id plausíveis (e não cabem num inteiro). */
    private const MAX_ID_DIGITS = 18;

    /**
     * @return array{has_tag: bool, ids: list<int>, invalid_tokens: list<string>}
     *   ids            → IDs numéricos lidos (por ordem, sem repetir);
     *   invalid_tokens → pedaços da tag que não são um ID (ex.: "abc", "0").
     */
    public static function parse(?string $adName): array
    {
        $result = ['has_tag' => false, 'ids' => [], 'invalid_tokens' => []];

        if ($adName === null || $adName === '') {
            return $result;
        }

        if (! preg_match_all(self::TAG_REGEX, $adName, $matches)) {
            return $result;
        }

        $result['has_tag'] = true;

        foreach ($matches[1] as $content) {
            foreach (explode(',', $content) as $token) {
                $token = trim($token);
                if ($token === '') {
                    continue;
                }

                if (preg_match('/^\d+$/', $token) && strlen(ltrim($token, '0')) <= self::MAX_ID_DIGITS && (int) $token > 0) {
                    $id = (int) $token;
                    if (! in_array($id, $result['ids'], true)) {
                        $result['ids'][] = $id;
                    }
                } elseif (! in_array($token, $result['invalid_tokens'], true)) {
                    $result['invalid_tokens'][] = $token;
                }
            }
        }

        return $result;
    }

    /**
     * @param  array{has_tag: bool, ids: list<int>, invalid_tokens: list<string>}  $parsed
     * @param  list<int>  $validIds    IDs que existem na empresa (Car::where('company_id', X)->whereKey(N)).
     * @param  list<int>  $removedIds  IDs de viaturas DESTA empresa já apagadas mas com
     *                                 histórico atribuído (continuam a receber o gasto,
     *                                 como "viatura removida", em vez de virar aviso).
     * @return array{status: string, car_ids: list<int>, removed_ids: list<int>, invalid_ids: list<string>}
     *   car_ids     → a quem se atribui (válidas + removidas), pela ordem da tag;
     *   invalid_ids → o que gera aviso (IDs inexistentes/de outra empresa + pedaços inválidos).
     */
    public static function resolve(array $parsed, array $validIds, array $removedIds = []): array
    {
        if (! $parsed['has_tag']) {
            return ['status' => MetaAd::TAG_UNTAGGED, 'car_ids' => [], 'removed_ids' => [], 'invalid_ids' => []];
        }

        $valid = array_map('intval', $validIds);
        $removed = array_map('intval', $removedIds);

        $carIds = [];
        $removedHit = [];
        $invalid = [];

        foreach ($parsed['ids'] as $id) {
            if (in_array($id, $valid, true)) {
                $carIds[] = $id;
            } elseif (in_array($id, $removed, true)) {
                $carIds[] = $id;
                $removedHit[] = $id;
            } else {
                $invalid[] = (string) $id;
            }
        }

        foreach ($parsed['invalid_tokens'] as $token) {
            $invalid[] = $token;
        }

        $status = match (count($carIds)) {
            0 => MetaAd::TAG_INVALID,
            1 => MetaAd::TAG_MATCHED,
            default => MetaAd::TAG_SPLIT,
        };

        return ['status' => $status, 'car_ids' => $carIds, 'removed_ids' => $removedHit, 'invalid_ids' => $invalid];
    }

    /**
     * Partes iguais de um valor por N viaturas, a somar EXACTAMENTE o total (o resto
     * do arredondamento vai para a última parte).
     *
     * @return list<float>
     */
    public static function splitEvenly(float $total, int $parts, int $decimals = 4): array
    {
        if ($parts <= 0) {
            return [];
        }

        $each = round($total / $parts, $decimals);
        $shares = array_fill(0, $parts, $each);
        $shares[$parts - 1] = round($total - $each * ($parts - 1), $decimals);

        return $shares;
    }
}
