<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Mail\AgencyNoticeMail;
use App\Models\User;
use App\Services\AlertService;
use App\Services\Billing\ChargeService;
use App\Support\ExternalCalls;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Avisos da monitorização ao root: no sino da equipa e por email. O email sai DIRETO (sendNow, nunca
 * a fila), porque o worker ou o Redis podem ser justamente o que está em baixo. Os destinatários
 * são os de HEALTH_ALERT_EMAILS e os roots (estes só se a base de dados responder). O email segue a
 * guarda ExternalCalls (no dev fica só o sino).
 */
class HealthNotifier
{
    public function __construct(private readonly AlertService $alerts) {}

    /** @param array<string, array{detail: ?string}> $failures */
    public function down(array $failures): void
    {
        $lines = [];
        foreach ($failures as $name => $r) {
            $lines[] = (SystemHealth::LABELS[$name] ?? $name) . ': ' . ($r['detail'] ?? 'falha.');
        }
        $lines[] = 'Verifique os contentores (docker ps; docker logs do serviço) e corra php artisan health:watch para confirmar.';
        $this->send('XPLENDOR: falha no servidor', 'Falha no servidor', $lines, 'urgent', 'high');
    }

    /** @param string[] $names */
    public function recovered(array $names): void
    {
        $labels = array_map(fn ($n) => SystemHealth::LABELS[$n] ?? $n, $names);
        $this->send('XPLENDOR: servidor recuperado', 'Servidor recuperado',
            ['Voltou a responder: ' . implode(', ', $labels) . '.'], 'opportunity', 'medium');
    }

    /** @return string[] */
    public static function recipients(): array
    {
        $emails = array_filter(array_map(fn ($e) => mb_strtolower(trim($e)), explode(',', (string) config('health.alert_emails'))));
        try {
            $emails = array_merge($emails, User::where('role', 'root')->whereNull('deactivated_at')->whereNotNull('email')
                ->pluck('email')->map(fn ($e) => mb_strtolower((string) $e))->all());
        } catch (\Throwable $e) {
            // Sem base de dados: ficam só os destinatários fixos.
        }

        return array_values(array_unique($emails));
    }

    /** @param string[] $lines */
    private function send(string $subject, string $title, array $lines, string $type, string $severity): void
    {
        try {
            if ($team = ChargeService::teamCompanyId()) {
                $this->alerts->createSystemAlert($team, $type, $title, mb_substr(implode(' ', $lines), 0, 900), $severity, '/admin/espaco');
            }
        } catch (\Throwable $e) {
            // Sem base de dados não há sino; segue o email.
        }
        if (! ExternalCalls::allowed()) {
            return;
        }
        $emails = self::recipients();
        if ($emails === []) {
            Log::warning('[Saúde] Sem destinatários para o aviso', ['title' => $title]);

            return;
        }
        try {
            // sendNow: o AgencyNoticeMail é ShouldQueue e o send() iria para a fila.
            Mail::to($emails)->sendNow(new AgencyNoticeMail($subject, $title, $lines, 'Abrir a XPLENDOR',
                rtrim((string) config('app.frontend_url'), '/') . '/admin/espaco'));
        } catch (\Throwable $e) {
            Log::error('[Saúde] Falha ao enviar o aviso por email', ['title' => $title, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }
}
