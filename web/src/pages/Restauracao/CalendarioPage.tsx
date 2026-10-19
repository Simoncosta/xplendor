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
import InfoTip from "Components/Common/InfoTip";
import { getPingwinCalendar } from "helpers/laravel_helper";
import { CalendarAnomaly, CalendarForecastDay, ForecastValue, PingwinCalendarDay, PingwinCalendarResponse } from "common/models/pingwin.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import "./calendario.css";

/**
 * XPLENDOR — Marketing › Calendário de Faturação. Calendário MENSAL (FullCalendar
 * do Velzon) SÓ LEITURA: cada dia mostra faturação + pessoas + ticket médio (dados
 * que já existem). Filtro por loja (default: todas somadas). Em PT. Sem criar/
 * arrastar/editar. Dias sem dados → célula vazia (portão de honestidade).
 *
 * UI-2a: o filtro de loja vale para a página inteira (fica nos filtros do PageHeader); o
 * calendário fica num PageCard.
 *
 * Fatia 6a: os dias de hoje em diante mostram as reservas lidas no CoverManager e, com "Mostrar
 * previsão", os valores previstos (noutra cor, com "≈"), com o intervalo e o erro medido no InfoTip.
 * Os feriados têm ícone; os dias passados fora do intervalo previsto têm um aviso. Nunca se promete
 * acerto: o erro medido está sempre à vista.
 */

const euro = (cents: number) =>
    (Number(cents || 0) / 100).toLocaleString("pt-PT", { style: "currency", currency: "EUR", minimumFractionDigits: 2, maximumFractionDigits: 2 });
const num = (n: number) => Number(n || 0).toLocaleString("pt-PT");

const pct = (n: number | null | undefined) => (n === null || n === undefined ? "—" : `${n.toLocaleString("pt-PT", { maximumFractionDigits: 0 })} %`);
// Com o separador dos milhares sempre (o pt-PT do browser não agrupa 4 dígitos: "6 060 €").
const eur0 = (v: number) => `${String(Math.round(v)).replace(/\B(?=(\d{3})+(?!\d))/g, " ")} €`;
const hhmm = (s: string) => s.slice(11, 16);
const FORECAST_KEY = "xp-calendar-forecast";
const readToggle = () => { try { return localStorage.getItem(FORECAST_KEY) !== "0"; } catch { return true; } };

type DayProps = {
    actual?: PingwinCalendarDay; forecast?: CalendarForecastDay; reservation?: { tables: number; guests: number; read_at: string };
    holiday?: string; anomalies?: CalendarAnomaly[]; future: boolean;
};

/** O texto do InfoTip de um valor previsto: o intervalo e o erro medido. */
const forecastTip = (label: string, v: ForecastValue, fmt: (n: number) => string, error: string, notes: string[]) => (
    <div className="text-start">
        <div className="fw-semibold mb-1">{label}: ≈ {fmt(v.value)}</div>
        <div>Intervalo provável: {fmt(v.low)} a {fmt(v.high)} (80 % dos dias das últimas 8 semanas ficaram dentro).</div>
        <div>{error}</div>
        {notes.map((n, i) => <div key={i} className="text-muted mt-1">{n}</div>)}
    </div>
);

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
    document.title = "Calendário de Faturação | Marketing | Xplendor";

    const companyId = useWorkingCompanyId();

    const [month, setMonth] = useState<string>(currentMonth());
    const [locationId, setLocationId] = useState<number | "">("");
    const [data, setData] = useState<PingwinCalendarResponse | null>(null);
    const [loading, setLoading] = useState(false);
    const [showForecast, setShowForecast] = useState<boolean>(readToggle());
    const calendarBox = useRef<HTMLDivElement | null>(null);
    const toggleForecast = (v: boolean) => { setShowForecast(v); try { localStorage.setItem(FORECAST_KEY, v ? "1" : "0"); } catch { /* sem armazenamento: fica só nesta visita */ } };

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

    // Opções do react-select do filtro de loja (a primeira é "Todos os restaurantes").
    const locationOptions = useMemo(
        () => [
            { value: "" as number | "", label: "Todos os restaurantes" },
            ...(data?.locations ?? []).map((l) => ({
                value: l.id as number | "",
                label: l.display_name || l.winrest_name || l.winrest_store_id,
            })),
        ],
        [data]
    );

    // Um "evento" (só leitura) por dia — SEM bloco sólido: fundo/borda transparentes, para os números
    // aparecerem como texto leve na célula. Junta o real, a previsão, as reservas, o feriado e as anomalias.
    const fc = data?.forecast;
    const events = useMemo(() => {
        const byDay: Record<string, DayProps> = {};
        const today = fc?.today ?? "";
        const get = (d: string) => (byDay[d] ??= { future: d >= today && today !== "" });
        (data?.days ?? []).forEach((d) => { get(d.date).actual = d; });
        (fc?.days ?? []).forEach((d) => { get(d.date).forecast = d; });
        Object.entries(fc?.reservations ?? {}).forEach(([d, r]) => { get(d).reservation = r; });
        Object.entries(fc?.holidays ?? {}).forEach(([d, h]) => { get(d).holiday = h; });
        Object.entries(fc?.anomalies ?? {}).forEach(([d, a]) => { get(d).anomalies = a; });
        return Object.entries(byDay).map(([date, p]) => ({
            start: date, allDay: true, display: "block", backgroundColor: "transparent", borderColor: "transparent",
            classNames: ["bg-transparent", "border-0", "shadow-none", "p-0"], extendedProps: p,
        }));
    }, [data, fc]);
    // O erro medido (uma loja: o dela; todas: uma linha por loja).
    const errorLine = (e: NonNullable<typeof fc>["errors"][number]) =>
        `faturação ${pct(e.invoiced.days_2_30)}, pessoas ${pct(e.guests.days_2_30)}`;
    const errorText = (fc?.errors ?? []).map((e) => (fc!.errors.length > 1 ? `${e.name}: ` : "") + errorLine(e)).join(" · ");

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader
                    title="Calendário de Faturação"
                    breadcrumbs={[{ label: "Marketing" }]}
                    info="A faturação com IVA, as pessoas que reservaram e o ticket médio de cada dia. Os dias sem dados sincronizados ficam vazios (ou dizem «Vendas em falta» quando só há reservas); o ticket médio só aparece com faturação e reservas."
                    filters={<>
                        <div className="form-check form-switch mb-0 me-2 d-inline-flex align-items-center" data-testid="calendar-forecast-toggle">
                            <input className="form-check-input me-2" type="checkbox" role="switch" id="calendar-forecast" checked={showForecast}
                                onChange={(e) => toggleForecast(e.target.checked)} />
                            <label className="form-check-label fs-13" htmlFor="calendar-forecast">Mostrar previsão</label>
                        </div>
                        {/* Filtro por loja: por omissão "Todos os restaurantes" (somados). */}
                        <XSelect small
                            ariaLabel="Loja"
                            width={220}
                            options={locationOptions}
                            value={locationId}
                            onChange={(v) => setLocationId(v)}
                            searchable
                            placeholder="Todos os restaurantes"
                        />
                    </>}
                />

                {showForecast && fc && fc.errors.length > 0 && (
                    <div className="card card-body py-2 px-3 mb-3 d-flex flex-row flex-wrap align-items-center gap-2" data-testid="calendar-forecast-error">
                        <span className="fw-semibold fs-13">Erro medido: {fc.errors.length === 1 ? pct(fc.errors[0].invoiced.days_2_30) : "por loja"} (últimas 8 semanas)</span>
                        <span className="text-muted fs-13">{errorText}</span>
                        <InfoTip label="Sobre o erro medido" text={
                            <div className="text-start">
                                <div className="mb-1">A previsão é a média do mesmo dia da semana nas últimas 4 semanas, sem o dia mais alto e o mais baixo (os feriados ficam de fora). O erro medido é a diferença média, em percentagem, entre o previsto e o real nas últimas 8 semanas.</div>
                                {fc.errors.map((e) => (
                                    <div key={e.location_id} className="mb-1">
                                        <div className="fw-semibold">{e.name}</div>
                                        <div>De 2 a 30 dias: faturação {pct(e.invoiced.days_2_30)}, pessoas {pct(e.guests.days_2_30)}.</div>
                                        <div>Amanhã, média simples: faturação {pct(e.invoiced.tomorrow_simple)}, pessoas {pct(e.guests.tomorrow_simple)}. Com as reservas: faturação {pct(e.invoiced.tomorrow_reservations)}, pessoas {pct(e.guests.tomorrow_reservations)}.</div>
                                        <div className="text-muted">{e.invoiced.tomorrow_reason}</div>
                                    </div>
                                ))}
                            </div>
                        } />
                    </div>
                )}
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
                                        // Texto LEVE (sem fundo colorido): faturação em destaque; pessoas e ticket discretos
                                        // por baixo. Dias de hoje em diante: as reservas e, com o interruptor, a previsão.
                                        const x = arg.event.extendedProps as DayProps;
                                        const p = x.actual;
                                        const missing = p?.missing_locations ?? [];
                                        const f = showForecast ? x.forecast : undefined;
                                        const errInv = fc?.errors.length === 1 ? `Erro medido nas últimas 8 semanas: ${pct(fc.errors[0].invoiced.days_2_30)}.` : `Erro medido nas últimas 8 semanas: ${errorText}.`;
                                        const errG = fc?.errors.length === 1 ? `Erro medido nas últimas 8 semanas: ${pct(fc.errors[0].guests.days_2_30)}.` : `Erro medido nas últimas 8 semanas: ${errorText}.`;
                                        return (
                                            <div className="w-100 px-1" style={{ whiteSpace: "normal", lineHeight: 1.25, background: "transparent" }}>
                                                {x.holiday && (
                                                    <div className="text-warning fs-11" title={x.holiday} data-testid="calendar-holiday">
                                                        <i className="ri-flag-line me-1" aria-hidden />{x.holiday}
                                                    </div>
                                                )}
                                                {p && (p.invoiced_cents === null
                                                    ? <div className="text-muted fs-11 fst-italic" title="Não há vendas sincronizadas deste dia (não é um dia sem vendas).">Vendas em falta</div>
                                                    : <div className="fw-bold text-primary fs-12">{euro(p.invoiced_cents)}</div>)}
                                                {p && p.invoiced_cents !== null && missing.length > 0 && (
                                                    <div className="text-warning fs-11" title={`Sem as vendas de: ${missing.join(", ")}`}>parcial · falta {missing.join(", ")}</div>
                                                )}
                                                {p && p.guests !== null && <div className="text-muted fs-11">{num(p.guests)} pessoas</div>}
                                                {p && p.avg_ticket_cents !== null && <div className="text-muted fs-11">{euro(p.avg_ticket_cents)}/pessoa</div>}
                                                {showForecast && x.anomalies && x.anomalies.length > 0 && (
                                                    <div className="text-danger fs-11" data-testid="calendar-anomaly" title={x.anomalies.map((a) => a.text_full).join(" ")}>
                                                        <i className="ri-error-warning-line me-1" aria-hidden />{(x.anomalies.find((a) => a.metric === "invoiced") ?? x.anomalies[0]).text_full}
                                                    </div>
                                                )}
                                                {!p && f && (f.invoiced || f.guests) && (
                                                    <div className="xp-forecast fs-11" data-testid="calendar-forecast">
                                                        {f.invoiced && (
                                                            <div className="d-flex align-items-center gap-1 fw-semibold fs-12">
                                                                <span>≈ {eur0(f.invoiced.value)}</span>
                                                                <InfoTip label="Sobre esta previsão" text={forecastTip("Faturação prevista", f.invoiced, eur0, errInv, f.notes)} />
                                                            </div>
                                                        )}
                                                        {f.guests && (
                                                            <div className="d-flex align-items-center gap-1">
                                                                <span>≈ {num(Math.round(f.guests.value))} pessoas</span>
                                                                <InfoTip label="Sobre a previsão das pessoas" text={forecastTip("Pessoas previstas", f.guests, (n) => `${num(Math.round(n))} pessoas`, errG, f.notes)} />
                                                            </div>
                                                        )}
                                                        {x.holiday && <div className="text-warning">Dia atípico: a média pode não servir.</div>}
                                                    </div>
                                                )}
                                                {x.future && x.reservation && (
                                                    <div className="text-muted fs-11" data-testid="calendar-reservations">
                                                        Reservas: {num(x.reservation.tables)} {x.reservation.tables === 1 ? "mesa" : "mesas"} · {num(x.reservation.guests)} pessoas (lido às {hhmm(x.reservation.read_at)})
                                                    </div>
                                                )}
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
