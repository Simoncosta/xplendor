# Xplendor Market Scraper

Serviço Python para recolher dados de mercado do Standvirtual e alimentar
a camada de **Price Intelligence** da Xplendor.

---

## Arquitetura

```
scraper/
├── main.py          # Entry point — orquestra tudo
├── config.py        # Configuração via .env
├── scraper.py       # Extrai __NEXT_DATA__ do Standvirtual
├── normalizer.py    # Normaliza para schema Xplendor
├── sender.py        # POST batches para Laravel
└── requirements.txt

laravel/
├── create_car_market_snapshots_table.php   # Migration
├── MarketSnapshotController.php            # Controller
├── MarketSnapshotService.php               # Serviço
└── routes_snippet.php                      # Rota a adicionar
```

---

## Setup Python

```bash
cd scraper
python -m venv venv
source venv/bin/activate        # Mac: venv\Scripts\activate
pip install -r requirements.txt
cp .env.example .env
# editar .env com os teus valores
```

---

## Configuração (.env)

| Variável | Descrição | Default |
|---|---|---|
| `LARAVEL_API_URL` | URL da API Laravel | `http://localhost:8000` |
| `LARAVEL_API_TOKEN` | Token Bearer de autenticação | — |
| `SCRAPER_MAX_PAGES` | Máximo de páginas a scraper | `50` |
| `SCRAPER_DELAY` | Delay entre requests (segundos) | `2.5` |
| `SCRAPER_PAGE_DELAY` | Delay entre páginas | `4.0` |
| `SCRAPER_BATCH_SIZE` | Anúncios por batch | `25` |

---

## Executar

```bash
python main.py
```

Output esperado:
```
2024-03-11 10:00:00 [INFO] main: Xplendor Market Scraper — início: ...
2024-03-11 10:00:02 [INFO] scraper: Total de páginas a scraper: 47
2024-03-11 10:00:04 [INFO] scraper: Página 1/47: 32 anúncios
2024-03-11 10:00:04 [INFO] sender: Batch enviado: 25 snapshots. Total: 25
...
2024-03-11 10:12:00 [INFO] main: Enviados Laravel: 1432 | Falhados: 3
```

## Executar com filtro
As mudanças principais ficaram em config.py (line 7), main.py (line 35) e scraper.py (line 32). Agora existe um SearchFilters dedicado que converte os filtros para os query params do Standvirtual, incluindo:

brand
model
year_from / year_to
fuel
gearbox
price_from / price_to
A CLI também ficou extensível e continua compatível com o uso atual:

python3 main.py preview 5
python3 main.py preview 5 --brand BMW --model "Série 3"
python3 main.py preview 10 --year-from 2020 --year-to 2023 --price-from 15000 --price-to 30000
Na parte da região, mantive o region no snapshot e acrescentei fallback para city quando o Standvirtual não trouxer location.region.name, em scraper.py (line 217). Aproveitei também para blindar o parser HTML com fallback de lxml para html.parser, para o scraper não falhar por falta desse parser.

Atualizei ainda a documentação de uso em README.md (line 207).

Validação feita:

python3 -m py_compile scraper/main.py scraper/config.py scraper/scraper.py scraper/normalizer.py scraper/sender.py
cd scraper && python3 main.py preview 2 --brand BMW --fuel diesel --price-from 10000 --price-to 20000
cd scraper && python3 main.py preview 2 --brand BMW --year-from 2020 --year-to 2021

---

## Setup Laravel

### 1. Copiar ficheiros

```bash
# Migration
cp laravel/create_car_market_snapshots_table.php \
   database/migrations/$(date +%Y_%m_%d)_000000_create_car_market_snapshots_table.php

# Controller
cp laravel/MarketSnapshotController.php \
   app/Http/Controllers/Api/Market/MarketSnapshotController.php

# Service
cp laravel/MarketSnapshotService.php \
   app/Services/Market/MarketSnapshotService.php
```

### 2. Criar Model

```bash
php artisan make:model CarMarketSnapshot
```

```php
// app/Models/CarMarketSnapshot.php
protected $fillable = [
    'external_id', 'source', 'brand', 'model', 'year',
    'title', 'url', 'category', 'price', 'price_currency',
    'km', 'fuel', 'gearbox', 'power_hp', 'color', 'doors', 'scraped_at',
];

protected $casts = [
    'year'     => 'integer',
    'price'    => 'integer',
    'km'       => 'integer',
    'power_hp' => 'integer',
    'doors'    => 'integer',
    'scraped_at' => 'datetime',
];
```

### 3. Rota (routes/api.php)

```php
// Protegida por middleware de token de scraper
Route::middleware('scraper.token')->group(function () {
    Route::post('/market/snapshots', [MarketSnapshotController::class, 'store']);
});
```

### 4. Middleware de autenticação do scraper

Criar `app/Http/Middleware/ScraperTokenMiddleware.php`:

```php
public function handle(Request $request, Closure $next): Response
{
    $token = $request->bearerToken();
    if ($token !== config('services.scraper.token')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }
    return $next($request);
}
```

Adicionar em `config/services.php`:
```php
'scraper' => [
    'token' => env('SCRAPER_API_TOKEN'),
],
```

### 5. Migrar

```bash
php artisan migrate
```

---

## Agendar execução (cron)

Para correr diariamente às 3h da manhã:

```bash
# crontab -e
0 3 * * * cd /path/to/scraper && /path/to/venv/bin/python main.py >> /var/log/xplendor-scraper.log 2>&1
```

Ou via Laravel Scheduler em `app/Console/Kernel.php`:
```php
$schedule->command('scraper:run')->dailyAt('03:00');
```

---

## Notas importantes

### Sobre o `__NEXT_DATA__`
O Standvirtual usa Next.js. Os dados de listagem estão embutidos num `<script id="__NEXT_DATA__">` na página HTML.
Este método é muito mais estável do que parsear HTML direto porque:
- Não depende de classes CSS
- Não depende de estrutura de DOM
- Devolve JSON estruturado diretamente

### Rate limiting
- Delays configuráveis no `.env`
- User-Agent rotativo automático
- Retry automático em 429/503

### Path do `__NEXT_DATA__` pode mudar
Se o Standvirtual fizer deploy com estrutura diferente, verificar no `scraper.py`
o método `_parse_listings_from_next_data` e ajustar os paths de acesso.

---

## Próximos passos (Price Intelligence)

Com dados em `car_market_snapshots`, podes construir:

```sql
-- Preço mediano por marca/modelo/ano
SELECT brand, model, year,
       PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY price) AS median_price,
       PERCENTILE_CONT(0.25) WITHIN GROUP (ORDER BY price) AS p25,
       PERCENTILE_CONT(0.75) WITHIN GROUP (ORDER BY price) AS p75,
       COUNT(*) AS sample_size
FROM car_market_snapshots
WHERE scraped_at > NOW() - INTERVAL 30 DAY
GROUP BY brand, model, year;
```

Isto alimenta a tabela `car_market_benchmarks` e o **Price Positioning** de cada viatura do stand.

Como executar com limit 5
```
python3 main.py preview 5
```

Exemplos com filtros
```
python3 main.py preview 5 --brand BMW --model "Série 3"
python3 main.py preview 10 --fuel diesel --gearbox automática
python3 main.py preview 10 --year-from 2020 --year-to 2023 --price-from 15000 --price-to 30000
python3 main.py --brand Mercedes-Benz --price-from 20000
```

Filtros suportados
- `--brand`
- `--model`
- `--year-from`
- `--year-to`
- `--fuel`
- `--gearbox`
- `--price-from`
- `--price-to`
