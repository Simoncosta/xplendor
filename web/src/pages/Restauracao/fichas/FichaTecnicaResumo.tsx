import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Button, Row, Col } from "reactstrap";
import PageCard from "Components/Common/PageCard";
import Skeleton from "Components/Common/Skeleton";
import { getRecipe } from "helpers/laravel_helper";
import { RecipeDetail } from "common/models/recipe.model";
import { DiffBadge, Money, StateBadge, fmtDate, fmtPct } from "./recipeFormat";

const STATE_LABEL = { com_ficha: "Com ficha", sem_ficha: "Sem ficha", sem_custo: "Com ficha mas sem custo", loja_sem_ficha: "Loja sem ficha em vigor" } as const;

/**
 * Fatia 1 — o resumo da ficha técnica no ecrã do artigo (Artigos): estado, versão em vigor, custo,
 * food cost e margem, com "Ver ficha técnica". Só leitura; os euros só para quem vê as Finanças.
 */
export default function FichaTecnicaResumo({ companyId, productId }: { companyId: number | null; productId: string }) {
    const navigate = useNavigate();
    const [data, setData] = useState<RecipeDetail | null>(null);
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!companyId || !productId) return;
        let alive = true;
        setLoading(true);
        getRecipe(companyId, productId)
            .then((res: any) => { if (alive) { setData(res?.data ?? null); setFailed(false); } })
            .catch(() => { if (alive) setFailed(true); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [companyId, productId]);

    if (failed) return null;
    const hidden = data?.financial?.visible === false;
    const v = data?.versions.find((x) => x.in_force) ?? null;
    const hasBom = (data?.versions.length ?? 0) > 0;

    return (
        <PageCard
            title="Ficha técnica"
            loading={loading}
            skeleton={data ? undefined : <div className="p-3"><Skeleton variant="lines" lines={2} label="" /></div>}
            flush={false}
            actions={hasBom ? (
                <Button size="sm" color="outline-primary" onClick={() => navigate(`/restauracao/fichas-tecnicas/${productId}`)}>
                    <i className="ri-file-list-3-line me-1" />Ver ficha técnica
                </Button>
            ) : undefined}
            data-testid="ficha-resumo"
        >
            {data && !hasBom && <p className="text-muted fs-13 mb-0">Este artigo não tem ficha técnica na PingWin.</p>}
            {data && hasBom && (
                <Row className="g-3 align-items-center">
                    <Col xs={12} md="auto">
                        <div className="d-flex flex-wrap gap-1">
                            <StateBadge state={data.state} label={STATE_LABEL[data.state]} />
                            {data.flags.map((f) => <span key={f} className="badge bg-warning-subtle text-warning">{f === "rendimento_por_confirmar" ? "Rendimento por confirmar" : "Fator por confirmar"}</span>)}
                        </div>
                    </Col>
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
            )}
        </PageCard>
    );
}
