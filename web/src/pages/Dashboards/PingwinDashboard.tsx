import { useCallback, useEffect, useMemo, useState } from "react";
import { Card, CardBody, CardHeader, Container, Row, Col, Spinner } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import ConfirmModal from "Components/Common/ConfirmModal";
import { getPingwinDashboard, queuePingwinSync } from "helpers/laravel_helper";
import { PingwinDashboard as PingwinDashboardData, PingwinMoneyPair, PingwinOccupancyPeriod } from "common/models/pingwin.model";
import MonthlyBillingChart from "./components/MonthlyBillingChart";

/**
 * XPLENDOR — Dashboard de restauração (empresas com o módulo pingwin), com dados
 * reais do PingWin. 3 cards (Anual / Mensal / Diário) com OS DOIS valores
 * (faturado c/IVA + líquido) e comparações (vs ano passado / mesmo dia da
 * semana) — que mostram "—" quando o período anterior não está todo sincronizado
 * (portão de honestidade). Tabela de lojas com faturação + última sincronização.
 * O conteúdo (PingwinDashboardContent) é reutilizado no painel principal.
 */

const yesterdayIso = () => {
    const d = new Date();
    d.setDate(d.getDate() - 1);
    return d.toISOString().slice(0, 10);
};

// Hoje (limite máximo do seletor): permite escolher ATÉ hoje (inclusive), nunca o futuro.
const todayIso = () => new Date().toISOString().slice(0, 10);

// Valores guardados em CÊNTIMOS → dividir por 100 e mostrar 2 casas (pt-PT: €1.694,02).
const euro = (cents: number) =>
    (Number(cents || 0) / 100).toLocaleString("pt-PT", { style: "currency", currency: "EUR", minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** % de comparação: "—" quando null (portão de honestidade); seta/cor conforme sinal. */
const DeltaPct = ({ pct }: { pct: number | null }) => {
    if (pct === null || pct === undefined) {
        return <span className="text-muted">—</span>;
    }
    const up = pct >= 0;
    return (
        <span className={up ? "text-success" : "text-danger"}>
            <i className={up ? "ri-arrow-up-line" : "ri-arrow-down-line"} /> {Math.abs(pct).toLocaleString("pt-PT")}%
        </span>
    );
};

// Contexto do período (para o utilizador perceber que Anual/Mensal podem coincidir
// quando só há dados deste mês) — em pt-PT.
const monthLabel = (ym?: string) => {
    if (!ym) return "";
    const [y, m] = ym.split("-").map(Number);
    return new Date(y, (m || 1) - 1, 1).toLocaleDateString("pt-PT", { month: "long", year: "numeric" });
};
const dayLabel = (d?: string) =>
    d ? new Date(d + "T00:00:00").toLocaleDateString("pt-PT", { day: "2-digit", month: "short", year: "numeric" }) : "";

/** Card de período: faturado c/IVA + líquido; contexto do período + cor de acento; comparação opcional. */
const PeriodCard = ({ label, subtitle, icon, accent, values, compareLabel, deltaInvoiced, deltaNet }: {
    label: string;
    subtitle?: string;
    icon: string;
    accent: "primary" | "info" | "success";
    values: PingwinMoneyPair;
    compareLabel?: string;
    deltaInvoiced?: number | null;
    deltaNet?: number | null;
}) => (
    <Col md={4}>
        <Card className="h-100 mb-0" style={{ borderTop: `3px solid var(--vz-${accent})` }}>
            <CardBody>
                <div className="d-flex align-items-start justify-content-between mb-2">
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-0" style={{ letterSpacing: "0.08em" }}>{label}</p>
                        {subtitle && <p className="text-muted fs-11 mb-0 text-capitalize">{subtitle}</p>}
                    </div>
                    <i className={`${icon} fs-4 text-${accent}`} />
                </div>
                <div className="mb-1">
                    <span className="text-muted fs-12">Faturado c/IVA</span>
                    <h4 className={`mb-0 fw-semibold text-${accent}`}>{euro(values.invoiced_cents)}</h4>
                </div>
                <div className="mb-0">
                    <span className="text-muted fs-12">Líquido</span>
                    <h5 className="mb-0 fw-semibold text-muted">{euro(values.net_cents)}</h5>
                </div>
                {compareLabel && (
                    <div className="mt-2 pt-2 fs-12" style={{ borderTop: "1px dashed var(--vz-border-color)" }}>
                        <div className="d-flex justify-content-between">
                            <span className="text-muted">{compareLabel} (faturado)</span>
                            <DeltaPct pct={deltaInvoiced ?? null} />
                        </div>
                        <div className="d-flex justify-content-between">
                            <span className="text-muted">{compareLabel} (líquido)</span>
                            <DeltaPct pct={deltaNet ?? null} />
                        </div>
                    </div>
                )}
            </CardBody>
        </Card>
    </Col>
);

/** Valor em cêntimos → €X,XX, ou "—" quando null (sem dados / flag desligada). */
const euroOrDash = (cents: number | null | undefined) =>
    cents === null || cents === undefined ? "—" : euro(cents);

/** Card do Ticket Médio (faturação PingWin ÷ pessoas CoverManager) — 3 períodos. */
const AvgTicketCard = ({ avg, enabled }: { avg?: { annual: number | null; monthly: number | null; daily: number | null }; enabled: boolean }) => (
    <Col md={6}>
        <Card className="h-100 mb-0" style={{ borderTop: "3px solid var(--vz-warning)" }}>
            <CardBody>
                <div className="d-flex align-items-start justify-content-between mb-2">
                    <p className="text-muted text-uppercase fw-semibold fs-11 mb-0" style={{ letterSpacing: "0.08em" }}>Ticket Médio</p>
                    <i className="ri-price-tag-3-line fs-4 text-warning" />
                </div>
                {!enabled ? (
                    <>
                        <h4 className="mb-1 fw-semibold text-muted">—</h4>
                        <p className="text-muted fs-12 mb-0">Cálculo com o CoverManager desligado (Restauração › Lojas).</p>
                    </>
                ) : (
                    <div className="row g-0 text-center">
                        {([["Anual", avg?.annual], ["Mensal", avg?.monthly], ["Diário", avg?.daily]] as const).map(([lbl, val], i) => (
                            <div className="col-4" key={lbl} style={{ borderLeft: i > 0 ? "1px solid var(--vz-border-color)" : "none" }}>
                                <p className="text-muted fs-11 mb-1">{lbl}</p>
                                <h5 className="mb-0 fw-semibold text-warning">{euroOrDash(val)}</h5>
                            </div>
                        ))}
                    </div>
                )}
                <p className="text-muted fs-11 mb-0 mt-2">Faturação ÷ pessoas que reservaram. Requer vendas e reservas sincronizadas.</p>
            </CardBody>
        </Card>
    </Col>
);

const num = (n: number) => Number(n || 0).toLocaleString("pt-PT");

/** Card da Lotação — TOTAL de pessoas que reservaram (todas as lojas), por período. */
const OccupancyCard = ({ occ }: { occ?: { annual: PingwinOccupancyPeriod; monthly: PingwinOccupancyPeriod; daily: PingwinOccupancyPeriod } }) => (
    <Col md={6}>
        <Card className="h-100 mb-0" style={{ borderTop: "3px solid var(--vz-info)" }}>
            <CardBody>
                <div className="d-flex align-items-start justify-content-between mb-2">
                    <div>
                        <p className="text-muted text-uppercase fw-semibold fs-11 mb-0" style={{ letterSpacing: "0.08em" }}>Pessoas (reservas)</p>
                        <p className="text-muted fs-11 mb-0">Total de todas as lojas</p>
                    </div>
                    <i className="ri-group-line fs-4 text-info" />
                </div>
                <div className="row g-0 text-center">
                    {([["Anual", occ?.annual], ["Mensal", occ?.monthly], ["Diário", occ?.daily]] as const).map(([lbl, p], i) => (
                        <div className="col-4" key={lbl} style={{ borderLeft: i > 0 ? "1px solid var(--vz-border-color)" : "none" }}>
                            <p className="text-muted fs-11 mb-1">{lbl}</p>
                            {/* has_data false → "—" (sem reservas sincronizadas), não 0 falso. */}
                            <h5 className="mb-1 fw-semibold text-info">{p?.has_data ? num(p.total) : "—"}</h5>
                            <p className="text-muted fs-11 mb-0">
                                {p?.has_data ? <>{num(p.lunch)} almoço · {num(p.dinner)} jantar</> : "sem reservas"}
                            </p>
                        </div>
                    ))}
                </div>
                <p className="text-muted fs-11 mb-0 mt-2">Nº de pessoas que reservaram (não é média). Requer reservas sincronizadas (CoverManager).</p>
            </CardBody>
        </Card>
    </Col>
);

const fmtDateTime = (d: string | null) =>
    d ? new Date(d).toLocaleString("pt-PT", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) : "—";

/** Célula de um período por loja: faturado c/IVA (cor do tema) + líquido + lotação (pessoas). */
const PeriodCell = ({ pair, occ, color }: { pair: PingwinMoneyPair; occ?: PingwinOccupancyPeriod; color: string }) => (
    <td className="text-end">
        <div className={`fw-semibold ${color}`}>{euro(pair?.invoiced_cents ?? 0)}</div>
        <div className="text-muted fs-11">{euro(pair?.net_cents ?? 0)} líq.</div>
        <div className="text-muted fs-11">{occ?.has_data ? `${num(occ.total)} pessoas` : "— pessoas"}</div>
    </td>
);

export function PingwinDashboardContent() {
    const companyId = useMemo(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return 0;
        try { return Number(JSON.parse(authUser).company_id || 0); } catch { return 0; }
    }, []);

    const [date, setDate] = useState<string>(yesterdayIso());
    const [data, setData] = useState<PingwinDashboardData | null>(null);
    const [loading, setLoading] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [queuing, setQueuing] = useState(false);

    const fetchDashboard = useCallback(async (d?: string) => {
        if (!companyId) return;
        setLoading(true);
        try {
            const res: any = await getPingwinDashboard(companyId, d);
            setData(res?.data ?? null);
            // Alinha o seletor de data com a data efetiva (último dia sincronizado).
            if (res?.data?.date && !d) setDate(res.data.date);
        } catch {
            setData(null);
        } finally {
            setLoading(false);
        }
    }, [companyId]);

    useEffect(() => { fetchDashboard(); }, [fetchDashboard]);

    const confirmRefresh = async () => {
        if (!companyId) return;
        setQueuing(true);
        try {
            await queuePingwinSync(companyId, date);
            toast.info("A atualizar… vais ser notificado no sino quando os dados estiverem prontos.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível pôr a atualização na fila.");
        } finally {
            setQueuing(false);
            setConfirmOpen(false);
        }
    };

    const annual = data?.annual;
    const monthly = data?.monthly;
    const daily = data?.daily;

    return (
        <>
            <ToastContainer />
            <ConfirmModal
                isOpen={confirmOpen}
                title="Buscar dados de vendas"
                message={`Buscar os dados de vendas atualizados de ${date}? A busca corre em segundo plano e podes continuar a trabalhar.`}
                confirmText="Buscar"
                cancelText="Cancelar"
                variant="default"
                loading={queuing}
                onCancel={() => setConfirmOpen(false)}
                onConfirm={() => { void confirmRefresh(); }}
            />

            {/* Cabeçalho no padrão do sistema (page-title-box): título + ações alinhados. */}
            <Row>
                <Col xs={12}>
                    <div className="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 className="mb-sm-0">Restauração — Vendas</h4>
                        <div className="d-flex align-items-end gap-2">
                            <div>
                                <label className="form-label fs-12 text-muted mb-1">Data</label>
                                <input
                                    type="date"
                                    className="form-control"
                                    value={date}
                                    max={todayIso()}
                                    onChange={(e) => { setDate(e.target.value); fetchDashboard(e.target.value); }}
                                    style={{ minWidth: 160 }}
                                />
                            </div>
                            <button className="btn btn-primary" onClick={() => setConfirmOpen(true)} disabled={!date || queuing || !companyId}>
                                {queuing ? <><Spinner size="sm" className="me-1" /> A atualizar…</> : <><i className="ri-refresh-line me-1" /> Buscar dados atualizados</>}
                            </button>
                        </div>
                    </div>
                </Col>
            </Row>

            {/* 3 cards de faturação por período (contexto do período no subtítulo) */}
            <Row className="g-3 mb-3">
                <PeriodCard
                    label="Anual"
                    subtitle={annual?.year ? `ano de ${annual.year} (até à data)` : undefined}
                    icon="ri-calendar-2-line"
                    accent="primary"
                    values={annual ?? { invoiced_cents: 0, net_cents: 0 }}
                    compareLabel="vs ano passado"
                    deltaInvoiced={annual?.delta_pct_invoiced ?? null}
                    deltaNet={annual?.delta_pct_net ?? null}
                />
                <PeriodCard
                    label="Mensal"
                    subtitle={monthLabel(monthly?.month)}
                    icon="ri-calendar-line"
                    accent="info"
                    values={monthly ?? { invoiced_cents: 0, net_cents: 0 }}
                />
                <PeriodCard
                    label="Diário"
                    subtitle={dayLabel(daily?.date)}
                    icon="ri-calendar-check-line"
                    accent="success"
                    values={daily ?? { invoiced_cents: 0, net_cents: 0 }}
                    compareLabel="vs mesmo dia (−7d)"
                    deltaInvoiced={daily?.delta_pct_invoiced ?? null}
                    deltaNet={daily?.delta_pct_net ?? null}
                />
            </Row>

            {/* Ticket Médio + Lotação (ambos reais, do CoverManager). */}
            <Row className="g-3 mb-3">
                <AvgTicketCard avg={data?.avg_ticket} enabled={data?.avg_ticket_enabled ?? true} />
                <OccupancyCard occ={data?.occupancy} />
            </Row>

            {/* Gráfico de faturação mensal por restaurante (acima da tabela). */}
            <Row className="g-3 mb-3">
                <MonthlyBillingChart />
            </Row>

            {/* Tabela de lojas — Card + CardHeader + tabela no estilo do sistema. */}
            {/* pb-5 mb-5: folga até ao rodapé (o footer não fica colado ao último card). */}
            <Row className="g-3 pb-5 mb-5">
                <Col xs={12}>
                    <Card className="mb-0">
                        <CardHeader className="d-flex align-items-center">
                            <h5 className="card-title mb-0 flex-grow-1">Lojas</h5>
                            <span className="text-muted fs-11 me-2 d-none d-md-inline">por período: faturado c/IVA · líquido · pessoas</span>
                            {loading && <Spinner size="sm" />}
                        </CardHeader>
                        <CardBody className="p-0">
                            <div className="table-responsive">
                                {/* Header visível: mesmo mecanismo das tabelas do sistema (Carros/Leads)
                                    — table-bordered + thead "text-muted table-light". */}
                                <table className="table table-bordered table-hover table-nowrap align-middle mb-0">
                                    <thead className="text-muted table-light">
                                        <tr>
                                            <th scope="col">Loja</th>
                                            <th scope="col">Anual</th>
                                            <th scope="col">Mensal</th>
                                            <th scope="col">Diário</th>
                                            <th scope="col" className="text-end">Sincronização</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {(data?.locations?.length ?? 0) === 0 ? (
                                            <tr>
                                                <td colSpan={5} className="text-center text-muted py-4">
                                                    Sem dados. Escolhe uma data e usa <strong>“Buscar dados atualizados”</strong>.
                                                </td>
                                            </tr>
                                        ) : (
                                            data!.locations.map((loc) => (
                                                <tr key={loc.id}>
                                                    <td>
                                                        <div className="d-flex align-items-center gap-2">
                                                            <span className="flex-shrink-0 rounded-circle bg-primary-subtle d-inline-flex align-items-center justify-content-center" style={{ width: 28, height: 28 }}>
                                                                <i className="ri-store-2-line text-primary fs-14" />
                                                            </span>
                                                            <span className="fw-medium">{loc.display_name}</span>
                                                        </div>
                                                    </td>
                                                    <PeriodCell pair={loc.annual} occ={loc.occupancy?.annual} color="text-primary" />
                                                    <PeriodCell pair={loc.monthly} occ={loc.occupancy?.monthly} color="text-info" />
                                                    <PeriodCell pair={loc.daily} occ={loc.occupancy?.daily} color="text-success" />
                                                    <td className="text-end text-muted fs-12">{fmtDateTime(loc.last_synced_at)}</td>
                                                </tr>
                                            ))
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </CardBody>
                    </Card>
                </Col>
            </Row>
        </>
    );
}

export default function PingwinDashboard() {
    document.title = "Restauração | Xplendor";
    return (
        <div className="page-content">
            <Container fluid>
                <PingwinDashboardContent />
            </Container>
        </div>
    );
}
