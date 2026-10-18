<?php

/*
 * Site do consultor imobiliário: a ligação ao site (contentor xplendor-consultor) e as regras.
 * Em dev e nos testes: sem URL de revalidação (nada é enviado) e sem envio à Meta.
 */
return [
    // Base dos URL públicos dos ficheiros (fotografias, vídeo do topo). Vazio = o APP_URL.
    // Preparado para o R2-2 (o domínio próprio das imagens).
    'public_files_base_url' => env('PUBLIC_FILES_BASE_URL', ''),

    // Revalidação das páginas do site, pela rede interna (ex.: http://xplendor-consultor:3000/api/revalidate).
    // Vazio = desligada.
    'revalidate_url' => env('CONSULTOR_REVALIDATE_URL', ''),
    'revalidate_secret' => env('CONSULTOR_REVALIDATE_SECRET', ''),

    // Pré-visualização do site (também em rascunho): o link assinado vale estes minutos. O endereço
    // é https://{host principal}/pre-visualizar; em dev, CONSULTOR_PREVIEW_BASE_URL muda a base
    // (por exemplo, http://simoncosta.localhost:3001).
    'preview_minutes' => (int) env('CONSULTOR_PREVIEW_MINUTES', 60),
    'preview_base_url' => env('CONSULTOR_PREVIEW_BASE_URL', ''),

    // As ligações "Ver na Chave Nova" só aceitam estes hosts, exatamente (sem outros subdomínios), em https.
    'chave_nova_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('CHAVE_NOVA_DOMAINS', 'chavenova.com,www.chavenova.com'))))),

    // Prazo de conservação dos contactos do site (meses), guardado em cada contacto. A limpeza
    // automática fica para depois.
    'contact_retention_months' => (int) env('SITE_CONTACT_RETENTION_MONTHS', 24),

    // API de Conversões da Meta (F6): desligada por omissão em todos os ambientes.
    'meta_capi_enabled' => (bool) env('META_CAPI_ENABLED', false),
];
