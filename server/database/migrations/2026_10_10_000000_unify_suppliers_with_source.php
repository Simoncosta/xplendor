<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * XPLENDOR — UNIFICAR fornecedores numa só tabela `suppliers` com flag `source`
 * ('manual' | 'pingwin'). Os nativos do automotivo ficam source='manual'; os do
 * PingWin migram de `pingwin_suppliers` para cá como source='pingwin'.
 *
 * DESENHO (o mais reversível possível):
 *  - Reaproveita a tabela `suppliers` (a FK expenses.supplier_id já aponta para cá,
 *    logo NÃO se toca em expenses).
 *  - Acrescenta as colunas específicas do PingWin (nullable) + `source`.
 *  - Copia as linhas de pingwin_suppliers, guardando um MAPA old_id → new_id, e
 *    repõe ocr_invoices.supplier_id (link mole) para o novo id em `suppliers`.
 *  - MANTÉM a tabela `pingwin_suppliers` intacta (backup para rollback); deixa de
 *    ser usada (os models passam a apontar para `suppliers`).
 *
 * ⚠️ Protecção do sync: a UNIQUE (company_id, pingwin_id) permite vários NULL
 * (manuais) → o UPSERT do sync (por company_id+pingwin_id) NUNCA casa com um
 * manual e NUNCA apaga nada. Portável (sqlite dos testes + MariaDB).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Estrutura: colunas do PingWin + flag source. name passa a nullable
        //    (fornecedor PingWin pode não ter nome).
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('source')->default('manual')->after('company_id'); // 'manual' | 'pingwin'
            $table->string('pingwin_id')->nullable()->after('source');
            $table->string('code')->nullable()->after('pingwin_id');
            $table->string('fiscal_name')->nullable()->after('name');
            $table->string('tax_number')->nullable()->after('nif'); // NIF do PingWin (o manual usa `nif`)
            $table->string('city')->nullable()->after('postal_code'); // localidade texto (PingWin)
            $table->boolean('is_active')->default(true)->after('archived'); // = NOT deleted (PingWin)
            $table->timestamp('synced_at')->nullable()->after('is_active');
        });

        // name → nullable (fora do closure acima para clareza; change() nativo no L12).
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
        });

        // Índices: source (filtros) + UNIQUE (company_id, pingwin_id) para o upsert.
        Schema::table('suppliers', function (Blueprint $table) {
            $table->index(['company_id', 'source'], 'suppliers_company_source_idx');
            $table->unique(['company_id', 'pingwin_id'], 'suppliers_company_pingwin_unique');
        });

        // 2) Garante source='manual' nos existentes (o default já trata, explícito por segurança).
        DB::table('suppliers')->whereNull('source')->update(['source' => 'manual']);

        // 3) Migra pingwin_suppliers → suppliers (source='pingwin'), com mapa old→new,
        //    e repõe ocr_invoices.supplier_id. Portável (loop + insertGetId).
        if (Schema::hasTable('pingwin_suppliers')) {
            DB::table('pingwin_suppliers')->orderBy('id')->chunkById(500, function ($rows) {
                foreach ($rows as $ps) {
                    $newId = DB::table('suppliers')->insertGetId([
                        'company_id'  => $ps->company_id,
                        'source'      => 'pingwin',
                        'pingwin_id'  => $ps->pingwin_id,
                        'code'        => $ps->code,
                        'name'        => $ps->name,
                        'fiscal_name' => $ps->fiscal_name ?? null,
                        'tax_number'  => $ps->tax_number,
                        'address'     => $ps->address,
                        'city'        => $ps->city,
                        'postal_code' => $ps->postal_code,
                        'phone'       => $ps->phone,
                        'email'       => $ps->email,
                        'archived'    => false,
                        'is_active'   => $ps->is_active,
                        'synced_at'   => $ps->synced_at,
                        'created_at'  => $ps->created_at,
                        'updated_at'  => $ps->updated_at,
                    ]);

                    // Link mole do OCR: repontar para o novo id na tabela unificada.
                    if (Schema::hasTable('ocr_invoices')) {
                        DB::table('ocr_invoices')
                            ->where('supplier_id', $ps->id)
                            ->update(['supplier_id' => $newId]);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // 1) Repor ocr_invoices.supplier_id de volta para pingwin_suppliers.id
        //    (casa pela chave natural company_id + pingwin_id).
        if (Schema::hasTable('pingwin_suppliers') && Schema::hasTable('ocr_invoices')) {
            DB::table('suppliers')->where('source', 'pingwin')->orderBy('id')->chunkById(500, function ($rows) {
                foreach ($rows as $s) {
                    $oldId = DB::table('pingwin_suppliers')
                        ->where('company_id', $s->company_id)
                        ->where('pingwin_id', $s->pingwin_id)
                        ->value('id');
                    if ($oldId) {
                        DB::table('ocr_invoices')->where('supplier_id', $s->id)->update(['supplier_id' => $oldId]);
                    }
                }
            });
        }

        // 2) Remove as linhas migradas do PingWin (pingwin_suppliers fica intacta).
        DB::table('suppliers')->where('source', 'pingwin')->delete();

        // 3) Larga índices e colunas acrescentadas.
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropUnique('suppliers_company_pingwin_unique');
            $table->dropIndex('suppliers_company_source_idx');
            $table->dropColumn(['source', 'pingwin_id', 'code', 'fiscal_name', 'tax_number', 'city', 'is_active', 'synced_at']);
        });

        // name volta a NOT NULL (os manuais têm sempre nome; as linhas PingWin já saíram).
        DB::table('suppliers')->whereNull('name')->update(['name' => '']);
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
        });
    }
};
