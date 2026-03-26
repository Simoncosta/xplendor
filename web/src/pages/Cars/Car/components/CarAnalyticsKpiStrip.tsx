import CountUp from "react-countup";
import { Col, Row } from "reactstrap";

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
        <Row className="g-0 row-cols-2 row-cols-md-4 row-cols-xl-6" style={{ border: "1px solid #e9ebec", borderRadius: 16, overflow: "hidden", background: "#fff" }}>
            {items.map((item, idx) => (
                <Col key={item.id}>
                    <div
                        className="px-3 py-3 h-100 d-flex flex-column justify-content-between"
                        style={{
                            borderRight: idx < items.length - 1 ? "1px solid #e9ebec" : "none",
                        }}
                    >
                        <p
                            className="text-muted text-uppercase fs-11 mb-2 d-flex align-items-center justify-content-between"
                            style={{ letterSpacing: "0.08em" }}
                        >
                            <span>{item.label}</span>
                            {item.badge && <i className={`fs-15 ${item.badge}`} />}
                        </p>
                        <div className="d-flex align-items-center gap-2">
                            <i className={`${item.icon} ${item.iconClass} fs-18`} />
                            <div style={{ minWidth: 0 }}>
                                <h4 className={`mb-0 fw-semibold ${item.valueClass}`} style={{ lineHeight: 1 }}>
                                    <CountUp
                                        start={0}
                                        end={item.counter}
                                        suffix={item.suffix}
                                        separator=","
                                        decimals={item.decimals}
                                        duration={1}
                                    />
                                </h4>
                            </div>
                        </div>
                    </div>
                </Col>
            ))}
        </Row>
    );
}
