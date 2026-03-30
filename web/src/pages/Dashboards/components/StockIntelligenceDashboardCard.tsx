import { Col } from "reactstrap";

type StockIntelligenceItem = {
    title: string;
    insight: string;
    key_signal?: string;
    avg_price?: number | null;
    total_listings?: number;
};

type Props = {
    opportunities?: StockIntelligenceItem[];
    saturatedSegments?: StockIntelligenceItem[];
};

const signalLabelMap: Record<string, string> = {
    no_inventory: "Sem oferta",
    low_inventory: "Oferta baixa",
    no_white_color: "Sem cor branca",
    high_supply: "Oferta alta",
    balanced_supply: "Oferta equilibrada",
};

export default function StockIntelligenceDashboardCard({
    opportunities = [],
    saturatedSegments = [],
}: Props) {
    return (
        <Col xs={12}>
            <section style={{ border: "1px solid #e9ebec", borderRadius: 16, padding: "16px 18px", background: "#fff" }}>
                <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                            Stock intelligence
                        </p>
                        <h5 className="mb-1 fw-semibold">O que comprar e o que evitar</h5>
                        <p className="text-muted fs-13 mb-0">Leitura curta para orientar decisões de stock sem entrar em detalhe técnico.</p>
                    </div>
                </div>

                <div className="row g-3">
                    <div className="col-xl-6">
                        <BlockTitle
                            label="Oportunidades de Compra"
                            toneClass="bg-success-subtle text-success"
                        />
                        <div className="vstack gap-2 mt-2">
                            {opportunities.length > 0 ? opportunities.map((item, index) => (
                                <StockItem
                                    key={`opp-${index}-${item.title}`}
                                    item={item}
                                    toneClass="bg-success-subtle text-success"
                                />
                            )) : (
                                <EmptyText text="Ainda não há oportunidades suficientemente fortes para destacar." />
                            )}
                        </div>
                    </div>

                    <div className="col-xl-6">
                        <BlockTitle
                            label="Segmentos Saturados"
                            toneClass="bg-warning-subtle text-warning"
                        />
                        <div className="vstack gap-2 mt-2">
                            {saturatedSegments.length > 0 ? saturatedSegments.map((item, index) => (
                                <StockItem
                                    key={`sat-${index}-${item.title}`}
                                    item={item}
                                    toneClass="bg-warning-subtle text-warning"
                                />
                            )) : (
                                <EmptyText text="Ainda não há segmentos saturados com peso suficiente para alerta." />
                            )}
                        </div>
                    </div>
                </div>
            </section>
        </Col>
    );
}

function BlockTitle({ label, toneClass }: { label: string; toneClass: string }) {
    return (
        <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap">
            <h6 className="mb-0 fw-semibold">{label}</h6>
            <span className={`badge rounded-pill px-3 py-2 fs-11 ${toneClass}`}>
                Top 3
            </span>
        </div>
    );
}

function StockItem({ item, toneClass }: { item: StockIntelligenceItem; toneClass: string }) {
    return (
        <div style={{ border: "1px solid #e9ebec", borderRadius: 14, padding: "14px 16px", background: "#fff" }}>
            <div className="d-flex align-items-start justify-content-between gap-2 flex-wrap mb-1">
                <div className="fw-semibold fs-14 text-body">{item.title}</div>
                <span className={`badge rounded-pill px-2 py-1 fs-10 ${toneClass}`}>
                    {signalLabelMap[item.key_signal || "balanced_supply"] || "Sinal"}
                </span>
            </div>
            <p className="text-muted fs-13 mb-2" style={{ lineHeight: 1.5 }}>
                {item.insight}
            </p>
            <div className="d-flex align-items-center gap-3 flex-wrap text-muted fs-12">
                <span>{formatListings(item.total_listings)} anúncios</span>
                <span>{formatAvgPrice(item.avg_price)}</span>
            </div>
        </div>
    );
}

function EmptyText({ text }: { text: string }) {
    return <p className="text-muted fs-13 mb-0">{text}</p>;
}

function formatListings(value?: number) {
    return Number(value || 0).toLocaleString("pt-PT");
}

function formatAvgPrice(value?: number | null) {
    if (!value || value <= 0) {
        return "Preço médio indisponível";
    }

    return value.toLocaleString("pt-PT", {
        style: "currency",
        currency: "EUR",
        maximumFractionDigits: 0,
    });
}
