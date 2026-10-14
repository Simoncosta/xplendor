// Bússola Master, R4: cria as empresas de demonstração (claramente fictícias) e as pessoas temporárias.
// Correr: docker exec -i xplendor-php php artisan tinker < documents/bussola-master/ferramentas/demo-criar.php
// Apagar tudo no fim com demo-limpar.php. Nunca correr em produção.
use App\Models\{Company, User, PermissionProfile, ProfilePermission, Car, CarBrand, CarModel, CarMarketAggregate, MetaAd, MetaAdInsightDaily, EditorialMonth, ContentSector};
use App\Services\CompanyModuleService;
use App\Services\MetaAdCarAllocator;
use App\Modules\ModuleRegistry;
use Illuminate\Support\Facades\{DB, Hash, Schema};

$modules = app(CompanyModuleService::class);
$sector = fn (string $slug) => ContentSector::where('slug', $slug)->value('id');
$planId = DB::table('plans')->min('id');
$password = Hash::make('Demo-2026-noite');
$sessoes = [];
$lisboa = DB::table('municipalities')->where('name', 'Lisboa')->first();
$porto = DB::table('municipalities')->where('name', 'Porto')->first();
$freguesia = fn (int $municipalityId, string $nome) => DB::table('parishes')->where('municipality_id', $municipalityId)->where('name', $nome)->value('id');

// 1) As três empresas de demonstração: o preset pelo ramo, depois os extras (só os módulos que existirem).
$empresa = function (string $nipc, string $nome, ?int $sectorId, string $preset, array $extras, object $mun, ?int $parishId) use ($modules, $planId) {
    $c = Company::create(['nipc' => $nipc, 'fiscal_name' => "$nome (fictício)", 'trade_name' => $nome, 'plan_id' => $planId,
        'subscription_status' => 'active', 'content_sector_id' => $sectorId,
        'district_id' => $mun->district_id, 'municipality_id' => $mun->id, 'parish_id' => $parishId]);
    $modules->applyPreset($c->id, ModuleRegistry::presetExists($preset) ? $preset : 'base');
    foreach ($extras as $m) { if (ModuleRegistry::exists($m)) { $modules->enable($c->id, $m); } }
    return $c;
};
$restaurante = $empresa('999900001', 'Tasca Demonstração', $sector('restauracao'), 'restaurant', ['linha_editorial', 'radar'], $porto, $freguesia($porto->id, 'Bonfim'));
$stand       = $empresa('999900002', 'Stand Demonstração', $sector('automovel'), 'automotive', ['linha_editorial', 'ficheiros', 'radar'], $porto, $freguesia($porto->id, 'Ramalde'));
$imobiliaria = $empresa('999900003', 'Consultor Imobiliário Demonstração', $sector('imobiliario') ?? $sector('universal'), 'real_estate', ['linha_editorial', 'imoveis', 'radar'], $lisboa, $freguesia($lisboa->id, 'Benfica'));
if (ModuleRegistry::exists('radar')) { $modules->enable(1, 'radar'); }   // a XPLENDOR de dev

// 2) O stock do stand: um veículo por tipo de jogada.
$brandCar = CarBrand::where('vehicle_type', 'car')->orderBy('id')->first();
$modelCar = CarModel::where('car_brand_id', $brandCar->id)->orderBy('id')->first();
$brandMh  = CarBrand::where('vehicle_type', 'motorhome')->orderBy('id')->first() ?? $brandCar;
$modelMh  = CarModel::where('car_brand_id', $brandMh->id)->orderBy('id')->first() ?? $modelCar;
$car = function (int $dias, array $attrs = []) use ($stand, $brandCar, $modelCar) {
    $c = Car::factory()->create(array_merge(['company_id' => $stand->id, 'car_brand_id' => $brandCar->id, 'car_model_id' => $modelCar->id,
        'vehicle_type' => 'car', 'status' => 'active', 'is_resume' => 0, 'price_gross' => 20000, 'promo_price_gross' => null,
        'registration_year' => 2019, 'mileage_km' => 85000], array_diff_key($attrs, ['sold_at' => 1])));
    DB::table('cars')->where('id', $c->id)->update(['created_at' => now()->startOfDay()->subDays($dias), 'car_created_at' => null]
        + (array_key_exists('sold_at', $attrs) ? ['sold_at' => $attrs['sold_at']] : []));
    return $c->fresh();
};
$aggregate = fn (Car $c, float $mediana, int $comparaveis = 12) => CarMarketAggregate::create(['car_id' => $c->id, 'vehicle_type' => $c->vehicle_type,
    'status' => 'success', 'confidence' => 'high', 'comparables_count' => $comparaveis, 'median_price' => $mediana, 'fallback_used' => false, 'method' => 'car_v1']);
$vistas = fn (Car $c, int $n) => DB::table('car_views')->insert(array_fill(0, $n, ['company_id' => $c->company_id, 'car_id' => $c->id,
    'ip_address' => '127.0.0.1', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]));
$anuncio = function (string $adId, Car $c, float $gasto) use ($stand) {
    $nome = "{$c->brand?->name} {$c->model?->name} [id:{$c->id}]";
    MetaAdInsightDaily::create(['company_id' => $stand->id, 'account_id' => 'demo', 'date' => now()->subDays(2)->toDateString(), 'campaign_id' => 'C1',
        'adset_id' => 'S1', 'ad_id' => $adId, 'ad_name' => $nome, 'spend' => $gasto, 'impressions' => 100, 'clicks' => 3]);
    MetaAd::updateOrCreate(['company_id' => $stand->id, 'account_id' => 'demo', 'ad_id' => $adId], ['ad_name' => $nome, 'campaign_id' => 'C1', 'effective_status' => 'ACTIVE']);
    app(MetaAdCarAllocator::class)->rebuild($stand->id, 'demo');
};
$vendido = $car(80, ['status' => 'sold', 'sold_at' => now()->subDays(5)]);  $anuncio('A1', $vendido, 40.0);
$caro    = $car(50, ['price_gross' => 22400]);                             $aggregate($caro, 20000);
$parado1 = $car(70);  $parado2 = $car(60);
$visto   = $car(10);                                                       $vistas($visto, 70);
$palco   = $car(12, ['price_gross' => 19000]);                             $aggregate($palco, 20000);
$semContactos = $car(25);                                                  $anuncio('A2', $semContactos, 45.0);
foreach ([130, 125] as $d) { $car($d, ['vehicle_type' => 'motorhome', 'car_brand_id' => $brandMh->id, 'car_model_id' => $modelMh->id, 'price_gross' => 48000]); }
$car(125, ['vehicle_type' => 'caravan', 'car_brand_id' => $brandMh->id, 'car_model_id' => $modelMh->id, 'price_gross' => 21000]);

// 3) A carteira do consultor imobiliário (só depois do R3): um imóvel por jogada. Sem morada, sem proprietários.
if (Schema::hasTable('properties')) {
    $imovel = function (array $a) use ($imobiliaria, $lisboa, $freguesia) {
        return DB::table('properties')->insertGetId(array_merge(['company_id' => $imobiliaria->id, 'kind' => 'apartamento', 'typology' => 'T2', 'area_m2' => 85,
            'parish_id' => $freguesia($lisboa->id, 'Benfica'), 'listing' => 'venda', 'price' => 350000, 'energy_class' => 'C', 'status' => 'disponivel',
            'listed_at' => now()->subDays(20)->toDateString(), 'contacts_30' => 0, 'visits_30' => 0, 'created_at' => now(), 'updated_at' => now()], $a));
    };
    $imovel(['reference' => 'DEMO-001', 'title' => 'T2 renovado em Benfica', 'listed_at' => now()->subDays(3)->toDateString(), 'energy_class' => null]);              // novo, sem classe energética
    $imovel(['reference' => 'DEMO-002', 'title' => 'T3 com varanda em Alvalade', 'parish_id' => $freguesia($lisboa->id, 'Alvalade'), 'area_m2' => 110, 'price' => 890000, 'listed_at' => now()->subDays(120)->toDateString()]);   // parado, acima da referência
    $imovel(['reference' => 'DEMO-003', 'title' => 'T1 para arrendar em Arroios', 'parish_id' => $freguesia($lisboa->id, 'Arroios'), 'listing' => 'arrendamento', 'typology' => 'T1', 'area_m2' => 55, 'price' => 1200, 'listed_at' => now()->subDays(60)->toDateString()]);   // parado (arrendamento)
    $imovel(['reference' => 'DEMO-004', 'title' => 'Moradia em Carnide', 'kind' => 'moradia', 'typology' => 'T4', 'parish_id' => $freguesia($lisboa->id, 'Carnide'), 'area_m2' => 180, 'price' => 650000, 'status' => 'fechado', 'listed_at' => now()->subDays(90)->toDateString(), 'closed_at' => now()->subDays(10)->toDateString()]);   // fechado: testemunho
    $imovel(['reference' => 'DEMO-005', 'title' => 'T2 com interesse em Benfica', 'listed_at' => now()->subDays(30)->toDateString(), 'contacts_30' => 5, 'visits_30' => 2, 'interest_updated_at' => now()->subDays(2)->toDateString()]);   // interesse manual
}

// 4) Os meses da Linha Editorial abertos (este e o próximo) nas três empresas.
foreach ([[now()->year, now()->month], [now()->addMonth()->year, now()->addMonth()->month]] as [$y, $m]) {
    foreach ([$restaurante, $stand, $imobiliaria] as $c) { EditorialMonth::firstOrCreate(['company_id' => $c->id, 'year' => $y, 'month' => $m], ['state' => EditorialMonth::OPEN]); }
}

// 5) As pessoas temporárias (o root na XPLENDOR; os outros nas empresas de demonstração). E-mails no domínio reservado .invalid.
\App\Access\CompatibilityMigration::run();
$pessoa = function (Company $c, string $nome, string $email, string $role, ?int $profileId) use ($password) {
    return User::create(['name' => $nome, 'email' => $email, 'password' => $password, 'role' => $role, 'company_id' => $c->id, 'accepted_at' => now()])
        ->forceFill(['profile_id' => $profileId])->refresh();
};
$adminProfile = PermissionProfile::system(PermissionProfile::ADMIN)->id;
$guestSys = PermissionProfile::where('system_key', \App\Access\ProfileSuggestions::GUEST_AGENCY)->firstOrFail();
$guestPerms = ProfilePermission::where('profile_id', $guestSys->id)->get()->map(fn ($p) => "{$p->area}.{$p->action}")->all();
$guestProfile = function (Company $c) use ($guestPerms) {
    $g = PermissionProfile::create(['company_id' => $c->id, 'side' => 'cliente', 'name' => 'Agência convidada (demonstração)', 'is_system' => false, 'is_suggestion' => false]);
    $g->syncPermissions($guestPerms);
    return $g->id;
};
$xplendor = Company::findOrFail(1);
$pessoas = [
    'admin_stand' => $pessoa($stand, 'Administradora Demonstração', 'admin@demo-stand.invalid', 'admin', $adminProfile),
    'agencia_stand' => $pessoa($stand, 'Agência Demonstração', 'agencia@demo-stand.invalid', 'user', $guestProfile($stand)),
    'admin_tasca' => $pessoa($restaurante, 'Administrador Tasca Demonstração', 'admin@demo-tasca.invalid', 'admin', $adminProfile),
    'admin_imob' => $pessoa($imobiliaria, 'Consultor Demonstração', 'admin@demo-imob.invalid', 'admin', $adminProfile),
    'agencia_imob' => $pessoa($imobiliaria, 'Agência Imobiliária Demonstração', 'agencia@demo-imob.invalid', 'user', $guestProfile($imobiliaria)),
    'root' => $pessoa($xplendor, 'Root Temporário', 'root@demo-noite.invalid', 'root', null),
];
foreach ($pessoas as $k => $u) { $sessoes[$k] = ['token' => $u->createToken('captura-noite')->plainTextToken] + $u->fresh()->load('company')->toArray(); }

// 6) O interruptor do stand e o primeiro cálculo, sem IA real nem worker (a chave vazia só neste processo; a fila síncrona).
config(['ai.providers.anthropic.key' => '', 'ai.providers.openai.key' => '', 'queue.default' => 'sync']);
$stand->forceFill(['bussola_stand_enabled' => true])->save();
app(\App\Services\Compass\Stand\StandSignalService::class)->compute($stand->id);
if (class_exists(\App\Services\Compass\Imoveis\ImoveisSignalService::class)) { app(\App\Services\Compass\Imoveis\ImoveisSignalService::class)->compute($imobiliaria->id); }

// 7) Radar: a relevância e o encaixe com a IA simulada (AI_FAKE), sem recolhas novas (usa as tendências já guardadas).
config(['queue.default' => 'sync', 'ai.fake' => true, 'radar.enabled' => true]);
if (class_exists(\App\Services\Radar\Relevance\RadarRelevanceService::class)) {
    app(\App\Services\Radar\Relevance\RadarRelevanceService::class)->evaluate();
    foreach ([1, $restaurante->id, $stand->id, $imobiliaria->id] as $cid) { app(\App\Services\Radar\Fit\RadarFitService::class)->fit($cid); }
    // Sem nomes de pessoas nas capturas: tira os sinais com a marca nome_pessoa.
    \App\Models\RadarSignal::whereIn('company_id', [1, $restaurante->id, $stand->id, $imobiliaria->id])->get()
        ->filter(fn ($s) => in_array('nome_pessoa', (array) $s->flags, true))->each->delete();
}

// 8) Imobiliário: os sinais, uma ideia de um imóvel sem classe energética (o aviso) e uma ideia do Radar (o gancho e as fontes).
if (class_exists(\App\Services\Compass\Imoveis\ImoveisCompassService::class)) {
    app(\App\Services\Compass\Imoveis\ImoveisSignalService::class)->compute($imobiliaria->id);
    $adminImob = $pessoas['admin_imob'];
    $plays = app(\App\Services\Compass\Imoveis\ImoveisCompassService::class)->payload($imobiliaria->id, $adminImob, 'all', true)['plays'];
    $play = collect($plays)->firstWhere('type', 'publish_new');
    if ($play) {
        app(\App\Services\Compass\Imoveis\ImoveisCompassActions::class)->createPost($imobiliaria, $adminImob, $play,
            ['title' => 'T2 renovado em Benfica', 'publish_date' => now()->addDay()->toDateString(), 'networks' => ['instagram'], 'format' => 'Carrossel']);
    }
    $sig = \App\Models\RadarSignal::with('topic')->where('company_id', $stand->id)->where('status', 'new')->first();
    if ($sig) {
        app(\App\Services\Radar\RadarSignalActions::class)->react($stand, $pessoas['admin_stand'], $sig,
            ['title' => 'Está a subir: ' . $sig->topic->title, 'publish_date' => now()->addDay()->toDateString(), 'networks' => ['instagram'], 'format' => 'Carrossel']);
    }
}

echo "===EMPRESAS===" . json_encode(['restaurante' => $restaurante->id, 'stand' => $stand->id, 'imobiliaria' => $imobiliaria->id]) . PHP_EOL;
echo "===SESSOES===" . json_encode($sessoes, JSON_UNESCAPED_UNICODE) . PHP_EOL;
