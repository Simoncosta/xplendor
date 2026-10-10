import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Badge, Button, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner, Tooltip } from "reactstrap";
import FullCalendar from "@fullcalendar/react";
import dayGridPlugin from "@fullcalendar/daygrid";
import listPlugin from "@fullcalendar/list";
import ptLocale from "@fullcalendar/core/locales/pt";
import { getAgencyAwaiting, getAgencyPosts, getAgencyToday } from "helpers/laravel_helper";
import { channelIcons } from "common/models/editorialPost.model";
import { STAGE_META, STAGE_ORDER, Stage, stageTextColor } from "common/models/editorialWorkflow.model";
import ClientMark from "Components/Common/ClientMark";
import ActionsMenu from "Components/Common/ActionsMenu";
import PageCard from "Components/Common/PageCard";
import XSelect, { XOption } from "./XSelect";
import MonthResults from "./MonthResults";
import CompanyPostPanel from "./CompanyPostPanel";
import type { PanelTarget } from "./PostPanel";

/**
 * Linha Editorial da agência, "Todos os clientes": as publicações de todas as empresas da
 * vista (a agência e os clientes que a pessoa vê), cada uma com o cliente. Abrir ou criar
 * uma publicação abre o painel na empresa dela. O que é de uma só empresa (gerar ideias,
 * aprovação por link, abrir e fechar meses, âncoras, o Feed) pede um cliente escolhido no
 * filtro, e a explicação está sempre à vista.
 */

export type AgencyClient = { id: number; name: string; logo_path: string | null; is_agency: boolean; has_editorial: boolean };
type Company = { id: number; name: string; logo_path: string | null };
type AgencyPost = {
    id: number; title: string; channel: any; networks: any[]; publish_date: string; publish_time: string | null; month_key: string;
    stage: Stage; overdue: boolean; company: Company; links?: { id: number; title: string }[]; can_mark?: boolean;
};
type View = "calendar" | "board" | "results";

const MONTHS_PT = ["Janeiro", "Fevereiro", "Março", "Abril", "Maio", "Junho", "Julho", "Agosto", "Setembro", "Outubro", "Novembro", "Dezembro"];
const monthLabel = (key: string) => { const [y, m] = key.split("-"); return `${MONTHS_PT[Number(m) - 1]} ${y}`; };
const shiftKey = (key: string, delta: number) => { const [y, m] = key.split("-").map(Number); const d = new Date(y, m - 1 + delta, 1); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`; };
const todayLisbon = () => new Date().toLocaleDateString("sv-SE", { timeZone: "Europe/Lisbon" });
const dm = (iso: string) => { const [, m, d] = iso.split("-"); return `${d}/${m}`; };
const ONE_CLIENT = "Escolha um cliente no filtro: esta ação é de uma só empresa.";

type Props = {
    agencyId: number;
    clients: AgencyClient[];
    initialView: string | null;
    initialMonth: string | null;
    onView: (view: string, month: string) => void;
};

export default function AgencyEditorialAll({ agencyId, clients, initialView, initialMonth, onView }: Props) {
    const [view, setView] = useState<View>(initialView === "kanban" ? "board" : initialView === "resultados" ? "results" : "calendar");
    const [month, setMonth] = useState(initialMonth && /^\d{4}-\d{2}$/.test(initialMonth) ? initialMonth : todayLisbon().slice(0, 7));
    const [posts, setPosts] = useState<AgencyPost[] | null>(null);
    const [today, setToday] = useState<{ today: AgencyPost[]; overdue: AgencyPost[] } | null>(null);
    const [awaiting, setAwaiting] = useState<AgencyPost[]>([]);
    const [open, setOpen] = useState<{ companyId: number; target: PanelTarget } | null>(null);
    const [chooser, setChooser] = useState(false);
    const [chosen, setChosen] = useState(0);
    const [reason, setReason] = useState<string | null>(null);
    const [reload, setReload] = useState(0);
    const calRef = useRef<FullCalendar | null>(null);
    const phone = useMemo(() => window.matchMedia("(max-width: 767.98px)").matches, []);
    const editorialClients = clients.filter((c) => c.has_editorial);

    useEffect(() => { onView(view === "board" ? "kanban" : view === "results" ? "resultados" : "calendario", month); }, [view, month, onView]);

    const load = useCallback(() => {
        setPosts(null);
        getAgencyPosts(agencyId, month).then((r: any) => setPosts(r?.data?.posts ?? [])).catch(() => setPosts([]));
        getAgencyToday(agencyId).then((r: any) => setToday(r?.data ?? null)).catch(() => setToday(null));
        getAgencyAwaiting(agencyId).then((r: any) => setAwaiting(r?.data?.posts ?? [])).catch(() => setAwaiting([]));
    }, [agencyId, month]);
    useEffect(() => { load(); }, [load, reload]);

    const goMonth = (key: string) => {
        setMonth(key);
        const [y, m] = key.split("-").map(Number);
        calRef.current?.getApi().gotoDate(new Date(y, m - 1, 1));
    };
    const monthOptions: XOption[] = useMemo(() => {
        const base = todayLisbon().slice(0, 7);
        return Array.from({ length: 24 }, (_, i) => shiftKey(base, i - 12)).map((k) => ({ value: k, label: monthLabel(k) }));
    }, []);

    const openPost = (p: { id: number; company: Company }, toPublish = false) =>
        setOpen({ companyId: p.company.id, target: { mode: "edit", postId: p.id, tab: toPublish ? "publish" : undefined } });
    const createIn = (companyId: number) => {
        const t = todayLisbon();
        setChooser(false);
        setOpen({ companyId, target: { mode: "create", date: t.startsWith(month) ? t : `${month}-01` } });
    };

    const clientTag = (c: Company, size = 16) => (
        <span className="d-inline-flex align-items-center gap-1 text-truncate" style={{ maxWidth: 160 }}>
            <ClientMark name={c.name} logoPath={c.logo_path} size={size} /><span className="text-truncate">{c.name}</span>
        </span>
    );
    const why = (key: string, label: React.ReactNode, color = "outline-primary") => (
        <>
            <span id={`why-all-${key}`} tabIndex={0} role="button" className="d-inline-block" onMouseEnter={() => setReason(key)} onMouseLeave={() => setReason(null)}
                onFocus={() => setReason(key)} onBlur={() => setReason(null)} onClick={() => setReason(key)}>
                <Button color={color} size="sm" disabled className="text-nowrap" style={{ pointerEvents: "none" }}>{label}</Button>
            </span>
            <Tooltip target={`why-all-${key}`} isOpen={reason === key} trigger="manual" placement="bottom">{ONE_CLIENT}</Tooltip>
        </>
    );

    const events = useMemo(() => (posts ?? []).map((p) => ({
        start: p.publish_date, allDay: true, display: "block", title: p.title,
        backgroundColor: "transparent", borderColor: "transparent", classNames: ["bg-transparent", "border-0", "shadow-none", "p-0"],
        extendedProps: { post: p } as any,
    })), [posts]);
    const columns = useMemo(() => STAGE_ORDER.filter((s) => s !== "idea").map((s) => ({ stage: s, items: (posts ?? []).filter((p) => p.stage === s) })), [posts]);
    const strip = (title: string, icon: string, list: AgencyPost[], tone: string, extra?: (p: AgencyPost) => React.ReactNode) => list.length > 0 && (
        <div className={`border rounded px-3 py-2 mb-2 border-${tone}-subtle`}>
            <div className="d-flex align-items-center gap-2 fs-13 fw-semibold"><i className={`${icon} text-${tone}`} />{title} ({list.length})</div>
            <ul className="list-unstyled mb-0 mt-1">
                {list.map((p) => (
                    <li key={`${p.company.id}-${p.id}`} className="d-flex flex-wrap align-items-center gap-2 py-1 fs-13">
                        <span className="text-muted fs-12 text-nowrap" style={{ minWidth: 44 }}>{p.publish_date !== todayLisbon() ? dm(p.publish_date) : p.publish_time ?? "Sem hora"}</span>
                        {clientTag(p.company)}
                        <button type="button" className="btn btn-link p-0 fs-13 text-start text-body" onClick={() => openPost(p)}>{p.title}</button>
                        {extra?.(p)}
                    </li>
                ))}
            </ul>
        </div>
    );

    return (
        <PageCard
            title="Linha Editorial"
            flush={false}
            status="Todos os clientes da agência"
            info={<>
                Abrir e fechar meses, âncoras próprias e o Feed são de cada cliente: escolha-o no filtro.
                {view === "board" && <> Para mudar uma publicação de etapa, abra-a: o painel mostra os passos possíveis nessa empresa.</>}
            </>}
            actions={<>
                <div className="xp-seg" role="tablist" aria-label="Vista">
                    {([["calendar", "Calendário", "ri-calendar-2-line"], ["board", "Kanban", "ri-layout-column-line"]] as const).map(([k, l, i]) => (
                        <button key={k} type="button" role="tab" aria-selected={view === k} className={view === k ? "on" : ""} onClick={() => setView(k)}><i className={`${i} me-1`} />{l}</button>
                    ))}
                    <span id="why-all-feed" tabIndex={0} role="button" onMouseEnter={() => setReason("feed")} onMouseLeave={() => setReason(null)} onFocus={() => setReason("feed")} onBlur={() => setReason(null)} onClick={() => setReason("feed")}>
                        <button type="button" role="tab" aria-selected={false} aria-disabled className="opacity-50" style={{ pointerEvents: "none" }}><i className="ri-smartphone-line me-1" />Feed</button>
                    </span>
                    <Tooltip target="why-all-feed" isOpen={reason === "feed"} trigger="manual" placement="bottom">Escolha um cliente no filtro: o Feed mostra a grelha de uma só conta.</Tooltip>
                    <button type="button" role="tab" aria-selected={view === "results"} className={view === "results" ? "on" : ""} onClick={() => setView("results")}><i className="ri-bar-chart-2-line me-1" />Resultados</button>
                </div>
                {why("ideas", <><i className="ri-lightbulb-flash-line me-1" />Gerar ideias</>, "outline-primary")}
                <ActionsMenu size="sm" label="Mais ações: Linha Editorial" items={[
                    { label: "Aprovação por link", icon: "ri-links-line", disabledReason: ONE_CLIENT },
                ]} />
                <Button color="primary" size="sm" onClick={() => { setChosen(editorialClients[0]?.id ?? 0); setChooser(true); }}><i className="ri-add-line me-1" />Nova publicação</Button>
            </>}
            filters={
                <div className="d-flex flex-wrap align-items-center gap-1" style={{ maxWidth: "100%" }}>
                    <Button color="outline-primary" size="sm" aria-label="Mês anterior" onClick={() => goMonth(shiftKey(month, -1))}><i className="ri-arrow-left-s-line" /></Button>
                    <div style={{ flex: "1 1 150px", minWidth: 150, maxWidth: 220 }}><XSelect small ariaLabel="Mês" options={monthOptions} value={month} onChange={goMonth} /></div>
                    <Button color="outline-primary" size="sm" aria-label="Mês seguinte" onClick={() => goMonth(shiftKey(month, 1))}><i className="ri-arrow-right-s-line" /></Button>
                </div>
            }
        >
                {view !== "results" && today && (
                    <>
                        {strip("Para publicar hoje", "ri-send-plane-line", today.today, "success", (p) => p.can_mark && <Button size="sm" color="success" className="ms-auto py-0" onClick={() => openPost(p, true)}><i className="ri-checkbox-circle-line me-1" />Marcar como publicada</Button>)}
                        {strip("Atrasadas", "ri-alarm-warning-line", today.overdue, "danger", () => <Badge color="danger" className="fw-normal">Atrasada</Badge>)}
                        {strip("À espera de aprovação", "ri-time-line", awaiting, "warning", (p) => (p.links?.length ? <span className="text-muted fs-12"><i className="ri-links-line me-1" />{p.links.map((l) => l.title).join(", ")}</span> : <span className="text-muted fs-12">Sem link enviado</span>))}
                    </>
                )}

                {view === "results" ? (
                    <MonthResults companyId={agencyId} month={month} monthLabel={monthLabel(month)} agency={{ agencyId }}
                        onOpen={(id, companyId) => companyId && setOpen({ companyId, target: { mode: "edit", postId: id } })} />
                ) : posts === null ? <div className="text-center py-5"><Spinner /></div> : view === "board" ? (
                    <>
                        <div className="d-flex gap-2 overflow-auto pb-2" style={{ scrollSnapType: "x mandatory" }} data-testid="agency-board">
                            {columns.map((col) => (
                                <div key={col.stage} className="border rounded flex-shrink-0 bg-light-subtle" style={{ width: 240, scrollSnapAlign: "start" }}>
                                    <div className="px-2 py-2 border-bottom d-flex align-items-center gap-1 fs-13 fw-semibold" style={{ borderTop: `3px solid ${STAGE_META[col.stage].hex}` }}>
                                        <i className={STAGE_META[col.stage].icon} />{STAGE_META[col.stage].label}<span className="text-muted fw-normal ms-1">{col.items.length}</span>
                                    </div>
                                    <div className="p-2 d-flex flex-column gap-2">
                                        {col.items.length === 0 && <div className="text-muted fs-12 text-center py-2">Sem publicações</div>}
                                        {col.items.map((p) => (
                                            <button key={`${p.company.id}-${p.id}`} type="button" className="text-start border rounded p-2 bg-body w-100" onClick={() => openPost(p)}>
                                                <div className="fs-12 text-muted mb-1">{clientTag(p.company, 16)}</div>
                                                <div className="fs-13 fw-medium">{p.title}</div>
                                                <div className="d-flex align-items-center gap-1 fs-12 text-muted mt-1">
                                                    {channelIcons(p).map((c) => <i key={c.icon} className={c.icon} />)}
                                                    <span>{dm(p.publish_date)}{p.publish_time ? ` ${p.publish_time}` : ""}</span>
                                                    {p.overdue && <Badge color="danger" className="ms-auto fw-normal">Atrasada</Badge>}
                                                </div>
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </>
                ) : (
                    <FullCalendar ref={calRef as any} plugins={[dayGridPlugin, listPlugin]} initialView={phone ? "listMonth" : "dayGridMonth"}
                        initialDate={`${month}-01`} locale={ptLocale} firstDay={1} height="auto" headerToolbar={false}
                        noEventsContent="Sem publicações neste mês." editable={false} selectable={false} dayMaxEvents={false} events={events}
                        datesSet={(arg) => { const d = arg.view.currentStart; const k = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`; setMonth((prev) => (prev === k ? prev : k)); }}
                        eventClick={(arg) => openPost((arg.event.extendedProps as any).post)}
                        eventContent={(arg) => {
                            const p = (arg.event.extendedProps as any).post as AgencyPost;
                            const sm = STAGE_META[p.stage];
                            return (
                                <div className="w-100 px-1 rounded d-flex align-items-center gap-1" title={`${p.company.name}: ${sm.label}: ${p.title}`}
                                    style={{ whiteSpace: "nowrap", overflow: "hidden", lineHeight: 1.3, cursor: "pointer", fontSize: "0.72rem", borderLeft: `3px solid ${p.overdue ? "var(--vz-danger)" : sm.hex}`, background: `${sm.hex}26`, color: "var(--vz-body-color)" }}>
                                    <ClientMark name={p.company.name} logoPath={p.company.logo_path} size={14} />
                                    <span className="rounded px-1 fw-semibold flex-shrink-0" style={{ background: sm.hex, color: stageTextColor(p.stage), fontSize: "0.62rem" }}>{sm.short}</span>
                                    <span style={{ overflow: "hidden", textOverflow: "ellipsis" }}>{p.title}</span>
                                </div>
                            );
                        }} />
                )}

            <Modal isOpen={chooser} toggle={() => setChooser(false)} centered>
                <ModalHeader toggle={() => setChooser(false)}>Nova publicação</ModalHeader>
                <ModalBody>
                    <Label for="new-post-client" className="mb-1">Para que cliente?</Label>
                    <XSelect<number> id="new-post-client" searchable value={chosen} onChange={setChosen}
                        options={editorialClients.map((c) => ({ value: c.id, label: c.is_agency ? `${c.name} (a própria agência)` : c.name }))} />
                    <p className="text-muted fs-12 mt-2 mb-0">A publicação fica na Linha Editorial desse cliente.</p>
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setChooser(false)}>Cancelar</Button>
                    <Button color="primary" disabled={!chosen} onClick={() => createIn(chosen)}>Continuar</Button>
                </ModalFooter>
            </Modal>

            <CompanyPostPanel companyId={open?.companyId ?? null} target={open?.target ?? null} onClose={() => setOpen(null)} onChanged={() => setReload((k) => k + 1)} />
        </PageCard>
    );
}
