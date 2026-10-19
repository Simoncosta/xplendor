import { useCallback, useEffect, useState } from "react";
import { Button, Container, Spinner, Table } from "reactstrap";
import { toast } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import Skeleton from "Components/Common/Skeleton";
import { getHabitualExclusions, restoreHabitual } from "helpers/laravel_helper";
import { HabitualExclusion } from "common/models/financeMonth.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { eur } from "./mes/format";

/**
 * XPLENDOR — Finanças › Configuração › Entidades excluídas dos habituais: as que alguém tirou dos
 * "Documentos habituais em falta" ("Não vem este mês" ou "Não é habitual"), com quem, quando e porquê,
 * e "Repor". Só na XPLENDOR.
 */
const monthName = (ym: string) => {
    const [y, m] = ym.split("-").map(Number);
    return new Date(y, m - 1, 1).toLocaleDateString("pt-PT", { month: "long", year: "numeric" });
};
const when = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleString("pt-PT", { dateStyle: "short", timeStyle: "short" }) : "—");

export default function HabituaisExcluidosPage() {
    document.title = "Entidades excluídas dos habituais | Finanças | Xplendor";
    const companyId = useWorkingCompanyId();
    const [rows, setRows] = useState<HabitualExclusion[] | null>(null);
    const [busy, setBusy] = useState<number | null>(null);

    const load = useCallback(async () => {
        if (!companyId) return;
        try {
            const r: any = await getHabitualExclusions(companyId);
            setRows(r?.data?.exclusions ?? []);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível carregar a lista.");
            setRows([]);
        }
    }, [companyId]);
    useEffect(() => { load(); }, [load]);

    const restore = async (e: HabitualExclusion) => {
        setBusy(e.id);
        try {
            const r: any = await restoreHabitual(companyId as number, e.id);
            toast.success(r?.message ?? "Reposto.");
            load();
        } catch (err: any) {
            toast.error(err?.message ?? "Não foi possível repor.");
        } finally {
            setBusy(null);
        }
    };
    const active = (rows ?? []).filter((r) => r.active);
    const history = (rows ?? []).filter((r) => !r.active);
    const table = (list: HabitualExclusion[], withAction: boolean, testid: string) => (
        <div className="table-responsive">
            <Table size="sm" className="fs-13 align-middle mb-0" data-testid={testid}>
                <thead className="table-light text-muted">
                    <tr><th>Entidade</th><th>Exclusão</th><th className="text-end">Valor médio</th><th>Motivo</th><th>Quem e quando</th>{withAction ? <th /> : <th>Reposta</th>}</tr>
                </thead>
                <tbody>
                    {list.map((e) => (
                        <tr key={e.id} data-testid={`exclusion-${e.id}`}>
                            <td className="fw-medium">{e.supplier ?? e.entity_pingwin_id}</td>
                            <td>{e.scope === "mes" ? `Não vem em ${monthName(e.month)}` : `Não é habitual desde ${monthName(e.month)}`}</td>
                            <td className="text-end text-nowrap">{eur(e.avg_cents)}</td>
                            <td>{e.reason ?? <span className="text-muted">—</span>}</td>
                            <td className="text-nowrap">{e.by ?? "—"} · {when(e.at)}</td>
                            {withAction
                                ? <td className="text-end"><Button size="sm" color="outline-primary" onClick={() => restore(e)} disabled={busy !== null}>{busy === e.id ? <Spinner size="sm" /> : "Repor"}</Button></td>
                                : <td className="text-nowrap">{e.removed_by ?? "—"} · {when(e.removed_at)}</td>}
                        </tr>
                    ))}
                </tbody>
            </Table>
        </div>
    );

    return (
        <div className="page-content">
            <Container fluid>
                <PageHeader title="Entidades excluídas dos habituais" breadcrumbs={[{ label: "Finanças" }, { label: "Configuração" }]}
                    info="As entidades que deixaram de contar nos documentos habituais em falta. Repor volta a pô-las na lista (e no resultado estimado) quando faltarem." />
                <PageCard title="Excluídas agora" status={rows ? `${active.length}` : undefined} flush={false} data-testid="exclusions-active">
                    {!rows ? <Skeleton variant="lines" lines={3} /> : active.length === 0 ? <p className="text-muted mb-0">Nenhuma entidade excluída.</p> : table(active, true, "exclusions-table")}
                </PageCard>
                {history.length > 0 && (
                    <PageCard title="Histórico" className="mt-3" flush={false} data-testid="exclusions-history">{table(history, false, "exclusions-history-table")}</PageCard>
                )}
            </Container>
        </div>
    );
}
