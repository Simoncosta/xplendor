import { Col, Row } from "reactstrap";

type MarketIntelligence = {
    competitors_count?: number | null;
    market_median_price?: number | null;
    market_p25_price?: number | null;
    market_p75_price?: number | null;
    car_price_vs_median_pct?: number | null;
    market_position?: string | null;
    pricing_signal?: string | null;
    recommended_price?: number | null;
};

const positionMap: Record<string, { label: string; className: string }> = {
    below_market: { label: "Abaixo do mercado", className: "bg-success-subtle text-success" },
    aligned_market: { label: "Alinhado com o mercado", className: "bg-info-subtle text-info" },
    above_market: { label: "Acima do mercado", className: "bg-warning-subtle text-warning" },
    insufficient_data: { label: "A recolher dados", className: "bg-secondary-subtle text-secondary" },
};

const signalMap: Record<string, { label: string; className: string }> = {
    good: { label: "Sinal positivo", className: "bg-success-subtle text-success" },
    neutral: { label: "Sinal neutro", className: "bg-light text-muted" },
    warning: { label: "Pede atenção", className: "bg-danger-subtle text-danger" },
};

export default function MarketIntelligenceCard({
    data,
}: {
    data?: MarketIntelligence | null;
}) {
    const position = positionMap[data?.market_position ?? "insufficient_data"] ?? positionMap.insufficient_data;
    const signal = signalMap[data?.pricing_signal ?? "neutral"] ?? signalMap.neutral;

    return (
        <section style={sectionStyle}>
            <div className="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                <div>
                    <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>
                        Inteligência de mercado
                    </p>
                    <h6 className="mb-1 fw-semibold">Posição no mercado</h6>
                    <p className="text-muted fs-13 mb-0">
                        Leitura rápida da competitividade da viatura face aos comparáveis atuais.
                    </p>
                </div>
                <div className="d-flex align-items-center gap-2 flex-wrap">
                    <span className={`badge rounded-pill px-3 py-2 fs-12 ${position.className}`}>
                        {position.label}
                    </span>
                    <span className={`badge rounded-pill px-3 py-2 fs-12 ${signal.className}`}>
                        {signal.label}
                    </span>
                </div>
            </div>

            <Row className="g-3">
                <Col xl={3} md={6}>
                    <Metric label="Concorrentes" value={formatCount(data?.competitors_count)} hint="comparáveis válidos" />
                </Col>
                <Col xl={3} md={6}>
                    <Metric label="Mediana mercado" value={formatCurrency(data?.market_median_price)} hint="referência principal" />
                </Col>
                <Col xl={3} md={6}>
                    <Metric label="Faixa mercado" value={formatRange(data?.market_p25_price, data?.market_p75_price)} hint="p25 a p75" />
                </Col>
                <Col xl={3} md={6}>
                    <Metric label="Preço recomendado" value={formatCurrency(data?.recommended_price)} hint="sugestão" />
                </Col>
            </Row>

            <div
                className="d-flex align-items-start justify-content-between gap-3 flex-wrap mt-3 pt-3"
                style={{ borderTop: "1px solid #eef0f2" }}
            >
                <div>
                    <span className="text-muted fs-12 d-block mb-1">Preço atual vs mediana</span>
                    <div className={`fw-semibold fs-16 ${deltaClassName(data?.car_price_vs_median_pct)}`}>
                        {formatPercent(data?.car_price_vs_median_pct)}
                    </div>
                </div>
                <div className="text-md-end">
                    <span className="text-muted fs-12 d-block mb-1">Leitura executiva</span>
                    <div className="fw-medium fs-13 text-body">
                        {buildExecutiveLine(data)}
                    </div>
                </div>
            </div>
        </section>
    );
}

function Metric({
    label,
    value,
    hint,
}: {
    label: string;
    value: string;
    hint: string;
}) {
    return (
        <div
            style={{
                padding: "14px 16px",
                borderRadius: "14px",
                border: "1px solid #eef0f2",
                background: "#fcfcfd",
            }}
        >
            <span className="text-muted fs-12 d-block mb-1">{label}</span>
            <div className="fw-semibold fs-18 text-body">{value}</div>
            <span className="text-muted fs-11">{hint}</span>
        </div>
    );
}

function buildExecutiveLine(data?: MarketIntelligence | null) {
    if (!data || data.market_position === "insufficient_data") {
        return "Ainda não há massa crítica suficiente para uma leitura fiável do mercado.";
    }

    if (data.market_position === "below_market") {
        return "A viatura está competitiva face à mediana observada.";
    }

    if (data.market_position === "above_market") {
        return "O preço está acima da mediana e pode estar a travar a decisão.";
    }

    return "O preço está alinhado com o mercado comparável atual.";
}

function formatCurrency(value?: number | null) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return "—";
    }

    return new Intl.NumberFormat("pt-PT", {
        style: "currency",
        currency: "EUR",
        maximumFractionDigits: 0,
    }).format(Number(value));
}

function formatPercent(value?: number | null) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return "—";
    }

    const amount = Number(value);
    const sign = amount > 0 ? "+" : "";

    return `${sign}${amount.toFixed(1)}%`;
}

function formatRange(min?: number | null, max?: number | null) {
    if ((min === null || min === undefined) && (max === null || max === undefined)) {
        return "—";
    }

    return `${formatCurrency(min)} - ${formatCurrency(max)}`;
}

function formatCount(value?: number | null) {
    return value === null || value === undefined ? "—" : String(value);
}

function deltaClassName(value?: number | null) {
    if (value === null || value === undefined) {
        return "text-muted";
    }

    if (value > 5) {
        return "text-warning";
    }

    if (value < -5) {
        return "text-success";
    }

    return "text-info";
}

const sectionStyle = {
    padding: "16px 18px",
    border: "1px solid #e9ebec",
    borderRadius: "16px",
    background: "#fff",
};
