<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guarda do CORS: o browser envia o Origin sem barra no fim e a comparação é exata. Uma origem
 * em config/cors.php com "/" no fim nunca coincide e bloqueia o site todo (foi o que bloqueou a
 * Quebom de 03/07 a 07/10/2026).
 */
class CorsOriginsTest extends TestCase
{
    public function test_no_allowed_origin_ends_with_a_slash_or_has_a_path(): void
    {
        $origins = config('cors.allowed_origins');
        $this->assertNotEmpty($origins);

        foreach ($origins as $origin) {
            $this->assertStringEndsNotWith('/', $origin, "A origem «{$origin}» acaba em /: o browser nunca a envia assim.");
            $parts = parse_url($origin);
            $this->assertIsArray($parts, "Origem inválida: «{$origin}»");
            $this->assertContains($parts['scheme'] ?? null, ['http', 'https'], "Origem sem http(s): «{$origin}»");
            $this->assertNotEmpty($parts['host'] ?? null, "Origem sem domínio: «{$origin}»");
            $this->assertArrayNotHasKey('path', $parts, "A origem «{$origin}» tem um caminho: o Origin é só esquema, domínio e porta.");
        }
    }

    public function test_the_sites_are_allowed_on_the_api(): void
    {
        $this->assertContains('api/*', config('cors.paths'));
        foreach (['https://quebom.pt', 'https://www.quebom.pt', 'https://paautomoveis.pt', 'https://spacedrive.pt'] as $site) {
            $this->assertContains($site, config('cors.allowed_origins'));
        }
    }

    public function test_preflight_from_a_site_gets_its_origin_back(): void
    {
        $this->call('OPTIONS', '/api/public/track', [], [], [], [
            'HTTP_ORIGIN' => 'https://quebom.pt',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ])->assertHeader('Access-Control-Allow-Origin', 'https://quebom.pt');
    }
}
