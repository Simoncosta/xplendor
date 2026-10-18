<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Site do consultor imobiliário (F1). Tudo aditivo; nada do que existe muda.
 *  · properties: os campos do site (colunas novas, todas opcionais; a Bússola não as usa).
 *    A localização dos usados é só o centro de um círculo (nunca a casa) e o raio.
 *  · developments: os empreendimentos em construção (as frações são imóveis ligados).
 *  · listing_images: as fotografias públicas dos imóveis e dos empreendimentos.
 *  · consultant_sites (+ hosts, freguesias de atuação): só o que é exclusivo do site. O nome, o
 *    WhatsApp (telemóvel), as redes, o Pixel e o responsável pelo tratamento vêm da empresa.
 *  · companies: linkedin e tiktok (redes que a empresa ainda não tinha).
 *  · site_server_tokens (+ sites): o token do contentor do site (só o hash), fora dos tokens das
 *    pessoas (Sanctum), para nunca ser aceite como um utilizador.
 *  · inbound_contacts: a central de contactos recebidos (hoje só o canal "site"; preparada para
 *    Standvirtual, Meta, WhatsApp), sem tocar nos car_leads. Dados pessoais, área ACL própria.
 *  · site_daily_counts: contagens diárias agregadas, sem IP, cookies nem identificador.
 *  · properties: as contagens de interesse passam a somar a parte do site (contactos e cliques
 *    no WhatsApp) e a parte manual (os outros canais); contacts_30 e visits_30 são o total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('developments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 160);
            $table->string('short_description', 280)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('parish_id')->nullable()->constrained('parishes')->nullOnDelete();
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->string('construction_phase', 20)->default('projeto'); // projeto | licenciamento | fundacoes | estrutura | acabamentos | concluido
            $table->date('expected_completion')->nullable();
            $table->text('payment_plan')->nullable();
            $table->string('youtube_id', 11)->nullable();
            $table->string('chave_nova_url', 255)->nullable();
            $table->string('chave_nova_reference', 40)->nullable();
            $table->string('energy_class_expected', 12)->nullable();
            $table->string('site_status', 12)->default('rascunho'); // rascunho | publicado
            $table->timestamp('published_at')->nullable();
            $table->boolean('featured')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'slug']);
            $table->index(['company_id', 'site_status']);
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->string('slug', 160)->nullable();
            $table->foreignId('development_id')->nullable()->constrained('developments')->nullOnDelete();
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->unsignedSmallInteger('gross_area_m2')->nullable();
            $table->string('construction_state', 16)->nullable(); // usado | novo | em_construcao
            $table->decimal('approx_lat', 9, 6)->nullable();
            $table->decimal('approx_lng', 9, 6)->nullable();
            $table->unsignedSmallInteger('approx_radius_m')->nullable();
            $table->boolean('price_on_request')->default(false);
            $table->boolean('energy_precertificate')->default(false);
            $table->string('short_description', 280)->nullable();
            $table->text('description')->nullable();
            $table->string('youtube_id', 11)->nullable();
            $table->string('chave_nova_url', 255)->nullable();
            $table->string('chave_nova_reference', 40)->nullable();
            $table->string('site_status', 12)->default('rascunho'); // rascunho | publicado
            $table->timestamp('published_at')->nullable();
            $table->boolean('show_as_sold')->default(false);
            $table->boolean('featured')->default(false);
            // As contagens de interesse somam duas partes: a do site (automática) e a manual (os
            // outros canais: o portal da rede, o telefone, as indicações). contacts_30 e visits_30
            // passam a ser o total, que a Bússola do imobiliário usa.
            $table->unsignedSmallInteger('contacts_30_manual')->default(0);
            $table->unsignedSmallInteger('visits_30_manual')->default(0);
            $table->unsignedSmallInteger('contacts_30_site')->default(0);
            $table->unsignedSmallInteger('visits_30_site')->default(0);
            $table->unique(['company_id', 'slug']);
            $table->index(['company_id', 'site_status']);
        });

        // O que já estava nas contagens foi contado à mão: passa a ser a parte manual.
        DB::table('properties')->update(['contacts_30_manual' => DB::raw('contacts_30'), 'visits_30_manual' => DB::raw('visits_30')]);

        Schema::table('companies', function (Blueprint $table) {
            $table->string('linkedin', 255)->nullable();
            $table->string('tiktok', 255)->nullable();
        });

        Schema::create('listing_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('owner_type', 12); // property | development
            $table->unsignedBigInteger('owner_id');
            $table->string('path', 255);      // 1600 px (WebP)
            $table->string('path_960', 255);
            $table->string('path_480', 255);
            $table->string('original_path', 255); // original sem metadados (sem EXIF nem GPS)
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedSmallInteger('order')->default(0);
            $table->boolean('is_cover')->default(false);
            $table->string('alt', 160)->nullable();
            $table->timestamps();
            $table->index(['owner_type', 'owner_id', 'order']);
            $table->index('company_id');
        });

        Schema::create('consultant_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->string('signature_svg_path', 255)->nullable();
            $table->string('photo_cutout_path', 255)->nullable();
            $table->string('hero_video_mp4_path', 255)->nullable();
            $table->string('hero_video_webm_path', 255)->nullable();
            $table->string('hero_poster_path', 255)->nullable();
            $table->string('hero_promise', 160)->nullable();
            $table->text('bio_short')->nullable();
            $table->string('intro_youtube_id', 11)->nullable();
            $table->boolean('intro_video_enabled')->default(false);
            $table->string('brokerage_legal_name', 160)->nullable(); // a empresa de mediação (da rede), não a do consultor
            $table->string('brokerage_ami', 20)->nullable();
            $table->string('network_label', 80)->nullable();
            $table->text('network_text')->nullable();
            $table->string('network_logo_path', 255)->nullable();
            $table->json('legal_links')->nullable();      // livro de reclamações, entidades RAL, informação legal
            // Responsável pelo tratamento (RGPD): sem valor por omissão (nulo até ser escolhido). A
            // própria empresa (nome fiscal, NIPC, morada, email) ou outra entidade (estes campos).
            $table->boolean('controller_is_company')->nullable();
            $table->string('controller_legal_name', 160)->nullable();
            $table->string('controller_nif', 20)->nullable();
            $table->string('controller_address', 255)->nullable();
            $table->string('controller_email', 160)->nullable();
            $table->string('privacy_policy_version', 20)->nullable();
            $table->json('credit_partner')->nullable();   // nome legal, registo no Banco de Portugal, texto
            $table->string('credit_partner_logo_path', 255)->nullable();
            $table->boolean('credit_partner_enabled')->default(false);
            $table->json('whatsapp_messages')->nullable(); // o número é o telemóvel da empresa
            $table->string('ga4_id', 20)->nullable();      // a etiqueta do site (G-…); o Pixel é o da empresa
            $table->json('colors')->nullable();
            $table->json('sections')->nullable();
            $table->boolean('show_made_with')->default(true);
            $table->string('site_status', 12)->default('rascunho'); // rascunho | publicado
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('consultant_site_hosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultant_site_id')->constrained('consultant_sites')->cascadeOnDelete();
            $table->string('host', 190)->unique();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('consultant_site_parishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultant_site_id')->constrained('consultant_sites')->cascadeOnDelete();
            $table->foreignId('parish_id')->constrained('parishes')->cascadeOnDelete();
            $table->unique(['consultant_site_id', 'parish_id']);
        });

        Schema::create('site_server_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('token_hash', 64)->unique(); // SHA-256; o token em claro nunca é guardado
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('site_server_token_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_server_token_id')->constrained('site_server_tokens')->cascadeOnDelete();
            $table->foreignId('consultant_site_id')->constrained('consultant_sites')->cascadeOnDelete();
            $table->unique(['site_server_token_id', 'consultant_site_id'], 'site_token_site_unique');
        });

        Schema::create('inbound_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('channel', 20);            // site (hoje); standvirtual | meta | whatsapp | telefone | manual (roteiro)
            $table->string('source_ref', 120)->nullable(); // identificador na origem (evita duplicados por canal)
            $table->foreignId('consultant_site_id')->nullable()->constrained('consultant_sites')->nullOnDelete();
            $table->string('type', 20);               // contacto | visita | estudo_zona | registo_procura | avaliacao | credito
            $table->string('subject_type', 20)->nullable(); // property | development (mais tarde: car)
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('name', 120);
            $table->string('email', 160)->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('message')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('consent_privacy_at')->nullable();
            $table->boolean('consent_marketing')->default(false);
            $table->timestamp('consent_marketing_at')->nullable();
            $table->boolean('consent_credit_share')->default(false);
            $table->timestamp('consent_credit_share_at')->nullable();
            $table->string('policy_version', 20)->nullable();
            $table->string('utm_source', 120)->nullable();
            $table->string('utm_medium', 120)->nullable();
            $table->string('utm_campaign', 120)->nullable();
            $table->string('utm_content', 120)->nullable();
            $table->string('utm_term', 120)->nullable();
            $table->string('gclid', 255)->nullable();
            $table->string('fbclid', 255)->nullable();
            $table->string('landing_path', 255)->nullable();
            $table->string('page_path', 255)->nullable();
            $table->string('referrer_host', 120)->nullable();
            $table->uuid('event_id')->nullable()->unique(); // partilhado com o Pixel e a API de Conversões
            $table->string('status', 12)->default('novo'); // novo | tratado | arquivado
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->date('retain_until')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'channel', 'source_ref'], 'inbound_contacts_source_unique');
            $table->index(['company_id', 'status', 'created_at']);
            $table->index(['subject_type', 'subject_id', 'created_at']);
        });

        Schema::create('site_daily_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('subject_type', 12); // property | development | site
            $table->unsignedBigInteger('subject_id');
            $table->date('day');
            $table->string('event', 20); // view | whatsapp_click | video_open | chave_nova_click
            $table->unsignedInteger('count')->default(0);
            $table->unique(['subject_type', 'subject_id', 'day', 'event'], 'site_daily_counts_unique');
            $table->index(['company_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_daily_counts');
        Schema::dropIfExists('inbound_contacts');
        Schema::dropIfExists('site_server_token_sites');
        Schema::dropIfExists('site_server_tokens');
        Schema::dropIfExists('consultant_site_parishes');
        Schema::dropIfExists('consultant_site_hosts');
        Schema::dropIfExists('consultant_sites');
        Schema::dropIfExists('listing_images');
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['linkedin', 'tiktok']));
        Schema::table('properties', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'slug']);
            $table->dropIndex(['company_id', 'site_status']);
            $table->dropConstrainedForeignId('development_id');
            $table->dropColumn(['slug', 'bedrooms', 'bathrooms', 'gross_area_m2', 'construction_state', 'approx_lat', 'approx_lng', 'approx_radius_m',
                'price_on_request', 'energy_precertificate', 'short_description', 'description', 'youtube_id', 'chave_nova_url', 'chave_nova_reference',
                'site_status', 'published_at', 'show_as_sold', 'featured', 'contacts_30_manual', 'visits_30_manual', 'contacts_30_site', 'visits_30_site']);
        });
        Schema::dropIfExists('developments');
    }
};
