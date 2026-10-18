<?php

declare(strict_types=1);

namespace App\Services\ConsultantSite;

use App\Models\ConsultantSite;
use App\Models\Development;
use App\Models\ListingImage;
use App\Models\Property;

/**
 * Site do consultor: o que falta para publicar, em pt-PT (lista vazia = pode publicar).
 *  · Imóvel: a classe energética é obrigatória em qualquer anúncio (Decreto-Lei 101-D/2020,
 *    artigo 18.º); "em processo" não chega. Também a freguesia, o preço (ou "sob consulta") e
 *    pelo menos uma fotografia.
 *  · Empreendimento: a freguesia, a localização, a classe energética prevista e uma fotografia.
 *  · Site: o nome (da empresa), a mediação (nome legal e AMI), o responsável pelo tratamento dos
 *    dados (escolhido: a empresa, ou outra entidade com nome legal, NIF, morada, email para os pedidos RGPD),
 *    a versão da política, o livro de reclamações, a resolução alternativa de litígios e um host.
 */
final class PublishRules
{
    /** @return string[] */
    public static function property(Property $p): array
    {
        return array_column(self::propertyChecklist($p), 'message');
    }

    /**
     * O que falta, item a item: o campo (para a app levar lá), o nome curto ("Faltam: classe
     * energética, fotografia") e a frase completa.
     *
     * @return list<array{field: string, label: string, message: string}>
     */
    public static function propertyChecklist(Property $p): array
    {
        $missing = [];
        if ($p->energyClassMissing()) {
            $missing[] = ['field' => 'energy_class', 'label' => 'classe energética', 'message' => 'Indique a classe energética: é obrigatória em qualquer anúncio ("em processo" não chega).'];
        }
        if (! $p->parish_id) {
            $missing[] = ['field' => 'parish_id', 'label' => 'freguesia', 'message' => 'Indique a freguesia.'];
        }
        if (! $p->price_on_request && (int) $p->price <= 0) {
            $missing[] = ['field' => 'price', 'label' => 'preço', 'message' => 'Indique o preço ou marque "preço sob consulta".'];
        }
        if (! ListingImage::where('owner_type', ListingImage::OWNER_PROPERTY)->where('owner_id', $p->id)->exists()) {
            $missing[] = ['field' => 'photos', 'label' => 'fotografia', 'message' => 'Carregue pelo menos uma fotografia.'];
        }
        if ($p->status === 'fechado' && ! $p->show_as_sold) {
            $missing[] = ['field' => 'show_as_sold', 'label' => 'autorização para mostrar como vendido',
                'message' => 'O imóvel está fechado: só aparece no site com a autorização do proprietário ("mostrar como vendido").'];
        }

        return $missing;
    }

    /** @return string[] */
    public static function development(Development $d): array
    {
        return array_column(self::developmentChecklist($d), 'message');
    }

    /** @return list<array{field: string, label: string, message: string}> */
    public static function developmentChecklist(Development $d): array
    {
        $missing = [];
        if (! $d->parish_id) {
            $missing[] = ['field' => 'parish_id', 'label' => 'freguesia', 'message' => 'Indique a freguesia.'];
        }
        if ($d->lat === null || $d->lng === null) {
            $missing[] = ['field' => 'lat', 'label' => 'localização', 'message' => 'Indique a localização do empreendimento.'];
        }
        if (! $d->energy_class_expected || $d->energy_class_expected === 'em_processo') {
            $missing[] = ['field' => 'energy_class_expected', 'label' => 'classe energética prevista', 'message' => 'Indique a classe energética prevista.'];
        }
        if (! ListingImage::where('owner_type', ListingImage::OWNER_DEVELOPMENT)->where('owner_id', $d->id)->exists()) {
            $missing[] = ['field' => 'photos', 'label' => 'fotografia', 'message' => 'Carregue pelo menos uma fotografia.'];
        }

        return $missing;
    }

    /** @return string[] */
    public static function site(ConsultantSite $s): array
    {
        $missing = [];
        $blank = fn ($v) => trim((string) $v) === '';
        if ($blank($s->publicName())) {
            $missing[] = 'Indique o nome comercial da empresa (Empresa › Dados).';
        }
        foreach (['brokerage_legal_name' => 'o nome legal da empresa de mediação', 'brokerage_ami' => 'a licença AMI',
            'privacy_policy_version' => 'a versão da política de privacidade'] as $field => $label) {
            if ($blank($s->{$field})) {
                $missing[] = "Indique {$label}.";
            }
        }
        $controller = $s->dataController();
        if ($controller['is_company'] === null) {
            $missing[] = 'Escolha o responsável pelo tratamento dos dados: a própria empresa ou outra entidade.';
        } else {
            $where = $controller['is_company'] ? ' (Empresa › Dados: nome fiscal, NIPC, morada, email)' : '';
            foreach (['legal_name' => 'o nome legal', 'nif' => 'o NIF', 'address' => 'a morada', 'email' => 'o email para os pedidos sobre dados pessoais'] as $k => $label) {
                if ($blank($controller[$k])) {
                    $missing[] = "Indique {$label} do responsável pelo tratamento dos dados{$where}.";
                }
            }
        }
        $links = (array) $s->legal_links;
        if ($blank($links['complaints_book_url'] ?? null)) {
            $missing[] = 'Indique a ligação do livro de reclamações.';
        }
        $ral = array_filter((array) ($links['ral'] ?? []), fn ($e) => is_array($e) && ! $blank($e['name'] ?? null) && ! $blank($e['url'] ?? null));
        if ($ral === []) {
            $missing[] = 'Indique pelo menos uma entidade de resolução alternativa de litígios.';
        }
        if (! $s->hosts()->exists()) {
            $missing[] = 'Falta o endereço do site (a equipa XPLENDOR configura-o).';
        }

        return $missing;
    }
}
