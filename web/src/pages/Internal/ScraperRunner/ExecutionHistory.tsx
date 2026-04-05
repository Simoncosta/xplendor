import React, { useCallback, useEffect, useRef, useState } from "react";
import { Badge, Spinner, Table } from "reactstrap";
import { getScraperExecutionsApi } from "helpers/laravel_helper";

interface Execution {
    id: number;
    source: string;
    mode: string;
    status: "pending" | "running" | "success" | "failed";
    total_raw: number;
    total_sent: number;
    total_failed: number;
    duration_seconds: number | null;
    created_at: string;
}

interface ExecutionHistoryProps {
    companyId: number;
    refreshKey: number;
}

const STATUS_BADGE: Record<string, { color: string; label: string }> = {
    pending: { color: "secondary", label: "Pendente" },
    running: { color: "primary", label: "A correr" },
    success: { color: "success", label: "Sucesso" },
    failed: { color: "danger", label: "Falhou" },
};

const ExecutionHistory: React.FC<ExecutionHistoryProps> = ({ companyId, refreshKey }) => {
    const [executions, setExecutions] = useState<Execution[]>([]);
    const [loading, setLoading] = useState(true);
    const autoRefreshRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const load = useCallback(async () => {
        try {
            const res: any = await getScraperExecutionsApi(companyId, { per_page: 20 });
            setExecutions(res.data.data ?? []);
        } catch {
            // silently ignore — not critical
        } finally {
            setLoading(false);
        }
    }, [companyId]);

    // Refresh whenever a new run completes
    useEffect(() => {
        load();
    }, [load, refreshKey]);

    // Auto-refresh while any execution is running
    useEffect(() => {
        const hasRunning = executions.some((e) => e.status === "pending" || e.status === "running");

        if (hasRunning && !autoRefreshRef.current) {
            autoRefreshRef.current = setInterval(load, 5000);
        } else if (!hasRunning && autoRefreshRef.current) {
            clearInterval(autoRefreshRef.current);
            autoRefreshRef.current = null;
        }

        return () => {
            if (autoRefreshRef.current) {
                clearInterval(autoRefreshRef.current);
                autoRefreshRef.current = null;
            }
        };
    }, [executions, load]);

    const formatDate = (iso: string) => {
        const d = new Date(iso);
        return d.toLocaleDateString("pt-PT") + " " + d.toLocaleTimeString("pt-PT", { hour: "2-digit", minute: "2-digit" });
    };

    if (loading) {
        return (
            <div className="text-center py-4">
                <Spinner color="primary" size="sm" />
            </div>
        );
    }

    if (executions.length === 0) {
        return <p className="text-muted text-center py-3 mb-0">Sem execuções registadas.</p>;
    }

    return (
        <div className="table-responsive">
            <Table className="table-borderless table-hover align-middle mb-0" size="sm">
                <thead className="table-light text-muted">
                    <tr>
                        <th>Data/hora</th>
                        <th>Source</th>
                        <th>Mode</th>
                        <th>Status</th>
                        <th className="text-end">Raw</th>
                        <th className="text-end">Enviados</th>
                        <th className="text-end">Falharam</th>
                        <th className="text-end">Duração</th>
                    </tr>
                </thead>
                <tbody>
                    {executions.map((ex) => {
                        const badge = STATUS_BADGE[ex.status] ?? STATUS_BADGE.pending;
                        return (
                            <tr key={ex.id}>
                                <td className="text-nowrap text-muted fs-12">{formatDate(ex.created_at)}</td>
                                <td>{ex.source}</td>
                                <td>
                                    <span className={`badge bg-${ex.mode === "run" ? "soft-success text-success" : "soft-secondary text-secondary"}`}>
                                        {ex.mode === "run" ? "Gravação" : "Pré-visualização"}
                                    </span>
                                </td>
                                <td>
                                    <Badge color={badge.color} className="d-inline-flex align-items-center gap-1">
                                        {ex.status === "running" && <Spinner size="sm" style={{ width: "0.6rem", height: "0.6rem" }} />}
                                        {badge.label}
                                    </Badge>
                                </td>
                                <td className="text-end">{ex.total_raw}</td>
                                <td className="text-end">{ex.total_sent}</td>
                                <td className="text-end">{ex.total_failed > 0 ? <span className="text-danger">{ex.total_failed}</span> : 0}</td>
                                <td className="text-end text-muted fs-12">
                                    {ex.duration_seconds != null ? `${ex.duration_seconds}s` : "—"}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </Table>
        </div>
    );
};

export default ExecutionHistory;
