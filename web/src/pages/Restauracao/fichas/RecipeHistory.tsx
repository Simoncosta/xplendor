import { useEffect, useState } from "react";
import { Button } from "reactstrap";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import { getRecipeHistory } from "helpers/laravel_helper";
import { RecipeHistoryRow } from "common/models/recipe.model";
import { fmtDate, fmtQty } from "./recipeFormat";

/**
 * Fatia 4 — "Histórico de alterações" da ficha técnica: cada gravação no PingWin (quem, quando,
 * operação, versão), o antes e o depois, e o resultado confirmado por releitura.
 */
const OPERATION: Record<RecipeHistoryRow["operation"], string> = {
    criar: "Criou a ficha", nova_versao: "Nova versão", corrigir: "Corrigiu a versão", apagar_versao: "Apagou a versão", apagar_ficha: "Apagou a ficha",
};
const STATUS: Record<RecipeHistoryRow["status"], { label: string; cls: string }> = {
    queued: { label: "na fila", cls: "bg-light text-body" },
    running: { label: "a gravar", cls: "bg-info-subtle text-info" },
    ok: { label: "confirmado", cls: "bg-success-subtle text-success" },
    mismatch: { label: "releitura não bate", cls: "bg-warning-subtle text-warning" },
    conflict: { label: "não gravou: a ficha mudou", cls: "bg-warning-subtle text-warning" },
    failed: { label: "falhou", cls: "bg-danger-subtle text-danger" },
};
const fmtDateTime = (d: string | null) => (d ? new Date(d).toLocaleString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "—");

function Lines({ lines, names }: { lines: { component_id: string; unit_id: string; qnt: number; waste: number; recovery?: number; description?: string | null }[]; names: RecipeHistoryRow["names"] }) {
    if (!lines.length) return <span className="text-muted">sem linhas</span>;
    return (
        <ul className="mb-0 ps-3">
            {lines.map((l, i) => (
                <li key={i}>{names.components[l.component_id] ?? l.description ?? l.component_id}: {fmtQty(l.qnt, 4)} {names.units[l.unit_id] ?? l.unit_id}{l.waste ? `, desperdício ${fmtQty(l.waste, 2)}%` : ""}</li>
            ))}
        </ul>
    );
}

export default function RecipeHistory({ companyId, productId, refreshKey }: { companyId: number | null; productId: string; refreshKey: number }) {
    const [rows, setRows] = useState<RecipeHistoryRow[] | null>(null);
    const [open, setOpen] = useState<number | null>(null);

    useEffect(() => {
        if (!companyId) return;
        let alive = true;
        getRecipeHistory(companyId, productId).then((res: any) => { if (alive) setRows(res?.data?.history ?? []); }).catch(() => { if (alive) setRows([]); });
        return () => { alive = false; };
    }, [companyId, productId, refreshKey]);

    const columns: DTColumn<RecipeHistoryRow>[] = [
        { id: "when", header: "Quando", value: (r) => r.created_at, cell: (r) => fmtDateTime(r.created_at), nowrap: true, mobile: "subtitle" },
        { id: "who", header: "Quem", value: (r) => r.user, cell: (r) => r.user ?? "—" },
        { id: "op", header: "Operação", value: (r) => OPERATION[r.operation], mobile: "title", cell: (r) => OPERATION[r.operation] },
        { id: "version", header: "Versão", value: (r) => r.valid_from, cell: (r) => (r.valid_from ? fmtDate(r.valid_from) : r.bom_id ?? "—"), nowrap: true },
        {
            id: "status", header: "Resultado no PingWin", value: (r) => r.status,
            cell: (r) => (
                <div>
                    <span className={`badge ${STATUS[r.status].cls}`}>{STATUS[r.status].label}</span>
                    {r.error && r.status !== "ok" && <div className="fs-12 text-muted">{r.error}</div>}
                </div>
            ),
        },
    ];
    const cols = useDataColumns("restauracao.ficha-tecnica.historico", columns);
    const sel = rows?.find((r) => r.id === open) ?? null;
    const beforeVersion = sel?.before?.versions?.find((v) => v.id === sel.bom_id) ?? null;

    return (
        <PageCard title="Histórico de alterações" info="Cada gravação desta ficha no PingWin feita pela XPLENDOR: quem, quando, o antes e o depois, e o resultado confirmado por releitura." loading={rows === null} skeleton={rows === null ? "table" : undefined}>
            <DataTable
                columns={cols}
                data={rows ?? []}
                rowKey={(r) => r.id}
                pageSize={10}
                caption="Histórico de alterações da ficha técnica"
                empty={{ message: "Ainda não houve gravações desta ficha pela XPLENDOR." }}
                rowActions={(r) => (
                    <Button type="button" size="sm" color="outline-primary" onClick={() => setOpen((o) => (o === r.id ? null : r.id))} aria-expanded={open === r.id}>
                        {open === r.id ? "Esconder" : "Antes e depois"}
                    </Button>
                )}
            />
            {sel && (
                <div className="border-top px-3 py-3 fs-13" data-testid="history-detail">
                    <div className="row g-3">
                        <div className="col-md-6">
                            <div className="fw-semibold mb-1">Antes {beforeVersion ? `(versão de ${fmtDate(beforeVersion.valid_from)})` : ""}</div>
                            {sel.operation === "criar" ? <span className="text-muted">sem ficha</span>
                                : beforeVersion ? <Lines lines={beforeVersion.lines} names={sel.names} />
                                    : <span className="text-muted">{(sel.before?.versions ?? []).length} {(sel.before?.versions ?? []).length === 1 ? "versão" : "versões"}: {(sel.before?.versions ?? []).map((v) => fmtDate(v.valid_from)).join(", ")}</span>}
                        </div>
                        <div className="col-md-6">
                            <div className="fw-semibold mb-1">Depois</div>
                            {sel.after?.deleted_versions ? <span>{sel.after.deleted_versions.length} {sel.after.deleted_versions.length === 1 ? "versão apagada" : "versões apagadas"}</span>
                                : <>
                                    {sel.after?.valid_from && <div className="text-muted mb-1">A valer a partir de {fmtDate(sel.after.valid_from)}</div>}
                                    <Lines lines={sel.after?.lines ?? []} names={sel.names} />
                                </>}
                        </div>
                    </div>
                    {!!sel.result?.diff?.length && (
                        <div className="alert alert-warning py-2 mt-3 mb-0"><strong>Diferenças na releitura:</strong><ul className="mb-0">{sel.result.diff.map((d, i) => <li key={i}>{d}</li>)}</ul></div>
                    )}
                </div>
            )}
        </PageCard>
    );
}
