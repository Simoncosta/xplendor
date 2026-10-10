<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Car;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pré-deploy, ponto 6: o league/commonmark só é usado pelos e-mails em Markdown do Laravel.
 * Depois de o atualizar, todos esses e-mails continuam a ser gerados em HTML.
 */
class MarkdownMailRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_markdown_mail_still_renders(): void
    {
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 9, 'created_at' => now(), 'updated_at' => now()]);
        $company = Company::create(['nipc' => '500800002', 'fiscal_name' => 'Correio Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $car = Car::factory()->create(['company_id' => $company->id]);

        $classes = collect(glob(app_path('Mail/*.php')))
            ->filter(fn ($file) => str_contains((string) file_get_contents($file), 'markdown:'))
            ->map(fn ($file) => 'App\\Mail\\' . basename($file, '.php'))
            ->values();
        $this->assertGreaterThanOrEqual(10, $classes->count());

        // Os que recebem linhas estruturadas.
        $special = [
            'App\\Mail\\ContentReviewDigestMail' => [[['company' => 'Correio Lda', 'title' => 'Publicação aprovada', 'message' => 'Sai amanhã.', 'severity' => 'high']]],
        ];

        foreach ($classes as $class) {
            if (isset($special[$class])) {
                $this->assertRendered($class, new $class(...$special[$class]));

                continue;
            }
            $args = [];
            foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $param) {
                $type = $param->getType()?->getName();
                $args[] = match (true) {
                    $param->isDefaultValueAvailable() => $param->getDefaultValue(),
                    $type === Car::class => $car,
                    $type === 'int' => 1,
                    $type === 'float' => 10.5,
                    $type === 'array' => ['Primeira linha', 'Segunda linha'],
                    $type === 'bool' => true,
                    default => 'Texto de exemplo',
                };
            }
            $this->assertRendered($class, new $class(...$args));
        }
    }

    private function assertRendered(string $class, Mailable $mail): void
    {
        $html = $mail->render();

        $this->assertStringContainsString('<html', $html, $class);
        $this->assertStringNotContainsString('**', $html, "{$class}: Markdown por converter");
    }

    public function test_tables_and_raw_html_follow_the_converter_rules(): void
    {
        $html = (string) Markdown::parse("| Artigo | Valor |\n|---|---|\n| Plano | 10 € |\n\n**negrito**");

        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<td>Plano</td>', $html);
        $this->assertStringContainsString('<strong>negrito</strong>', $html);
    }
}
