import { Suspense, lazy, useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
    Card, CardBody, CardHeader, Container, Row, Col, Spinner, Button,
    Modal, ModalHeader, ModalBody, ModalFooter, Form, FormGroup, Label, Input,
    Offcanvas, OffcanvasHeader, OffcanvasBody, Tooltip,
} from "reactstrap";
import FullCalendar from "@fullcalendar/react";
import dayGridPlugin from "@fullcalendar/daygrid";
import listPlugin from "@fullcalendar/list";
import ptLocale from "@fullcalendar/core/locales/pt";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import ActionsMenu from "Components/Common/ActionsMenu";
import {
    getEditorialCalendar, setEditorialSector, openEditorialMonth, closeEditorialMonth,
    hideEditorialAnchor, showEditorialAnchor, createEditorialOwnAnchor, deleteEditorialOwnAnchor,
    getBrandProfile, getEditorialFormats,
} from "helpers/laravel_helper";
import { EditorialPost, channelIcons } from "common/models/editorialPost.model";
import { useSearchParams } from "react-router-dom";
import SectorChooser from "./SectorChooser";
import IdeasModal from "./IdeasModal";
import EditorialBoard from "./EditorialBoard";
import FeedView from "./FeedView";
import PostPanel, { PanelTarget } from "./PostPanel";
import XSelect, { XOption } from "./XSelect";
import StageLegendModal from "./StageLegendModal";
import ReviewLinksModal from "./ReviewLinksModal";
import TodayPanel from "./TodayPanel";
import MonthResults from "./MonthResults";
import "./editorial.css";
import { BLOG_STATUS_STAGE, FormatTable, STAGE_META, Stage, stageTextColor } from "common/models/editorialWorkflow.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { confirmAction } from "helpers/swal";

// O diagrama (React Flow) só é carregado quando o "Como funciona" abre.
const HowItWorksModal = lazy(() => import("./HowItWorksModal"));

/**
 * Linha Editorial: uma ação principal ("Nova publicação"), "Gerar ideias" como secundária e
 * o resto no menu "Mais"; as vistas (Calendário, Kanban, Feed, Resultados) num controlo
 * segmentado; o seletor do mês. Qualquer publicação, em qualquer vista, abre o MESMO painel
 * (PostPanel). Âncoras = ocasiões (chips subtis); publicações = trabalho (ícones das redes e
 * etapa). No telemóvel, o calendário passa a lista por dias.
 */

type BaseItem = { title: string; origin: string; rule_type: string; anchor_id: number; owned: boolean; hidden: boolean; occ_year: number; month_key: string; suggestion: string | null };
type DayItem = BaseItem & { type: "day"; date: string };
type RangeItem = BaseItem & { type: "range"; start: string; end: string };
type Item = DayItem | RangeItem;

type MonthState = {
    year: number; month: number; month_key: string;
    state: "open" | "closed"; is_current: boolean;
    can_open: boolean; can_close: boolean; closes_also: string[];
};

const MONTHS_PT = ["Janeiro", "Fevereiro", "Março", "Abril", "Maio", "Junho", "Julho", "Agosto", "Setembro", "Outubro", "Novembro", "Dezembro"];
const WEEKDAYS_PT = ["Domingo", "Segunda", "Terça", "Quarta", "Quinta", "Sexta", "Sábado"];
const ORDINALS = [{ v: 1, l: "1.º" }, { v: 2, l: "2.º" }, { v: 3, l: "3.º" }, { v: 4, l: "4.º" }, { v: 5, l: "5.º" }, { v: -1, l: "Último" }];
const RULE_LABEL: Record<string, string> = { fixa: "Data fixa", nth_weekday: "Dia da semana", periodo: "Período", relativa_pascoa: "Relativa à Páscoa" };

const monthLabel = (key: string) => { const [y, m] = key.split("-"); return `${MONTHS_PT[Number(m) - 1]} ${y}`; };
const fmtDate = (iso: string) => { const [y, m, d] = iso.split("-"); return `${d}/${m}/${y}`; };
const nextDay = (iso: string) => { const d = new Date(iso + "T00:00:00"); d.setDate(d.getDate() + 1); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`; };

type CreateForm = {
    title: string; rule_type: string; suggestion: string;
    month: number; day: number; ordinal: number; weekday: number;
    start_month: number; start_day: number; end_month: number; end_day: number; easter_offset: number;
};
const emptyForm = (month: number): CreateForm => ({
    title: "", rule_type: "fixa", suggestion: "", month, day: 1, ordinal: 1, weekday: 0,
    start_month: month, start_day: 1, end_month: month, end_day: 28, easter_offset: 0,
});

/** Etapa a mostrar no calendário: a da publicação ou, no Site, a equivalente do artigo do blog. */
const postStage = (p: EditorialPost): Stage => (p.blog ? BLOG_STATUS_STAGE[p.blog.status] ?? "production" : p.stage ?? "planning");

/** Vista lembrada por utilizador; o URL (?vista=) tem prioridade. */
const VIEW_PARAM = "vista";
const viewKey = () => {
    try { return `xp-editorial-view:${JSON.parse(sessionStorage.getItem("authUser") || "{}").id ?? "0"}`; } catch { return "xp-editorial-view:0"; }
};
type View = "calendar" | "board" | "feed" | "results";
const VIEW_URL: Record<View, string> = { calendar: "calendario", board: "kanban", feed: "feed", results: "resultados" };
const VIEWS: { key: View; label: string; icon: string }[] = [
    { key: "calendar", label: "Calendário", icon: "ri-calendar-2-line" }, { key: "board", label: "Kanban", icon: "ri-layout-column-line" },
    { key: "feed", label: "Feed", icon: "ri-smartphone-line" }, { key: "results", label: "Resultados", icon: "ri-bar-chart-2-line" },
];
const initialView = (): View => {
    const q = new URLSearchParams(window.location.search).get(VIEW_PARAM);
    if (q === "grelha") return "feed"; // a antiga "Grelha do Instagram"
    const fromUrl = (Object.keys(VIEW_URL) as View[]).find((v) => VIEW_URL[v] === q);
    if (fromUrl) return fromUrl;
    try { const v = localStorage.getItem(viewKey()); return v === "board" || v === "feed" || v === "results" ? v : "calendar"; } catch { return "calendar"; }
};
/** Mês seguinte e anterior (AAAA-MM). */
const shiftKey = (key: string, delta: number) => { const [y, m] = key.split("-").map(Number); const d = new Date(y, m - 1 + delta, 1); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`; };
const isPhone = () => window.matchMedia("(max-width: 767.98px)").matches;
/** "Como funciona" já aberto sozinho para este utilizador (abre uma só vez). */
const howToKey = () => viewKey().replace("xp-editorial-view:", "xp-editorial-howto:");
const initialMonth = () => {
    const m = new URLSearchParams(window.location.search).get("mes");
    return m && /^\d{4}-\d{2}$/.test(m) ? m : "";
};

type PageProps = {
    /** Na Linha Editorial da agência, o cliente escolhido no filtro (em vez da empresa de trabalho). */
    companyIdOverride?: number;
    /** O filtro por cliente da agência, mostrado no cabeçalho. */
    clientFilter?: React.ReactNode;
};

export default function EditorialCalendarPage({ companyIdOverride, clientFilter }: PageProps = {}) {
    document.title = "Linha Editorial | Xplendor";

    const workingId = useWorkingCompanyId();
    const companyId = companyIdOverride ?? workingId;

    const calRef = useRef<FullCalendar | null>(null);
    const [phone] = useState(isPhone);

    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [hasSector, setHasSector] = useState<boolean | null>(null);
    const [sectorName, setSectorName] = useState<string>("");
    const [range, setRange] = useState<{ from: string; to: string } | null>(null);
    const [items, setItems] = useState<Item[]>([]);
    const [posts, setPosts] = useState<EditorialPost[]>([]);
    const [months, setMonths] = useState<MonthState[]>([]);

    const [view, setViewState] = useState<View>(initialView);
    const setView = (v: View) => {
        setViewState(v);
        try { localStorage.setItem(viewKey(), v); } catch { /* sem armazenamento: só o URL */ }
    };
    const [urlMonth] = useState(initialMonth);
    // Modo de produção: quem não produz (cliente gerido pela equipa) não vê as ações de produção.
    const [canProduce, setCanProduce] = useState(true);
    const [selectedKey, setSelectedKey] = useState<string>("");
    const [acting, setActing] = useState(false);
    const [working, setWorking] = useState(false);
    const [cascade, setCascade] = useState<MonthState | null>(null);

    // Âncora clicada no calendário (ocasião): detalhe num painel lateral.
    const [panelAnchor, setPanelAnchor] = useState<Item | null>(null);
    const [createOpen, setCreateOpen] = useState(false);           // modal criar âncora própria
    const [form, setForm] = useState<CreateForm>(emptyForm(1));
    // O painel único da publicação (criar e editar, em todas as vistas).
    const [panel, setPanel] = useState<PanelTarget | null>(null);
    const [reload, setReload] = useState(0);
    const [formats, setFormats] = useState<FormatTable | null>(null);
    // "Gerar ideias do mês": só com o mínimo do Perfil da Marca (o servidor também recusa).
    const [ideasOpen, setIdeasOpen] = useState(false);
    const [pillars, setPillars] = useState<string[]>([]);
    const [ideasGate, setIdeasGate] = useState<{ ready: boolean; reason: string | null } | null>(null);
    const [howOpen, setHowOpen] = useState(false);
    const [legendOpen, setLegendOpen] = useState(false);
    // F3c: links de aprovação por lote; ?aprovacoes=ID (aviso do sino) abre-os nesse link.
    const [reviewOpen, setReviewOpen] = useState(() => new URLSearchParams(window.location.search).has("aprovacoes"));
    const [reviewFocus] = useState(() => Number(new URLSearchParams(window.location.search).get("aprovacoes") || 0) || null);
    // Razão de um botão desativado: ao passar o rato, no foco e ao tocar.
    const [reasonOpen, setReasonOpen] = useState<string | null>(null);
    const touchRef = useRef(false); // no toque, o "sair com o rato" simulado não fecha a razão

    const applyCalendar = useCallback((d: any) => {
        setHasSector(!!d.has_sector);
        if (d.has_sector) {
            setSectorName(d.sector?.name ?? "");
            setRange({ from: d.from, to: d.to });
            setItems(d.items ?? []);
            setPosts(d.posts ?? []);
            setMonths(d.months ?? []);
            setCanProduce(d.can_produce !== false);
            // Mês do URL (?mes=) se estiver na janela; senão o corrente.
            const inWindow = urlMonth && d.from && d.to && `${urlMonth}-01` >= d.from.slice(0, 8) + "01" && `${urlMonth}-01` <= d.to;
            setSelectedKey((prev) => prev || (inWindow ? urlMonth : d.months?.find((m: MonthState) => m.is_current)?.month_key ?? d.months?.[0]?.month_key ?? ""));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const load = useCallback(async () => {
        if (!companyId) { setLoading(false); return; }
        try {
            const r: any = await getEditorialCalendar(companyId);
            applyCalendar(r?.data ?? {});
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível carregar a linha editorial.");
        } finally { setLoading(false); }
    }, [companyId, applyCalendar]);
    const refresh = () => { void load(); setReload((k) => k + 1); };

    useEffect(() => { void load(); }, [load]);
    useEffect(() => {
        if (!companyId) return;
        getEditorialFormats(companyId).then((r: any) => setFormats(r.data)).catch(() => setFormats(null));
    }, [companyId]);
    useEffect(() => {
        if (!companyId) return;
        getBrandProfile(companyId)
            .then((r: any) => {
                setIdeasGate({ ready: !!r?.data?.ideas_ready, reason: r?.data?.ideas_blocked_reason ?? null });
                setPillars((r?.data?.pillars ?? []).map((x: any) => String(x?.name ?? "")).filter(Boolean));
            })
            // Sem resposta, não bloqueia no ecrã: o servidor recusa com a explicação se faltar o mínimo.
            .catch(() => setIdeasGate({ ready: true, reason: null }));
    }, [companyId, ideasOpen]);

    // "Como funciona" abre sozinho uma vez por utilizador, na primeira visita com o perfil incompleto.
    useEffect(() => {
        if (!hasSector || !ideasGate || ideasGate.ready) return;
        try {
            if (localStorage.getItem(howToKey())) return;
            localStorage.setItem(howToKey(), "1");
        } catch { return; }
        setHowOpen(true);
    }, [hasSector, ideasGate]);

    const chooseSector = async (sectorId: number) => {
        setSaving(true);
        try { await setEditorialSector(companyId, sectorId); toast.success("Ramo definido."); await load(); }
        catch (e: any) { toast.error(e?.message ?? "Não foi possível definir o ramo."); }
        finally { setSaving(false); }
    };

    const selected = useMemo(() => months.find((m) => m.month_key === selectedKey) ?? null, [months, selectedKey]);
    const minKey = range ? range.from.slice(0, 7) : months[0]?.month_key;       // 12 meses atrás (consulta)
    const maxKey = range ? range.to.slice(0, 7) : months[months.length - 1]?.month_key;
    const currentKey = useMemo(() => months.find((m) => m.is_current)?.month_key ?? months[0]?.month_key, [months]);
    const isPastView = !!selectedKey && !!currentKey && selectedKey < currentKey;
    // Publicações do mês por etapa (o "Como funciona" destaca a etapa atual).
    const stageCounts = useMemo(() => posts.filter((p) => p.month_key === selectedKey && p.channel !== "site")
        .reduce((acc, p) => ({ ...acc, [p.stage]: (acc[p.stage] ?? 0) + 1 }), {} as Partial<Record<Stage, number>>), [posts, selectedKey]);
    const monthOpen = useCallback((date: string) => months.find((m) => m.month_key === date.slice(0, 7))?.state === "open", [months]);

    const [searchParams, setSearchParams] = useSearchParams();

    /** Mudar de mês (também move o calendário). */
    const goMonth = (key: string) => {
        if (!key) return;
        setSelectedKey(key);
        const [y, m] = key.split("-").map(Number);
        calRef.current?.getApi().gotoDate(new Date(y, m - 1, 1));
    };
    const monthOptions: XOption[] = useMemo(() => {
        if (!minKey || !maxKey) return [];
        const out: XOption[] = [];
        for (let k = minKey; k <= maxKey; k = shiftKey(k, 1)) {
            const st = months.find((m) => m.month_key === k);
            out.push({ value: k, label: `${monthLabel(k)}${st ? (st.state === "open" ? "" : " (fechado)") : " (só consulta)"}` });
        }
        return out;
    }, [minKey, maxKey, months]);

    // Vista e mês no URL (?vista=calendario|kanban|feed|resultados&mes=AAAA-MM), sem criar entradas no histórico.
    useEffect(() => {
        setSearchParams((prev) => {
            const next = new URLSearchParams(prev);
            next.set(VIEW_PARAM, VIEW_URL[view]);
            if (selectedKey) next.set("mes", selectedKey);
            return next;
        }, { replace: true });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [view, selectedKey]);

    // Ligações do sino: ?ideas=AAAA-MM abre as ideias desse mês; ?creative=ID ou ?publicacao=ID abre o painel.
    const handledLink = useRef(false);
    useEffect(() => {
        if (handledLink.current || !range) return;
        const ideasKey = searchParams.get("ideas");
        const postId = Number(searchParams.get("creative") || searchParams.get("publicacao") || 0);
        if (!ideasKey && !postId) return;
        handledLink.current = true;
        if (ideasKey && months.some((mo) => mo.month_key === ideasKey)) {
            goMonth(ideasKey);
            setIdeasOpen(true);
        }
        if (postId) setPanel({ mode: "edit", postId, tab: searchParams.get("creative") ? "content" : undefined });
        setSearchParams((prev) => {
            const next = new URLSearchParams(prev);
            ["ideas", "creative", "publicacao"].forEach((k) => next.delete(k));
            return next;
        }, { replace: true });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [range, months, posts]);

    // ── Eventos: âncoras (ocasiões) + publicações (trabalho) ──
    const events = useMemo(() => {
        const anchorEvents = items.map((it) => ({
            start: it.type === "range" ? (it as RangeItem).start : (it as DayItem).date,
            end: it.type === "range" ? nextDay((it as RangeItem).end) : undefined,
            allDay: true, display: "block", title: it.title,
            backgroundColor: "transparent", borderColor: "transparent",
            classNames: ["bg-transparent", "border-0", "shadow-none", "p-0"],
            extendedProps: { kind: "anchor", item: it } as any,
        }));
        const postEvents = posts.map((p) => ({
            start: p.publish_date, allDay: true, display: "block", title: p.title,
            backgroundColor: "transparent", borderColor: "transparent",
            classNames: ["bg-transparent", "border-0", "shadow-none", "p-0"],
            extendedProps: { kind: "post", post: p } as any,
        }));
        return [...anchorEvents, ...postEvents];
    }, [items, posts]);

    const anchorChip = (it: Item) =>
        it.hidden ? "bg-warning-subtle text-warning"
            : it.owned ? "bg-success-subtle text-success"
                : "bg-secondary-subtle text-secondary";

    // ── Meses ──
    const doOpen = async (mo: MonthState) => {
        setActing(true);
        try { const r: any = await openEditorialMonth(companyId, mo.year, mo.month); setMonths(r?.data?.months ?? []); toast.success(`${monthLabel(mo.month_key)} aberto.`); }
        catch (e: any) { toast.error(e?.message ?? "Não foi possível abrir o mês."); await load(); }
        finally { setActing(false); }
    };
    const performClose = async (mo: MonthState) => {
        setActing(true); setCascade(null);
        try { const r: any = await closeEditorialMonth(companyId, mo.year, mo.month); setMonths(r?.data?.months ?? []); toast.success(`${monthLabel(mo.month_key)} fechado.`); }
        catch (e: any) { toast.error(e?.message ?? "Não foi possível fechar o mês."); await load(); }
        finally { setActing(false); }
    };
    const doClose = (mo: MonthState) => { mo.closes_also.length > 0 ? setCascade(mo) : performClose(mo); };

    // ── Âncoras ──
    const runAnchor = async (fn: () => Promise<any>, okMsg: string, ref: Item) => {
        setWorking(true);
        try {
            const r: any = await fn(); const d = r?.data ?? {}; applyCalendar(d);
            const fresh = (d.items ?? []).find((x: Item) => x.owned === ref.owned && x.anchor_id === ref.anchor_id && x.occ_year === ref.occ_year && x.month_key === ref.month_key);
            setPanelAnchor(fresh ?? null);
            toast.success(okMsg);
        } catch (e: any) { toast.error(e?.message ?? "Operação não permitida."); await load(); }
        finally { setWorking(false); }
    };
    const onHide = (it: Item) => runAnchor(() => hideEditorialAnchor(companyId, it.anchor_id, it.occ_year), "Âncora escondida.", it);
    const onShow = (it: Item) => runAnchor(() => showEditorialAnchor(companyId, it.anchor_id, it.occ_year), "Âncora mostrada.", it);
    const onDeleteAnchor = (it: Item) => runAnchor(async () => { const r = await deleteEditorialOwnAnchor(companyId, it.anchor_id); setPanelAnchor(null); return r; }, "Âncora própria apagada.", it);

    const openCreate = () => { if (selected) { setForm(emptyForm(selected.month)); setCreateOpen(true); } };
    const setF = (patch: Partial<CreateForm>) => setForm((p) => ({ ...p, ...patch }));
    const submitCreate = async () => {
        const f = form;
        const payload: any = { title: f.title.trim(), rule_type: f.rule_type, suggestion: f.suggestion.trim() || null };
        if (f.rule_type === "fixa") { payload.month = f.month; payload.day = f.day; }
        else if (f.rule_type === "nth_weekday") { payload.month = f.month; payload.ordinal = f.ordinal; payload.weekday = f.weekday; }
        else if (f.rule_type === "periodo") { payload.start_month = f.start_month; payload.start_day = f.start_day; payload.end_month = f.end_month; payload.end_day = f.end_day; }
        else if (f.rule_type === "relativa_pascoa") { payload.easter_offset = f.easter_offset; }
        if (!payload.title) { toast.error("Dê um título à âncora."); return; }
        setWorking(true);
        try { const r: any = await createEditorialOwnAnchor(companyId, payload); applyCalendar(r?.data ?? {}); toast.success("Âncora própria criada."); setCreateOpen(false); }
        catch (e: any) { toast.error(e?.message ?? "Não foi possível criar a âncora."); }
        finally { setWorking(false); }
    };

    // Opções de ligação a âncora (herdada 'a:' / própria 'o:') — distintas por espaço de id.
    const anchorLinkOptions = useMemo(() => {
        const seen = new Set<string>();
        const opts: XOption[] = [{ value: "", label: "Sem âncora" }];
        for (const it of items) {
            const key = `${it.owned ? "o" : "a"}:${it.anchor_id}`;
            if (seen.has(key)) continue;
            seen.add(key);
            opts.push({ value: key, label: `${it.title}${it.owned ? " (própria)" : ""}` });
        }
        return opts;
    }, [items]);

    /** Nova publicação: hoje, se estiver no mês escolhido; senão o dia 1 desse mês. */
    const newPost = (date?: string) => {
        const today = new Date().toLocaleDateString("sv-SE", { timeZone: "Europe/Lisbon" });
        setPanel({ mode: "create", date: date ?? (today.startsWith(selectedKey) ? today : `${selectedKey}-01`) });
    };
    const openPost = (id: number, toPublish = false) => setPanel({ mode: "edit", postId: id, tab: toPublish ? "publish" : undefined });

    // Razões de botões desativados (nunca um botão desativado sem explicação).
    const monthIsOpen = selected?.state === "open";
    const ideasReason = !canProduce ? null : !monthIsOpen ? "Abra o mês para gerar ideias." : ideasGate && !ideasGate.ready ? ideasGate.reason : null;
    const newPostReason = !canProduce ? null : !monthIsOpen ? (isPastView ? "Mês passado: só consulta." : "Abra o mês para criar publicações.") : null;
    const reasonButton = (key: string, reason: string, children: React.ReactNode, color: string) => (
        <>
            <span id={`why-${key}`} tabIndex={0} className="d-inline-block" role="button" aria-describedby={`why-${key}-text`}
                onPointerDown={(e) => { touchRef.current = e.pointerType !== "mouse"; }}
                onMouseEnter={() => setReasonOpen(key)} onMouseLeave={() => { if (!touchRef.current) setReasonOpen(null); }}
                onFocus={() => setReasonOpen(key)} onBlur={() => setReasonOpen(null)} onClick={() => setReasonOpen(key)}>
                <Button color={color} size="sm" disabled className="text-nowrap" style={{ pointerEvents: "none" }}>{children}</Button>
            </span>
            <Tooltip target={`why-${key}`} isOpen={reasonOpen === key} trigger="manual" placement="bottom">
                <span id={`why-${key}-text`}>{reason}</span>
            </Tooltip>
        </>
    );

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Linha Editorial" breadcrumbs={[{ label: "Marketing" }]} />

                {clientFilter && (loading || hasSector === false) && <div className="d-flex justify-content-end mb-3">{clientFilter}</div>}
                {loading ? (
                    <div className="text-center py-5"><Spinner color="primary" /></div>
                ) : hasSector === false ? (
                    <Row className="justify-content-center"><Col xl={8}>
                        <SectorChooser companyId={companyId} busy={saving} onChoose={chooseSector} />
                    </Col></Row>
                ) : (
                    <Card>
                        <CardHeader className="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div className="me-auto">
                                <h5 className="card-title mb-0 d-flex align-items-center gap-1">
                                    Linha Editorial
                                    <button type="button" className="btn btn-link btn-sm p-0 lh-1 text-muted" aria-label="Como funciona" title="Como funciona" onClick={() => setHowOpen(true)}>
                                        <i className="ri-question-line fs-18" />
                                    </button>
                                </h5>
                                <small className="text-muted">Ramo: <strong>{sectorName}</strong></small>
                            </div>
                            {/* Uma ação principal; "Gerar ideias" secundária; o resto em "Mais". */}
                            <div className="d-flex flex-wrap align-items-center gap-2">
                                {clientFilter}
                                {canProduce && (ideasReason
                                    ? reasonButton("ideas", ideasReason, <><i className="ri-lightbulb-flash-line me-1" />Gerar ideias</>, "outline-primary")
                                    : <Button color="outline-primary" size="sm" disabled={working} onClick={() => setIdeasOpen(true)}><i className="ri-lightbulb-flash-line me-1" />Gerar ideias</Button>)}
                                {canProduce && (newPostReason
                                    ? reasonButton("new", newPostReason, <><i className="ri-add-line me-1" />Nova publicação</>, "primary")
                                    : <Button color="primary" size="sm" onClick={() => newPost()}><i className="ri-add-line me-1" />Nova publicação</Button>)}
                                <ActionsMenu size="sm" label="Mais ações: Linha Editorial" items={[
                                    { label: "Aprovação por link", icon: "ri-links-line", onClick: () => setReviewOpen(true) },
                                    { label: "Âncora própria", icon: "ri-calendar-event-line", onClick: openCreate, hidden: !(selected && monthIsOpen) },
                                    { label: selected ? `Fechar ${monthLabel(selected.month_key)}` : "Fechar o mês", icon: "ri-lock-2-line", hidden: !selected?.can_close,
                                        disabledReason: acting ? "A processar o mês." : null, onClick: () => { if (selected) doClose(selected); } },
                                    { label: "Legenda das etapas", icon: "ri-palette-line", onClick: () => setLegendOpen(true) },
                                    { label: "Como funciona", icon: "ri-question-line", onClick: () => setHowOpen(true) },
                                ]} />
                            </div>
                        </CardHeader>

                        <CardBody>
                            {/* Vistas (controlo segmentado) e o mês */}
                            <div className="d-flex flex-wrap align-items-center gap-2 mb-3">
                                <div className="xp-seg" role="tablist" aria-label="Vista">
                                    {VIEWS.map((v) => (
                                        <button key={v.key} type="button" role="tab" aria-selected={view === v.key} className={view === v.key ? "on" : ""} onClick={() => setView(v.key)}>
                                            <i className={`${v.icon} me-1`} />{v.label}
                                        </button>
                                    ))}
                                </div>
                                {selectedKey && (
                                    <div className="d-flex flex-wrap align-items-center gap-1 ms-md-auto" style={{ maxWidth: "100%" }}>
                                        <Button color="outline-primary" size="sm" aria-label="Mês anterior" className={!!minKey && selectedKey <= minKey ? "invisible" : ""} onClick={() => goMonth(shiftKey(selectedKey, -1))}><i className="ri-arrow-left-s-line" /></Button>
                                        <div style={{ flex: "1 1 150px", minWidth: 150, maxWidth: 220 }}><XSelect small ariaLabel="Mês" options={monthOptions} value={selectedKey} onChange={goMonth} /></div>
                                        <Button color="outline-primary" size="sm" aria-label="Mês seguinte" className={!!maxKey && selectedKey >= maxKey ? "invisible" : ""} onClick={() => goMonth(shiftKey(selectedKey, 1))}><i className="ri-arrow-right-s-line" /></Button>
                                        {selected ? (monthIsOpen
                                            ? <span className="badge bg-success-subtle text-success ms-1"><i className="ri-lock-unlock-line me-1" />Aberto</span>
                                            : selected.can_open && canProduce
                                                ? <Button color="success" size="sm" className="ms-1" disabled={acting} onClick={() => doOpen(selected)}>{acting ? <Spinner size="sm" /> : <><i className="ri-lock-unlock-line me-1" />Abrir mês</>}</Button>
                                                : <span className="badge bg-body-secondary text-muted ms-1" title={selected.state === "closed" ? "Abra primeiro o mês anterior." : undefined}><i className="ri-lock-2-line me-1" />Fechado</span>)
                                            : isPastView ? <span className="badge bg-warning-subtle text-warning ms-1"><i className="ri-archive-line me-1" />Só consulta</span> : null}
                                    </div>
                                )}
                            </div>

                            {(view === "calendar" || view === "board") && <TodayPanel companyId={companyId} reloadKey={reload} onOpen={openPost} />}

                            {view === "results" ? (
                                selectedKey && <MonthResults companyId={companyId} month={selectedKey} monthLabel={monthLabel(selectedKey)} onOpen={(id) => openPost(id)} />
                            ) : view === "feed" ? (
                                <FeedView companyId={companyId} reloadKey={reload} onOpen={(id) => openPost(id)} />
                            ) : view === "board" ? (
                                <EditorialBoard companyId={companyId} monthKey={selectedKey} reloadKey={reload} onOpen={(id) => openPost(id)} onChanged={() => void load()} />
                            ) : range && (
                                <FullCalendar
                                    ref={calRef as any}
                                    plugins={[dayGridPlugin, listPlugin]}
                                    // No telemóvel, uma lista por dias (a grelha de 7 colunas não cabe).
                                    initialView={phone ? "listMonth" : "dayGridMonth"}
                                    initialDate={selectedKey ? `${selectedKey}-01` : currentKey ? `${currentKey}-01` : range.from}
                                    locale={ptLocale}
                                    firstDay={1}
                                    height="auto"
                                    headerToolbar={false}
                                    noEventsContent="Sem publicações nem ocasiões neste mês."
                                    validRange={{ start: range.from, end: nextDay(range.to) }}
                                    editable={false} selectable={false} droppable={false} dayMaxEvents={false}
                                    events={events}
                                    datesSet={(arg) => {
                                        const d = arg.view.currentStart;
                                        const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
                                        setSelectedKey((prev) => (prev === key ? prev : key));
                                    }}
                                    eventClick={(arg) => {
                                        const ep = arg.event.extendedProps as any;
                                        if (ep.kind === "post") openPost((ep.post as EditorialPost).id);
                                        else setPanelAnchor(ep.item as Item);
                                    }}
                                    eventContent={(arg) => {
                                        const ep = arg.event.extendedProps as any;
                                        if (ep.kind === "post") {
                                            const p = ep.post as EditorialPost;
                                            const stage = postStage(p);
                                            const sm = STAGE_META[stage];
                                            return (
                                                <div className="w-100 px-1 rounded d-flex align-items-center gap-1" title={`${p.overdue ? "Atrasada · " : ""}${sm.label}${p.blog ? " (artigo do blog)" : ""}: ${p.publish_time ? `${p.publish_time} ` : ""}${p.title}`}
                                                    style={{ whiteSpace: "nowrap", overflow: "hidden", lineHeight: 1.3, cursor: "pointer", fontSize: "0.72rem", borderLeft: `3px solid ${p.overdue ? "var(--vz-danger)" : sm.hex}`, background: `${sm.hex}26`, color: "var(--vz-body-color)" }}>
                                                    {p.overdue && <span className="rounded px-1 fw-semibold flex-shrink-0 bg-danger text-white" style={{ fontSize: "0.62rem" }}>Atrasada</span>}
                                                    {channelIcons(p).map((c) => <i key={c.icon} className={c.icon} />)}
                                                    <span className="rounded px-1 fw-semibold flex-shrink-0" title={sm.label} style={{ background: sm.hex, color: stageTextColor(stage), fontSize: "0.62rem" }}>{sm.short}</span>
                                                    <span style={{ overflow: "hidden", textOverflow: "ellipsis" }}>{p.title}</span>
                                                </div>
                                            );
                                        }
                                        const it = ep.item as Item;
                                        return (
                                            <div className={`w-100 px-1 rounded ${anchorChip(it)}`} style={{ whiteSpace: "normal", lineHeight: 1.2, cursor: "pointer", fontSize: "0.7rem", opacity: it.hidden ? 0.7 : 1 }}>
                                                <i className="ri-calendar-event-line me-1" />
                                                <span className={it.hidden ? "text-decoration-line-through" : ""}>{it.title}</span>
                                                {it.type === "range" && <i className="ri-time-line ms-1" title="período" />}
                                            </div>
                                        );
                                    }}
                                />
                            )}
                        </CardBody>
                    </Card>
                )}
            </Container>

            {/* Ocasião (âncora): detalhe, esconder/mostrar/apagar e criar uma publicação nessa data. */}
            <Offcanvas isOpen={!!panelAnchor} toggle={() => setPanelAnchor(null)} direction="end">
                <OffcanvasHeader toggle={() => setPanelAnchor(null)}>Ocasião</OffcanvasHeader>
                <OffcanvasBody>
                    {panelAnchor && (() => {
                        const date = panelAnchor.type === "range" ? (panelAnchor as RangeItem).start : (panelAnchor as DayItem).date;
                        const open = monthOpen(date);
                        return (
                            <div>
                                <div className="d-flex align-items-center gap-2 mb-2">
                                    <span className={`badge ${anchorChip(panelAnchor)}`}>{panelAnchor.owned ? "própria" : "herdada"}</span>
                                    {panelAnchor.hidden && <span className="badge bg-warning-subtle text-warning">escondida</span>}
                                    {panelAnchor.type === "range" && <span className="badge bg-info-subtle text-info">período</span>}
                                </div>
                                <h5 className={`mb-1 ${panelAnchor.hidden ? "text-decoration-line-through text-muted" : ""}`}>{panelAnchor.title}</h5>
                                <p className="text-muted fs-13 mb-2">{fmtDate(date)} · {RULE_LABEL[panelAnchor.rule_type] ?? panelAnchor.rule_type}</p>
                                {panelAnchor.suggestion && <div className="bg-primary-subtle text-body rounded p-2 mb-3 fs-13"><i className="ri-lightbulb-flash-line text-primary me-1" />{panelAnchor.suggestion}</div>}
                                <div className="d-flex flex-wrap gap-2">
                                    {canProduce && open && <Button color="primary" size="sm" onClick={() => { setPanelAnchor(null); newPost(date); }}><i className="ri-add-line me-1" />Publicação nesta data</Button>}
                                    {open ? (
                                        panelAnchor.owned ? <ActionsMenu size="sm" label="Mais ações da âncora" disabled={working} items={[{ label: "Apagar âncora", icon: "ri-delete-bin-line", danger: true, onClick: async () => { if (await confirmAction({ title: `Apagar a âncora "${panelAnchor.title}"?`, text: "Esta ação não se desfaz.", confirmText: "Apagar", icon: "warning", confirmVariant: "danger" })) onDeleteAnchor(panelAnchor); } }]} />
                                            : panelAnchor.hidden ? <Button color="outline-primary" size="sm" disabled={working} onClick={() => onShow(panelAnchor)}><i className="ri-eye-line me-1" />Mostrar</Button>
                                                : <Button color="outline-primary" size="sm" disabled={working} onClick={() => onHide(panelAnchor)}><i className="ri-eye-off-line me-1" />Esconder</Button>
                                    ) : <span className="text-muted fs-13"><i className="ri-lock-2-line me-1" />Mês fechado</span>}
                                </div>
                            </div>
                        );
                    })()}
                </OffcanvasBody>
            </Offcanvas>

            {/* O painel único da publicação */}
            <PostPanel target={panel} onClose={() => setPanel(null)} companyId={companyId} anchors={anchorLinkOptions} pillars={pillars} range={range}
                canProduce={canProduce} formats={formats} monthOpen={monthOpen} onCalendar={(cal) => { applyCalendar(cal); setReload((k) => k + 1); }} onChanged={refresh} />

            {/* Modal — fecho em cascata */}
            <Modal isOpen={!!cascade} toggle={() => setCascade(null)} centered>
                <ModalHeader toggle={() => setCascade(null)}>Fechar em cascata</ModalHeader>
                <ModalBody>
                    {cascade && (
                        <>
                            <p className="mb-2">Fechar <strong>{monthLabel(cascade.month_key)}</strong> vai fechar também os meses seguintes:</p>
                            <ul className="mb-2">{cascade.closes_also.map((k) => <li key={k}>{monthLabel(k)}</li>)}</ul>
                            <p className="text-muted mb-0 fs-13">O trabalho fica guardado e reaparece quando voltar a abrir.</p>
                        </>
                    )}
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setCascade(null)}>Cancelar</Button>
                    <Button color="primary" onClick={() => cascade && performClose(cascade)}><i className="ri-lock-2-line me-1" />Fechar estes meses</Button>
                </ModalFooter>
            </Modal>

            {/* "Como funciona" (diagrama carregado só ao abrir) e legenda das etapas. */}
            {howOpen && (
                <Suspense fallback={<Modal isOpen centered><ModalBody className="text-center py-5"><Spinner /></ModalBody></Modal>}>
                    <HowItWorksModal isOpen={howOpen} toggle={() => setHowOpen(false)} companyId={companyId} profileReady={ideasGate ? ideasGate.ready : null}
                        blockedReason={ideasGate?.reason ?? null} canProduce={canProduce} stageCounts={stageCounts} />
                </Suspense>
            )}
            <StageLegendModal isOpen={legendOpen} toggle={() => setLegendOpen(false)} />
            {companyId > 0 && (
                <ReviewLinksModal isOpen={reviewOpen} toggle={() => setReviewOpen(false)} companyId={companyId} canProduce={canProduce}
                    focusLinkId={reviewFocus} onChanged={refresh} />
            )}

            {/* "Gerar ideias do mês" (aceitação ideia a ideia) */}
            {selected && (
                <IdeasModal isOpen={ideasOpen} toggle={() => setIdeasOpen(false)} companyId={companyId} year={selected.year} month={selected.month}
                    monthLabel={monthLabel(selectedKey)} onAccepted={(cal) => applyCalendar(cal)} />
            )}

            {/* Âncora própria */}
            <Modal isOpen={createOpen} toggle={() => setCreateOpen(false)} centered>
                <ModalHeader toggle={() => setCreateOpen(false)}>Nova âncora própria{selected && <>, {monthLabel(selected.month_key)}</>}</ModalHeader>
                <ModalBody>
                    <Form onSubmit={(e) => { e.preventDefault(); submitCreate(); }}>
                        <FormGroup>
                            <Label for="an-title">Título</Label>
                            <Input id="an-title" value={form.title} onChange={(e) => setF({ title: e.target.value })} placeholder="Por exemplo, Aniversário da Empresa" autoFocus />
                        </FormGroup>
                        <FormGroup>
                            <Label for="an-rule">Tipo de regra</Label>
                            <XSelect id="an-rule" value={form.rule_type} onChange={(x) => setF({ rule_type: x })} options={[
                                { value: "fixa", label: "Data fixa (dia e mês)" }, { value: "nth_weekday", label: "N-ésimo dia da semana" },
                                { value: "periodo", label: "Período (intervalo)" }, { value: "relativa_pascoa", label: "Relativa à Páscoa" }]} />
                        </FormGroup>
                        {form.rule_type === "fixa" && (
                            <Row className="g-2">
                                <Col xs={7}><FormGroup><Label for="an-m">Mês</Label><XSelect id="an-m" value={form.month} onChange={(x) => setF({ month: x })} options={MONTHS_PT.map((m, i) => ({ value: i + 1, label: m }))} /></FormGroup></Col>
                                <Col xs={5}><FormGroup><Label for="an-d">Dia</Label><Input id="an-d" type="number" min={1} max={31} value={form.day} onChange={(e) => setF({ day: Number(e.target.value) })} /></FormGroup></Col>
                            </Row>
                        )}
                        {form.rule_type === "nth_weekday" && (
                            <Row className="g-2">
                                <Col xs={4}><FormGroup><Label for="an-o">Ordinal</Label><XSelect id="an-o" value={form.ordinal} onChange={(x) => setF({ ordinal: x })} options={ORDINALS.map((o) => ({ value: o.v, label: o.l }))} /></FormGroup></Col>
                                <Col xs={4}><FormGroup><Label for="an-w">Dia</Label><XSelect id="an-w" value={form.weekday} onChange={(x) => setF({ weekday: x })} options={WEEKDAYS_PT.map((w, i) => ({ value: i, label: w }))} /></FormGroup></Col>
                                <Col xs={4}><FormGroup><Label for="an-m2">Mês</Label><XSelect id="an-m2" value={form.month} onChange={(x) => setF({ month: x })} options={MONTHS_PT.map((m, i) => ({ value: i + 1, label: m }))} /></FormGroup></Col>
                            </Row>
                        )}
                        {form.rule_type === "periodo" && (
                            <>
                                <Row className="g-2">
                                    <Col xs={7}><FormGroup><Label for="an-sm">Mês (início)</Label><XSelect id="an-sm" value={form.start_month} onChange={(x) => setF({ start_month: x })} options={MONTHS_PT.map((m, i) => ({ value: i + 1, label: m }))} /></FormGroup></Col>
                                    <Col xs={5}><FormGroup><Label for="an-sd">Dia (início)</Label><Input id="an-sd" type="number" min={1} max={31} value={form.start_day} onChange={(e) => setF({ start_day: Number(e.target.value) })} /></FormGroup></Col>
                                </Row>
                                <Row className="g-2">
                                    <Col xs={7}><FormGroup><Label for="an-em">Mês (fim)</Label><XSelect id="an-em" value={form.end_month} onChange={(x) => setF({ end_month: x })} options={MONTHS_PT.map((m, i) => ({ value: i + 1, label: m }))} /></FormGroup></Col>
                                    <Col xs={5}><FormGroup><Label for="an-ed">Dia (fim)</Label><Input id="an-ed" type="number" min={1} max={31} value={form.end_day} onChange={(e) => setF({ end_day: Number(e.target.value) })} /></FormGroup></Col>
                                </Row>
                            </>
                        )}
                        {form.rule_type === "relativa_pascoa" && (
                            <FormGroup>
                                <Label for="an-e">Dias face à Páscoa (mais ou menos)</Label>
                                <Input id="an-e" type="number" value={form.easter_offset} onChange={(e) => setF({ easter_offset: Number(e.target.value) })} />
                                <small className="text-muted">Por exemplo: -2 é a Sexta-feira Santa, 60 é o Corpo de Deus, 0 é a Páscoa.</small>
                            </FormGroup>
                        )}
                        <FormGroup className="mb-0">
                            <Label for="an-s">Gancho de conteúdo <span className="text-muted fw-normal">(opcional)</span></Label>
                            <Input id="an-s" type="textarea" rows={3} value={form.suggestion} onChange={(e) => setF({ suggestion: e.target.value })} placeholder="Abordagem sugerida para esta ocasião" />
                        </FormGroup>
                    </Form>
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setCreateOpen(false)}>Cancelar</Button>
                    <Button color="primary" disabled={working} onClick={submitCreate}>{working ? <Spinner size="sm" /> : <><i className="ri-check-line me-1" />Criar</>}</Button>
                </ModalFooter>
            </Modal>
        </div>
    );
}
