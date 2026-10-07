import { useCallback, useEffect, useMemo, useState } from "react";
import { Card, CardBody, CardHeader, Container, Spinner } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import ReasonButton from "Components/Common/ReasonButton";
import XSelect from "pages/Editorial/XSelect";
import { useIsMobile } from "../../hooks/useIsMobile";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { confirmFamilyCategories, getFamilyCategories, suggestFamilyCategories } from "helpers/laravel_helper";
import { FamilyCategoriesData, FamilyCategoryRow } from "common/models/pingwin.model";

/**
 * XPLENDOR — F1-3 do marketing da restauração: categoria de marketing de cada família do
 * PingWin com vendas (documents/PINGWIN-F1-DESENHO.md §5). As regras e a IA só sugerem;
 * uma categoria só conta depois de uma pessoa da equipa a confirmar. Escolha com o
 * react-select (XSelect), tema claro e escuro, e lista empilhada no telemóvel.
 */

const pct = (v: number) => `${v.toLocaleString("pt-PT", { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
const eur = (cents: number) => (cents / 100).toLocaleString("pt-PT", { style: "currency", currency: "EUR" });
const fmtDate = (d: string | null) => (d ? new Date(d).toLocaleDateString("pt-PT", { day: "2-digit", month: "short", year: "numeric" }) : "");

export default function CategoriasFamiliasPage() {
    document.title = "Categorias das famílias | Restauração | Xplendor";
    const companyId = useWorkingCompanyId();
    const isMobile = useIsMobile();

    const [data, setData] = useState<FamilyCategoriesData | null>(null);
    const [choices, setChoices] = useState<Record<string, string | null>>({});
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [suggesting, setSuggesting] = useState(false);

    const apply = (d: FamilyCategoriesData) => {
        setData(d);
        const next: Record<string, string | null> = {};
        d.families.forEach((f) => { next[f.family_pingwin_id] = f.category ?? f.suggested_category ?? null; });
        setChoices(next);
    };

    const load = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        try {
            const res: any = await getFamilyCategories(companyId);
            apply(res?.data);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível carregar as categorias.");
        } finally {
            setLoading(false);
        }
    }, [companyId]);

    useEffect(() => { load(); }, [load]);

    const labelOf = useMemo(() => {
        const m: Record<string, string> = {};
        (data?.categories ?? []).forEach((c) => { m[c.value] = c.label; });
        return m;
    }, [data]);

    const families = data?.families ?? [];
    const changed = families.filter((f) => choices[f.family_pingwin_id] && choices[f.family_pingwin_id] !== f.category);
    const withoutSuggestion = families.filter((f) => !f.category && !f.suggested_category).length;
    const canManage = !!data?.can_manage;

    const confirm = async () => {
        if (!companyId || changed.length === 0) return;
        setSaving(true);
        try {
            const res: any = await confirmFamilyCategories(companyId, changed.map((f) => ({ family_pingwin_id: f.family_pingwin_id, category: choices[f.family_pingwin_id] as string })));
            apply(res?.data);
            toast.success(res?.message ?? "Categorias confirmadas.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível confirmar as categorias.");
        } finally {
            setSaving(false);
        }
    };

    const askAi = async () => {
        if (!companyId) return;
        setSuggesting(true);
        try {
            const res: any = await suggestFamilyCategories(companyId);
            apply(res?.data);
            toast.info(res?.message ?? "Sugestões recebidas.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível pedir sugestões à IA.");
        } finally {
            setSuggesting(false);
        }
    };

    const confirmReason = !canManage
        ? "Só o administrador da empresa ou da agência gestora confirma as categorias."
        : changed.length === 0 ? "Não há categorias por confirmar nem alterações." : null;
    const aiReason = !canManage
        ? "Só o administrador da empresa ou da agência gestora pede sugestões."
        : withoutSuggestion === 0 ? "Todas as famílias já têm sugestão ou categoria." : null;

    const suggestionCell = (f: FamilyCategoryRow) => f.suggested_category ? (
        <span className="d-inline-flex align-items-center gap-1 flex-wrap">
            <span>{labelOf[f.suggested_category] ?? f.suggested_category}</span>
            <span className={`badge ${f.suggested_by === "ai" ? "bg-info-subtle text-info" : "bg-light text-muted"}`}>{f.suggested_by === "ai" ? "IA" : "Regras"}</span>
        </span>
    ) : <span className="text-muted">Sem sugestão</span>;

    const statusCell = (f: FamilyCategoryRow) => {
        if (f.category && choices[f.family_pingwin_id] === f.category) {
            return (
                <span className="d-inline-flex flex-column">
                    <span><span className="badge bg-success-subtle text-success">Confirmada</span></span>
                    {f.confirmed_by && <span className="text-muted fs-12">{f.confirmed_by}, {fmtDate(f.confirmed_at)}</span>}
                </span>
            );
        }
        if (choices[f.family_pingwin_id]) return <span className="badge bg-warning-subtle text-warning">Por confirmar</span>;
        return <span className="badge bg-light text-muted">Sem categoria</span>;
    };

    const select = (f: FamilyCategoryRow) => (
        <XSelect
            small
            ariaLabel={`Categoria de ${f.family}`}
            options={data?.categories ?? []}
            value={choices[f.family_pingwin_id] ?? null}
            onChange={(v) => setChoices((c) => ({ ...c, [f.family_pingwin_id]: v }))}
            disabled={!canManage}
            placeholder="Escolher categoria"
            width={isMobile ? "100%" : 220}
        />
    );

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader
                    title="Categorias das famílias"
                    breadcrumbs={[{ label: "Restauração" }]}
                    description="Categoria de marketing de cada família do PingWin com vendas. As regras e a IA só sugerem: uma categoria só conta depois de confirmada pela equipa."
                    actions={
                        <div className="d-flex flex-wrap gap-2">
                            <ReasonButton color="outline-primary" onClick={askAi} reason={suggesting ? null : aiReason} disabled={suggesting}>
                                {suggesting ? <><Spinner size="sm" className="me-1" /> A pedir sugestões</> : <><i className="ri-sparkling-line me-1" /> Pedir sugestões à IA</>}
                            </ReasonButton>
                            <ReasonButton color="primary" onClick={confirm} reason={saving ? null : confirmReason} disabled={saving}>
                                {saving ? <><Spinner size="sm" className="me-1" /> A confirmar</> : <><i className="ri-check-line me-1" /> {changed.length === 1 ? "Confirmar 1 categoria" : `Confirmar ${changed.length} categorias`}</>}
                            </ReasonButton>
                        </div>
                    }
                />

                <Card className="mb-3">
                    <CardHeader className="d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <h5 className="card-title mb-0">
                            Famílias com vendas {loading && <Spinner size="sm" className="ms-1" />}
                        </h5>
                        {data && (
                            <span className={`badge ${data.pending > 0 ? "bg-warning-subtle text-warning" : "bg-success-subtle text-success"}`}>
                                {data.pending > 0 ? `${data.pending} por confirmar` : "Todas confirmadas"}
                            </span>
                        )}
                    </CardHeader>
                    <CardBody>
                        {data && !canManage && (
                            <p className="text-muted fs-13 mb-3">
                                <i className="ri-lock-line me-1" />Pode consultar as categorias; só o administrador da empresa ou da agência gestora as confirma.
                            </p>
                        )}
                        {!loading && families.length === 0 ? (
                            <div className="text-center text-muted py-4">
                                Ainda não há vendas por artigo. As famílias aparecem depois da primeira leitura das vendas por artigo do PingWin.
                            </div>
                        ) : isMobile ? (
                            <div className="d-flex flex-column gap-3">
                                {families.map((f) => (
                                    <div key={f.family_pingwin_id} className="border rounded p-3" style={{ borderColor: "var(--vz-border-color)" }}>
                                        <div className="d-flex justify-content-between align-items-start gap-2 mb-1">
                                            <span className="fw-semibold text-body" style={{ wordBreak: "break-word" }}>{f.family}</span>
                                            <span className="text-muted fs-12 text-nowrap">{pct(f.share_pct)}</span>
                                        </div>
                                        <div className="text-muted fs-12 mb-2" style={{ wordBreak: "break-word" }}>{f.family_path}</div>
                                        <div className="fs-13 mb-2">Sugestão: {suggestionCell(f)}</div>
                                        <div className="mb-2">{select(f)}</div>
                                        <div>{statusCell(f)}</div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="table-responsive">
                                <table className="table table-sm align-middle mb-0">
                                    <thead className="table-light">
                                        <tr>
                                            <th>Família</th>
                                            <th className="text-end">Peso (90 dias)</th>
                                            <th>Sugestão</th>
                                            <th>Categoria</th>
                                            <th>Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {families.map((f) => (
                                            <tr key={f.family_pingwin_id}>
                                                <td>
                                                    <div className="fw-semibold text-body">{f.family}</div>
                                                    <div className="text-muted fs-12">{f.family_path}</div>
                                                </td>
                                                <td className="text-end text-nowrap">
                                                    <div>{pct(f.share_pct)}</div>
                                                    <div className="text-muted fs-12">{eur(f.net_cents_90d)}</div>
                                                </td>
                                                <td>{suggestionCell(f)}</td>
                                                <td>{select(f)}</td>
                                                <td>{statusCell(f)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardBody>
                </Card>
            </Container>
        </div>
    );
}
