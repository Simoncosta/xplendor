// Bússola Master, R4: apaga as empresas de demonstração ("(fictício)" no nome fiscal), as pessoas temporárias (.invalid) e tudo o que lhes pertence.
// Correr: docker exec -i xplendor-php php artisan tinker < documents/bussola-master/ferramentas/demo-limpar.php
// Recusa as empresas 1 a 5 (as reais em dev). Nunca correr em produção.
use App\Models\{Company, User, Car};
use Illuminate\Support\Facades\{DB, Schema};

$ids = Company::withTrashed()->where('fiscal_name', 'like', '%(fictício)%')->pluck('id')->all();
if (array_intersect($ids, [1, 2, 3, 4, 5])) { throw new RuntimeException('Uma empresa real tem (fictício) no nome: parar.'); }
$carIds  = Car::whereIn('company_id', $ids)->pluck('id')->all();
$userIds = User::withTrashed()->where(fn ($q) => $q->whereIn('company_id', $ids)->orWhere('email', 'like', '%.invalid'))->pluck('id')->all();
// Só as tabelas base (as vistas, como car_funnel_metrics_daily, não se apagam).
$tables  = array_map(fn ($t) => array_values((array) $t)[0], DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"));

DB::statement('SET FOREIGN_KEY_CHECKS=0');
DB::table('personal_access_tokens')->whereIn('tokenable_id', $userIds)->delete();
if (Schema::hasTable('impersonation_sessions')) { DB::table('impersonation_sessions')->whereIn('target_user_id', $userIds)->orWhereIn('root_id', $userIds)->delete(); }
foreach ($tables as $t) {
    if ($t === 'companies') { continue; }
    if (Schema::hasColumn($t, 'company_id')) { DB::table($t)->whereIn('company_id', $ids)->delete(); }
    elseif ($carIds && Schema::hasColumn($t, 'car_id')) { DB::table($t)->whereIn('car_id', $carIds)->delete(); }
}
if (Schema::hasTable('company_managements')) { DB::table('company_managements')->whereIn('agency_company_id', $ids)->orWhereIn('managed_company_id', $ids)->delete(); }
DB::table('users')->whereIn('id', $userIds)->delete();
DB::table('companies')->whereIn('id', $ids)->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');
echo json_encode(['empresas' => $ids, 'veiculos' => count($carIds), 'pessoas' => count($userIds)]) . PHP_EOL;
