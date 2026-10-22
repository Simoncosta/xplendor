<?php

declare(strict_types=1);

namespace App\Services\Financing;

use App\Models\Company;
use App\Models\FinancingRequest;

/**
 * Os documentos que se podem pedir e os tipos de ficheiro aceites, confirmados pelo CONTEÚDO
 * (os primeiros bytes), nunca pela extensão nem pelo tipo que o browser diz.
 *
 * Financiamento: a lista por omissão é a da Quebom (DEFAULTS); a nota de liquidação e o mapa do
 * Banco de Portugal continuam disponíveis para marcar; "cc" e "irs" são os nomes antigos, só para
 * os pedidos que já existiam. Retoma: o DUA (imagem ou PDF) e as fotografias (só imagens).
 */
final class FinancingDocumentTypes
{
    private const DOCS = ['pdf', 'jpg', 'png', 'heic'];
    private const IMAGES = ['jpg', 'png', 'heic'];

    /**
     * chave => rótulo, ajuda para o cliente, propósito, máximo de ficheiros, tipos aceites,
     * obrigatório (quando pedido) e se é um nome antigo (não se oferece em pedidos novos).
     * Em "irs_declaracao", {ano} é o ano escolhido no pedido.
     */
    public const ITEMS = [
        'identificacao' => ['label' => 'Documento de identificação', 'hint' => 'Frente e verso.', 'purpose' => 'financiamento', 'max' => 4, 'types' => self::DOCS, 'required' => true],
        'iban' => ['label' => 'Comprovativo de IBAN', 'hint' => 'Atualizado, com o nome do titular.', 'purpose' => 'financiamento', 'max' => 3, 'types' => self::DOCS, 'required' => true],
        'extratos' => ['label' => 'Extratos bancários', 'hint' => 'Dos últimos três meses, de todas as contas de que é titular.', 'purpose' => 'financiamento', 'max' => 12, 'types' => self::DOCS, 'required' => true],
        'morada' => ['label' => 'Comprovativo de morada', 'hint' => 'Pode obtê-lo no Portal das Finanças ou enviar uma fatura recente de água ou eletricidade.', 'purpose' => 'financiamento', 'max' => 3, 'types' => self::DOCS, 'required' => true],
        'recibos' => ['label' => 'Recibos de vencimento', 'hint' => 'Os três últimos.', 'purpose' => 'financiamento', 'max' => 6, 'types' => self::DOCS, 'required' => true],
        'irs_declaracao' => ['label' => 'Declaração de IRS de {ano}', 'hint' => 'A declaração completa, referente aos rendimentos de {ano}.', 'purpose' => 'financiamento', 'max' => 6, 'types' => self::DOCS, 'required' => true],
        'contrato' => ['label' => 'Contrato de trabalho', 'hint' => 'O contrato atual.', 'purpose' => 'financiamento', 'max' => 6, 'types' => self::DOCS, 'required' => true],
        'irs_nota' => ['label' => 'Nota de liquidação do IRS', 'hint' => 'Do mesmo ano da declaração.', 'purpose' => 'financiamento', 'max' => 3, 'types' => self::DOCS, 'required' => true],
        'mapa_bp' => ['label' => 'Mapa de responsabilidades do Banco de Portugal', 'hint' => 'Pode pedi-lo no Portal do Cliente Bancário.', 'purpose' => 'financiamento', 'max' => 3, 'types' => self::DOCS, 'required' => true],
        'outro' => ['label' => 'Outro documento', 'hint' => '', 'purpose' => 'financiamento', 'max' => 6, 'types' => self::DOCS, 'required' => true],
        // Nomes antigos (pedidos criados antes da lista da Quebom).
        'cc' => ['label' => 'Cartão de Cidadão', 'hint' => 'Frente e verso.', 'purpose' => 'financiamento', 'max' => 6, 'types' => self::DOCS, 'required' => true, 'legacy' => true],
        'irs' => ['label' => 'Declaração de IRS e nota de liquidação', 'hint' => 'Do último ano.', 'purpose' => 'financiamento', 'max' => 6, 'types' => self::DOCS, 'required' => true, 'legacy' => true],
        // Retoma.
        'dua' => ['label' => 'DUA (frente e verso)', 'hint' => 'Imagem ou PDF.', 'purpose' => 'retoma', 'max' => 4, 'types' => self::DOCS, 'required' => true],
        'exterior' => ['label' => 'Fotografias do exterior', 'hint' => 'Frente, traseira, laterais e pormenores.', 'purpose' => 'retoma', 'max' => 12, 'types' => self::IMAGES, 'required' => true],
        'interior' => ['label' => 'Fotografias do interior', 'hint' => 'Bancos, tablier e bagageira.', 'purpose' => 'retoma', 'max' => 8, 'types' => self::IMAGES, 'required' => true],
        'odometro' => ['label' => 'Conta-quilómetros', 'hint' => 'Opcional: uma fotografia com os quilómetros visíveis.', 'purpose' => 'retoma', 'max' => 2, 'types' => self::IMAGES, 'required' => false],
    ];

    /** A lista por omissão da plataforma (a da Quebom). */
    public const DEFAULTS = ['identificacao', 'iban', 'extratos', 'morada', 'recibos', 'irs_declaracao', 'contrato'];

    /** Os itens da retoma, pela ordem. */
    public const TRADE_IN = ['dua', 'exterior', 'interior', 'odometro'];

    /** Itens com dados pessoais do proprietário: seguem a área "Financiamento" (não a lead). */
    public const PERSONAL = ['dua'];

    /** O documento de identificação (pede o consentimento para a cópia). */
    public const ID_DOCUMENTS = ['identificacao', 'cc'];

    /** extensão detetada => tipo MIME com que se guarda */
    public const ACCEPTED = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'heic' => 'image/heic', 'heif' => 'image/heif'];

    private const HEIC_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs'];
    private const HEIF_BRANDS = ['mif1', 'msf1', 'heif'];

    /** Os itens que se oferecem num pedido novo do propósito dado. @return string[] */
    public static function offered(string $purpose): array
    {
        return array_keys(array_filter(self::ITEMS, fn ($i) => $i['purpose'] === $purpose && empty($i['legacy'])));
    }

    /** A lista por omissão da empresa (ou a da plataforma). @return string[] */
    public static function defaultsFor(?Company $company): array
    {
        $own = array_values(array_intersect(self::offered('financiamento'), (array) ($company?->financing_default_items ?? [])));

        return $own !== [] ? $own : self::DEFAULTS;
    }

    public static function defaultIrsYear(): int
    {
        return (int) now('Europe/Lisbon')->format('Y') - 1;
    }

    public static function label(string $item, ?FinancingRequest $request = null): string
    {
        if ($item === 'outro' && $request?->other_description) {
            return $request->other_description;
        }

        return self::year((string) (self::ITEMS[$item]['label'] ?? $item), $request);
    }

    public static function hint(string $item, ?FinancingRequest $request = null): string
    {
        return self::year((string) (self::ITEMS[$item]['hint'] ?? ''), $request);
    }

    public static function maxFiles(string $item): int
    {
        return (int) (self::ITEMS[$item]['max'] ?? config('financing.max_files_per_item'));
    }

    /** @return string[] */
    public static function types(string $item): array
    {
        return self::ITEMS[$item]['types'] ?? self::DOCS;
    }

    public static function required(string $item): bool
    {
        return (bool) (self::ITEMS[$item]['required'] ?? true);
    }

    /** O tipo detetado ("heif" conta como "heic") é aceite neste item? */
    public static function accepts(string $item, string $ext): bool
    {
        return in_array($ext === 'heif' ? 'heic' : $ext, self::types($item), true);
    }

    private static function year(string $text, ?FinancingRequest $request): string
    {
        return str_replace('{ano}', (string) ($request?->irs_year ?: self::defaultIrsYear()), $text);
    }

    /** O tipo pelo conteúdo: [extensão, mime] ou null (recusado). @return array{0: string, 1: string}|null */
    public static function detect(string $path): ?array
    {
        $fh = @fopen($path, 'rb');
        if (! $fh) {
            return null;
        }
        $head = (string) fread($fh, 1024);
        fclose($fh);
        if ($head === '' || \App\Services\Files\FileTypeGuard::looksExecutable($head) || str_contains(strtolower($head), '<html') || str_contains(strtolower($head), '<script')) {
            return null; // programas, scripts e HTML disfarçados, mesmo com um %PDF- lá dentro
        }

        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => ['jpg', self::ACCEPTED['jpg']],
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => ['png', self::ACCEPTED['png']],
            // Alguns PDF trazem bytes antes do cabeçalho; aceita-se o %PDF- no primeiro KB.
            str_contains($head, '%PDF-') => ['pdf', self::ACCEPTED['pdf']],
            substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), self::HEIC_BRANDS, true) => ['heic', self::ACCEPTED['heic']],
            substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), self::HEIF_BRANDS, true) => ['heif', self::ACCEPTED['heif']],
            default => null,
        };
    }
}
