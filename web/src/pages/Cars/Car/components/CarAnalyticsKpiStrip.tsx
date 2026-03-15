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
        <Card className="mb-0">
            <CardBody className="p-0">
                <Row className="g-0 row-cols-2 row-cols-sm-3 row-cols-md-6">
                    {items.map((item, idx) => (
                        <Col key={item.id}>
                            <div
                                className="py-3 px-3 h-100"
                                style={{
                                    borderRight: idx < items.length - 1 ? "1px solid #e9ebec" : "none",
                                    borderBottom: "1px solid #e9ebec",
                                }}
                            >
                                <p className="text-muted text-uppercase fs-12 mb-2 d-flex align-items-center justify-content-between">
                                    {item.label}
                                    {item.badge && <i className={`fs-16 ${item.badge}`} />}
                                </p>
                                <div className="d-flex align-items-center gap-2">
                                    <i className={`${item.icon} ${item.iconClass} fs-24`} />
                                    <h4 className={`mb-0 fw-semibold ${item.valueClass}`}>
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
                        </Col>
                    ))}
                </Row>
            </CardBody>
        </Card>
    );
}