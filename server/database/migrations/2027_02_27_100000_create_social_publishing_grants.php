<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F2.8 · "Ligar publicação": uma autorização à parte da ligação de leitura das redes sociais, que pede
 * instagram_content_publish e pages_manage_posts. A ligação de leitura não muda (as dos clientes
 * continuam a funcionar até ao App Review). Os tokens das Páginas desta autorização ficam em cada
 * conta escolhida (social_connection_accounts.publish_access_token, cifrado) e são os que o
 * publicador usa.
 *
 * ADITIVA: uma tabela nova e uma coluna nova, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('social_publishing_grants')) {
            Schema::create('social_publishing_grants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('meta_user_id', 64)->nullable()->index();
                $table->text('access_token');
                $table->timestamp('token_expires_at')->nullable();
                $table->json('granted_scopes')->nullable();
                $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('connected_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('social_connection_accounts', 'publish_access_token')) {
            Schema::table('social_connection_accounts', function (Blueprint $table) {
                $table->text('publish_access_token')->nullable()->after('page_access_token');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('social_connection_accounts', 'publish_access_token')) {
            Schema::table('social_connection_accounts', function (Blueprint $table) {
                $table->dropColumn('publish_access_token');
            });
        }
        Schema::dropIfExists('social_publishing_grants');
    }
};
