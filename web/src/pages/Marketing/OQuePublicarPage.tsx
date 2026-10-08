import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Card, CardBody, CardHeader, Col, Container, Row, Spinner } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import XSelect from "pages/Editorial/XSelect";
import HeatmapCard from "pages/Restauracao/HeatmapCard";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { getRestaurantSignals, ignoreRestaurantSignal, restoreRestaurantSignal } from "helpers/laravel_helper";
import { RestaurantSignalItem, RestaurantSignalsData } from "common/models/pingwin.model";
import SignalCard, { ConfidenceBadge, fmtDay, sampleText } from "./SignalCard";
import CreatePostModal from "./CreatePostModal";

/**
 * XPLENDOR — F3 do marketing da restauração: "O que publicar e quando"
 * (documents/PINGWIN-F3-DESENHO.md §2). Sugestões da semana, mapa da semana, os mais
 * vendidos e os que mudam, e a informação das reservas. Frases descritivas, só confiança
 * Alta e Média. Visível com o módulo PingWin; os dados só com o interruptor ligado.
 */

const SIGNAL_LABELS: Record<string, string> = {
    top_items: "Os mais vendidos",
    top_categories: "Categorias com mais peso",
    changes: "Em subida e em descida",
    weak_periods: "Períodos fracos",
    stale_items: "Artigos parados",
    lead_time: "Antecedência das reservas",
    channels: "Canais de reserva",
    delivery_share: "Peso da entrega",
};
const eur = (cents: number) => (cents / 100).toLocaleString("pt-PT", { maximumFractionDigits: 0 }) + " €";
const SUGGESTIONS_SHOWN = 6;

export default function OQuePublicarPage() {
    document.title = "O que publicar e quando | Marketing | Xplendor";
    const companyId = useWorkingCompanyId();
    const [data, setData] = useState<RestaurantSignalsData | null>(null);
    const [locationId, setLocationId] = useState<number>(0);
    const [showIgnored, setShowIgnored] = useState(false);
    const [showAll, setShowAll] = useState(false);
    const [loading, setLoading] = useState(false);
    const [busyKey, setBusyKey] = useState<string | null>(null);
    const [creating, setCreating] = useState<RestaurantSignalItem | null>(null);

    const load = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        try {
            const res: any = await getRestaurantSignals(companyId, locationId || null, showIgnored);
            setData(res?.data ?? null);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível carregar as sugestões.");
        } finally {
            setLoading(false);
        }
    }, [companyId, locationId, showIgnored]);

    useEffect(() => { load(); }, [load]);

    const act = async (s: RestaurantSignalItem, action: "ignore" | "restore") => {
        setBusyKey(s.key);
        try {
            const res: any = action === "ignore" ? await ignoreRestaurantSignal(companyId, s.key) : await restoreRestaurantSignal(companyId, s.key);
            toast.success(res?.message ?? "Feito.");
            await load();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível guardar.");
        } finally {
            setBusyKey(null);
        }
    };

    const suggestions = data?.suggestions ?? [];
    const visibleSuggestions = showAll ? suggestions : suggestions.slice(0, SUGGESTIONS_SHOWN);
    const unavailable = (data?.availability ?? []).flatMap((a) =>
        Object.entries(a.signals).filter(([, v]) => !v.available).map(([type, v]) => ({ loc: a.name, type, ...v })));

    const card = (title: string, children: React.ReactNode, extra?: React.ReactNode) => (
        <Card className="mb-3">
            <CardHeader className="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <h5 className="card-title mb-0">{title}</h5>
                {extra}
            </CardHeader>
            <CardBody>{children}</CardBody>
        </Card>
    );

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader
                    title="O que publicar e quando"
                    breadcrumbs={[{ label: "Marketing" }]}
                    description="Sugestões a partir das vendas e das reservas do restaurante. Os números descrevem o que aconteceu; não dizem porquê."
                    actions={data && data.locations.length > 1 ? (
                        <XSelect ariaLabel="Loja" width={220} value={locationId}
                            options={[{ value: 0, label: "Todas as lojas" }, ...data.locations.map((l) => ({ value: l.id, label: l.name }))]}
                            onChange={(v) => setLocationId(v)} />
                    ) : undefined}
                />

                {loading && !data && <div className="text-center py-5"><Spinner color="primary" /></div>}

                {data && !data.enabled && (
                    <Card><CardBody>
                        <p className="mb-0 text-muted">A leitura das vendas por artigo está desligada nesta empresa. As sugestões aparecem quando for ligada e validada.</p>
                    </CardBody></Card>
                )}

                {data && data.enabled && (
                    <>
                        {data.categories_pending > 0 && (
                            <div className="alert alert-info fs-13 d-flex flex-wrap align-items-center justify-content-between gap-2" role="status">
                                <span><i className="ri-information-line me-1" />
                                    Confirme as categorias das famílias ({data.categories_pending} por confirmar): até lá, os sinais por categoria não aparecem e, por precaução, ficam de fora das listas as famílias que as regras sugerem como Entrega ou Excluir.</span>
                                <Link to="/restauracao/categorias" className="btn btn-sm btn-outline-primary">Ver as categorias</Link>
                            </div>
                        )}

                        {card("Sugestões da semana", (
                            suggestions.length === 0 ? (
                                <p className="text-muted mb-0">Sem sugestões para esta semana{data.hidden_count > 0 ? " (há sugestões ignoradas)" : ""}.</p>
                            ) : (
                                <>
                                    <Row className="g-3">
                                        {visibleSuggestions.map((s) => (
                                            <Col key={s.key} xs={12} md={6} xl={4}>
                                                <SignalCard signal={s} canAct={data.can_act} canActReason={data.can_act_reason} busy={busyKey === s.key}
                                                    onCreate={setCreating} onIgnore={(x) => act(x, "ignore")} onRestore={(x) => act(x, "restore")} />
                                            </Col>
                                        ))}
                                    </Row>
                                    {suggestions.length > SUGGESTIONS_SHOWN && (
                                        <button type="button" className="btn btn-link px-0 mt-2" onClick={() => setShowAll((v) => !v)}>
                                            {showAll ? "Mostrar menos" : `Mostrar todas (${suggestions.length})`}
                                        </button>
                                    )}
                                </>
                            )
                        ), (data.hidden_count > 0 || showIgnored) && (
                            <button type="button" className="btn btn-link btn-sm px-0" onClick={() => setShowIgnored((v) => !v)}>
                                {showIgnored ? "Esconder as ignoradas" : `Mostrar ignoradas (${data.hidden_count})`}
                            </button>
                        ))}

                        <Row className="g-3 mb-3"><HeatmapCard companyId={companyId} /></Row>

                        <Row className="g-3">
                            <Col xs={12} xl={6}>
                                {card("Os mais vendidos (4 semanas)", data.top_items.length === 0 ? (
                                    <p className="text-muted mb-0">Ainda sem 4 semanas de vendas por artigo lidas.</p>
                                ) : data.top_items.map((t) => (
                                    <div key={t.key} className="mb-3">
                                        {data.top_items.length > 1 && <h6 className="fs-13 fw-semibold mb-2">{t.location}</h6>}
                                        <div className="table-responsive">
                                            <table className="table table-sm align-middle mb-0">
                                                <thead className="table-light"><tr><th>Artigo</th><th className="text-end">Unidades</th><th className="text-end">Valor</th><th className="text-end">Peso</th></tr></thead>
                                                <tbody>
                                                    {(t.numbers.items ?? []).map((i: any) => (
                                                        <tr key={i.product_id}>
                                                            <td className="text-body">{i.name}{i.confidence === "media" && <span className="text-muted fs-11"> (confiança média)</span>}</td>
                                                            <td className="text-end">{Number(i.qty).toLocaleString("pt-PT")}</td>
                                                            <td className="text-end text-nowrap">{eur(i.net_cents)}</td>
                                                            <td className="text-end">{String(i.share_pct).replace(".", ",")}%</td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                        {data.top_categories.filter((c) => c.location_id === t.location_id).map((c) => (
                                            <p key={c.key} className="fs-13 text-body mt-2 mb-0">{c.sentence}</p>
                                        ))}
                                    </div>
                                )))}
                            </Col>
                            <Col xs={12} xl={6}>
                                {card("Os que mudam (4 semanas contra as 4 anteriores)", data.changes.length === 0 ? (
                                    <p className="text-muted mb-0">Sem artigos com mudanças fora do normal da loja.</p>
                                ) : (
                                    <ul className="list-unstyled mb-0 vstack gap-3">
                                        {data.changes.map((c) => (
                                            <li key={c.key}>
                                                <div className="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                    <i className={c.type === "item_up" ? "ri-arrow-up-line text-success" : "ri-arrow-down-line text-danger"} />
                                                    <span className="fw-semibold text-body">{c.title}</span>
                                                    <ConfidenceBadge value={c.confidence} />
                                                </div>
                                                <div className="fs-13 text-body">{c.sentence}</div>
                                            </li>
                                        ))}
                                    </ul>
                                ))}
                            </Col>
                        </Row>

                        {card("Reservas e canais", [...data.lead_time, ...data.channels, ...data.delivery].length === 0 ? (
                            <p className="text-muted mb-0">Ainda sem reservas suficientes lidas para estes sinais.</p>
                        ) : (
                            <ul className="list-unstyled mb-0 vstack gap-2">
                                {[...data.lead_time, ...data.channels, ...data.delivery].map((s) => (
                                    <li key={s.key} className="fs-13 text-body">
                                        {s.sentence} <ConfidenceBadge value={s.confidence} />
                                        <div className="text-muted fs-12">{sampleText(s)}</div>
                                    </li>
                                ))}
                            </ul>
                        ))}

                        {unavailable.length > 0 && card("Quando chegam os outros sinais", (
                            <>
                                <ul className="list-unstyled mb-2 vstack gap-1 fs-13">
                                    {unavailable.map((u) => (
                                        <li key={`${u.loc}-${u.type}`}>
                                            <span className="fw-medium text-body">{SIGNAL_LABELS[u.type] ?? u.type}</span>
                                            <span className="text-muted"> ({u.loc}): {u.reason}{u.from ? ` A partir de ${fmtDay(u.from)}.` : ""}</span>
                                        </li>
                                    ))}
                                </ul>
                                {data.availability.filter((a) => a.yoy_from).map((a) => (
                                    <p key={a.location_id} className="text-muted fs-12 mb-0">
                                        {a.name}: a comparação com o ano anterior aparece a partir de {fmtDay(a.yoy_from)}/{a.yoy_from!.slice(0, 4)} (12 meses de vendas).
                                    </p>
                                ))}
                            </>
                        ))}

                        {data.computed_at && (
                            <p className="text-muted fs-12 pb-4">Calculado a {new Date(data.computed_at).toLocaleString("pt-PT", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" })}. Recalcula-se todas as noites.</p>
                        )}
                    </>
                )}

                {data && (
                    <CreatePostModal companyId={companyId} signal={creating} formats={data.formats} networks={data.networks} specialDays={data.special_days ?? {}}
                        onClose={() => setCreating(null)}
                        onCreated={(msg) => { setCreating(null); toast.success(msg); load(); }} />
                )}
            </Container>
        </div>
    );
}
