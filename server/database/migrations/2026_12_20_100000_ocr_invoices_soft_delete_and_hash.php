<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * XPLENDOR — F2c: apagar faturas OCR (soft delete) e ficheiro duplicado no carregamento.
 *   deleted_at / deleted_by — apagada (sai das listas e dos jobs; repõe-se enquanto o ficheiro existir)
 *   file_purged_at          — o ficheiro foi apagado do disco (30 dias depois de apagada)
 *   file_sha256             — hash do ficheiro, para recusar o mesmo ficheiro outra vez (backfill aqui)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_invoices', function (Blueprint $table) {
            $table->softDeletes()->after('updated_at');
            $table->unsignedBigInteger('deleted_by')->nullable()->after('deleted_at');
            $table->timestamp('file_purged_at')->nullable()->after('deleted_by');
            $table->char('file_sha256', 64)->nullable()->after('image_mime');
            $table->index(['company_id', 'file_sha256'], 'ocr_inv_company_hash_idx');
        });

        // Backfill do hash a partir dos ficheiros que existem (os que faltarem ficam sem hash).
        $disk = Storage::disk((string) config('services.openai.ocr_disk', 'local'));
        DB::table('ocr_invoices')->whereNull('file_sha256')->orderBy('id')->select(['id', 'image_path'])
            ->chunkById(100, function ($rows) use ($disk) {
                foreach ($rows as $r) {
                    try {
                        if ($r->image_path && $disk->exists($r->image_path)) {
                            DB::table('ocr_invoices')->where('id', $r->id)->update(['file_sha256' => hash('sha256', (string) $disk->get($r->image_path))]);
                        }
                    } catch (\Throwable $e) {
                        Log::warning('[OCR] hash do ficheiro não calculado', ['id' => $r->id, 'error' => $e->getMessage()]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('ocr_invoices', function (Blueprint $table) {
            $table->dropIndex('ocr_inv_company_hash_idx');
            $table->dropColumn(['deleted_at', 'deleted_by', 'file_purged_at', 'file_sha256']);
        });
    }
};
