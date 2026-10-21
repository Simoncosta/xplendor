import ReactApexChart from "react-apexcharts";
import { Card, CardBody, Col, Row } from "reactstrap";

/**
 * "Leituras do QR no stand" (Tráfego & Canais): as leituras do QR da ficha A4, contadas na
 * plataforma, com o total (desde sempre, como o resto do separador), 7 dias, 24 horas, a evolução
 * por dia (30 dias) e a última leitura. Ao lado, e nunca somadas, as visitas do site que chegaram
 * com utm_source=qr (o script de seguimento do site): são duas medidas diferentes.
 */
export interface QrScans {
    total: number;
    last_7d: number;
    last_24h: number;
    last_at: string | null;
    by_day: { date: string; total: number }[];
    site_visits_from_qr: number;
}

const fmtDateTime = (iso: string) =>
    new Date(iso).toLocaleString("pt-PT", { timeZone: "Europe/Lisbon", day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" });

export default function QrScansCard({ data }: { data: QrScans | null | undefined }) {
    const d = data ?? { total: 0, last_7d: 0, last_24h: 0, last_at: null, by_day: [], site_visits_from_qr: 0 };
    const options: any = {
        chart: { type: "bar", toolbar: { show: false }, sparkline: { enabled: false }, fontFamily: "inherit" },
        plotOptions: { bar: { columnWidth: "60%", borderRadius: 2 } },
        dataLabels: { enabled: false },
        xaxis: { categories: d.by_day.map((x) => x.date.slice(8, 10) + "/" + x.date.slice(5, 7)), labels: { rotate: -45, style: { fontSize: "10px" } }, tickAmount: 10 },
        yaxis: { labels: { formatter: (v: number) => String(Math.round(v)) }, min: 0, forceNiceScale: true },
        colors: ["var(--vz-primary)"],
        tooltip: { y: { formatter: (v: number) => `${v} ${v === 1 ? "leitura" : "leituras"}` } },
        grid: { strokeDashArray: 3 },
    };

    return (
        <Card className="mb-0" data-testid="qr-scans-card">
            <CardBody>
                <div className="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.08em" }}>Ficha A4 da viatura</p>
                        <h6 className="mb-0 fw-semibold">Leituras do QR no stand</h6>
                    </div>
                    <span className="text-muted fs-12">{d.last_at ? `Última leitura: ${fmtDateTime(d.last_at)}` : "Ainda sem leituras"}</span>
                </div>
                <Row className="g-3 mb-3">
                    <Col xs={6} md={3}><Stat label="Leituras do QR (total)" value={d.total} testid="qr-total" /></Col>
                    <Col xs={6} md={3}><Stat label="Últimos 7 dias" value={d.last_7d} /></Col>
                    <Col xs={6} md={3}><Stat label="Últimas 24 horas" value={d.last_24h} /></Col>
                    <Col xs={6} md={3}><Stat label="Visitas do site vindas do QR" value={d.site_visits_from_qr} testid="qr-site-visits" muted /></Col>
                </Row>
                {d.total > 0 && <ReactApexChart options={options} series={[{ name: "Leituras", data: d.by_day.map((x) => x.total) }]} type="bar" height={180} />}
                <p className="text-muted fs-12 mb-0 mt-2">
                    São leituras do código na folha, não pessoas: não se sabe quem leu, e o mesmo telemóvel conta uma vez em cada 30 minutos.
                    As visitas do site vindas do QR são outra medida (o script do site, com utm_source=qr) e não se somam às leituras.
                </p>
            </CardBody>
        </Card>
    );
}

function Stat({ label, value, testid, muted }: { label: string; value: number; testid?: string; muted?: boolean }) {
    return (
        <div className={`border rounded px-3 py-2 h-100${muted ? " border-dashed" : ""}`}>
            <p className="text-muted text-uppercase fw-semibold fs-11 mb-1" style={{ letterSpacing: "0.06em" }}>{label}</p>
            <span className="fs-18 fw-bold" data-testid={testid}>{value.toLocaleString("pt-PT")}</span>
        </div>
    );
}
