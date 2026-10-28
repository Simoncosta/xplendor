<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A migração da fatura separada do pagamento: nos pedidos que já existem, o pagamento e a fatura
 * vêm da auditoria do ticket (sem auditoria, o updated_at), os "paid" passam a "approved" com o
 * paid_at, e o down() desfaz tudo (os "paid" voltam e as colunas e as tabelas novas saem).
 */
class SupportTicketBillingMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const FILE = 'migrations/2027_03_25_100000_support_tickets_edits_and_billing.php';

    public function test_up_fills_the_old_tickets_from_the_audit_and_down_undoes_everything(): void
    {
        $plan = DB::table('plans')->insertGetId(['name' => 'P', 'price' => 0, 'car_limit' => 99, 'created_at' => now(), 'updated_at' => now()]);
        $company = Company::create(['nipc' => '500000800', 'fiscal_name' => 'Cliente Lda', 'plan_id' => $plan, 'subscription_status' => 'active']);
        $root = User::factory()->create(['company_id' => $company->id, 'role' => 'root']);
        $client = User::factory()->create(['company_id' => $company->id, 'role' => 'admin']);
        $migration = require database_path(self::FILE);

        $migration->down(); // a tabela como estava antes
        $this->assertFalse(Schema::hasColumn('support_tickets', 'paid_at'));
        $this->assertFalse(Schema::hasTable('support_ticket_removed_files'));

        $row = fn (string $status, ?string $invoice, string $updated) => DB::table('support_tickets')->insertGetId([
            'company_id' => $company->id, 'user_id' => $client->id, 'type' => 'site_change', 'title' => "Pedido {$status}", 'description' => 'D.',
            'status' => 'in_review', 'quote_status' => $status, 'invoice_path' => $invoice, 'quoted_amount' => 50,
            'created_at' => '2027-01-01 10:00:00', 'updated_at' => $updated]);
        $paid = $row('paid', 'support-invoices/a.pdf', '2027-02-10 09:00:00');
        $completed = $row('completed', null, '2027-02-20 18:00:00');
        $approved = $row('approved', null, '2027-02-05 12:00:00');
        DB::table('audits')->insert(['user_type' => User::class, 'user_id' => $root->id, 'event' => 'updated', 'auditable_type' => SupportTicket::class,
            'auditable_id' => $paid, 'old_values' => json_encode(['quote_status' => 'approved']),
            'new_values' => json_encode(['quote_status' => 'paid', 'invoice_path' => 'support-invoices/a.pdf']),
            'created_at' => '2027-02-03 15:30:00', 'updated_at' => '2027-02-03 15:30:00']);

        $migration->up();

        $t = fn (int $id) => DB::table('support_tickets')->find($id);
        // O pago passa a aprovado com o pagamento e a fatura da auditoria (quem e quando).
        $this->assertSame(['approved', '2027-02-03 15:30:00', $root->id, '2027-02-03 15:30:00', $root->id],
            [$t($paid)->quote_status, (string) $t($paid)->paid_at, (int) $t($paid)->paid_by_user_id, (string) $t($paid)->invoiced_at, (int) $t($paid)->invoice_by_user_id]);
        // O concluído sem auditoria fica concluído e pago no updated_at.
        $this->assertSame(['completed', '2027-02-20 18:00:00', null], [$t($completed)->quote_status, (string) $t($completed)->paid_at, $t($completed)->paid_by_user_id]);
        // O aprovado não tinha pagamento: continua sem ele.
        $this->assertSame(['approved', null, null], [$t($approved)->quote_status, $t($approved)->paid_at, $t($approved)->invoiced_at]);
        // Mostra o mesmo de antes: pago (com a fatura) e concluído.
        $this->assertSame(['paid', 'paid', null], [SupportTicket::find($paid)->paymentStatus(), SupportTicket::find($completed)->paymentStatus(), $t($approved)->paid_at]);

        $migration->down();

        $this->assertSame(['paid', 'completed', 'approved'], [$t($paid)->quote_status, $t($completed)->quote_status, $t($approved)->quote_status]);
        foreach (['impersonator_user_id', 'invoiced_at', 'invoice_by_user_id', 'paid_at', 'paid_by_user_id', 'deleted_at', 'deleted_by_user_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('support_tickets', $column), $column);
        }
        foreach (['impersonator_user_id', 'edited_at', 'deleted_at', 'deleted_by_user_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('support_ticket_messages', $column), $column);
        }
        $this->assertFalse(Schema::hasTable('support_ticket_message_versions'));
        $this->assertFalse(Schema::hasTable('support_ticket_removed_files'));
        $this->assertSame('support-invoices/a.pdf', $t($paid)->invoice_path); // a fatura antiga fica onde estava

        $migration->up(); // volta ao esquema atual para o resto da suite
        $this->assertSame('approved', $t($paid)->quote_status);
    }
}
