<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\Collaborator;
use App\Models\Company;
use App\Models\CompanyDepartment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * API pública por token de empresa: equipa (só ativos, autorizados e marcados para o
 * site; contacto pessoal só com autorização; nunca dados de conta) e blog (só artigos
 * publicados da empresa do token, campos públicos). Testes de fuga entre empresas.
 */
class PublicTeamAndBlogApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;
    private Company $b;

    protected function setUp(): void
    {
        parent::setUp();
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $this->a = Company::create(['nipc' => '500018001', 'fiscal_name' => 'Quebom', 'plan_id' => $planId, 'subscription_status' => 'active', 'public_api_token' => 'tok-a']);
        $this->b = Company::create(['nipc' => '500018002', 'fiscal_name' => 'Outra', 'plan_id' => $planId, 'subscription_status' => 'active', 'public_api_token' => 'tok-b']);
    }

    private function team(string $token)
    {
        return $this->getJson("/api/public/team?token={$token}");
    }

    private function publishable(Company $c, array $extra = []): Collaborator
    {
        return Collaborator::create(array_merge([
            'company_id' => $c->id, 'name' => 'Ana Martins', 'role_title' => 'Comercial', 'bio' => 'Olá.',
            'show_on_site' => true, 'publish_consent_at' => now(), 'active' => true,
        ], $extra));
    }

    // ── Equipa ───────────────────────────────────────────────────────────────

    public function test_token_is_required(): void
    {
        $this->getJson('/api/public/team')->assertStatus(401);
        $this->team('inventado')->assertStatus(401);
    }

    public function test_only_active_authorised_and_marked_members_are_listed(): void
    {
        $dept = CompanyDepartment::create(['company_id' => $this->a->id, 'name' => 'Oficina', 'phone' => '22 998 4130', 'phone_type' => 'fixed', 'whatsapp' => '916 644 780']);
        $this->publishable($this->a, ['name' => 'Rui Silva', 'department_id' => $dept->id]);
        $this->publishable($this->a, ['name' => 'Sem autorização', 'publish_consent_at' => null]);
        $this->publishable($this->a, ['name' => 'Escondido', 'show_on_site' => false]);
        $this->publishable($this->a, ['name' => 'Inativo', 'active' => false]);
        $apagado = $this->publishable($this->a, ['name' => 'Apagado']);
        $apagado->delete();

        $res = $this->team('tok-a')->assertOk();
        $this->assertTrue($res->headers->hasCacheControlDirective('public'));
        $this->assertSame('300', $res->headers->getCacheControlDirective('max-age'));
        $groups = $res->json('data.departments');
        $names = collect($groups)->flatMap(fn ($g) => array_column($g['members'], 'name'))->all();
        $this->assertSame(['Rui Silva'], $names);
        $this->assertSame('Oficina', $groups[0]['name']);
        $this->assertSame(['whatsapp' => '351916644780', 'phone' => '22 998 4130', 'phone_tel' => '+351229984130', 'phone_type' => 'fixed', 'email' => null], $groups[0]['contact']);
        $this->assertSame('department', $groups[0]['members'][0]['contact_source']);
        $this->assertSame('Rui', $groups[0]['members'][0]['first_name']);
    }

    public function test_personal_contact_only_with_its_own_consent(): void
    {
        $dept = CompanyDepartment::create(['company_id' => $this->a->id, 'name' => 'Comercial', 'whatsapp' => '916644780']);
        $this->publishable($this->a, ['name' => 'Ana', 'department_id' => $dept->id, 'whatsapp' => '912345678', 'email' => 'ana@quebom.pt', 'contact_mode' => 'personal']);
        $this->publishable($this->a, ['name' => 'Bea', 'department_id' => $dept->id, 'whatsapp' => '919999999', 'email' => 'bea@quebom.pt', 'contact_mode' => 'personal', 'personal_contact_consent_at' => now()]);

        $members = collect($this->team('tok-a')->json('data.departments.0.members'))->keyBy('name');
        $this->assertSame(['department', '351916644780'], [$members['Ana']['contact_source'], $members['Ana']['contact']['whatsapp']]);
        $this->assertSame(['personal', '351919999999', 'bea@quebom.pt'], [$members['Bea']['contact_source'], $members['Bea']['contact']['whatsapp'], $members['Bea']['contact']['email']]);
        $this->assertStringNotContainsString('ana@quebom.pt', json_encode($this->team('tok-a')->json()));
    }

    public function test_never_exposes_account_or_internal_fields(): void
    {
        $user = User::factory()->create(['company_id' => $this->a->id, 'role' => 'user', 'email' => 'conta.interna@quebom.pt']);
        $this->publishable($this->a, ['user_id' => $user->id, 'email' => 'pessoal@quebom.pt']);

        $json = json_encode($this->team('tok-a')->json());
        foreach (['conta.interna@quebom.pt', 'pessoal@quebom.pt', 'user_id', 'company_id', 'publish_consent', '"role"', 'deactivated', 'sort'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, $forbidden);
        }
        $member = $this->team('tok-a')->json('data.departments.0.members.0');
        $this->assertSame(['bio', 'contact', 'contact_source', 'first_name', 'id', 'name', 'photo_url', 'role_title'], collect($member)->keys()->sort()->values()->all());
    }

    public function test_no_leak_between_companies(): void
    {
        $this->publishable($this->a, ['name' => 'Da Quebom']);
        CompanyDepartment::create(['company_id' => $this->a->id, 'name' => 'Oficina da Quebom', 'phone' => '229984130', 'phone_type' => 'fixed']);
        $this->publishable($this->b, ['name' => 'Da Outra']);

        $fromB = json_encode($this->team('tok-b')->json());
        $this->assertStringContainsString('Da Outra', $fromB);
        $this->assertStringNotContainsString('Da Quebom', $fromB);
        $this->assertStringNotContainsString('Oficina da Quebom', $fromB);
        $this->assertStringNotContainsString('229984130', $fromB);
    }

    public function test_photo_url_is_a_storage_path_like_the_other_public_images(): void
    {
        $this->publishable($this->a, ['photo_path' => "company_{$this->a->id}/team/x.webp"]);
        $this->assertSame("/storage/company_{$this->a->id}/team/x.webp", $this->team('tok-a')->json('data.departments.0.members.0.photo_url'));
    }

    // ── Blog ─────────────────────────────────────────────────────────────────

    private function blog(Company $c, string $slug, string $status = 'published'): void
    {
        // Inserção direta: o modelo gera o slug a partir do título e o teste precisa de slugs fixos.
        $userId = User::factory()->create(['company_id' => $c->id, 'role' => 'admin'])->id;
        DB::table('blogs')->insert(['company_id' => $c->id, 'user_id' => $userId, 'title' => "Artigo {$slug}", 'slug' => $slug, 'content' => 'Texto',
            'status' => $status, 'published_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_blog_detail_of_another_company_is_not_readable(): void
    {
        $this->blog($this->a, 'da-quebom');
        $this->blog($this->b, 'da-outra');

        $this->getJson('/api/public/blogs/da-quebom?token=tok-b')->assertStatus(404);
        $this->getJson('/api/public/blogs/da-quebom?token=tok-a')->assertOk()->assertJsonPath('data.slug', 'da-quebom');
    }

    public function test_blog_list_is_scoped_published_and_allow_listed(): void
    {
        $this->blog($this->a, 'publicado');
        $this->blog($this->a, 'rascunho', 'draft');
        $this->blog($this->b, 'da-outra');

        $list = $this->getJson('/api/public/blogs?token=tok-a')->assertOk()->json('data');
        $this->assertSame(['publicado'], array_column($list, 'slug'));
        $this->assertArrayNotHasKey('company_id', $list[0]);
        $this->assertArrayNotHasKey('user_id', $list[0]);
        $this->assertArrayNotHasKey('status', $list[0]);
        $this->getJson('/api/public/blogs/rascunho?token=tok-a')->assertStatus(404);

        $paged = $this->getJson('/api/public/blogs?token=tok-a&perPage=10')->assertOk()->json('data');
        $this->assertSame(['publicado'], array_column($paged['data'], 'slug'));
        $this->assertArrayNotHasKey('company_id', $paged['data'][0]);
    }

    public function test_cors_allows_the_quebom_origin(): void
    {
        $this->getJson('/api/public/team?token=tok-a', ['Origin' => 'https://quebom.pt'])
            ->assertHeader('Access-Control-Allow-Origin', 'https://quebom.pt');
        $this->getJson('/api/public/team?token=tok-a', ['Origin' => 'https://www.quebom.pt'])
            ->assertHeader('Access-Control-Allow-Origin', 'https://www.quebom.pt');
    }
}
