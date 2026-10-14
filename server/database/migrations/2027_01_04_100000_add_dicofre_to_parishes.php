<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Bússola Master, R3 (resposta à pergunta 10): o código oficial das freguesias (DICOFRE) e dos
 * concelhos (DICO), para ligar as freguesias da plataforma à referência do INE.
 *
 * A tabela oficial vem do INE (metainformação do indicador 0011609, Censos 2021, geografia NUTS
 * 2013 versão 04479: 308 concelhos e 3092 freguesias), lida a 14 de outubro de 2026 em
 * https://www.ine.pt/ine/json_indicador/pindicaMeta.jsp?varcd=0011609&lang=PT e marcada com as
 * 584 freguesias da geografia NUTS 2024 do indicador 0012234 (a referência do imobiliário).
 * Fonte: Instituto Nacional de Estatística, IP – Portugal (licença CC BY 4.0). O ficheiro está em
 * database/data/ine_freguesias_dicofre_censos2021.csv.
 *
 * Aditiva: a tabela official_parishes (a lista oficial, para o painel do root mostrar as
 * diferenças), parishes.dicofre e municipalities.dico. O nome normalizado só serve para casar
 * aqui; as que não casam ficam sem código e listadas no painel do root. Nada é apagado.
 */
return new class extends Migration
{
    /** Distritos e ilhas da plataforma (ids 19 a 30) para o código oficial do distrito ou da ilha. */
    private const ISLANDS = [19 => '31', 20 => '32', 21 => '41', 22 => '42', 23 => '4', 24 => '43', 25 => '44', 26 => '45', 27 => '46', 28 => '47', 29 => '48', 30 => '49'];

    public function up(): void
    {
        Schema::create('official_parishes', function (Blueprint $table) {
            $table->id();
            $table->string('dicofre', 6)->unique();
            $table->string('name', 191);
            $table->string('normalized_name', 191);
            $table->string('dico', 4)->index();
            $table->string('municipality_name', 120);
            $table->boolean('in_nuts_2024')->default(false);
            $table->string('source', 120);
            $table->date('fetched_on');
        });
        Schema::table('parishes', function (Blueprint $table) {
            $table->string('dicofre', 6)->nullable()->index();
        });
        Schema::table('municipalities', function (Blueprint $table) {
            $table->string('dico', 4)->nullable()->index();
        });

        [$municipalities, $parishes] = $this->officialList();
        foreach (array_chunk($parishes, 500) as $chunk) {
            DB::table('official_parishes')->insert(array_map(fn ($p) => $p + ['municipality_name' => $municipalities[$p['dico']] ?? '',
                'source' => 'INE, indicador 0011609 (Censos 2021)', 'fetched_on' => '2026-10-14'], $chunk));
        }

        // Concelhos: pelo nome normalizado, dentro do distrito (ou da ilha) quando o nome se repete.
        $byName = [];
        foreach ($municipalities as $dico => $name) {
            $byName[self::norm($name)][] = $dico;
        }
        foreach (DB::table('municipalities')->get(['id', 'name', 'district_id']) as $m) {
            $codes = $byName[self::norm((string) $m->name)] ?? [];
            if (count($codes) > 1) {
                $prefix = (int) $m->district_id <= 18 ? str_pad((string) $m->district_id, 2, '0', STR_PAD_LEFT) : (self::ISLANDS[(int) $m->district_id] ?? '');
                $codes = $prefix === '' ? [] : array_values(array_filter($codes, fn ($c) => str_starts_with($c, $prefix)));
            }
            if (count($codes) === 1) {
                DB::table('municipalities')->where('id', $m->id)->update(['dico' => $codes[0]]);
            }
        }

        // Freguesias: pelo nome normalizado dentro do concelho; depois sem o "União das freguesias de".
        $official = [];
        foreach ($parishes as $p) {
            $official[$p['dico']][$p['normalized_name']] = $p['dicofre'];
            $official[$p['dico']]['~' . self::loose($p['name'])] ??= $p['dicofre'];
        }
        $dicos = DB::table('municipalities')->whereNotNull('dico')->pluck('dico', 'id');
        foreach (DB::table('parishes')->get(['id', 'name', 'municipality_id']) as $p) {
            $dico = $dicos[$p->municipality_id] ?? null;
            if ($dico === null) {
                continue;
            }
            $code = $official[$dico][self::norm((string) $p->name)] ?? $official[$dico]['~' . self::loose((string) $p->name)] ?? null;
            if ($code !== null) {
                DB::table('parishes')->where('id', $p->id)->update(['dicofre' => $code]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('parishes', fn (Blueprint $t) => $t->dropColumn('dicofre'));
        Schema::table('municipalities', fn (Blueprint $t) => $t->dropColumn('dico'));
        Schema::dropIfExists('official_parishes');
    }

    /** @return array{0: array<string, string>, 1: list<array<string, mixed>>} concelhos (dico => nome) e freguesias */
    private function officialList(): array
    {
        $municipalities = [];
        $parishes = [];
        $fh = fopen(database_path('data/ine_freguesias_dicofre_censos2021.csv'), 'r');
        fgetcsv($fh);
        while (($row = fgetcsv($fh)) !== false) {
            [$level, $code, $name, $nuts] = array_pad($row, 4, '');
            if ($level === 'concelho') {
                $municipalities[$code] = $name;
            } elseif ($level === 'freguesia') {
                $parishes[] = ['dicofre' => $code, 'name' => $name, 'normalized_name' => self::norm($name), 'dico' => substr($code, 0, 4), 'in_nuts_2024' => $nuts === '1'];
            }
        }
        fclose($fh);

        return [$municipalities, $parishes];
    }

    public static function norm(string $name): string
    {
        $s = preg_replace('/[^a-z0-9]+/', ' ', Str::ascii(mb_strtolower($name, 'UTF-8'))) ?? '';

        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }

    /** O nome sem o prefixo das uniões e sem as palavras de ligação (para os nomes escritos de outra forma). */
    public static function loose(string $name): string
    {
        $s = ' ' . self::norm($name) . ' ';
        $s = preg_replace('/^ uniao (das|de) freguesias (de|da|do|das|dos) /', ' ', $s) ?? $s;
        $s = preg_replace('/ (e|de|da|do|das|dos) /', ' ', $s) ?? $s;
        $s = preg_replace('/ (e|de|da|do|das|dos) /', ' ', $s) ?? $s;

        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }
};
