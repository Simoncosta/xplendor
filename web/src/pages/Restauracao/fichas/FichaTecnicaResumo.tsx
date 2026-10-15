import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Button, Row, Col } from "reactstrap";
import Skeleton from "Components/Common/Skeleton";
import ReasonButton from "Components/Common/ReasonButton";
import { useCan } from "contexts/ModulesContext";
import { getRecipe } from "helpers/laravel_helper";
import { RecipeDetail } from "common/models/recipe.model";
import { DiffBadge, Money, StateBadge, fmtDate, fmtPct } from "./recipeFormat";
import { WarningTip } from "./warningInfo";

const STATE_LABEL = { com_ficha: "Com ficha", sem_ficha: "Sem ficha", sem_custo: "Com ficha mas sem custo", loja_sem_ficha: "Loja sem ficha em vigor" } as const;

/**
 * Fatias 1 e 4 — o resumo da ficha técnica no separador "Ficha técnica" do artigo: estado, versão em
 * vigor, lojas, custo, food cost, margem e avisos, com "Editar ficha" (ou "Criar ficha") e "Ver
 * ficha técnica". Os euros só para quem vê as Finanças. Fica dentro do formulário do artigo: todos
 * os botões são type="button".
 */
export default function FichaTecnicaResumo({ companyId, productId, refreshKey = 0, editing = false, onEdit }: {
    companyId: number | null; productId: string; refreshKey?: number; editing?: boolean; onEdit?: () => void;
}) {
    const navigate = useNavigate();
    const [canEdit, editReason] = useCan("restauracao.editar");
    const [data, setData] = useState<RecipeDetail | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!companyId || !productId) return;
        let alive = true;
        getRecipe(companyId, productId)
            .then((res: any) => { if (alive) { setData(res?.data ?? null); setFailed(false); } })
            .catch(() => { if (alive) setFailed(true); });
        return () => { alive = false; };
    }, [companyId, productId, refreshKey]);

    if (failed) return <p className="text-muted fs-13 mb-0">Não foi possível ler a ficha técnica deste artigo.</p>;
    if (!data) return <Skeleton variant="lines" lines={3} label="A carregar a ficha técnica…" />;

    const hidden = data.financial?.visible === false;
    const v = data.versions.find((x) => x.in_force) ?? null;
    const hasBom = data.versions.length > 0;
    const warnings = v?.warnings ?? [];

    return (
        <div data-testid="ficha-resumo">
            <div className="d-flex flex-wrap align-items-center gap-2 mb-3">
                <StateBadge state={data.state} label={STATE_LABEL[data.state]} />
                {data.flags.map((f) => <span key={f} className="badge bg-warning-subtle text-warning">{f === "rendimento_por_confirmar" ? "Rendimento por confirmar" : "Fator por confirmar"}</span>)}
                <div className="ms-auto d-flex gap-2">
                    {onEdit && !editing && (
                        <ReasonButton type="button" size="sm" color={hasBom ? "outline-primary" : "primary"} onClick={onEdit}
                            reason={canEdit ? null : (editReason ?? "Precisa da permissão de editar os artigos da restauração.")}>
                            <i className={`${hasBom ? "ri-edit-line" : "ri-add-line"} me-1`} aria-hidden />{hasBom ? "Editar ficha" : "Criar ficha"}
                        </ReasonButton>
                    )}
                    {hasBom && (
                        <Button type="button" size="sm" color="outline-primary" onClick={() => navigate(`/restauracao/fichas-tecnicas/${productId}`)}>
                            <i className="ri-file-list-3-line me-1" aria-hidden />Ver ficha técnica
                        </Button>
                    )}
                </div>
            </div>

            {!hasBom ? (
                <p className="text-muted fs-13 mb-0">Este artigo não tem ficha técnica na PingWin.</p>
            ) : (
                <>
                    <Row className="g-3">
                        <Col xs={6} md>
                            <div className="text-muted fs-12">Versão em vigor</div>
                            <div className="fw-semibold">{v ? `${fmtDate(v.valid_from)}, ${v.line_count} ${v.line_count === 1 ? "componente" : "componentes"}` : "Nenhuma"}</div>
                        </Col>
                        <Col xs={6} md>
                            <div className="text-muted fs-12">Custo XPLENDOR</div>
                            <div className="fw-semibold">
                                <Money value={v?.cost_eur ?? null} hidden={hidden && !!v && v.line_count > 0} seed={1} />
                                {v && !v.cost_complete && v.line_count > 0 && (v.cost_eur === null && !hidden
                                    ? <span className="badge bg-danger-subtle text-danger fs-11 ms-1">sem custo</span>
                                    : <span className="badge bg-warning-subtle text-warning fs-11 ms-1">parcial</span>)}
                            </div>
                        </Col>
                        <Col xs={6} md>
                            <div className="text-muted fs-12">Custo PingWin</div>
                            <div className="fw-semibold"><Money value={v?.pingwin_cost_eur ?? null} hidden={hidden && !!v && v.line_count > 0} seed={2} /> {v && v.diff_pct !== null && <DiffBadge pct={v.diff_pct} />}</div>
                        </Col>
                        <Col xs={6} md>
                            <div className="text-muted fs-12">Food cost</div>
                            <div className="fw-semibold">{fmtPct(v?.food_cost_pct ?? null)}</div>
                        </Col>
                        <Col xs={6} md>
                            <div className="text-muted fs-12">Margem</div>
                            <div className="fw-semibold"><Money value={v?.margin_eur ?? null} hidden={hidden && !!v && v.line_count > 0 && (data.article.qty_90d ?? 0) > 0} seed={3} /></div>
                        </Col>
                    </Row>
                    {v && (
                        <div className="mt-3 d-flex flex-wrap align-items-center gap-1">
                            <span className="text-muted fs-12 me-1">Lojas:</span>
                            {v.stores.map((s) => (
                                <span key={s.store_id} className={`badge ${s.active ? (s.in_force ? "bg-success-subtle text-success" : "bg-info-subtle text-info") : "bg-secondary-subtle text-secondary text-decoration-line-through"}`}>{s.name ?? s.code}</span>
                            ))}
                        </div>
                    )}
                    {warnings.length > 0 && (
                        <div className="mt-3">
                            <div className="fw-semibold fs-13 mb-1"><i className="ri-alert-line text-warning me-1" aria-hidden />Avisos ({warnings.length})</div>
                            <ul className="fs-13 mb-0 ps-3">
                                {warnings.slice(0, 5).map((w, i) => (
                                    <li key={i} className={w.level === "info" ? "text-muted" : undefined}>{w.line ? <><strong>{w.line}</strong>: </> : null}{w.text} <WarningTip type={w.type} /></li>
                                ))}
                                {warnings.length > 5 && <li className="text-muted">e mais {warnings.length - 5} em "Ver ficha técnica".</li>}
                            </ul>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
