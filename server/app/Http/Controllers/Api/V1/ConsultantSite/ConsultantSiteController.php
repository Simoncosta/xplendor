<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\ConsultantSite;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ConsultantSite;
use App\Models\Parish;
use App\Services\ConsultantSite\ConsultantSiteAssets;
use App\Services\ConsultantSite\PublishRules;
use App\Services\ConsultantSite\SitePreview;
use App\Support\ConsultantSite\YoutubeId;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Site do consultor: o perfil do site (área site_consultor, módulo site_consultor), só com o que
 * é exclusivo do site: os textos, a mediação da rede, as ligações legais, o responsável pelo
 * tratamento quando não é a própria empresa, o parceiro de crédito, as mensagens do WhatsApp, as
 * freguesias de atuação, a etiqueta do GA4, as cores e as secções; os ficheiros (assinatura SVG,
 * fotografia recortada, vídeo e capa do topo, logótipos) no disco público, registados nos
 * Ficheiros (pasta "Site do consultor"). O nome, o telemóvel (WhatsApp), as redes, o Pixel e os
 * dados fiscais vêm da empresa (Empresa › Dados; aqui só se mostram). Os hosts são do root
 * (Admin\ConsultantSiteHostsController). Publicar o site é uma decisão do cliente (aprovar) e
 * exige a mediação, o responsável pelo tratamento e as ligações legais preenchidos.
 */
class ConsultantSiteController extends Controller
{
    /** Os ficheiros do perfil (ConsultantSiteAssets::SLOTS). */
    public const SLOTS = ['signature', 'photo', 'about_photo', 'hero_video_mp4', 'hero_video_webm', 'hero_poster', 'network_logo', 'credit_partner_logo'];

    public function show(int $companyId)
    {
        return ApiResponse::success($this->present($this->site($companyId)), 'Perfil do site.');
    }

    public function update(Request $request, int $companyId)
    {
        $site = $this->site($companyId);
        $hex = 'regex:/^#[0-9A-Fa-f]{6}$/';
        $data = $request->validate([
            'hero_promise' => ['nullable', 'string', 'max:160'],
            'bio_short' => ['nullable', 'string', 'max:1200'],
            'intro_youtube' => ['nullable', 'string', 'max:255'],
            'intro_video_enabled' => ['boolean'],
            'featured_videos' => ['nullable', 'array', 'max:' . ConsultantSite::MAX_FEATURED_VIDEOS],
            'featured_videos.*' => ['nullable', 'string', 'max:255'],
            'brokerage_legal_name' => ['nullable', 'string', 'max:160'],
            'brokerage_ami' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]{1,8}$/'],
            'network_label' => ['nullable', 'string', 'max:80'],
            'network_text' => ['nullable', 'string', 'max:2000'],
            'legal_links' => ['nullable', 'array'],
            'legal_links.complaints_book_url' => ['nullable', 'url:https', 'max:255'],
            'legal_links.ral' => ['nullable', 'array', 'max:5'],
            'legal_links.ral.*.name' => ['required', 'string', 'max:160'],
            'legal_links.ral.*.url' => ['required', 'url:https', 'max:255'],
            'legal_links.legal_info_text' => ['nullable', 'string', 'max:4000'],
            'controller_is_company' => ['nullable', 'boolean'], // nulo até ser escolhido
            'controller_legal_name' => ['nullable', 'string', 'max:160'],
            'controller_nif' => ['nullable', 'string', 'regex:/^[0-9]{9}$/'],
            'controller_address' => ['nullable', 'string', 'max:255'],
            'controller_email' => ['nullable', 'email:rfc', 'max:160'],
            'privacy_policy_version' => ['nullable', 'string', 'max:20'],
            'credit_partner' => ['nullable', 'array'],
            'credit_partner.legal_name' => ['nullable', 'string', 'max:160'],
            'credit_partner.bp_registration' => ['nullable', 'string', 'max:40'],
            'credit_partner.text' => ['nullable', 'string', 'max:1200'],
            'credit_partner_enabled' => ['boolean'],
            'whatsapp_messages' => ['nullable', 'array'],
            'whatsapp_messages.*.popup' => ['nullable', 'string', 'max:280'],
            'whatsapp_messages.*.prefill' => ['nullable', 'string', 'max:280'],
            'parish_ids' => ['nullable', 'array', 'max:60'],
            'parish_ids.*' => ['integer', 'exists:parishes,id'],
            'ga4_id' => ['nullable', 'string', 'regex:/^G-[A-Z0-9]{4,16}$/'],
            'colors' => ['nullable', 'array'],
            'colors.*' => ['nullable', 'string', $hex],
            'sections' => ['nullable', 'array'],
            'sections.*' => ['boolean'],
            'show_made_with' => ['boolean'],
        ], [
            'brokerage_ami.regex' => 'A licença AMI só tem números.',
            'controller_nif.regex' => 'O NIF tem 9 algarismos.',
            'ga4_id.regex' => 'O ID do GA4 começa por "G-".',
            'colors.*.regex' => 'As cores são códigos #RRGGBB.',
            'featured_videos.max' => 'Até ' . ConsultantSite::MAX_FEATURED_VIDEOS . ' vídeos em destaque.',
        ]);
        foreach (array_keys((array) ($data['whatsapp_messages'] ?? [])) as $ctx) {
            if (! isset(ConsultantSite::DEFAULT_WHATSAPP_MESSAGES[$ctx])) {
                throw ValidationException::withMessages(['whatsapp_messages' => 'Contexto de mensagem desconhecido.']);
            }
        }
        $raw = trim((string) ($data['intro_youtube'] ?? ''));
        $data['intro_youtube_id'] = $raw === '' ? null : (YoutubeId::parse($raw) ?? throw ValidationException::withMessages(['intro_youtube' => 'Cole o link de um vídeo do YouTube.']));
        unset($data['intro_youtube']);
        if (($data['intro_video_enabled'] ?? false) && ! $data['intro_youtube_id']) {
            throw ValidationException::withMessages(['intro_video_enabled' => 'Para mostrar o vídeo de apresentação, indique o vídeo.']);
        }
        if (array_key_exists('featured_videos', $data)) {
            // Links ou IDs do YouTube, por ordem; guarda-se só o ID, sem repetidos.
            $ids = [];
            foreach (array_values((array) $data['featured_videos']) as $i => $raw) {
                if (trim((string) $raw) === '') {
                    continue;
                }
                $ids[] = YoutubeId::parse((string) $raw) ?? throw ValidationException::withMessages(["featured_videos.{$i}" => 'Cole o link de um vídeo do YouTube (ou o ID).']);
            }
            $data['featured_videos'] = array_values(array_unique($ids)) ?: null;
        }
        $data['colors'] = array_intersect_key((array) ($data['colors'] ?? []), ConsultantSite::DEFAULT_COLORS);
        $data['sections'] = array_intersect_key((array) ($data['sections'] ?? []), ConsultantSite::DEFAULT_SECTIONS);
        $parishIds = $data['parish_ids'] ?? null;
        unset($data['parish_ids']);

        $site->update($data);
        if ($parishIds !== null) {
            $site->parishes()->sync($parishIds);
        }
        // Publicado e deixou de ter o obrigatório (por exemplo, apagou o responsável): sai do ar.
        if ($site->site_status === 'publicado' && PublishRules::site($site) !== []) {
            $site->forceFill(['site_status' => 'rascunho'])->save();
        }

        return ApiResponse::success($this->present($site->fresh()), 'Perfil do site guardado.');
    }

    public function uploadAsset(Request $request, int $companyId, string $slot)
    {
        $site = $this->site($companyId);
        $request->validate(['file' => ['required', 'file', 'max:10240']], ['file.max' => 'O ficheiro pode ter até 10 MB.']);
        $file = $request->file('file');
        app(ConsultantSiteAssets::class)->store($site, $slot, (string) $file->getRealPath(), (string) $file->getClientOriginalName());

        return ApiResponse::success($this->present($site->fresh()), 'Ficheiro guardado.');
    }

    public function deleteAsset(int $companyId, string $slot)
    {
        $site = $this->site($companyId);
        app(ConsultantSiteAssets::class)->delete($site, $slot);

        return ApiResponse::success($this->present($site->fresh()), 'Ficheiro retirado.');
    }

    /** Uma fotografia de uma zona de atuação (fundo ou separador do site), sem EXIF nem GPS. */
    public function uploadZonePhoto(Request $request, int $companyId)
    {
        $site = $this->site($companyId);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:15360'],
            'alt' => ['nullable', 'string', 'max:160'],
            'parish_id' => ['nullable', 'integer', 'exists:parishes,id'],
        ], ['file.max' => 'O ficheiro pode ter até 15 MB.']);
        $file = $request->file('file');
        app(ConsultantSiteAssets::class)->storeZonePhoto($site, (string) $file->getRealPath(), (string) $file->getClientOriginalName(),
            $data['alt'] ?? null, isset($data['parish_id']) ? (int) $data['parish_id'] : null);

        return ApiResponse::success($this->present($site->fresh()), 'Fotografia guardada.');
    }

    public function deleteZonePhoto(int $companyId, string $photo)
    {
        $site = $this->site($companyId);
        if (! app(ConsultantSiteAssets::class)->deleteZonePhoto($site, $photo)) {
            return ApiResponse::error('Fotografia não encontrada.', 404);
        }

        return ApiResponse::success($this->present($site->fresh()), 'Fotografia retirada.');
    }

    public function publish(int $companyId)
    {
        $site = $this->site($companyId);
        $missing = PublishRules::site($site);
        if ($missing !== []) {
            return ApiResponse::error('Ainda não pode publicar o site.', 422, ['publish' => $missing]);
        }
        $site->forceFill(['site_status' => 'publicado', 'published_at' => $site->published_at ?? now()])->save();

        return ApiResponse::success($this->present($site->fresh()), 'Site publicado.');
    }

    /** Pré-visualizar o site (também em rascunho): um link assinado e temporário (1 hora). */
    public function preview(int $companyId)
    {
        $site = $this->site($companyId);
        if (! $site->hosts()->exists()) {
            return ApiResponse::error('Falta o endereço do site (a equipa XPLENDOR configura-o).', 422);
        }

        return ApiResponse::success(SitePreview::issue($site->load('hosts')), 'Link de pré-visualização.');
    }

    public function unpublish(int $companyId)
    {
        $site = $this->site($companyId);
        $site->forceFill(['site_status' => 'rascunho'])->save();

        return ApiResponse::success($this->present($site->fresh()), 'Site retirado do ar.');
    }

    /** O perfil da empresa; nasce na primeira visita com os valores por omissão. */
    private function site(int $companyId): ConsultantSite
    {
        return ConsultantSite::with('company')->firstOrCreate(['company_id' => $companyId], ['network_label' => 'Consultor Chave Nova']);
    }

    private function present(ConsultantSite $s): array
    {
        $s->loadMissing('hosts', 'parishes.municipality');

        $s->loadMissing('company');
        $c = $s->company;

        return [
            // Da empresa (Empresa › Dados): só para ver aqui.
            'company' => [
                'public_name' => $s->publicName(), 'mobile' => $c?->mobile, 'whatsapp_number' => $s->whatsappNumber(), 'socials' => $s->socials(),
                'facebook_pixel_id' => $c?->facebook_pixel_id, 'fiscal_name' => $c?->fiscal_name, 'nipc' => $c?->nipc,
                'address' => trim(implode(', ', array_filter([(string) $c?->address, (string) $c?->postal_code]))) ?: null, 'email' => $c?->email,
            ],
            'hero_promise' => $s->hero_promise, 'bio_short' => $s->bio_short,
            'intro_youtube_id' => $s->intro_youtube_id, 'intro_video_enabled' => (bool) $s->intro_video_enabled,
            'featured_videos' => $s->featuredVideoIds(),
            'zone_photos' => array_map(fn (array $p) => ['id' => $p['id'], 'path' => $p['path'], 'path_960' => $p['path_960'] ?? null,
                'width' => $p['width'] ?? null, 'height' => $p['height'] ?? null, 'alt' => $p['alt'] ?? null, 'parish_id' => $p['parish_id'] ?? null], $s->zonePhotos()),
            'brokerage_legal_name' => $s->brokerage_legal_name, 'brokerage_ami' => $s->brokerage_ami,
            'network_label' => $s->network_label, 'network_text' => $s->network_text,
            'legal_links' => array_merge(['complaints_book_url' => null, 'ral' => [], 'legal_info_text' => null], (array) $s->legal_links),
            'controller_is_company' => $s->controller_is_company, // nulo até ser escolhido
            'controller_legal_name' => $s->controller_legal_name, 'controller_nif' => $s->controller_nif, 'controller_address' => $s->controller_address,
            'controller_email' => $s->controller_email, 'data_controller' => $s->dataController(), 'privacy_policy_version' => $s->privacy_policy_version,
            'credit_partner' => array_merge(['legal_name' => null, 'bp_registration' => null, 'text' => null], (array) $s->credit_partner),
            'credit_partner_enabled' => (bool) $s->credit_partner_enabled,
            'whatsapp_messages' => $s->whatsappTexts(),
            'parishes' => $s->parishes->map(fn (Parish $p) => ['id' => $p->id, 'name' => $p->name, 'municipality' => $p->municipality?->name])->values()->all(),
            'ga4_id' => $s->ga4_id,
            'colors' => $s->colorTokens(), 'sections' => $s->sectionFlags(), 'show_made_with' => (bool) $s->show_made_with,
            'assets' => collect(ConsultantSiteAssets::SLOTS)->map(fn ($a) => $s->{$a[0]})->all(),
            'share_image' => $s->share_image_path,
            'hosts' => $s->hosts->map(fn ($h) => ['host' => $h->host, 'is_primary' => $h->is_primary])->values()->all(),
            'site_status' => $s->site_status, 'published_at' => $s->published_at?->toIso8601String(),
            'publish_missing' => PublishRules::site($s),
            'options' => ['sections' => ConsultantSite::SECTIONS, 'default_colors' => ConsultantSite::DEFAULT_COLORS,
                'default_whatsapp_messages' => ConsultantSite::DEFAULT_WHATSAPP_MESSAGES],
        ];
    }
}
