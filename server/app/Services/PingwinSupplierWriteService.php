<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\SupplierNifConflict;
use App\Jobs\WritePingwinSupplierJob;
use App\Models\PingwinSupplier;
use App\Models\PingwinSupplierWrite;
use Illuminate\Support\Facades\Log;

/**
 * XPLENDOR — Fornecedores: ESCRITA no PingWin (FN). Pedidos assíncronos (escrita registada +
 * job no worker + polling) e execução no worker. O espelho (suppliers, source=pingwin) só é
 * tocado DEPOIS da confirmação por releitura (persisted / voided_confirmed).
 *
 * Guarda de NIF em três camadas: (1) espelho, no pedido; (2) PingWin vivo (browserdataset
 * por TAX_NUMBER), no worker, antes de abrir o form; (3) tax_number_count do servidor, entre
 * o MERGE e o SAVE (no Python). O PingWin, por si, aceita NIF duplicado.
 */
class PingwinSupplierWriteService
{
    /** Campos do formulário (chaves do PingWin) aceites numa escrita. */
    public const FIELDS = ['description', 'fiscalname', 'tax_number', 'paycond_id', 'address',
        'postalcode', 'postalcode_description', 'country_id', 'obs'];

    public function __construct(private readonly PingwinService $pingwin) {}

    /** NIF português: 9 dígitos e dígito de controlo (módulo 11). */
    public static function isValidPortugueseNif(?string $nif): bool
    {
        $nif = trim((string) $nif);
        if (! preg_match('/^\d{9}$/', $nif)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $sum += (int) $nif[$i] * (9 - $i);
        }
        $check = 11 - ($sum % 11);
        $check = $check >= 10 ? 0 : $check;

        return $check === (int) $nif[8];
    }

    /** Fornecedor ATIVO do espelho com este NIF (opcionalmente excluindo um). */
    public function mirrorByNif(int $companyId, string $nif, ?int $exceptId = null): ?PingwinSupplier
    {
        $nif = trim($nif);
        if ($nif === '') {
            return null;
        }

        return PingwinSupplier::where('company_id', $companyId)->where('is_active', true)
            ->where('tax_number', $nif)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->orderBy('id')->first();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pedidos (no request; despacham o job)
    // ─────────────────────────────────────────────────────────────────────────

    /** @throws SupplierNifConflict */
    public function requestCreate(int $companyId, ?int $userId, array $fields, bool $allowDuplicateNif = false): PingwinSupplierWrite
    {
        $fields = self::clean($fields);
        if (! $allowDuplicateNif && ($existing = $this->mirrorByNif($companyId, (string) ($fields['tax_number'] ?? '')))) {
            throw new SupplierNifConflict($existing);
        }

        return $this->queue($companyId, $userId, 'criar', null, $fields, $allowDuplicateNif);
    }

    /** @throws SupplierNifConflict */
    public function requestUpdate(int $companyId, ?int $userId, PingwinSupplier $s, array $fields, bool $allowDuplicateNif = false): PingwinSupplierWrite
    {
        $fields = self::clean($fields);
        $nif = $fields['tax_number'] ?? null;
        if (! $allowDuplicateNif && $nif !== null && $nif !== (string) $s->tax_number
            && ($existing = $this->mirrorByNif($companyId, $nif, $s->id))) {
            throw new SupplierNifConflict($existing);
        }

        return $this->queue($companyId, $userId, 'editar', $s, $fields, $allowDuplicateNif);
    }

    public function requestVoid(int $companyId, ?int $userId, PingwinSupplier $s): PingwinSupplierWrite
    {
        return $this->queue($companyId, $userId, 'anular', $s, [], false);
    }

    /**
     * F2 (sem UI própria): devolve o fornecedor ATIVO do espelho com o NIF; se não houver,
     * pede a criação (assíncrona) e devolve a escrita para acompanhar. O worker volta a
     * procurar o NIF no PingWin vivo antes de criar (status "duplicado" se já existir lá).
     *
     * @return array{supplier: ?PingwinSupplier, write: ?PingwinSupplierWrite}
     */
    public function findOrCreateByNif(int $companyId, ?int $userId, array $fields): array
    {
        $nif = trim((string) ($fields['tax_number'] ?? ''));
        if ($nif !== '' && ($existing = $this->mirrorByNif($companyId, $nif))) {
            return ['supplier' => $existing, 'write' => null];
        }

        return ['supplier' => null, 'write' => $this->requestCreate($companyId, $userId, $fields)];
    }

    private function queue(int $companyId, ?int $userId, string $action, ?PingwinSupplier $s, array $fields, bool $allowDuplicateNif): PingwinSupplierWrite
    {
        $write = PingwinSupplierWrite::create([
            'company_id'          => $companyId,
            'user_id'             => $userId,
            'action'              => $action,
            'supplier_id'         => $s?->id,
            'pingwin_id'          => $s?->pingwin_id,
            'fields'              => $fields,
            'allow_duplicate_nif' => $allowDuplicateNif,
            'status'              => PingwinSupplierWrite::PENDING,
        ]);
        WritePingwinSupplierJob::dispatch($companyId, $write->id);

        return $write;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Execução (no worker)
    // ─────────────────────────────────────────────────────────────────────────

    public function execute(int $writeId): PingwinSupplierWrite
    {
        $write = PingwinSupplierWrite::findOrFail($writeId);
        if ($write->status !== PingwinSupplierWrite::PENDING) {
            return $write; // idempotente: nunca repete uma escrita
        }
        $companyId = $write->company_id;
        $fields = (array) ($write->fields ?? []);

        try {
            if ($write->action === 'anular') {
                $res = $this->pingwin->voidSupplier($companyId, (string) $write->pingwin_id);
                if (! ($res['voided_confirmed'] ?? false)) {
                    return $this->fail($write, $res['error'] ?? 'Anulação não confirmada (o fornecedor continua ativo no PingWin).', $res);
                }
                PingwinSupplier::where('company_id', $companyId)->where('pingwin_id', $write->pingwin_id)
                    ->update(['is_active' => false, 'synced_at' => now()]);

                return $this->done($write, $res);
            }

            // Guarda viva de NIF (criar, ou editar com NIF novo).
            $nif = trim((string) ($fields['tax_number'] ?? ''));
            $nifChanged = $write->action === 'criar' || ($nif !== '' && $nif !== (string) $write->supplier?->tax_number);
            if (! $write->allow_duplicate_nif && $nif !== '' && $nifChanged) {
                $live = $this->pingwin->findSuppliersByNif($companyId, $nif);
                $others = array_values(array_filter($live['active'], fn ($r) => (string) ($r['id'] ?? '') !== (string) $write->pingwin_id));
                if ($others !== []) {
                    return $this->duplicate($write, "Já existe no PingWin um fornecedor com o NIF {$nif}.", ['existing' => $others]);
                }
            }

            $res = $write->action === 'criar'
                ? $this->pingwin->createSupplier($companyId, $fields, $write->allow_duplicate_nif)
                : $this->pingwin->updateSupplier($companyId, (string) $write->pingwin_id, $fields, $write->allow_duplicate_nif);

            if ($res['duplicate_nif'] ?? false) {
                return $this->duplicate($write, (string) ($res['error'] ?? 'NIF duplicado.'), self::slim($res));
            }
            if (! ($res['persisted'] ?? false)) {
                return $this->fail($write, (string) ($res['error'] ?? 'A releitura não confirmou os valores gravados.'), self::slim($res));
            }

            $supplier = $this->applyConfirm($companyId, (array) ($res['confirm'] ?? []));
            $write->supplier_id = $supplier->id;
            $write->pingwin_id = $supplier->pingwin_id;

            return $this->done($write, self::slim($res));
        } catch (\Throwable $e) {
            return $this->fail($write, $e->getMessage(), null);
        }
    }

    /** Espelho a partir da releitura viva (upsert por pingwin_id; nunca toca nos manuais). */
    public function applyConfirm(int $companyId, array $c): PingwinSupplier
    {
        $s = PingwinSupplier::firstOrNew(['company_id' => $companyId, 'pingwin_id' => (string) $c['id']]);
        $s->fill([
            'code'               => $c['code'] ?? $s->code,
            'name'               => $c['description'] ?? $s->name,
            'fiscal_name'        => self::nullable($c['fiscalname'] ?? null),
            'tax_number'         => self::nullable($c['tax_number'] ?? null),
            'address'            => self::nullable($c['address'] ?? null),
            'postal_code'        => self::nullable($c['postalcode'] ?? null),
            'city'               => self::nullable($c['postalcode_description'] ?? null),
            'country_pingwin_id' => self::nullable($c['country_id'] ?? null),
            'paycond_pingwin_id' => self::nullable($c['paycond_id'] ?? null),
            'obs'                => self::nullable($c['obs'] ?? null),
            'is_active'          => (int) ($c['deleted'] ?? 0) === 0 && (int) ($c['supplier_deleted'] ?? 0) === 0,
            'synced_at'          => now(),
        ]);
        $s->save();

        return $s;
    }

    private function done(PingwinSupplierWrite $w, ?array $result): PingwinSupplierWrite
    {
        $w->fill(['status' => PingwinSupplierWrite::OK, 'error_message' => null, 'result' => $result, 'finished_at' => now()])->save();
        Log::info('[PingWin Fornecedor] escrita confirmada', ['write_id' => $w->id, 'action' => $w->action, 'pingwin_id' => $w->pingwin_id]);

        return $w;
    }

    private function fail(PingwinSupplierWrite $w, string $error, ?array $result): PingwinSupplierWrite
    {
        $w->fill(['status' => PingwinSupplierWrite::ERROR, 'error_message' => mb_substr($error, 0, 1000), 'result' => $result, 'finished_at' => now()])->save();
        Log::warning('[PingWin Fornecedor] escrita falhou', ['write_id' => $w->id, 'action' => $w->action, 'error' => $error]);

        return $w;
    }

    private function duplicate(PingwinSupplierWrite $w, string $error, ?array $result): PingwinSupplierWrite
    {
        $w->fill(['status' => PingwinSupplierWrite::DUPLICATE, 'error_message' => $error, 'result' => $result, 'finished_at' => now()])->save();

        return $w;
    }

    /** Resultado sem o payload completo (guarda-se o essencial para auditoria). */
    private static function slim(array $res): array
    {
        $out = array_intersect_key($res, array_flip(['ok', 'persisted', 'pingwin_id', 'code', 'checks', 'confirm', 'error', 'duplicate_nif']));
        if (isset($res['capture'])) {
            $out['capture'] = array_diff_key((array) $res['capture'], ['merge_payload' => 1]);
        }

        return $out;
    }

    /** Só as chaves do formulário, com trim. */
    private static function clean(array $fields): array
    {
        $out = [];
        foreach (self::FIELDS as $k) {
            if (array_key_exists($k, $fields)) {
                $out[$k] = $fields[$k] === null ? null : trim((string) $fields[$k]);
            }
        }

        return $out;
    }

    private static function nullable($v): ?string
    {
        $v = trim((string) ($v ?? ''));

        return $v === '' ? null : $v;
    }
}
