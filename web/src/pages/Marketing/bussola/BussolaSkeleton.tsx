import { Card, CardBody, Col, Row } from "reactstrap";
import Skeleton from "Components/Common/Skeleton";

/**
 * A Bússola na 1.ª carga (sem dados): os números da semana (4 cartões, como o BussolaTop) e as
 * jogadas (cartões em grelha, como o BussolaPlays), em esqueleto. Serve às Bússolas da
 * restauração, do stand e do imobiliário. "A carregar…" é dito uma vez (leitores de ecrã).
 */
export default function BussolaSkeleton({ plays = 3 }: { plays?: number }) {
    return (
        <div aria-busy="true" data-testid="bussola-skeleton">
            <span className="visually-hidden">A carregar a Bússola…</span>
            <Row className="row-cols-1 row-cols-md-2 row-cols-xl-4 g-3 mb-3">
                {Array.from({ length: 4 }).map((_, i) => (
                    <Col key={i}><Card className="mb-0 h-100"><CardBody><Skeleton variant="kpi" height={64} label="" /></CardBody></Card></Col>
                ))}
            </Row>
            <Card className="mb-3">
                <CardBody>
                    <Skeleton variant="lines" lines={1} label="" className="mb-3" style={{ maxWidth: 280 }} />
                    <Row className="g-3">
                        {Array.from({ length: plays }).map((_, i) => (
                            <Col key={i} xl={4} md={6}>
                                <div className="border rounded p-3 h-100"><Skeleton variant="lines" lines={4} label="" /></div>
                            </Col>
                        ))}
                    </Row>
                </CardBody>
            </Card>
        </div>
    );
}
