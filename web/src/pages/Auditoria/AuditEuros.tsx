import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Card, CardBody, Col, Row } from "reactstrap";
import InfoTip from "Components/Common/InfoTip";
import HiddenMoney from "Components/Common/HiddenMoney";
import { getAuditSummary } from "helpers/laravel_helper";
import { AuditEurosRow, AuditEurosSummary } from "common/models/unitGuide.model";

/**
 * XPLENDOR — Auditoria v2: os euros no topo da Auditoria. "Em jogo agora" (os achados abertos, como
 * no Pareto), "Resolvido este mês" e "Resolvido desde o início" (o € em jogo no momento de corrigir,
 * contado quando a corrida seguinte o confirmou), com o detalhe por pessoa e por regra.
 */
const eur = (c: number | null) => (c === null ? "" : (c / 100).toLocaleString("pt-PT", { maximumFractionDigits: 0 }) + " €");

function Kpi({ title, tip, value, sub, testid, hidden, seed, tone }: {
    title: string; tip: string; value: number | null; sub?: React.ReactNode; testid: string; hidden: boolean; seed: number; tone?: string;
}) {
    return (
        <Card className="h-100 mb-0" data-testid={testid}>
            <CardBody style={{ padding: "1.25rem" }}>
                <div className="text-muted fs-12 text-uppercase fw-semibold d-flex align-items-center gap-1 mb-2">{title}<InfoTip label={title} text={tip} /></div>
                <div className={`fs-3 fw-semibold lh-1 ${tone ?? ""}`}>{hidden ? <HiddenMoney seed={seed} /> : eur(value)}</div>
                {sub && <div className="fs-12 text-muted mt-2">{sub}</div>}
            </CardBody>
        </Card>
    );
}

function Rows({ rows, hidden }: { rows: AuditEurosRow[]; hidden: boolean }) {
    if (!rows.length) return <span>Nada ainda.</span>;
    return <>{rows.slice(0, 3).map((r, k) => <div key={k} className="d-flex justify-content-between gap-2"><span className="text-truncate">{r.label}</span>
        <span className="text-nowrap">{hidden ? <HiddenMoney seed={k} /> : eur(r.cents)}</span></div>)}</>;
}

export default function AuditEuros({ companyId, area, refreshKey }: { companyId: number | null; area?: string; refreshKey?: number }) {
    const [s, setS] = useState<AuditEurosSummary | null>(null);
    useEffect(() => {
        if (!companyId) return;
        getAuditSummary(companyId, area || undefined).then((res: any) => setS(res?.data ?? null)).catch(() => setS(null));
    }, [companyId, area, refreshKey]);
    if (!s) return null;
    const hidden = s.financial?.visible === false;

    return (
        <Row className="g-3 mb-3" data-testid="audit-euros">
            <Col xs={12} md={4}>
                <Kpi title="Em jogo agora" testid="audit-euros-open" hidden={hidden} seed={1} value={s.open_cents} tone="text-warning"
                    tip="A soma do € em jogo dos achados abertos (o mesmo do Pareto). Não inclui o «a confirmar»."
                    sub={<Link to="/restauracao/auditoria/guiao">Abrir o Guião de unidades</Link>} />
            </Col>
            <Col xs={12} md={4}>
                <Kpi title="Resolvido este mês" testid="audit-euros-month" hidden={hidden} seed={2} value={s.month.cents} tone="text-success"
                    tip="O € em jogo de cada achado no momento em que foi corrigido, contado quando a auditoria seguinte deixou de o encontrar. Por pessoa e por regra."
                    sub={<><div className="mb-1">{s.month.count} {s.month.count === 1 ? "achado" : "achados"}</div><Rows rows={s.month.by_person} hidden={hidden} /></>} />
            </Col>
            <Col xs={12} md={4}>
                <Kpi title="Resolvido desde o início" testid="audit-euros-total" hidden={hidden} seed={3} value={s.total.cents}
                    tip="Todos os achados resolvidos, com o € em jogo no momento de corrigir. As três regras com mais euros resolvidos."
                    sub={<><div className="mb-1">{s.total.count} {s.total.count === 1 ? "achado" : "achados"}</div><Rows rows={s.total.by_rule} hidden={hidden} /></>} />
            </Col>
        </Row>
    );
}
