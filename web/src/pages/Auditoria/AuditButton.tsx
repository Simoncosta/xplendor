import { useState } from "react";
import { Button, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { startAuditRun } from "helpers/laravel_helper";
import { AuditArea, AuditOrigin, AuditRun } from "common/models/audit.model";
import AuditRunPanel from "./AuditRunPanel";

/**
 * XPLENDOR — o botão "Auditoria" dos ecrãs (Artigos, Famílias, Faturas, Fornecedores, Fichas
 * técnicas): corre só as regras dessa área e desse ecrã e abre o painel do pipeline. Se já houver
 * uma corrida destas a correr, mostra essa.
 */
export default function AuditButton({ companyId, area, origin, label = "Auditoria", size = "sm", color = "outline-primary", onFinished }: {
    companyId: number | null; area: AuditArea; origin: AuditOrigin; label?: string; size?: "sm" | "md";
    color?: string; onFinished?: (run: AuditRun) => void;
}) {
    const [run, setRun] = useState<AuditRun | null>(null);
    const [busy, setBusy] = useState(false);

    const start = async () => {
        if (!companyId) return;
        setBusy(true);
        try {
            const res: any = await startAuditRun(companyId, area, origin);
            setRun(res?.data?.run ?? null);
        } catch (e: any) {
            if (e?.__status === 409 && e?.errors?.run) setRun(e.errors.run);
            else toast.error(e?.message ?? "Não foi possível começar a auditoria. Tente de novo dentro de momentos.");
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <Button type="button" size={size === "sm" ? "sm" : undefined} color={color} onClick={start} disabled={busy || !companyId} data-testid="audit-button">
                {busy ? <Spinner size="sm" className="me-1" /> : <i className="ri-shield-check-line me-1" aria-hidden />}{label}
            </Button>
            {run && companyId && <AuditRunPanel companyId={companyId} run={run} onClose={() => setRun(null)} onFinished={onFinished} />}
        </>
    );
}
