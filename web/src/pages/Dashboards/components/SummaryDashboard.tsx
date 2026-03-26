import { Col } from "reactstrap";

interface ISummaryDashboard {
    total_cars: number;
    own_stock: number;
    trade_ins: number;
    avg_price: number;
    avg_km: number;
    avg_days_in_stock: number;
}

type SummaryDashboardProps = {
    summary: ISummaryDashboard;
};

const metrics = (summary: ISummaryDashboard) => [
    {
        label: "Total carros",
        value: Number(summary?.total_cars || 0).toLocaleString("pt-PT"),
        icon: "ri-car-line",
    },
    {
        label: "Stock proprio",
        value: Number(summary?.own_stock || 0).toLocaleString("pt-PT"),
        icon: "ri-archive-drawer-line",
    },
    {
        label: "Preco medio",
        value: Number(summary?.avg_price || 0).toLocaleString("pt-PT", {
            style: "currency",
            currency: "EUR",
            maximumFractionDigits: 0,
        }),
        icon: "ri-money-euro-circle-line",
    },
    {
        label: "Tempo medio em stock",
        value: `${Number(summary?.avg_days_in_stock || 0)} dias`,
        icon: "ri-time-line",
    },
];

export default function SummaryDashboard({ summary }: SummaryDashboardProps) {
    return (
        <Col xs={12}>
            <section
                style={{
                    border: "1px solid #e9ebec",
                    borderRadius: 16,
                    background: "#fff",
                    overflow: "hidden",
                }}
            >
                <div className="row g-0">
                    {metrics(summary).map((item, index) => (
                        <div className="col-xl-3 col-md-6" key={item.label}>
                            <div
                                className="d-flex align-items-center justify-content-between gap-3"
                                style={{
                                    padding: "16px 18px",
                                    borderRight: index < 3 ? "1px solid #e9ebec" : "none",
                                }}
                            >
                                <div>
                                    <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                                        {item.label}
                                    </p>
                                    <h4 className="mb-0 fw-semibold">{item.value}</h4>
                                </div>
                                <i className={`${item.icon} fs-3 text-muted`} />
                            </div>
                        </div>
                    ))}
                </div>
            </section>
        </Col>
    );
}
