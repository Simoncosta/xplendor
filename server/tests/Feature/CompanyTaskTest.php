<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * XPLENDOR — Tarefas internas do cliente (Kanban do stand). Cobre CRUD, mover
 * (status + ordem), partilha pela EMPRESA (todos os utilizadores da empresa vêem
 * o mesmo quadro), o responsável ter de ser da empresa, e a tenancy (2 camadas).
 */
class CompanyTaskTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Company $other;
    private User $user;      // utilizador da empresa A
    private User $mate;      // colega da mesma empresa A
    private User $stranger;  // utilizador da empresa B

    protected function setUp(): void
    {
        parent::setUp();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Test Plan', 'price' => 0, 'car_limit' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->company = Company::create(['nipc' => '500000500', 'fiscal_name' => 'Stand A Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->other = Company::create(['nipc' => '500000501', 'fiscal_name' => 'Stand B Lda', 'plan_id' => $planId, 'subscription_status' => 'active']);
        $this->user = User::factory()->create(['company_id' => $this->company->id, 'role' => 'admin']);
        $this->mate = User::factory()->create(['company_id' => $this->company->id, 'role' => 'user']);
        $this->stranger = User::factory()->create(['company_id' => $this->other->id, 'role' => 'admin']);
    }

    private function url(?int $id = null, string $suffix = ''): string
    {
        $base = "/api/v1/companies/{$this->company->id}/tasks";
        return $id ? "{$base}/{$id}{$suffix}" : $base;
    }

    private function task(array $extra = []): CompanyTask
    {
        return CompanyTask::create(array_merge([
            'company_id' => $this->company->id,
            'title' => 'Tarefa',
            'status' => 'todo',
            'order' => 0,
            'created_by' => $this->user->id,
        ], $extra));
    }

    public function test_creates_task_in_todo_by_default(): void
    {
        $res = $this->actingAs($this->user, 'sanctum')->postJson($this->url(), [
            'title' => 'Ligar ao cliente X',
            'description' => 'Confirmar entrega.',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.title', 'Ligar ao cliente X')
            ->assertJsonPath('data.status', 'todo');

        $this->assertDatabaseHas('company_tasks', [
            'company_id' => $this->company->id,
            'title' => 'Ligar ao cliente X',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_new_task_goes_to_end_of_column(): void
    {
        $this->task(['status' => 'todo', 'order' => 0]);
        $this->task(['status' => 'todo', 'order' => 1]);

        $res = $this->actingAs($this->user, 'sanctum')->postJson($this->url(), ['title' => 'Terceira']);
        $res->assertStatus(201)->assertJsonPath('data.order', 2);
    }

    public function test_title_is_required(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson($this->url(), ['description' => 'sem título'])
            ->assertStatus(422);
    }

    public function test_board_is_shared_by_company(): void
    {
        // Criado pelo user; o COLEGA da mesma empresa vê a mesma tarefa e pode editá-la.
        $t = $this->task(['title' => 'Partilhada']);

        $this->actingAs($this->mate, 'sanctum')->getJson($this->url())
            ->assertStatus(200)
            ->assertJsonFragment(['title' => 'Partilhada']);

        $this->actingAs($this->mate, 'sanctum')->patchJson($this->url($t->id), ['title' => 'Editada pelo colega'])
            ->assertStatus(200)
            ->assertJsonPath('data.title', 'Editada pelo colega');
    }

    public function test_lists_only_own_company_tasks(): void
    {
        $this->task(['title' => 'Minha']);
        CompanyTask::create(['company_id' => $this->other->id, 'title' => 'Alheia', 'status' => 'todo', 'created_by' => $this->stranger->id]);

        $titles = collect($this->actingAs($this->user, 'sanctum')->getJson($this->url())->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Minha'));
        $this->assertFalse($titles->contains('Alheia'));
    }

    public function test_cannot_access_company_you_do_not_belong_to(): void
    {
        // Camada 1: utilizador de outra empresa na rota da empresa A → 403.
        $this->actingAs($this->stranger, 'sanctum')->getJson($this->url())->assertStatus(403);
    }

    public function test_cannot_touch_other_company_task(): void
    {
        // Camada 2: tarefa da empresa B pela rota da empresa A → 404 (findScoped).
        $alien = CompanyTask::create(['company_id' => $this->other->id, 'title' => 'Alheia', 'status' => 'todo', 'created_by' => $this->stranger->id]);

        $this->actingAs($this->user, 'sanctum')->patchJson($this->url($alien->id), ['title' => 'x'])->assertStatus(404);
        $this->actingAs($this->user, 'sanctum')->deleteJson($this->url($alien->id))->assertStatus(404);
    }

    public function test_move_persists_status_and_order(): void
    {
        $a = $this->task(['title' => 'A', 'status' => 'todo', 'order' => 0]);
        $b = $this->task(['title' => 'B', 'status' => 'todo', 'order' => 1]);

        // Move A para 'doing', à frente (ordem [a]); e reordena a coluna destino.
        $this->actingAs($this->user, 'sanctum')->patchJson($this->url($a->id, '/move'), [
            'status' => 'doing', 'ordered_ids' => [$a->id],
        ])->assertStatus(200)->assertJsonPath('data.status', 'doing');

        $this->assertSame('doing', $a->fresh()->status);
        $this->assertSame(0, $a->fresh()->order);
        // B permanece em todo, intacto.
        $this->assertSame('todo', $b->fresh()->status);
    }

    public function test_move_reorders_destination_column(): void
    {
        $a = $this->task(['title' => 'A', 'status' => 'todo', 'order' => 0]);
        $b = $this->task(['title' => 'B', 'status' => 'todo', 'order' => 1]);
        $c = $this->task(['title' => 'C', 'status' => 'todo', 'order' => 2]);

        // Reordena para C, A, B dentro de 'todo'.
        $this->actingAs($this->user, 'sanctum')->patchJson($this->url($c->id, '/move'), [
            'status' => 'todo', 'ordered_ids' => [$c->id, $a->id, $b->id],
        ])->assertStatus(200);

        $this->assertSame(0, $c->fresh()->order);
        $this->assertSame(1, $a->fresh()->order);
        $this->assertSame(2, $b->fresh()->order);
    }

    public function test_move_rejects_invalid_status(): void
    {
        $t = $this->task();
        $this->actingAs($this->user, 'sanctum')->patchJson($this->url($t->id, '/move'), [
            'status' => 'nonsense',
        ])->assertStatus(422);
    }

    public function test_assignee_must_belong_to_company(): void
    {
        // Responsável da empresa A → OK.
        $this->actingAs($this->user, 'sanctum')->postJson($this->url(), [
            'title' => 'Com responsável', 'assignee_user_id' => $this->mate->id,
        ])->assertStatus(201)->assertJsonPath('data.assignee_user_id', $this->mate->id);

        // Responsável de OUTRA empresa → 422 (não é da empresa).
        $this->actingAs($this->user, 'sanctum')->postJson($this->url(), [
            'title' => 'Responsável alheio', 'assignee_user_id' => $this->stranger->id,
        ])->assertStatus(422);
    }

    public function test_updates_task(): void
    {
        $t = $this->task(['title' => 'Velho']);
        $this->actingAs($this->user, 'sanctum')->patchJson($this->url($t->id), [
            'title' => 'Novo', 'description' => 'atualizada',
        ])->assertStatus(200)->assertJsonPath('data.title', 'Novo');

        $this->assertSame('Novo', $t->fresh()->title);
    }

    public function test_deletes_task(): void
    {
        $t = $this->task();
        $this->actingAs($this->user, 'sanctum')->deleteJson($this->url($t->id))->assertStatus(200);
        $this->assertDatabaseMissing('company_tasks', ['id' => $t->id]);
    }
}
