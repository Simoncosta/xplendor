<?php

use App\Settings\SettingsCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configurações: as definições por empresa e da plataforma (o que saiu do .env), com o histórico.
 * Migração de dados: copia para cada empresa com o PingWin os valores atuais do .env que não são o
 * defeito, e para a plataforma as provas ligadas. Nada muda de comportamento: o valor gravado é o
 * mesmo que a empresa já tinha, e o que fica por gravar continua a cair no mesmo valor (o defeito).
 *
 * As listas antigas de artigos autorizados (PINGWIN_*_WRITE_ALLOWLIST) passam a "Escrita no
 * PingWin" e "Modo de testes" só quando a tradução é exata: todas "0" → escrita desligada. Com listas de artigos, a empresa fica sem as duas
 * definições e as listas do .env continuam a mandar até o root escolher. Todas vazias também ficam
 * por gravar (é o defeito: escrita ligada, sem modo de testes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('key', 80);
            $t->json('value')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'key']);
        });
        Schema::create('platform_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key', 80)->unique();
            $t->json('value')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('setting_events', function (Blueprint $t) {
            $t->id();
            $t->string('scope', 20);
            $t->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('key', 80);
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('reason', 255)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['company_id', 'key']);
        });

        $now = now();
        $reason = 'Migração: o valor que estava no .env (ou o defeito).';
        $event = fn (string $scope, ?int $companyId, string $key, $value) => DB::table('setting_events')->insert([
            'scope' => $scope, 'company_id' => $companyId, 'key' => $key, 'before' => null, 'after' => json_encode($value), 'user_id' => null,
            'reason' => $reason, 'created_at' => $now,
        ]);

        foreach (SettingsCatalog::keys(SettingsCatalog::PLATFORM) as $key) {
            $d = SettingsCatalog::get($key);
            $value = (bool) (config($d['config']) ?? $d['default']);
            if ($value === (bool) $d['default']) {
                continue;
            }
            DB::table('platform_settings')->insert(['key' => $key, 'value' => json_encode($value), 'created_at' => $now, 'updated_at' => $now]);
            $event(SettingsCatalog::PLATFORM, null, $key, $value);
        }

        $lists = array_map(fn ($k) => trim((string) config("services.pingwin.{$k}", '')),
            ['bom_write_allowlist', 'unit_write_allowlist', 'stock_write_allowlist', 'stockdoc_write_allowlist', 'warehouse_write_allowlist']);
        $writes = match (true) {
            count(array_filter($lists, fn ($l) => $l !== '')) === 0 => [],   // todas vazias: escrita ligada, sem modo de testes (o defeito)
            count(array_filter($lists, fn ($l) => $l === '0')) === count($lists) => ['pingwin.writes_enabled' => false],
            default => [],
        };

        $companies = DB::table('company_integrations')->where('platform', 'pingwin')->distinct()->pluck('company_id');
        foreach ($companies as $companyId) {
            foreach (SettingsCatalog::keys(SettingsCatalog::COMPANY) as $key) {
                $d = SettingsCatalog::get($key);
                if (! $d['config']) {
                    continue;   // sem variável no .env (anulações por funcionário, feriado municipal): fica o defeito
                }
                $value = config($d['config']) ?? $d['default'];
                $value = match ($d['type']) {
                    'bool' => (bool) $value, 'pct', 'eur', 'number' => (float) $value, 'family_pct' => (array) $value, default => (string) ($value ?? ''),
                };
                if ($value == $d['default']) {
                    continue;   // o valor do .env é o defeito: fica por gravar (o mesmo comportamento)
                }
                DB::table('company_settings')->insert(['company_id' => $companyId, 'key' => $key, 'value' => json_encode($value), 'created_at' => $now, 'updated_at' => $now]);
                $event(SettingsCatalog::COMPANY, (int) $companyId, $key, $value);
            }
            foreach ($writes as $key => $value) {
                DB::table('company_settings')->insert(['company_id' => $companyId, 'key' => $key, 'value' => json_encode($value), 'created_at' => $now, 'updated_at' => $now]);
                $event(SettingsCatalog::COMPANY_ROOT, (int) $companyId, $key, $value);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_events');
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('company_settings');
    }
};
