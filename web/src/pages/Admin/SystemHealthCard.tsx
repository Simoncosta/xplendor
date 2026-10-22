import { useEffect, useState } from "react";
import { Card, CardBody } from "reactstrap";
import { getAdminSystemHealth } from "helpers/laravel_helper";

/**
 * Saúde do sistema (só o root): base de dados, Redis, antivírus, disco e os sinais de vida do
 * scheduler e do worker, com o motivo de cada falha e desde quando. É o que o health:watch vigia
 * de 5 em 5 minutos e o que o serviço externo vê em /api/health.
 */

export type HealthCheck = {
    name: string; label: string; ok: boolean; skipped: boolean; detail: string | null;
    last_ok_at: string | null; failing_since: string | null; alerted_at: string | null;
};
type Payload = { healthy: boolean; checks: HealthCheck[] };

const fmt = (iso: string | null): string | null =>
    iso ? new Date(iso).toLocaleString("pt-PT", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" }) : null;

export function checkStatus(c: HealthCheck): { label: string; tone: string } {
    if (c.skipped) return { label: "Não usado", tone: "secondary" };
    return c.ok ? { label: "Bem", tone: "success" } : { label: "Falha", tone: "danger" };
}

export default function SystemHealthCard() {
    const [data, setData] = useState<Payload | null>(null);
    const [error, setError] = useState(false);

    useEffect(() => {
        getAdminSystemHealth().then((r: any) => setData(r?.data ?? null)).catch(() => setError(true));
    }, []);

    return (
        <Card className="mb-3" data-testid="system-health">
            <CardBody>
                <div className="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <h5 className="card-title mb-0 flex-grow-1">Saúde do sistema</h5>
                    {data && (
                        <span className={`badge bg-${data.healthy ? "success" : "danger"}-subtle text-${data.healthy ? "success" : "danger"} fs-12`}>
                            {data.healthy ? "Tudo a funcionar" : "Há falhas"}
                        </span>
                    )}
                </div>
                {error && <p className="text-muted mb-0">Não foi possível ler a saúde do sistema. Tente recarregar a página.</p>}
                {!error && !data && <p className="text-muted mb-0">A verificar…</p>}
                {data && (
                    <ul className="list-unstyled mb-0">
                        {data.checks.map((c) => {
                            const s = checkStatus(c);
                            const since = fmt(c.failing_since);
                            const lastOk = fmt(c.last_ok_at);
                            return (
                                <li key={c.name} className="d-flex flex-wrap align-items-start gap-2 py-2 border-top">
                                    <span className={`badge bg-${s.tone}-subtle text-${s.tone}`} style={{ minWidth: 72 }}>{s.label}</span>
                                    <div className="flex-grow-1">
                                        <div className="fw-medium">{c.label}</div>
                                        {!c.ok && c.detail && <div className="text-danger fs-13">{c.detail}</div>}
                                        <div className="text-muted fs-12">
                                            {since && !c.ok && <>Em falha desde {since}{c.alerted_at ? "; o root foi avisado." : "."} </>}
                                            {c.ok && !c.skipped && lastOk && <>Último sinal bom: {lastOk}.</>}
                                        </div>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </CardBody>
        </Card>
    );
}
