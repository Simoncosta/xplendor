<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Site do consultor: o perfil do site público de um consultor imobiliário (um por empresa), só
 * com o que é exclusivo do site. O que a empresa já tem vem da empresa: o nome (nome comercial),
 * o WhatsApp (telemóvel), as redes, o Pixel da Meta e, por omissão, o responsável pelo tratamento
 * dos dados (nome fiscal, NIPC, morada, email). Nada pessoal no código do site. O consultor é
 * reconhecido pelo host do pedido (consultant_site_hosts).
 */
class ConsultantSite extends Model
{
    public const SECTIONS = [
        // Página inicial (cada bloco escondido sem conteúdo real).
        'featured_videos' => 'Início: os meus vídeos (Shorts em destaque)',
        'featured_properties' => 'Início: imóveis em destaque',
        'developments' => 'Início: empreendimentos em construção',
        'market_number' => 'Início: o mercado num número (INE)',
        'process' => 'Início: como vendo a sua casa',
        'about' => 'Início: quem sou',
        'contact' => 'Início: contacto',
        // Página Vender.
        'marketing' => 'Vender: o que faço pela sua casa',
        'exclusivity' => 'Vender: exclusividade',
        'qualified_buyers' => 'Vender: compradores qualificados',
        'network' => 'Vender e Sobre: porque a rede',
        'credit' => 'Vender: crédito com o parceiro',
        'market_study' => 'Vender: estudo de mercado da sua zona',
        'faq' => 'Vender: perguntas frequentes',
        // Sobre e mais tarde.
        'intro_video' => 'Sobre: vídeo de apresentação',
        'blog' => 'Blog e guias',
        'testimonials' => 'Testemunhos',
    ];

    /** Por omissão: ligadas as secções de texto; desligadas as que dependem de conteúdo real. */
    public const DEFAULT_SECTIONS = [
        'featured_videos' => true, 'market_number' => true, 'about' => true,
        'intro_video' => false, 'marketing' => true, 'process' => true, 'exclusivity' => true, 'qualified_buyers' => true,
        'featured_properties' => true, 'developments' => true, 'market_study' => true, 'network' => true, 'credit' => false,
        'blog' => true, 'faq' => true, 'contact' => true, 'testimonials' => false,
    ];

    /** O creme e o cinzento azulado do texto secundário escolhidos para o contraste AA (14,8:1 e 6,3:1). */
    public const DEFAULT_COLORS = ['navy' => '#0E1E37', 'blue' => '#1D3856', 'amber' => '#FFA000', 'cream' => '#F7F1E7', 'slate' => '#4A5A6E'];

    /** Rascunhos por omissão das mensagens do WhatsApp (o consultor muda-as no perfil). */
    public const DEFAULT_WHATSAPP_MESSAGES = [
        'general' => [
            'popup' => 'Está a pensar vender? Diga-me só a zona da sua casa e respondo com o que o mercado está a mostrar. Sem compromisso.',
            'prefill' => 'Olá {nome}, tenho uma casa em ___ e gostava de perceber como está o mercado.',
        ],
        'property' => [
            'popup' => 'Há pormenores que as fotografias não mostram. Pergunte-me o que quiser sobre este imóvel.',
            'prefill' => 'Olá {nome}, gostava de saber mais sobre o imóvel com a referência {referencia}.',
        ],
        'development' => [
            'popup' => 'Quer saber que frações ainda estão disponíveis e como funciona o plano de pagamentos? Pergunte-me.',
            'prefill' => 'Olá {nome}, gostava de saber mais sobre o empreendimento {empreendimento}.',
        ],
        'buy' => [
            'popup' => 'Diga-me o que procura e aviso-o quando entrar algo assim.',
            'prefill' => 'Olá {nome}, procuro um imóvel com estas características: ___',
        ],
    ];

    /** As redes da empresa (companies) que o site mostra. */
    public const SOCIALS = ['instagram', 'facebook', 'linkedin', 'youtube', 'tiktok'];

    protected $fillable = ['company_id', 'hero_promise', 'bio_short', 'intro_youtube_id', 'intro_video_enabled', 'brokerage_legal_name',
        'brokerage_ami', 'network_label', 'network_text', 'legal_links', 'controller_is_company', 'controller_legal_name', 'controller_nif', 'controller_address',
        'controller_email', 'privacy_policy_version', 'credit_partner', 'credit_partner_enabled', 'whatsapp_messages', 'ga4_id', 'colors', 'sections', 'show_made_with', 'featured_videos'];

    protected $casts = ['legal_links' => 'array', 'credit_partner' => 'array', 'whatsapp_messages' => 'array', 'colors' => 'array',
        'sections' => 'array', 'featured_videos' => 'array', 'zone_photos' => 'array', 'intro_video_enabled' => 'boolean', 'credit_partner_enabled' => 'boolean', 'show_made_with' => 'boolean',
        'controller_is_company' => 'boolean', 'published_at' => 'datetime']; // controller_is_company: nulo até ser escolhido

    /** Os vídeos em destaque da página inicial (até 12 IDs do YouTube, por ordem). */
    public const MAX_FEATURED_VIDEOS = 12;

    /** As fotografias das zonas (até 24). */
    public const MAX_ZONE_PHOTOS = 24;

    /** @return list<string> Os IDs dos vídeos em destaque, por ordem. */
    public function featuredVideoIds(): array
    {
        return array_values(array_filter((array) $this->featured_videos, fn ($v) => is_string($v) && preg_match('/^[A-Za-z0-9_-]{11}$/', $v)));
    }

    /** @return list<array{id: string, path: string, path_960: ?string, width: ?int, height: ?int, alt: ?string, parish_id: ?int}> */
    public function zonePhotos(): array
    {
        return array_values(array_filter((array) $this->zone_photos, fn ($p) => is_array($p) && ! empty($p['id']) && ! empty($p['path'])));
    }

    /** O nome público: o nome comercial da empresa do consultor (ou o fiscal). */
    public function publicName(): string
    {
        $c = $this->company;

        return (string) ($c?->trade_name ?: $c?->fiscal_name ?: '');
    }

    /** O número do WhatsApp: o telemóvel da empresa. */
    public function whatsappNumber(): ?string
    {
        $n = preg_replace('/[^0-9+]/', '', (string) $this->company?->mobile) ?? '';

        return $n !== '' ? $n : null;
    }

    /** @return array<string, string> as redes da empresa, só as preenchidas */
    public function socials(): array
    {
        $c = $this->company;
        $out = [];
        foreach (self::SOCIALS as $k) {
            $v = trim((string) ($c?->{$k} ?? ''));
            if ($k === 'instagram' && preg_match('/^@?([A-Za-z0-9._]{1,30})$/', $v, $m)) {
                $v = "https://www.instagram.com/{$m[1]}"; // a empresa guarda o @ do Instagram
            }
            if ($v !== '' && preg_match('#^https://#i', $v)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * O responsável pelo tratamento dos dados: sem valor até ser escolhido (tudo vazio); a própria
     * empresa (nome fiscal, NIPC, morada com o código postal, email) ou outra entidade (os campos
     * do perfil do site).
     *
     * @return array{legal_name: ?string, nif: ?string, address: ?string, email: ?string, is_company: ?bool}
     */
    public function dataController(): array
    {
        if ($this->controller_is_company === null) {
            return ['legal_name' => null, 'nif' => null, 'address' => null, 'email' => null, 'is_company' => null];
        }
        if ($this->controller_is_company) {
            $c = $this->company;
            $address = trim(implode(', ', array_filter([trim((string) $c?->address), trim((string) $c?->postal_code)])));

            return ['legal_name' => $c?->fiscal_name ?: null, 'nif' => $c?->nipc ?: null, 'address' => $address !== '' ? $address : null,
                'email' => $c?->email ?: null, 'is_company' => true];
        }

        return ['legal_name' => $this->controller_legal_name, 'nif' => $this->controller_nif, 'address' => $this->controller_address,
            'email' => $this->controller_email, 'is_company' => false];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function hosts(): HasMany
    {
        return $this->hasMany(ConsultantSiteHost::class)->orderByDesc('is_primary')->orderBy('host');
    }

    public function parishes(): BelongsToMany
    {
        return $this->belongsToMany(Parish::class, 'consultant_site_parishes');
    }

    public function primaryHost(): ?string
    {
        return $this->hosts->firstWhere('is_primary', true)?->host ?? $this->hosts->first()?->host;
    }

    /** @return array<string, bool> */
    public function sectionFlags(): array
    {
        return array_merge(self::DEFAULT_SECTIONS, array_intersect_key((array) $this->sections, self::DEFAULT_SECTIONS));
    }

    /** @return array<string, string> */
    public function colorTokens(): array
    {
        return array_merge(self::DEFAULT_COLORS, array_intersect_key((array) $this->colors, self::DEFAULT_COLORS));
    }

    /** @return array<string, array{popup: string, prefill: string}> */
    public function whatsappTexts(): array
    {
        $out = self::DEFAULT_WHATSAPP_MESSAGES;
        foreach ((array) $this->whatsapp_messages as $ctx => $m) {
            if (isset($out[$ctx]) && is_array($m)) {
                $out[$ctx] = array_merge($out[$ctx], array_filter(array_intersect_key($m, $out[$ctx]), fn ($v) => is_string($v) && trim($v) !== ''));
            }
        }

        return $out;
    }
}
