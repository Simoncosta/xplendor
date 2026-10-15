import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Button, Offcanvas, OffcanvasBody, OffcanvasHeader, Spinner } from "reactstrap";
import { getAuditRun } from "helpers/laravel_helper";
import { AuditRun, AuditRunStep } from "common/models/audit.model";
import { fmtNum } from "./auditFormat";
import "./auditoria.css";

/**
 * XPLENDOR — Auditoria: o progresso de uma auditoria, num painel lateral. Um passo por regra (estado,
 * contagem e tempo); no fim, o resumo (novos, ainda por resolver, resolvidos) e "Ver achados", que
 * abre a página central já filtrada. Pergunta o estado a cada segundo enquanto não acaba.
 */
const FINAL: AuditRun["status"][] = ["done", "failed"];

const fmtMs = (ms?: number) => (ms === undefined ? "" : ms < 1000 ? `${ms} ms` : `${(ms / 1000).toLocaleString("pt-PT", { maximumFractionDigits: 1 })} s`);

function StepRow({ s }: { s: AuditRunStep }) {
    const icon = {
        pending: <i className="ri-checkbox-blank-circle-line text-muted" aria-hidden />,
        running: <Spinner size="sm" />,
        ok: <i className="ri-checkbox-circle-fill text-success" aria-hidden />,
        failed: <i className="ri-close-circle-fill text-danger" aria-hidden />,
        skipped: <i className="ri-subtract-line text-muted" aria-hidden />,
    }[s.status];
    return (
        <li className={`d-flex align-items-start gap-2 py-2 border-bottom ${s.status === "pending" || s.status === "skipped" ? "text-muted" : ""}`} data-rule={s.rule} data-state={s.status}>
            <span className="pt-1" style={{ width: 18 }}>{icon}</span>
            <div className="flex-grow-1" style={{ minWidth: 0 }}>
                <div className="fw-medium">{s.title}</div>
                {s.status === "ok" && (
                    <div className="fs-12 text-muted">
                        {s.found === 0 ? "Nada a assinalar" : `${fmtNum(s.found ?? 0, 0)} ${s.found === 1 ? "achado" : "achados"}`}
                        {!!s.new && <> · <span className="text-danger">{fmtNum(s.new, 0)} {s.new === 1 ? "novo" : "novos"}</span></>}
                        {!!s.resolved && <> · <span className="text-success">{fmtNum(s.resolved, 0)} {s.resolved === 1 ? "resolvido" : "resolvidos"}</span></>}
                    </div>
                )}
                {s.status === "failed" && <div className="fs-12 text-danger" title={s.error}>Esta regra não conseguiu correr. As outras continuam.</div>}
                {s.status === "skipped" && <div className="fs-12">Não se aplica a esta empresa.</div>}
            </div>
            <span className="fs-12 text-muted text-nowrap pt-1">{fmtMs(s.ms)}</span>
        </li>
    );
}

export default function AuditRunPanel({ companyId, run: initial, onClose, onFinished }: {
    companyId: number; run: AuditRun; onClose: () => void; onFinished?: (run: AuditRun) => void;
}) {
    const navigate = useNavigate();
    const [run, setRun] = useState(initial);
    const final = FINAL.includes(run.status);

    useEffect(() => { setRun(initial); }, [initial]);
    useEffect(() => {
        if (final) { onFinished?.(run); return; }
        const t = setTimeout(async () => {
            try {
                const res: any = await getAuditRun(companyId, run.id);
                if (res?.data?.run) setRun(res.data.run);
            } catch { /* tenta outra vez no próximo segundo */ }
        }, 1000);
        return () => clearTimeout(t);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [run, companyId, final]);

    const done = run.steps.filter((s) => ["ok", "failed", "skipped"].includes(s.status)).length;
    const viewFindings = () => {
        onClose();
        navigate(`/auditoria?area=${run.area}&rule=${encodeURIComponent(run.rules.join(","))}`);
    };

    return (
        <Offcanvas isOpen toggle={onClose} direction="end" className="xp-audit-run" data-testid="audit-run-panel">
            <OffcanvasHeader toggle={onClose}>
                Auditoria
                <div className="fs-12 text-muted fw-normal">{final ? "Terminada" : run.status === "queued" ? "Vai começar dentro de momentos" : `A verificar: ${done} de ${run.steps.length} regras`}</div>
            </OffcanvasHeader>
            <OffcanvasBody>
                {!final && (
                    <div className="progress mb-3" style={{ height: 6 }} role="progressbar" aria-valuenow={done} aria-valuemin={0} aria-valuemax={run.steps.length}>
                        <div className="progress-bar" style={{ width: `${run.steps.length ? (done / run.steps.length) * 100 : 0}%` }} />
                    </div>
                )}
                <ol className="list-unstyled mb-3" aria-live="polite" data-testid="audit-steps">
                    {run.steps.map((s) => <StepRow key={s.rule} s={s} />)}
                </ol>
                {final && (
                    <div data-testid="audit-run-summary">
                        <div className="d-flex gap-2 mb-3">
                            <div className="flex-fill border rounded p-2 text-center"><div className="fs-20 fw-semibold text-danger">{fmtNum(run.new_count, 0)}</div><div className="fs-12 text-muted">novos</div></div>
                            <div className="flex-fill border rounded p-2 text-center"><div className="fs-20 fw-semibold">{fmtNum(run.open_count, 0)}</div><div className="fs-12 text-muted">por resolver</div></div>
                            <div className="flex-fill border rounded p-2 text-center"><div className="fs-20 fw-semibold text-success">{fmtNum(run.resolved_count, 0)}</div><div className="fs-12 text-muted">resolvidos</div></div>
                        </div>
                        {run.error && <div className="alert alert-warning py-2 fs-13">{run.error}</div>}
                        <Button type="button" color="primary" className="w-100" onClick={viewFindings} data-testid="audit-view-findings">
                            <i className="ri-list-check-2 me-1" aria-hidden />Ver achados
                        </Button>
                    </div>
                )}
            </OffcanvasBody>
        </Offcanvas>
    );
}
