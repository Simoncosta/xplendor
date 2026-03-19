### CONEXÃO META ADS

1: https://developers.facebook.com/apps/creation/
2: Acessar meus Apps e criar uma nova app
3: Selecionar as opções
3.1: "Criar e gerenciar anúncios com a API de Marketing" - "Gerenciar mensagens e conteúdo no Instagram" - "Gerenciar tua Página"
4: A qual portfólio empresarial você quer conectar o app? Seleciona a empresa
5: Vai avançando até "Criar aplicativo"
6: No menu lateral vai a Settings → Basic
6.1: Copia o App ID e o App Secret
6.2: Cola no .env da VPS:
6.3: META_APP_ID=123456789
6.4: META_APP_SECRET=abcdef123456
6.5: docker exec -it xplendor-php php artisan config:cache

Passo 4 — Gerar o token de acesso

Vai ao Graph API Explorer — developers.facebook.com/tools/explorer
No canto superior direito selecciona a tua app Xplendor
Clica "Generate Access Token"
Autoriza as permissões ads_read
Copia o token gerado — este é o short-lived token (válido 1 hora)

Sim, dá perfeitamente! O Meta aceita localhost para desenvolvimento.
Adiciona os dois no painel:
http://localhost:3000/oauth/meta/callback
https://xplendor.tech/oauth/meta/callback
Como adicionar:

developers.facebook.com → a tua app Xplendor
Menu lateral → Facebook Login for Business → Configurações
Campo "URIs de redirecionamento do OAuth válidos"
Adiciona as duas linhas
Guarda


------------

1. php artisan migrate  (3 novas tabelas)
2. Ir a /settings/integrations
3. Clicar "Conectar com Facebook" → popup abre 
4. Autorizar → introduzir account_id → popup fecha
5. Ver status "Conectado ✅" na página
6. Ir ao CarAnalytics de um carro
7. Na tab Métricas, no fim → "Mapear campanha"
8. Seleccionar o adset do Mercedes
9. Confirmar → mapeamento guardado
10. Testar job manualmente:
    docker compose exec -it php php artisan tinker
    >>> dispatch(new \App\Jobs\FetchMetaAdsMetricsJob());
11. Verificar logs:
    docker logs -f xplendor-worker
12. Verificar dados em car_performance_metrics:
    >>> \App\Models\CarPerformanceMetric::where('car_id', 4)->get(['car_id','channel','total_spend','total_impressions','total_clicks']);