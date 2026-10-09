import { useEffect, useRef, useState } from "react";
import { UncontrolledTooltip } from "reactstrap";
import { toast } from "react-toastify";
import { getSupplierCcStatus, refreshSupplierCc } from "helpers/laravel_helper";
import { SupplierCcStatus, SupplierCcSyncState } from "common/models/supplierCc.model";

/**
 * XPLENDOR — Conta Corrente de Fornecedor (S2): utilitários partilhados pela visão geral e
 * pela página do fornecedor. Sinal em toda a página: positivo = em dívida ao fornecedor.
 */

/** Cêntimos → "1.234,56 €" (pt-PT, 2 casas). */
export const eur = (cents: number | null | undefined) =>
    cents === null || cents === undefined ? "—" : (cents / 100).toLocaleString("pt-PT", { style: "currency", currency: "EUR", minimumFractionDigits: 2 });

/** "2026-10-05" → "05/10/2026". */
export const fmtDay = (d?: string | null) => (d ? d.split("-").reverse().join("/") : "—");
export const fmtInstant = (d?: string | null) => (d ? new Date(d).toLocaleString("pt-PT", { dateStyle: "short", timeStyle: "short" }) : "—");

export const isoDay = (d: Date) => {
    const p = (n: number) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
};

export const DIFF_TOOLTIP = "Faturas-recibo de compra já pagas que o PingWin conta como dívida";

const STATUS: Record<SupplierCcStatus, { label: string; cls: string; tip?: string }> = {
    ok: { label: "OK", cls: "bg-success-subtle text-success" },
    not_reconciled: { label: "Não reconciliado", cls: "bg-warning-subtle text-warning", tip: "O saldo do PingWin não bate com a soma dos documentos lidos." },
    failed: { label: "Falhou", cls: "bg-danger-subtle text-danger" },
    syncing: { label: "A atualizar", cls: "bg-info-subtle text-info" },
    never: { label: "Nunca sincronizado", cls: "bg-secondary-subtle text-secondary" },
};

export function CcStatusBadge({ status, error, id }: { status: SupplierCcStatus; error?: string | null; id: string }) {
    const s = STATUS[status] ?? STATUS.never;
    const tip = status === "failed" ? (error || "Erro desconhecido") : s.tip;
    return (
        <>
            <span id={id} className={`badge ${s.cls}`} style={tip ? { cursor: "help" } : undefined}>{s.label}</span>
            {tip && <UncontrolledTooltip target={id}>{tip}</UncontrolledTooltip>}
        </>
    );
}

/**
 * "Atualizar" um fornecedor: pede a sync (202) e faz polling do sync_status até "ok" ou
 * "failed" (máx. 5 min). No fim chama onDone(supplierId, estado) — quem chama recarrega.
 */
export function useSupplierCcRefresh(companyId: number | null, onDone: (supplierId: number, state: SupplierCcSyncState | null) => void) {
    const [busy, setBusy] = useState<Record<number, boolean>>({});
    const timers = useRef<Record<number, ReturnType<typeof setInterval>>>({});
    const onDoneRef = useRef(onDone);
    onDoneRef.current = onDone;

    useEffect(() => () => { Object.values(timers.current).forEach(clearInterval); }, []);

    const finish = (supplierId: number, state: SupplierCcSyncState | null) => {
        clearInterval(timers.current[supplierId]);
        delete timers.current[supplierId];
        setBusy((b) => ({ ...b, [supplierId]: false }));
        if (state?.sync_status === "ok") {
            toast.success(state.reconciled ? "Conta corrente atualizada." : "Atualizada, mas o saldo do PingWin não bate com os documentos.");
        } else {
            toast.error(`A atualização falhou${state?.last_error ? `: ${state.last_error}` : "."}`);
        }
        onDoneRef.current(supplierId, state);
    };

    const refresh = async (supplierId: number) => {
        if (!companyId || busy[supplierId]) return;
        setBusy((b) => ({ ...b, [supplierId]: true }));
        try {
            await refreshSupplierCc(companyId, supplierId);
        } catch (err: any) {
            setBusy((b) => ({ ...b, [supplierId]: false }));
            toast.error(err?.message ?? "Não foi possível pedir a atualização.");
            return;
        }
        const started = Date.now();
        timers.current[supplierId] = setInterval(async () => {
            try {
                const res: any = await getSupplierCcStatus(companyId, supplierId);
                const st: SupplierCcSyncState | null = res?.data ?? null;
                if (st?.sync_status === "ok" || st?.sync_status === "failed") {
                    finish(supplierId, st);
                } else if (Date.now() - started > 5 * 60 * 1000) {
                    finish(supplierId, { sync_status: "failed", reconciled: false, last_error: "sem resposta em 5 minutos", synced_at: null });
                }
            } catch { /* tenta no próximo intervalo */ }
        }, 2000);
    };

    return { busy, refresh };
}
