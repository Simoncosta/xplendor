import { useEffect, useMemo, useState } from "react";
import { Alert, Badge, Card, CardBody, Col, ListGroup, ListGroupItem, Row } from "reactstrap";
import XSelect from "Components/Common/Select";
import InfoTip from "Components/Common/InfoTip";
import { FamilyStats, MenuClass, MenuItem, MenuSnapshot } from "common/models/menuEngineering.model";
import MatrixChart from "./MatrixChart";
import { CLASS_META, CLASS_ORDER, CONFIDENCE_META, byClass, familiesByRevenue, familyInsights, fmtEur, fmtPct, fmtQty, matrixPoints } from "./menuFormat";

/**
 * XPLENDOR — Fatia 3: o separador Matriz. A família escolhida, a matriz (SVG) e, ao lado, "O que isto
 * quer dizer": três ou quatro frases geradas dos dados (sem IA) e as quatro classes com os pratos e a
 * jogada. No telemóvel (< 768 px) a matriz passa a lista por classe e as frases vêm primeiro.
 */
const MOBILE = "(max-width: 767.98px)";

export function useIsMobile() {
    const [m, setM] = useState(() => typeof window !== "undefined" && !!window.matchMedia && window.matchMedia(MOBILE).matches);
    useEffect(() => {
        if (!window.matchMedia) return;
        const mq = window.matchMedia(MOBILE);
        const on = () => setM(mq.matches);
        mq.addEventListener?.("change", on);
        return () => mq.removeEventListener?.("change", on);
    }, []);
    return m;
}

export function ClassBadge({ c }: { c: MenuClass }) {
    return <Badge color={`${CLASS_META[c].color}-subtle`} className={`text-${CLASS_META[c].color} fs-11`}><i className={`${CLASS_META[c].icon} me-1`} />{CLASS_META[c].label}</Badge>;
}

export function ItemLine({ item, hidden, onOpen }: { item: MenuItem; hidden: boolean; onOpen: (i: MenuItem) => void }) {
    return (
        <ListGroupItem action tag="button" type="button" onClick={() => onOpen(item)} className="d-flex justify-content-between align-items-start gap-2" data-testid="menu-item-line">
            <div className="text-start">
                <div className="fw-medium">{item.description}</div>
                <div className="text-muted fs-12">
                    {fmtQty(item.qty)} vendidos · quota {fmtPct(item.share_pct)}
                    {!hidden && item.margin_eur !== null && <> · margem {fmtEur(item.margin_eur)}</>}
                </div>
            </div>
            <div className="text-end">
                <Badge color={`${CONFIDENCE_META[item.confidence].color}-subtle`} className={`text-${CONFIDENCE_META[item.confidence].color} fs-11`}>{CONFIDENCE_META[item.confidence].label}</Badge>
                {item.fragile && <div className="fs-11 text-warning mt-1"><i className="ri-error-warning-line me-1" />Classe frágil</div>}
            </div>
        </ListGroupItem>
    );
}

function Insights({ lines, groups, plays }: { lines: string[]; groups: Record<MenuClass | "sem_custo", MenuItem[]>; plays: Partial<Record<MenuClass, string>> }) {
    return (
        <Card className="h-100 mb-0 border shadow-none" data-testid="menu-insights">
            <CardBody className="p-3">
                <h6 className="mb-2"><i className="ri-lightbulb-flash-line text-warning me-1" />O que isto quer dizer</h6>
                <ul className="ps-3 mb-3 fs-13">
                    {lines.map((l, i) => <li key={i} className="mb-1">{l}</li>)}
                </ul>
                {CLASS_ORDER.map((c) => groups[c].length > 0 && (
                    <div key={c} className="mb-2">
                        <div className="d-flex align-items-center gap-2"><ClassBadge c={c} /><span className="fs-12 text-muted">{groups[c].length} {groups[c].length === 1 ? "prato" : "pratos"}</span></div>
                        <div className="fs-13 mt-1">{groups[c].slice(0, 4).map((i) => i.description).join(", ")}{groups[c].length > 4 ? ` e mais ${groups[c].length - 4}` : ""}</div>
                        {plays[c] && <div className="fs-12 text-muted">{plays[c]}</div>}
                    </div>
                ))}
            </CardBody>
        </Card>
    );
}

export default function MatrixTab({ items, snapshot, families, hidden, plays, onOpen }: {
    items: MenuItem[]; snapshot: MenuSnapshot; families: FamilyStats[]; hidden: boolean; plays: Partial<Record<MenuClass, string>>; onOpen: (i: MenuItem) => void;
}) {
    const mobile = useIsMobile();
    const familyNames = useMemo(() => familiesByRevenue(items), [items]);
    const [family, setFamily] = useState<string>("");
    useEffect(() => {
        if (!family || !familyNames.includes(family)) setFamily(familyNames[0] ?? "");
    }, [familyNames, family]);
    const stats = families.find((f) => f.family === family) ?? null;
    const { costed, noCost } = useMemo(() => matrixPoints(items, family), [items, family]);
    const groups = useMemo(() => byClass(items.filter((i) => i.family === family)), [items, family]);
    const insights = useMemo(() => familyInsights(items, family, snapshot.period.days, snapshot.worklist, hidden), [items, family, snapshot, hidden]);

    const insightsCard = <Insights lines={insights} groups={groups} plays={plays} />;
    return (
        <div data-testid="menu-matrix">
            <Row className="g-2 align-items-end mb-3">
                <Col xs={12} md={5} lg={4}>
                    <label className="form-label mb-1">Família</label>
                    <XSelect<string> value={family} ariaLabel="Família da matriz" options={familyNames.map((f) => ({ value: f, label: f }))} onChange={(v) => setFamily(v)} />
                </Col>
                {stats && (
                    <Col xs={12} md={7} lg={8} className="fs-13 text-muted">
                        {stats.items} pratos na matriz, {stats.costed} com custo · muito vendido a partir de {fmtPct(stats.popularity_threshold_pct, 0)} das vendas
                        <InfoTip label="Popularidade" text="Um prato é muito vendido quando a sua quota na quantidade da família chega a 70 % de 1 ÷ N, sendo N o número de pratos da família na matriz (Kasavana e Smith)." />
                        {!hidden && <> · margem média {fmtEur(stats.margin_line)}<InfoTip label="Margem média" text="A margem por unidade média da família, pesada pela quantidade vendida, só com os pratos que têm custo (Confirmado ou Parcial)." /></>}
                    </Col>
                )}
            </Row>
            {stats && stats.fragile.length > 0 && (
                <Alert color="warning" className="py-2 fs-13 mb-3" data-testid="menu-fragile">
                    <i className="ri-error-warning-line me-1" />Classes frágeis nesta família: {stats.fragile.map((f) => f.text).join(" ")}
                </Alert>
            )}
            <Row className="g-3">
                {mobile && <Col xs={12}>{insightsCard}</Col>}
                <Col xs={12} xl={8}>
                    {hidden ? (
                        <Alert color="light" className="fs-13 mb-2">As margens só se mostram a quem vê as Finanças. A popularidade e as classes estão na lista abaixo.</Alert>
                    ) : mobile ? null : costed.length ? (
                        <MatrixChart items={costed} threshold={stats?.popularity_threshold_pct ?? null} line={stats?.margin_line ?? null} onOpen={onOpen} />
                    ) : (
                        <Alert color="light" className="fs-13">Nenhum prato desta família tem custo: a matriz precisa de fichas técnicas.</Alert>
                    )}
                    {(mobile || hidden) && (
                        <div data-testid="menu-matrix-list">
                            {CLASS_ORDER.map((c) => groups[c].length > 0 && (
                                <div key={c} className="mb-3">
                                    <h6 className="mb-1 d-flex align-items-center gap-2"><ClassBadge c={c} /><span className="fs-12 text-muted">{groups[c].length}</span></h6>
                                    <ListGroup>{groups[c].map((i) => <ItemLine key={i.product_id} item={i} hidden={hidden} onOpen={onOpen} />)}</ListGroup>
                                </div>
                            ))}
                        </div>
                    )}
                    {noCost.length > 0 && (
                        <div className="mt-3" data-testid="menu-nocost-band">
                            <div className="fs-13 fw-medium mb-1">Sem custo <span className="text-muted fw-normal">(só se sabe a popularidade: falta a ficha técnica)</span></div>
                            <div className="d-flex flex-wrap gap-1">
                                {[...noCost].sort((a, b) => (b.share_pct ?? 0) - (a.share_pct ?? 0)).map((i) => (
                                    <button key={i.product_id} type="button" className="btn btn-sm btn-soft-secondary" onClick={() => onOpen(i)}>
                                        {i.description} · {fmtPct(i.share_pct)}{i.popular ? " · muito vendido" : ""}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}
                </Col>
                {!mobile && <Col xs={12} xl={4}>{insightsCard}</Col>}
            </Row>
        </div>
    );
}
