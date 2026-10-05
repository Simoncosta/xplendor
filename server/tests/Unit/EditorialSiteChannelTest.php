<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Blog;
use App\Models\Company;
use App\Models\ContentSector;
use App\Models\User;
use App\Services\EditorialAnchorResolver;
use App\Services\EditorialLineService;
use App\Services\EditorialPostService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Linha Editorial, canal "Site" (Ticket 11): a publicação liga-se a um artigo do blog da
 * própria empresa, o formato é fixo ("Artigo") e o estado mostrado vem do artigo.
 */
class EditorialSiteChannelTest extends TestCase
{
    use RefreshDatabase;

    private EditorialLineService $line;
    private EditorialPostService $posts;
    private Company $company;
    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-15 12:00:00', 'Europe/Lisbon'));

        $this->line = new EditorialLineService(new EditorialAnchorResolver());
        $this->posts = new EditorialPostService($this->line);
        $this->company = $this->makeCompany();
        $this->other = $this->makeCompany();
        $this->line->openMonth($this->company, 2026, 12);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function makeCompany(): Company
    {
        $planId = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);

        return Company::create([
            'nipc' => (string) random_int(500000000, 599999999), 'fiscal_name' => 'C Lda', 'plan_id' => $planId,
            'content_sector_id' => ContentSector::where('slug', 'restauracao')->firstOrFail()->id,
        ]);
    }

    private function blog(Company $c, string $status = Blog::IN_REVIEW): Blog
    {
        $user = User::factory()->create(['company_id' => $c->id]);

        return Blog::create(['company_id' => $c->id, 'user_id' => $user->id, 'title' => 'Menu de Natal', 'slug' => 'menu-de-natal-' . $c->id, 'content' => '<p>x</p>', 'status' => $status]);
    }

    private function site(array $over = []): array
    {
        return array_merge(['title' => 'Artigo de Natal', 'publish_date' => '2026-12-20', 'status' => 'rascunho', 'channel' => 'site'], $over);
    }

    public function test_site_post_links_a_blog_and_shows_its_status(): void
    {
        $blog = $this->blog($this->company);

        $cal = $this->posts->createPost($this->company, $this->site(['blog_id' => $blog->id, 'format' => 'Carrossel']));
        $post = $cal['posts'][0];

        $this->assertSame('site', $post['channel']);
        $this->assertSame('Artigo', $post['format'], 'o canal site tem formato fixo');
        $this->assertSame($blog->id, $post['blog_id']);
        $this->assertSame(['id' => $blog->id, 'title' => 'Menu de Natal', 'status' => 'in_review', 'published_at' => null], $post['blog']);
    }

    public function test_blog_of_another_company_is_rejected(): void
    {
        $foreign = $this->blog($this->other);

        $this->expectException(ValidationException::class);
        $this->posts->createPost($this->company, $this->site(['blog_id' => $foreign->id]));
    }

    public function test_social_channels_drop_the_blog_link_and_still_need_a_format(): void
    {
        $blog = $this->blog($this->company);

        $cal = $this->posts->createPost($this->company, $this->site(['channel' => 'instagram', 'format' => 'Reels', 'blog_id' => $blog->id]));
        $this->assertNull($cal['posts'][0]['blog_id']);

        try {
            $this->posts->createPost($this->company, $this->site(['channel' => 'facebook']));
            $this->fail('Sem formato devia falhar.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('format', $e->errors());
        }
    }

    public function test_deleted_blog_unlinks_softly(): void
    {
        $blog = $this->blog($this->company);
        $this->posts->createPost($this->company, $this->site(['blog_id' => $blog->id]));
        $blog->delete();

        $this->assertNull($this->line->calendar($this->company)['posts'][0]['blog']);
    }
}
