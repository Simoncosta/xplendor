import { useCallback, useEffect, useMemo, useState } from "react";
import { Card, CardBody, Container, Row, Col, Spinner } from "reactstrap";
import FullCalendar from "@fullcalendar/react";
import dayGridPlugin from "@fullcalendar/daygrid";
import ptLocale from "@fullcalendar/core/locales/pt";
import { toast, ToastContainer } from "react-toastify";
import { getPingwinCalendar } from "helpers/laravel_helper";
import { PingwinCalendarDay, PingwinCalendarResponse } from "common/models/pingwin.model";

/**
 * XPLENDOR — Restauração › Calendário de faturação. Calendário MENSAL (FullCalendar
 * do Velzon) SÓ LEITURA: cada dia mostra faturação + pessoas + ticket médio (dados
 * que já existem). Filtro por loja (default: todas somadas). Em PT. Sem criar/
 * arrastar/editar. Dias sem dados → célula vazia (portão de honestidade).
 */

const euro = (cents: number) =>
    (Number(cents || 0) / 100).toLocaleString("pt-PT", { style: "currency", currency: "EUR", minimumFractionDigits: 2, maximumFractionDigits: 2 });
const num = (n: number) => Number(n || 0).toLocaleString("pt-PT");

const currentMonth = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
};

export default function CalendarioPage() {
    document.title = "Calendário de faturação | Restauração | Xplendor";

    const companyId = useMemo(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return 0;
        try { return Number(JSON.parse(authUser).company_id || 0); } catch { return 0; }
    }, []);

    const [month, setMonth] = useState<string>(currentMonth());
    const [locationId, setLocationId] = useState<number | "">("");
    const [data, setData] = useState<PingwinCalendarResponse | null>(null);
    const [loading, setLoading] = useState(false);

    const fetchCalendar = useCallback(async (m: string, loc: number | "") => {
        if (!companyId) return;
        setLoading(true);
        try {
            const res: any = await getPingwinCalendar(companyId, m, loc || undefined);
            setData(res?.data ?? null);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível carregar o calendário.");
            setData(null);
        } finally {
            setLoading(false);
        }
    }, [companyId]);

    useEffect(() => { fetchCalendar(month, locationId); }, [fetchCalendar, month, locationId]);

    // Um "evento" (só leitura) por dia com dados — SEM bloco sólido: fundo/borda
    // transparentes, para os números aparecerem como texto leve na célula.
    const events = (data?.days ?? []).map((d: PingwinCalendarDay) => ({
        start: d.date,
        allDay: true,
        display: "block",
        backgroundColor: "transparent",
        borderColor: "transparent",
        classNames: ["bg-transparent", "border-0", "shadow-none", "p-0"],
        extendedProps: d,
    }));

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <Row>
                    <Col xs={12}>
                        <div className="page-title-box d-sm-flex align-items-center justify-content-between">
                            <h4 className="mb-sm-0">Calendário de faturação</h4>
                            <div className="d-flex align-items-center gap-2">
                                {loading && <Spinner size="sm" />}
                                {/* Filtro por loja — default "Todas as lojas" (somadas). */}
                                <select
                                    className="form-select form-select-sm"
                                    style={{ minWidth: 200 }}
                                    value={locationId}
                                    onChange={(e) => setLocationId(e.target.value ? Number(e.target.value) : "")}
                                >
                                    <option value="">Todas as lojas</option>
                                    {(data?.locations ?? []).map((l) => (
                                        <option key={l.id} value={l.id}>{l.display_name || l.winrest_name || l.winrest_store_id}</option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    </Col>
                </Row>

                <Row className="pb-5 mb-5">
                    <Col xs={12}>
                        <Card>
                            <CardBody>
                                <p className="text-muted fs-13 mb-3">
                                    Faturação (c/IVA), pessoas que reservaram e ticket médio por dia. Só leitura — dias sem
                                    dados sincronizados ficam vazios; o ticket médio só aparece com faturação e reservas.
                                </p>
                                <FullCalendar
                                    plugins={[dayGridPlugin]}
                                    initialView="dayGridMonth"
                                    locale={ptLocale}
                                    firstDay={1}
                                    height="auto"
                                    headerToolbar={{ left: "prev,next today", center: "title", right: "" }}
                                    /* ⚠️ Só leitura: sem criar, arrastar ou selecionar. */
                                    editable={false}
                                    selectable={false}
                                    droppable={false}
                                    dayMaxEvents={false}
                                    events={events}
                                    datesSet={(arg) => {
                                        const d = arg.view.currentStart;
                                        const m = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
                                        setMonth((prev) => (prev === m ? prev : m));
                                    }}
                                    eventContent={(arg) => {
                                        // Texto LEVE (sem fundo colorido): faturação em destaque; pessoas
                                        // e ticket discretos por baixo. Cores theme-aware (claro/escuro).
                                        const p = arg.event.extendedProps as PingwinCalendarDay;
                                        return (
                                            <div className="w-100 px-1" style={{ whiteSpace: "normal", lineHeight: 1.25, background: "transparent" }}>
                                                <div className="fw-bold text-primary fs-12">{euro(p.invoiced_cents)}</div>
                                                {p.guests !== null && <div className="text-muted fs-11">{num(p.guests)} pessoas</div>}
                                                {p.avg_ticket_cents !== null && <div className="text-muted fs-11">{euro(p.avg_ticket_cents)}/pessoa</div>}
                                            </div>
                                        );
                                    }}
                                />
                            </CardBody>
                        </Card>
                    </Col>
                </Row>
            </Container>
        </div>
    );
}
