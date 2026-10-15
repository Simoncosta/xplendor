import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Container, Row, Col } from "reactstrap";
import FullCalendar from "@fullcalendar/react";
import dayGridPlugin from "@fullcalendar/daygrid";
import ptLocale from "@fullcalendar/core/locales/pt";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import XSelect from "Components/Common/Select";
import PageCard from "Components/Common/PageCard";
import Skeleton from "Components/Common/Skeleton";
import { getPingwinCalendar } from "helpers/laravel_helper";
import { PingwinCalendarDay, PingwinCalendarResponse } from "common/models/pingwin.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";

/**
 * XPLENDOR — Restauração › Calendário de faturação. Calendário MENSAL (FullCalendar
 * do Velzon) SÓ LEITURA: cada dia mostra faturação + pessoas + ticket médio (dados
 * que já existem). Filtro por loja (default: todas somadas). Em PT. Sem criar/
 * arrastar/editar. Dias sem dados → célula vazia (portão de honestidade).
 *
 * UI-2a: o filtro de loja vale para a página inteira (fica nos filtros do PageHeader); o
 * calendário fica num PageCard.
 */

const euro = (cents: number) =>
    (Number(cents || 0) / 100).toLocaleString("pt-PT", { style: "currency", currency: "EUR", minimumFractionDigits: 2, maximumFractionDigits: 2 });
const num = (n: number) => Number(n || 0).toLocaleString("pt-PT");

const currentMonth = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
};

/** Semanas (linhas) da grelha do mês, com a semana a começar à segunda (como o calendário). */
const monthWeeks = (ym: string) => {
    const [y, m] = ym.split("-").map(Number);
    const first = (new Date(y, m - 1, 1).getDay() + 6) % 7; // 0 = segunda
    const days = new Date(y, m, 0).getDate();
    return Math.ceil((first + days) / 7);
};

export default function CalendarioPage() {
    document.title = "Calendário de faturação | Restauração | Xplendor";

    const companyId = useWorkingCompanyId();

    const [month, setMonth] = useState<string>(currentMonth());
    const [locationId, setLocationId] = useState<number | "">("");
    const [data, setData] = useState<PingwinCalendarResponse | null>(null);
    const [loading, setLoading] = useState(false);
    const calendarBox = useRef<HTMLDivElement | null>(null);

    // O FullCalendar não aceita aria-label nos botões da barra (só o title, via buttonHints):
    // põe-se aqui, a cada mudança de mês (a barra pode ser redesenhada).
    const labelNavButtons = useCallback(() => {
        const box = calendarBox.current;
        box?.querySelector(".fc-prev-button")?.setAttribute("aria-label", "Mês anterior");
        box?.querySelector(".fc-next-button")?.setAttribute("aria-label", "Mês seguinte");
    }, []);
    // Também depois de cada render (no 1.º datesSet o contentor ainda não está ligado ao ref).
    useEffect(() => { labelNavButtons(); });

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

    // Opções do react-select do filtro de loja (default "Todas as lojas").
    const locationOptions = useMemo(
        () => [
            { value: "" as number | "", label: "Todas as lojas" },
            ...(data?.locations ?? []).map((l) => ({
                value: l.id as number | "",
                label: l.display_name || l.winrest_name || l.winrest_store_id,
            })),
        ],
        [data]
    );

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
                <PageHeader
                    title="Calendário de faturação"
                    breadcrumbs={[{ label: "Restauração" }]}
                    info="A faturação com IVA, as pessoas que reservaram e o ticket médio de cada dia. Os dias sem dados sincronizados ficam vazios (ou dizem «Vendas em falta» quando só há reservas); o ticket médio só aparece com faturação e reservas."
                    filters={<>
                        {/* Filtro por loja: por omissão "Todas as lojas" (somadas). */}
                        <XSelect small
                            ariaLabel="Loja"
                            width={220}
                            options={locationOptions}
                            value={locationId}
                            onChange={(v) => setLocationId(v)}
                            searchable
                            placeholder="Todas as lojas"
                        />
                    </>}
                />

                <Row className="pb-5 mb-5">
                    <Col xs={12}>
                        {/* 1.ª carga: a grelha do mês em esqueleto; ao mudar de mês ou de loja, os números
                            ficam visíveis, esbatidos, com o indicador no título. */}
                        <PageCard title="Faturação por dia" flush={false} loading={loading} className="mb-0"
                            skeleton={data ? undefined : <Skeleton variant="calendar" toolbar weeks={monthWeeks(month)} weekHeight={101} />}>
                                {/* Lojas sem dados no mês e dias de vendas em falta (nunca a zero). */}
                                {data?.coverage?.note && (
                                    <div className="alert alert-warning fs-13 py-2 mb-3" role="status" data-testid="calendar-coverage">
                                        <i className="ri-information-line me-1" />{data.coverage.note}
                                    </div>
                                )}
                                <div ref={calendarBox}>
                                <FullCalendar
                                    plugins={[dayGridPlugin]}
                                    initialView="dayGridMonth"
                                    initialDate={`${month}-01`} /* depois do esqueleto, volta ao mês escolhido */
                                    locale={ptLocale}
                                    firstDay={1}
                                    height="auto"
                                    headerToolbar={{ left: "prev,next today", center: "title", right: "" }}
                                    buttonHints={{ prev: "Mês anterior", next: "Mês seguinte", today: "Mês atual" }}
                                    /* ⚠️ Só leitura: sem criar, arrastar ou selecionar. */
                                    editable={false}
                                    selectable={false}
                                    droppable={false}
                                    dayMaxEvents={false}
                                    events={events}
                                    datesSet={(arg) => {
                                        labelNavButtons();
                                        const d = arg.view.currentStart;
                                        const m = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
                                        setMonth((prev) => (prev === m ? prev : m));
                                    }}
                                    eventContent={(arg) => {
                                        // Texto LEVE (sem fundo colorido): faturação em destaque; pessoas
                                        // e ticket discretos por baixo. Cores theme-aware (claro/escuro).
                                        const p = arg.event.extendedProps as PingwinCalendarDay;
                                        const missing = p.missing_locations ?? [];
                                        return (
                                            <div className="w-100 px-1" style={{ whiteSpace: "normal", lineHeight: 1.25, background: "transparent" }}>
                                                {p.invoiced_cents === null
                                                    ? <div className="text-muted fs-11 fst-italic" title="Não há vendas sincronizadas deste dia (não é um dia sem vendas).">Vendas em falta</div>
                                                    : <div className="fw-bold text-primary fs-12">{euro(p.invoiced_cents)}</div>}
                                                {p.invoiced_cents !== null && missing.length > 0 && (
                                                    <div className="text-warning fs-11" title={`Sem as vendas de: ${missing.join(", ")}`}>parcial · falta {missing.join(", ")}</div>
                                                )}
                                                {p.guests !== null && <div className="text-muted fs-11">{num(p.guests)} pessoas</div>}
                                                {p.avg_ticket_cents !== null && <div className="text-muted fs-11">{euro(p.avg_ticket_cents)}/pessoa</div>}
                                            </div>
                                        );
                                    }}
                                />
                                </div>
                        </PageCard>
                    </Col>
                </Row>
            </Container>
        </div>
    );
}
