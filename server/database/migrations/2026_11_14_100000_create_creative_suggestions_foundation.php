<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil da Marca, Parte B: base das sugestões de criativos.
 *  · creative_format_rules: regra de formato por rede e faixa de seguidores (referência de
 *    mercado, editável só pelo root), com a semente do estudo e a fonte a preencher.
 *  · editorial_posts.media_format: o FORMATO da publicação (vocabulário do publicador F2).
 *    O campo "format" atual passa a ser o "tipo de conteúdo" (os 18 valores ficam iguais).
 *    Os valores que já eram formatos de media são copiados para media_format; nada é apagado.
 *  · editorial_post_creatives: o criativo aceite (campo a campo) de uma publicação.
 *  · ai_requests.editorial_post_id: a publicação a que um pedido de criativo se refere.
 */
return new class extends Migration
{
    /** Tipo de conteúdo antigo que é, de facto, um formato de media → formato F2 por rede. */
    private const LEGACY_MEDIA_FORMATS = [
        'instagram' => ['Carrossel' => 'ig_carousel', 'Imagem única' => 'ig_feed_image', 'Reels' => 'ig_reel', 'Stories' => 'ig_story', 'Vídeo' => 'ig_reel'],
        'facebook'  => ['Carrossel' => 'fb_photos', 'Imagem única' => 'fb_photos', 'Reels' => 'fb_reel', 'Stories' => 'fb_story', 'Vídeo' => 'fb_video'],
    ];

    public function up(): void
    {
        Schema::create('creative_format_rules', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20);                         // instagram | facebook
            $table->unsignedBigInteger('followers_min')->default(0);
            $table->unsignedBigInteger('followers_max')->nullable(); // null = sem limite
            $table->string('format_key', 30);                      // vocabulário F2 (ig_carousel, ig_reel, …)
            $table->decimal('engagement_rate', 5, 2)->nullable();  // taxa de interação média (%), se o estudo a der
            $table->unsignedTinyInteger('rank')->default(1);       // 1 = o mais indicado na faixa
            $table->string('note', 500)->nullable();
            $table->string('source_label', 255);
            $table->string('source_url', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['channel', 'is_active', 'followers_min'], 'cfr_channel_active_min_idx');
        });

        $source = '[FONTE DO ESTUDO]';
        $now = now();
        $rows = [
            // Até 10 mil seguidores.
            ['followers_min' => 0, 'followers_max' => 10000, 'format_key' => 'ig_carousel', 'engagement_rate' => 6.81, 'rank' => 1, 'note' => null],
            ['followers_min' => 0, 'followers_max' => 10000, 'format_key' => 'ig_reel', 'engagement_rate' => 5.47, 'rank' => 2, 'note' => null],
            ['followers_min' => 0, 'followers_max' => 10000, 'format_key' => 'ig_feed_image', 'engagement_rate' => 4.65, 'rank' => 3, 'note' => null],
            // Acima de 10 mil (até 500 mil): o vídeo lidera.
            ['followers_min' => 10001, 'followers_max' => 500000, 'format_key' => 'ig_reel', 'engagement_rate' => null, 'rank' => 1, 'note' => 'Acima de 10 mil seguidores, o vídeo lidera.'],
            // Acima de 500 mil.
            ['followers_min' => 500001, 'followers_max' => null, 'format_key' => 'ig_reel', 'engagement_rate' => 4.94, 'rank' => 1, 'note' => null],
            ['followers_min' => 500001, 'followers_max' => null, 'format_key' => 'ig_carousel', 'engagement_rate' => 3.77, 'rank' => 2, 'note' => null],
            ['followers_min' => 500001, 'followers_max' => null, 'format_key' => 'ig_feed_image', 'engagement_rate' => 2.78, 'rank' => 3, 'note' => null],
        ];
        DB::table('creative_format_rules')->insert(array_map(fn ($r) => $r + [
            'channel' => 'instagram', 'source_label' => $source, 'source_url' => null, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ], $rows));

        Schema::table('editorial_posts', function (Blueprint $table) {
            $table->string('media_format', 30)->nullable()->after('format');
        });
        foreach (self::LEGACY_MEDIA_FORMATS as $channel => $map) {
            foreach ($map as $contentType => $mediaFormat) {
                DB::table('editorial_posts')
                    ->where('channel', $channel)
                    ->where('format', $contentType)
                    ->whereNull('media_format')
                    ->update(['media_format' => $mediaFormat]);
            }
        }

        Schema::create('editorial_post_creatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('editorial_post_id')->unique()->constrained('editorial_posts')->cascadeOnDelete();
            $table->string('media_format', 30)->nullable();
            $table->string('hook', 200)->nullable();
            $table->text('caption')->nullable();
            $table->json('hashtags')->nullable();
            $table->string('cta', 300)->nullable();
            $table->text('rationale')->nullable();                // o porquê da sugestão aceite
            $table->string('source', 30)->nullable();             // market_reference | own_history | none
            $table->string('source_label', 255)->nullable();
            $table->foreignId('ai_request_id')->nullable()->constrained('ai_requests')->nullOnDelete();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        Schema::table('ai_requests', function (Blueprint $table) {
            $table->foreignId('editorial_post_id')->nullable()->after('blog_id')->constrained('editorial_posts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('editorial_post_id');
        });
        Schema::dropIfExists('editorial_post_creatives');
        Schema::table('editorial_posts', function (Blueprint $table) {
            $table->dropColumn('media_format');
        });
        Schema::dropIfExists('creative_format_rules');
    }
};
