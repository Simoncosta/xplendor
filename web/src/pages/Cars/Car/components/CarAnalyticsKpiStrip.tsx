import CountUp from "react-countup";
import { Card, CardBody, Col, Row } from "reactstrap";

interface KpiItem {
    id: number;
    label: string;
    counter: number;
    icon: string;
    iconClass: string;
    suffix: string;
    decimals: number;
    valueClass: string;
    badge: string;
}

interface Props {
    items: KpiItem[];
}

export default function CarAnalyticsKpiStrip({ items }: Props) {
    return (
        <Card
            className="mb-0 border-0"
            style={{
                boxShadow: "0 12px 32px rgba(15, 23, 42, 0.06)",
                background: "linear-gradient(180deg, #ffffff 0%, #fbfcfe 100%)",
            }}
        >
            <CardBody className="p-0">
                <Row className="g-0 row-cols-2 row-cols-sm-3 row-cols-md-6">
                    {items.map((item, idx) => (
                        <Col key={item.id}>
                            <div
                                className="px-3 px-xl-4 py-3 py-xl-4 h-100 d-flex flex-column justify-content-between"
                                style={{
                                    borderRight: idx < items.length - 1 ? "1px solid #e9ebec" : "none",
                                    borderBottom: "1px solid rgba(233,235,236,0.85)",
                                }}
                            >
                                <p
                                    className="text-muted text-uppercase fs-11 mb-3 d-flex align-items-center justify-content-between"
                                    style={{ letterSpacing: "0.08em" }}
                                >
                                    <span>{item.label}</span>
                                    {item.badge && <i className={`fs-16 ${item.badge}`} />}
                                </p>
                                <div className="d-flex align-items-center gap-3">
                                    <div
                                        className="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                                        style={{
                                            width: 42,
                                            height: 42,
                                            background: "rgba(64, 81, 137, 0.07)",
                                        }}
                                    >
                                        <i className={`${item.icon} ${item.iconClass} fs-22`} />
                                    </div>
                                    <div style={{ minWidth: 0 }}>
                                        <h3 className={`mb-1 fw-semibold ${item.valueClass}`} style={{ lineHeight: 1 }}>
                                            <CountUp
                                                start={0}
                                                end={item.counter}
                                                suffix={item.suffix}
                                                separator=","
                                                decimals={item.decimals}
                                                duration={1}
                                            />
                                        </h3>
                                        <p className="mb-0 text-muted fs-12">Indicador principal</p>
                                    </div>
                                </div>
                            </div>
                        </Col>
                    ))}
                </Row>
            </CardBody>
        </Card>
    );
}
