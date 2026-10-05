import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
    Card, CardBody, CardHeader, Container, Row, Col, Spinner, Button,
    Modal, ModalHeader, ModalBody, ModalFooter, Form, FormGroup, Label, Input,
    Offcanvas, OffcanvasHeader, OffcanvasBody,
} from "reactstrap";
import FullCalendar from "@fullcalendar/react";
import dayGridPlugin from "@fullcalendar/daygrid";
import ptLocale from "@fullcalendar/core/locales/pt";
import Select from "react-select";
import { reactSelectTheme } from "helpers/reactSelectStyles";
import { toast, ToastContainer } from "react-toastify";
import BreadCrumb from "Components/Common/BreadCrumb";
import {
    getEditorialCalendar, setEditorialSector, openEditorialMonth, closeEditorialMonth,
    hideEditorialAnchor, showEditorialAnchor, createEditorialOwnAnchor, deleteEditorialOwnAnchor,
    createEditorialPost, updateEditorialPost, deleteEditorialPost, getBrandProfile, getBlogs,
} from "helpers/laravel_helper";
import {
    EditorialPost, EDITORIAL_POST_STATUS_META, MEDIA_FORMATS, mediaFormatLabel, POST_CHANNEL_META, POST_FORMATS, POST_STATUS_ORDER, PostStatus, SITE_FORMAT,
} from "common/models/editorialPost.model";
import { BLOG_STATUS_META } from "common/models/blog.model";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import SectorChooser from "./SectorChooser";
import CreativeModal from "./CreativeModal";
import IdeasModal from "./IdeasModal";

/**
 * XPLENDOR — Linha Editorial (Camada 2 redesenhada + Publicações P1). Mesmos dados do
 * calendar() e os MESMOS endpoints. Âncoras = ocasiões (chips subtis); Publicações = trabalho
 * (chips com ícone do canal + estado). Offcanvas por dia: detalhe da âncora + posts do dia
 * (editar/apagar em mês aberto). O Board é Camada 3 → placeholder. ZERO backend.
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
const MONTHS_SHORT = ["Jan", "Fev", "Mar", "Abr", "Mai", "Jun", "Jul", "Ago", "Set", "Out", "Nov", "Dez"];
const WEEKDAYS_PT = ["Domingo", "Segunda", "Terça", "Quarta", "Quinta", "Sexta", "Sábado"];
const ORDINALS = [{ v: 1, l: "1.º" }, { v: 2, l: "2.º" }, { v: 3, l: "3.º" }, { v: 4, l: "4.º" }, { v: 5, l: "5.º" }, { v: -1, l: "Último" }];
const RULE_LABEL: Record<string, string> = { fixa: "Data fixa", nth_weekday: "Dia da semana", periodo: "Período", relativa_pascoa: "Relativa à Páscoa" };

const monthLabel = (key: string) => { const [y, m] = key.split("-"); return `${MONTHS_PT[Number(m) - 1]} ${y}`; };
const shortLabel = (key: string) => { const [, m] = key.split("-"); return MONTHS_SHORT[Number(m) - 1]; };
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

// format = tipo de conteúdo (18 valores); media_format = formato da rede (vocabulário F2).
type PostForm = { id?: number; publish_date: string; title: string; format: string; media_format: string; channel: string; keyword: string; status: PostStatus; link: string; blog_id: string };
const emptyPost = (date: string): PostForm => ({ publish_date: date, title: "", format: POST_FORMATS[0], media_format: "", channel: "instagram", keyword: "", status: "rascunho", link: "", blog_id: "" });
const formatsFor = (channel: string) => (channel === "instagram" || channel === "facebook" ? MEDIA_FORMATS[channel] : []);
/** Estado a mostrar: num post do canal "site" ligado a um artigo, o do artigo. */
const postStatusMeta = (p: EditorialPost) => (p.blog ? BLOG_STATUS_META[p.blog.status] : EDITORIAL_POST_STATUS_META[p.status]);

export default function EditorialCalendarPage() {
    document.title = "Linha Editorial | Xplendor";

    const companyId = useMemo(() => {
        const a = sessionStorage.getItem("authUser");
        try { return a ? Number(JSON.parse(a).company_id || 0) : 0; } catch { return 0; }
    }, []);

    const calRef = useRef<FullCalendar | null>(null);

    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [hasSector, setHasSector] = useState<boolean | null>(null);
    const [sectorName, setSectorName] = useState<string>("");
    const [range, setRange] = useState<{ from: string; to: string } | null>(null);
    const [items, setItems] = useState<Item[]>([]);
    const [posts, setPosts] = useState<EditorialPost[]>([]);
    const [months, setMonths] = useState<MonthState[]>([]);

    const [view, setView] = useState<"calendar" | "board">("calendar");
    const [selectedKey, setSelectedKey] = useState<string>("");
    const [acting, setActing] = useState(false);
    const [working, setWorking] = useState(false);
    const [cascade, setCascade] = useState<MonthState | null>(null);

    // Offcanvas por DIA: a data + a âncora clicada (se foi âncora).
    const [panelDate, setPanelDate] = useState<string | null>(null);
    const [panelAnchor, setPanelAnchor] = useState<Item | null>(null);

    const [createOpen, setCreateOpen] = useState(false);           // modal criar âncora própria
    const [form, setForm] = useState<CreateForm>(emptyForm(1));
    const [postOpen, setPostOpen] = useState(false);               // modal criar/editar publicação
    const [postForm, setPostForm] = useState<PostForm>(emptyPost(""));
    const [creativeOpen, setCreativeOpen] = useState(false);
    // "Gerar ideias do mês" e guia de descoberta (Perfil da Marca preenchido?).
    const [ideasOpen, setIdeasOpen] = useState(false);
    const [profileFilled, setProfileFilled] = useState<boolean | null>(null);
    const navigate = useNavigate();

    const applyCalendar = useCallback((d: any) => {
        setHasSector(!!d.has_sector);
        if (d.has_sector) {
            setSectorName(d.sector?.name ?? "");
            setRange({ from: d.from, to: d.to });
            setItems(d.items ?? []);
            setPosts(d.posts ?? []);
            setMonths(d.months ?? []);
            setSelectedKey((prev) => prev || (d.months?.[0]?.month_key ?? ""));
        }
    }, []);

    const load = useCallback(async () => {
        if (!companyId) { setLoading(false); return; }
        setLoading(true);
        try {
            const r: any = await getEditorialCalendar(companyId);
            applyCalendar(r?.data ?? {});
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível carregar a linha editorial.");
        } finally { setLoading(false); }
    }, [companyId, applyCalendar]);

    useEffect(() => { load(); }, [load]);
    useEffect(() => {
        if (!companyId) return;
        getBrandProfile(companyId).then((r: any) => setProfileFilled(!r?.data?.is_empty)).catch(() => setProfileFilled(null));
    }, [companyId, ideasOpen]);

    const chooseSector = async (sectorId: number) => {
        setSaving(true);
        try { await setEditorialSector(companyId, sectorId); toast.success("Ramo definido."); await load(); }
        catch (e: any) { toast.error(e?.message ?? "Não foi possível definir o ramo."); }
        finally { setSaving(false); }
    };

    const selected = useMemo(() => months.find((m) => m.month_key === selectedKey) ?? null, [months, selectedKey]);
    const monthIsEmpty = useMemo(() => !posts.some((p) => p.month_key === selectedKey), [posts, selectedKey]);
    /** Editor do blog com o título e o tema da publicação; o artigo fica ligado a ela ao guardar. */
    const writeArticleUrl = (p: EditorialPost) => {
        const q = new URLSearchParams({ editorial_post_id: String(p.id), title: p.title });
        if (p.keyword) q.set("keyword", p.keyword);
        return `/blogs/create?${q.toString()}`;
    };
    const firstKey = months[0]?.month_key;
    const lastKey = months[months.length - 1]?.month_key;

    // Limites de NAVEGAÇÃO (ler): a janela de leitura vai 12 meses para trás
    // (range.from) até +11 à frente (range.to). O passado é só consulta.
    const minKey = range ? range.from.slice(0, 7) : firstKey;       // 12 meses atrás
    const maxKey = range ? range.to.slice(0, 7) : lastKey;          // +11 à frente
    const currentKey = useMemo(() => months.find((m) => m.is_current)?.month_key ?? firstKey, [months, firstKey]);
    // Mês em consulta (navegado para trás): fora da tira e anterior ao corrente.
    const isPastView = !!selectedKey && !!currentKey && selectedKey < currentKey;

    const gotoKey = (key: string) => {
        const [y, m] = key.split("-").map(Number);
        calRef.current?.getApi().gotoDate(new Date(y, m - 1, 1));
    };

    // Ligações do sino: ?ideas=AAAA-MM abre as ideias desse mês; ?creative=ID abre o
    // criativo da publicação. O resultado à espera é retomado pelo próprio modal.
    const [searchParams, setSearchParams] = useSearchParams();
    const handledLink = useRef(false);
    useEffect(() => {
        if (handledLink.current || !range) return;
        const ideasKey = searchParams.get("ideas");
        const creativeId = Number(searchParams.get("creative") || 0);
        if (!ideasKey && !creativeId) return;
        handledLink.current = true;
        if (ideasKey && months.some((mo) => mo.month_key === ideasKey)) {
            setSelectedKey(ideasKey);
            gotoKey(ideasKey);
            setIdeasOpen(true);
        }
        const post = creativeId ? posts.find((p) => p.id === creativeId) : null;
        if (post) {
            gotoKey(post.month_key);
            openEditPost(post);
            setCreativeOpen(true);
        }
        const next = new URLSearchParams(searchParams);
        next.delete("ideas");
        next.delete("creative");
        setSearchParams(next, { replace: true });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [range, months, posts]);

    // ── Eventos: âncoras (ocasiões) + publicações (trabalho) ──
    const events = useMemo(() => {
        const anchorEvents = items.map((it) => ({
            start: it.type === "range" ? (it as RangeItem).start : (it as DayItem).date,
            end: it.type === "range" ? nextDay((it as RangeItem).end) : undefined,
            allDay: true, display: "block",
            backgroundColor: "transparent", borderColor: "transparent",
            classNames: ["bg-transparent", "border-0", "shadow-none", "p-0"],
            extendedProps: { kind: "anchor", item: it } as any,
        }));
        const postEvents = posts.map((p) => ({
            start: p.publish_date, allDay: true, display: "block",
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

    // ── B2 ──
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

    // ── B3a: âncoras ──
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

    // ── B3a: criar âncora própria ──
    const openCreate = () => { if (selected) { setForm(emptyForm(selected.month)); setCreateOpen(true); } };
    const setF = (patch: Partial<CreateForm>) => setForm((p) => ({ ...p, ...patch }));
    const submitCreate = async () => {
        const f = form;
        const payload: any = { title: f.title.trim(), rule_type: f.rule_type, suggestion: f.suggestion.trim() || null };
        if (f.rule_type === "fixa") { payload.month = f.month; payload.day = f.day; }
        else if (f.rule_type === "nth_weekday") { payload.month = f.month; payload.ordinal = f.ordinal; payload.weekday = f.weekday; }
        else if (f.rule_type === "periodo") { payload.start_month = f.start_month; payload.start_day = f.start_day; payload.end_month = f.end_month; payload.end_day = f.end_day; }
        else if (f.rule_type === "relativa_pascoa") { payload.easter_offset = f.easter_offset; }
        if (!payload.title) { toast.error("Dá um título à âncora."); return; }
        setWorking(true);
        try { const r: any = await createEditorialOwnAnchor(companyId, payload); applyCalendar(r?.data ?? {}); toast.success("Âncora própria criada."); setCreateOpen(false); }
        catch (e: any) { toast.error(e?.message ?? "Não foi possível criar a âncora."); }
        finally { setWorking(false); }
    };

    // ── P1: publicações ──
    // Opções de ligação a âncora (herdada 'a:' / própria 'o:') — distintas por espaço de id.
    const anchorLinkOptions = useMemo(() => {
        const seen = new Set<string>();
        const opts: { value: string; label: string }[] = [{ value: "", label: "— Sem âncora —" }];
        for (const it of items) {
            const key = `${it.owned ? "o" : "a"}:${it.anchor_id}`;
            if (seen.has(key)) continue;
            seen.add(key);
            opts.push({ value: key, label: `${it.title}${it.owned ? " (própria)" : ""}` });
        }
        return opts;
    }, [items]);

    const openCreatePost = (date: string) => { setPostForm(emptyPost(date)); setPostOpen(true); };
    const openEditPost = (p: EditorialPost) => {
        setPostForm({
            id: p.id, publish_date: p.publish_date, title: p.title, format: p.format, media_format: p.media_format ?? "",
            channel: p.channel, keyword: p.keyword ?? "", status: p.status,
            link: p.anchor_id ? `a:${p.anchor_id}` : p.own_anchor_id ? `o:${p.own_anchor_id}` : "",
            blog_id: p.blog_id ? String(p.blog_id) : "",
        });
        setPostOpen(true);
    };
    const setPF = (patch: Partial<PostForm>) => setPostForm((p) => ({ ...p, ...patch }));
    // Artigos para ligar ao canal "site" (carregados quando o modal abre nesse canal).
    const [siteBlogs, setSiteBlogs] = useState<{ id: number; title: string; status: string }[]>([]);
    useEffect(() => {
        if (!postOpen || postForm.channel !== "site" || !companyId) return;
        getBlogs(companyId, { perPage: 100 }).then((r: any) => setSiteBlogs(r?.data?.page?.data ?? [])).catch(() => setSiteBlogs([]));
    }, [postOpen, postForm.channel, companyId]);
    const submitPost = async () => {
        const f = postForm;
        if (!f.title.trim()) { toast.error("Dá um título à publicação."); return; }
        const payload: any = {
            title: f.title.trim(), publish_date: f.publish_date, format: f.channel === "site" ? SITE_FORMAT : f.format,
            media_format: f.channel === "site" ? null : (f.media_format || null),
            channel: f.channel, status: f.status, keyword: f.keyword.trim() || null,
            blog_id: f.channel === "site" && f.blog_id ? Number(f.blog_id) : null,
        };
        if (f.link.startsWith("a:")) payload.anchor_id = Number(f.link.slice(2));
        else if (f.link.startsWith("o:")) payload.own_anchor_id = Number(f.link.slice(2));
        setWorking(true);
        try {
            const r: any = f.id ? await updateEditorialPost(companyId, f.id, payload) : await createEditorialPost(companyId, payload);
            applyCalendar(r?.data ?? {});
            toast.success(f.id ? "Publicação atualizada." : "Publicação criada.");
            setPostOpen(false);
        } catch (e: any) { toast.error(e?.message ?? "Não foi possível guardar a publicação."); }
        finally { setWorking(false); }
    };
    const onDeletePost = async (p: EditorialPost) => {
        setWorking(true);
        try { const r: any = await deleteEditorialPost(companyId, p.id); applyCalendar(r?.data ?? {}); toast.success("Publicação apagada."); }
        catch (e: any) { toast.error(e?.message ?? "Não foi possível apagar a publicação."); await load(); }
        finally { setWorking(false); }
    };

    // Estado do offcanvas: posts do dia + se o mês está aberto.
    const panelPosts = useMemo(() => (panelDate ? posts.filter((p) => p.publish_date === panelDate) : []), [posts, panelDate]);
    const panelMonthOpen = panelDate ? (months.find((m) => m.month_key === panelDate.slice(0, 7))?.state === "open") : false;

    const openPanel = (date: string, anchor: Item | null) => { setPanelDate(date); setPanelAnchor(anchor); };

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <BreadCrumb title="Linha Editorial" pageTitle="Marketing" />

                {loading ? (
                    <div className="text-center py-5"><Spinner color="primary" /></div>
                ) : hasSector === false ? (
                    <Row className="justify-content-center"><Col xl={8}>
                        <SectorChooser companyId={companyId} busy={saving} onChoose={chooseSector} />
                    </Col></Row>
                ) : (
                    <Card>
                        <CardHeader className="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div>
                                <h5 className="mb-0">Linha Editorial</h5>
                                <small className="text-muted">Ramo: <strong>{sectorName}</strong></small>
                            </div>
                            <div className="btn-group" role="group">
                                <Button color={view === "calendar" ? "primary" : "light"} size="sm" onClick={() => setView("calendar")}>
                                    <i className="ri-calendar-2-line me-1" />Calendário
                                </Button>
                                {/* 
                                <Button color={view === "board" ? "primary" : "light"} size="sm" onClick={() => setView("board")}>
                                    <i className="ri-layout-column-line me-1" />Board
                                </Button>
                                */}
                            </div>
                        </CardHeader>

                        <CardBody>
                            {view === "board" ? (
                                <div className="text-center py-5">
                                    <div className="avatar-lg mx-auto mb-4">
                                        <span className="avatar-title bg-primary-subtle text-primary rounded-circle fs-1"><i className="ri-layout-column-line" /></span>
                                    </div>
                                    <h5 className="mb-2">O quadro de publicações chega em breve</h5>
                                    <p className="text-muted mb-0">
                                        Vais poder organizar as publicações por estado (rascunho · revisão · publicada · otimizada)<br />
                                        num quadro arrastável. Para já, planeia no <strong>Calendário</strong>.
                                    </p>
                                </div>
                            ) : (
                                <>
                                    {/* TIRA DOS 12 MESES */}
                                    <div className="d-flex gap-2 overflow-auto pb-2 mb-3">
                                        {months.map((mo) => {
                                            const active = mo.month_key === selectedKey;
                                            const open = mo.state === "open";
                                            return (
                                                <button key={mo.month_key} type="button" onClick={() => gotoKey(mo.month_key)}
                                                    className="btn btn-sm d-flex align-items-center gap-1 flex-shrink-0"
                                                    style={{ border: `1px solid ${active ? "var(--vz-primary)" : "var(--vz-border-color)"}`, background: active ? "var(--vz-primary-subtle)" : "var(--vz-card-bg)", color: active ? "var(--vz-primary)" : "var(--vz-body-color)", borderRadius: 8 }}
                                                    title={monthLabel(mo.month_key)}>
                                                    <i className={open ? "ri-check-line text-success" : "ri-lock-2-line text-muted"} />
                                                    <span className="fw-semibold">{shortLabel(mo.month_key)}</span>
                                                    {mo.is_current && <span className="badge bg-primary-subtle text-primary">agora</span>}
                                                </button>
                                            );
                                        })}
                                    </div>

                                    {/* CABEÇALHO DA GRELHA — renderiza também em meses PASSADOS
                                        (consulta), não só nos da tira. Navegação até 12 meses atrás. */}
                                    {range && selectedKey && (
                                        <div className="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                                            <div className="d-flex align-items-center gap-2">
                                                <Button color="light" size="sm" disabled={!!minKey && selectedKey <= minKey} onClick={() => calRef.current?.getApi().prev()}><i className="ri-arrow-left-s-line" /></Button>
                                                <h5 className="mb-0" style={{ minWidth: 150, textAlign: "center" }}>{monthLabel(selectedKey)}</h5>
                                                <Button color="light" size="sm" disabled={!!maxKey && selectedKey >= maxKey} onClick={() => calRef.current?.getApi().next()}><i className="ri-arrow-right-s-line" /></Button>
                                                {selected ? (
                                                    selected.state === "open"
                                                        ? <span className="badge bg-success-subtle text-success ms-1"><i className="ri-lock-unlock-line me-1" />Aberto</span>
                                                        : <span className="badge bg-body-secondary text-muted ms-1"><i className="ri-lock-2-line me-1" />Bloqueado</span>
                                                ) : isPastView ? (
                                                    <span className="badge bg-warning-subtle text-warning ms-1" title="Mês passado: só consulta, não editável."><i className="ri-archive-line me-1" />Mês arquivado · só consulta</span>
                                                ) : null}
                                            </div>
                                            {/* Ações de ESCRITA só nos meses da tira (presente+futuro). No passado não há. */}
                                            {selected && (
                                                <div className="d-flex align-items-center gap-2">
                                                    {selected.state === "open" && (
                                                        <>
                                                            <Button color="primary" size="sm" disabled={working} onClick={() => setIdeasOpen(true)}>
                                                                <i className="ri-lightbulb-flash-line me-1" />Gerar ideias
                                                            </Button>
                                                            <Button color="soft-primary" size="sm" disabled={working} onClick={() => openCreatePost(`${selectedKey}-01`)}>
                                                                <i className="ri-image-add-line me-1" />Publicação
                                                            </Button>
                                                            <Button color="soft-secondary" size="sm" disabled={working} onClick={openCreate}>
                                                                <i className="ri-calendar-event-line me-1" />Âncora própria
                                                            </Button>
                                                        </>
                                                    )}
                                                    {selected.can_open ? (
                                                        <Button color="success" size="sm" disabled={acting} onClick={() => doOpen(selected)}>{acting ? <Spinner size="sm" /> : <><i className="ri-lock-unlock-line me-1" />Abrir mês</>}</Button>
                                                    ) : selected.can_close ? (
                                                        <Button color="light" size="sm" disabled={acting} onClick={() => doClose(selected)}>{acting ? <Spinner size="sm" /> : <><i className="ri-lock-2-line me-1" />Fechar mês</>}</Button>
                                                    ) : selected.state === "closed" ? (
                                                        <span className="text-muted fs-12"><i className="ri-lock-2-line me-1" />Abre o mês anterior primeiro</span>
                                                    ) : null}
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    {/* DESCOBERTA: mês da tira sem publicações → guia em 3 passos. */}
                                    {selected && monthIsEmpty && (
                                        <div className="border border-dashed rounded p-3 mb-3 bg-light-subtle">
                                            <div className="fw-semibold mb-2"><i className="ri-route-line me-1 text-primary" />Como começar {monthLabel(selectedKey)}</div>
                                            <div className="row g-2 fs-13">
                                                <div className="col-md-4">
                                                    <div className="d-flex gap-2">
                                                        <span className={`badge rounded-pill ${profileFilled ? "bg-success" : "bg-primary"} align-self-start`}>{profileFilled ? <i className="ri-check-line" /> : "1"}</span>
                                                        <div>
                                                            <Link to="/brand-profile" className="fw-medium">Perfil da Marca</Link>
                                                            <div className="text-muted">{profileFilled ? "Preenchido. Pode rever quando quiser." : "Tom, público e pilares: dão contexto às ideias."}</div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="col-md-4">
                                                    <div className="d-flex gap-2">
                                                        <span className="badge rounded-pill bg-primary align-self-start">2</span>
                                                        <div>
                                                            {selected.state === "open"
                                                                ? <button type="button" className="btn btn-link p-0 fw-medium fs-13 align-baseline" onClick={() => setIdeasOpen(true)}>Gerar ideias</button>
                                                                : <span className="fw-medium">Gerar ideias</span>}
                                                            <div className="text-muted">{selected.state === "open" ? "A IA propõe ideias para o mês; aceita as que quiser." : "Abra o mês para gerar ideias."}</div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="col-md-4">
                                                    <div className="d-flex gap-2">
                                                        <span className="badge rounded-pill bg-primary align-self-start">3</span>
                                                        <div>
                                                            <span className="fw-medium">Criativo ou artigo</span>
                                                            <div className="text-muted">Em cada publicação: "Sugerir criativo" (Instagram e Facebook) ou "Escrever artigo" (Site).</div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* GRELHA */}
                                    {range && (
                                        <FullCalendar
                                            ref={calRef as any}
                                            plugins={[dayGridPlugin]}
                                            initialView="dayGridMonth"
                                            // Abrir no mês CORRENTE (não em range.from, que agora é 12
                                            // meses atrás — isso é só a fronteira de consulta).
                                            initialDate={currentKey ? `${currentKey}-01` : range.from}
                                            locale={ptLocale}
                                            firstDay={1}
                                            height="auto"
                                            headerToolbar={false}
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
                                                if (ep.kind === "post") openPanel((ep.post as EditorialPost).publish_date, null);
                                                else openPanel(arg.event.startStr, ep.item as Item);
                                            }}
                                            eventContent={(arg) => {
                                                const ep = arg.event.extendedProps as any;
                                                if (ep.kind === "post") {
                                                    const p = ep.post as EditorialPost;
                                                    const meta = postStatusMeta(p);
                                                    return (
                                                        <div className={`w-100 px-1 rounded d-flex align-items-center gap-1 bg-${meta.color}-subtle text-${meta.color}`} style={{ whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis", lineHeight: 1.3, cursor: "pointer", fontSize: "0.72rem", borderLeft: `3px solid var(--vz-${meta.color})` }}>
                                                            <i className={POST_CHANNEL_META[p.channel].icon} />
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
                                </>
                            )}
                        </CardBody>
                    </Card>
                )}
            </Container>

            {/* OFFCANVAS — dia: detalhe da âncora + publicações do dia (Camada 3 preenche mais) */}
            <Offcanvas isOpen={!!panelDate} toggle={() => setPanelDate(null)} direction="end">
                <OffcanvasHeader toggle={() => setPanelDate(null)}>{panelDate ? fmtDate(panelDate) : ""}</OffcanvasHeader>
                <OffcanvasBody>
                    {/* Detalhe da âncora clicada (ocasião) */}
                    {panelAnchor && (
                        <div className="mb-4">
                            <div className="d-flex align-items-center gap-2 mb-2">
                                <span className={`badge ${anchorChip(panelAnchor)}`}>{panelAnchor.owned ? "própria" : "herdada"}</span>
                                {panelAnchor.hidden && <span className="badge bg-warning-subtle text-warning">escondida</span>}
                                {panelAnchor.type === "range" && <span className="badge bg-info-subtle text-info">período</span>}
                            </div>
                            <h5 className={`mb-2 ${panelAnchor.hidden ? "text-decoration-line-through text-muted" : ""}`}>{panelAnchor.title}</h5>
                            <p className="text-muted fs-13 mb-2">{RULE_LABEL[panelAnchor.rule_type] ?? panelAnchor.rule_type}</p>
                            {panelAnchor.suggestion && (
                                <div className="bg-primary-subtle text-body rounded p-2 mb-3 fs-13">
                                    <i className="ri-lightbulb-flash-line text-primary me-1" />{panelAnchor.suggestion}
                                </div>
                            )}
                            {panelMonthOpen ? (
                                panelAnchor.owned ? (
                                    <Button color="soft-danger" size="sm" disabled={working} onClick={() => onDeleteAnchor(panelAnchor)}><i className="ri-delete-bin-line me-1" />Apagar âncora</Button>
                                ) : panelAnchor.hidden ? (
                                    <Button color="soft-secondary" size="sm" disabled={working} onClick={() => onShow(panelAnchor)}><i className="ri-eye-line me-1" />Mostrar</Button>
                                ) : (
                                    <Button color="soft-secondary" size="sm" disabled={working} onClick={() => onHide(panelAnchor)}><i className="ri-eye-off-line me-1" />Esconder</Button>
                                )
                            ) : (
                                <span className="text-muted fs-13"><i className="ri-lock-2-line me-1" />Mês bloqueado</span>
                            )}
                            <hr className="my-4" />
                        </div>
                    )}

                    {/* Publicações do dia */}
                    <div className="d-flex align-items-center justify-content-between mb-3">
                        <h6 className="text-uppercase text-muted fs-11 mb-0" style={{ letterSpacing: "0.05em" }}>Publicações</h6>
                        {panelMonthOpen && panelDate && (
                            <Button color="soft-primary" size="sm" disabled={working} onClick={() => openCreatePost(panelDate)}><i className="ri-add-line me-1" />Adicionar</Button>
                        )}
                    </div>

                    {panelPosts.length === 0 ? (
                        <p className="text-muted fs-13">Sem publicações neste dia.</p>
                    ) : (
                        <div className="vstack gap-2">
                            {panelPosts.map((p) => {
                                const meta = postStatusMeta(p);
                                return (
                                    <div key={p.id} className="border rounded p-2">
                                        <div className="d-flex align-items-start justify-content-between gap-2">
                                            <div className="flex-grow-1">
                                                <div className="fw-semibold"><i className={`${POST_CHANNEL_META[p.channel].icon} me-1`} />{p.title}</div>
                                                <div className="d-flex flex-wrap gap-1 mt-1">
                                                    <span className={`badge bg-${meta.color}-subtle text-${meta.color}`}><i className={`${meta.icon} me-1`} />{meta.label}</span>
                                                    {p.media_format && <span className="badge bg-dark-subtle text-body" title="Formato"><i className="ri-layout-grid-line me-1" />{mediaFormatLabel(p.media_format)}</span>}
                                                    <span className="badge bg-light text-body" title="Tipo de conteúdo">{p.format}</span>
                                                    {p.has_creative && <span className="badge bg-success-subtle text-success" title="Criativo guardado"><i className="ri-magic-line me-1" />Criativo</span>}
                                                    {p.keyword && <span className="badge bg-primary-subtle text-primary">#{p.keyword}</span>}
                                                    {p.linked_title && <span className="badge bg-secondary-subtle text-secondary"><i className="ri-links-line me-1" />{p.linked_title}</span>}
                                                    {p.blog && <Link to={`/blogs/${p.blog.id}`} className="badge bg-info-subtle text-info"><i className="ri-article-line me-1" />{p.blog.title}</Link>}
                                                </div>
                                                {/* Ponte para o Blog: o artigo novo fica ligado a esta publicação. */}
                                                {p.channel === "site" && !p.blog && (
                                                    <Button color="soft-info" size="sm" className="mt-2" onClick={() => navigate(writeArticleUrl(p))}>
                                                        <i className="ri-quill-pen-line me-1" />Escrever artigo
                                                    </Button>
                                                )}
                                            </div>
                                            {panelMonthOpen && (
                                                <div className="d-flex flex-shrink-0 gap-1">
                                                    <button type="button" className="btn btn-sm btn-ghost-secondary p-1" title="Editar" disabled={working} onClick={() => openEditPost(p)}><i className="ri-pencil-line" /></button>
                                                    <button type="button" className="btn btn-sm btn-ghost-danger p-1" title="Apagar" disabled={working} onClick={() => onDeletePost(p)}><i className="ri-delete-bin-line" /></button>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    <hr className="my-4" />
                    <p className="text-muted fs-13 mb-0">Cada publicação tem formato, tipo de conteúdo, estado e palavra-chave. O criativo abre-se ao editar a publicação.</p>
                </OffcanvasBody>
            </Offcanvas>

            {/* Modal — fecho em cascata (B2) */}
            <Modal isOpen={!!cascade} toggle={() => setCascade(null)} centered>
                <ModalHeader toggle={() => setCascade(null)}>Fechar em cascata</ModalHeader>
                <ModalBody>
                    {cascade && (
                        <>
                            <p className="mb-2">Fechar <strong>{monthLabel(cascade.month_key)}</strong> vai fechar também os meses seguintes:</p>
                            <ul className="mb-2">{cascade.closes_also.map((k) => <li key={k}>{monthLabel(k)}</li>)}</ul>
                            <p className="text-muted mb-0 fs-13">O trabalho fica guardado e reaparece quando voltares a abrir.</p>
                        </>
                    )}
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setCascade(null)}>Cancelar</Button>
                    <Button color="danger" onClick={() => cascade && performClose(cascade)}><i className="ri-lock-2-line me-1" />Fechar estes meses</Button>
                </ModalFooter>
            </Modal>

            {/* Modal — criar/editar publicação (P1) */}
            <Modal isOpen={postOpen} toggle={() => setPostOpen(false)} centered>
                <ModalHeader toggle={() => setPostOpen(false)}>{postForm.id ? "Editar publicação" : "Nova publicação"}</ModalHeader>
                <ModalBody>
                    <Form onSubmit={(e) => { e.preventDefault(); submitPost(); }}>
                        <FormGroup>
                            <Label>Título / tema</Label>
                            <Input value={postForm.title} onChange={(e) => setPF({ title: e.target.value })} placeholder="Ex.: Menu especial de Natal" autoFocus />
                        </FormGroup>
                        <Row className="g-2">
                            <Col xs={6}><FormGroup><Label>Data</Label>
                                <Input type="date" value={postForm.publish_date} min={range?.from} max={range?.to} onChange={(e) => setPF({ publish_date: e.target.value })} /></FormGroup></Col>
                            <Col xs={6}><FormGroup><Label>Canal</Label>
                                <Input type="select" value={postForm.channel} onChange={(e) => {
                                    const channel = e.target.value;
                                    // O formato é de cada rede: ao mudar de rede, só fica se existir na nova.
                                    setPF({ channel, media_format: formatsFor(channel).some((f) => f.value === postForm.media_format) ? postForm.media_format : "" });
                                }}>
                                    <option value="instagram">Instagram</option>
                                    <option value="facebook">Facebook</option>
                                    <option value="site">Site (blog)</option>
                                </Input></FormGroup></Col>
                        </Row>
                        {postForm.channel === "site" ? (
                            <FormGroup>
                                <Label>Artigo do blog</Label>
                                <Input type="select" value={postForm.blog_id} onChange={(e) => setPF({ blog_id: e.target.value })}>
                                    <option value="">Ainda sem artigo</option>
                                    {siteBlogs.map((b) => <option key={b.id} value={b.id}>{b.title} ({BLOG_STATUS_META[b.status as keyof typeof BLOG_STATUS_META]?.label ?? b.status})</option>)}
                                </Input>
                                <div className="form-text">
                                    {postForm.blog_id ? "O estado mostrado no calendário vem do artigo (rascunho, em revisão, agendado, publicado)." : <>Pode criar o artigo em <Link to="/blogs/create">Blog</Link> e ligá-lo depois.</>}
                                </div>
                            </FormGroup>
                        ) : null}
                        {postForm.channel !== "site" && (
                            <Row className="g-2">
                                <Col xs={6}><FormGroup><Label>Formato</Label>
                                    <Input type="select" value={postForm.media_format} onChange={(e) => setPF({ media_format: e.target.value })}>
                                        <option value="">Por definir</option>
                                        {formatsFor(postForm.channel).map((f) => <option key={f.value} value={f.value}>{f.label}</option>)}
                                    </Input></FormGroup></Col>
                                <Col xs={6}><FormGroup><Label>Tipo de conteúdo</Label>
                                    <Input type="select" value={postForm.format} onChange={(e) => setPF({ format: e.target.value })}>
                                        {POST_FORMATS.map((f) => <option key={f} value={f}>{f}</option>)}
                                    </Input></FormGroup></Col>
                            </Row>
                        )}
                        <Row className="g-2">
                            {!(postForm.channel === "site" && postForm.blog_id) && (
                                <Col xs={6}><FormGroup><Label>Estado</Label>
                                    <Input type="select" value={postForm.status} onChange={(e) => setPF({ status: e.target.value as PostStatus })}>
                                        {POST_STATUS_ORDER.map((s) => <option key={s} value={s}>{EDITORIAL_POST_STATUS_META[s].label}</option>)}
                                    </Input></FormGroup></Col>
                            )}
                        </Row>
                        <FormGroup>
                            <Label>Palavra-chave</Label>
                            <Input value={postForm.keyword} onChange={(e) => setPF({ keyword: e.target.value })} placeholder="Ex.: natal" />
                        </FormGroup>
                        <FormGroup>
                            <Label>Ligar a âncora (opcional)</Label>
                            <Select
                                styles={reactSelectTheme}
                                menuPortalTarget={document.body}
                                options={anchorLinkOptions}
                                value={anchorLinkOptions.find((o) => o.value === postForm.link) ?? anchorLinkOptions[0]}
                                onChange={(o: any) => setPF({ link: o?.value ?? "" })}
                                isSearchable
                            />
                        </FormGroup>
                    </Form>
                </ModalBody>
                <ModalFooter>
                    {postForm.id && postForm.channel !== "site" && (
                        <Button color="soft-primary" className="me-auto" onClick={() => setCreativeOpen(true)}>
                            <i className="ri-magic-line me-1" />Criativo
                        </Button>
                    )}
                    <Button color="light" onClick={() => setPostOpen(false)}>Cancelar</Button>
                    <Button color="primary" disabled={working} onClick={submitPost}>{working ? <Spinner size="sm" /> : <><i className="ri-check-line me-1" />{postForm.id ? "Guardar" : "Criar"}</>}</Button>
                </ModalFooter>
            </Modal>

            {/* Modal: "Gerar ideias do mês" (aceitação ideia a ideia) */}
            {selected && (
                <IdeasModal
                    isOpen={ideasOpen}
                    toggle={() => setIdeasOpen(false)}
                    companyId={companyId}
                    year={selected.year}
                    month={selected.month}
                    monthLabel={monthLabel(selectedKey)}
                    onAccepted={(cal) => applyCalendar(cal)}
                />
            )}

            {/* Modal: criativo da publicação (Sugerir criativo e criativo aceite) */}
            {postForm.id && postForm.channel !== "site" && (
                <CreativeModal
                    isOpen={creativeOpen}
                    toggle={() => setCreativeOpen(false)}
                    companyId={companyId}
                    postId={postForm.id}
                    postTitle={postForm.title}
                    onSaved={(mediaFormat) => { setPF({ media_format: mediaFormat ?? "" }); void load(); }}
                />
            )}

            {/* Modal — criar âncora própria (B3a) */}
            <Modal isOpen={createOpen} toggle={() => setCreateOpen(false)} centered>
                <ModalHeader toggle={() => setCreateOpen(false)}>Nova âncora própria{selected && <> — {monthLabel(selected.month_key)}</>}</ModalHeader>
                <ModalBody>
                    <Form onSubmit={(e) => { e.preventDefault(); submitCreate(); }}>
                        <FormGroup>
                            <Label>Título</Label>
                            <Input value={form.title} onChange={(e) => setF({ title: e.target.value })} placeholder="Ex.: Aniversário da Empresa" autoFocus />
                        </FormGroup>
                        <FormGroup>
                            <Label>Tipo de regra</Label>
                            <Input type="select" value={form.rule_type} onChange={(e) => setF({ rule_type: e.target.value })}>
                                <option value="fixa">Data fixa (dia/mês)</option>
                                <option value="nth_weekday">N-ésimo dia da semana</option>
                                <option value="periodo">Período (intervalo)</option>
                                <option value="relativa_pascoa">Relativa à Páscoa</option>
                            </Input>
                        </FormGroup>
                        {form.rule_type === "fixa" && (
                            <Row className="g-2">
                                <Col xs={7}><FormGroup><Label>Mês</Label>
                                    <Input type="select" value={form.month} onChange={(e) => setF({ month: Number(e.target.value) })}>{MONTHS_PT.map((m, i) => <option key={i} value={i + 1}>{m}</option>)}</Input></FormGroup></Col>
                                <Col xs={5}><FormGroup><Label>Dia</Label>
                                    <Input type="number" min={1} max={31} value={form.day} onChange={(e) => setF({ day: Number(e.target.value) })} /></FormGroup></Col>
                            </Row>
                        )}
                        {form.rule_type === "nth_weekday" && (
                            <Row className="g-2">
                                <Col xs={4}><FormGroup><Label>Ordinal</Label>
                                    <Input type="select" value={form.ordinal} onChange={(e) => setF({ ordinal: Number(e.target.value) })}>{ORDINALS.map((o) => <option key={o.v} value={o.v}>{o.l}</option>)}</Input></FormGroup></Col>
                                <Col xs={4}><FormGroup><Label>Dia</Label>
                                    <Input type="select" value={form.weekday} onChange={(e) => setF({ weekday: Number(e.target.value) })}>{WEEKDAYS_PT.map((w, i) => <option key={i} value={i}>{w}</option>)}</Input></FormGroup></Col>
                                <Col xs={4}><FormGroup><Label>Mês</Label>
                                    <Input type="select" value={form.month} onChange={(e) => setF({ month: Number(e.target.value) })}>{MONTHS_PT.map((m, i) => <option key={i} value={i + 1}>{m}</option>)}</Input></FormGroup></Col>
                            </Row>
                        )}
                        {form.rule_type === "periodo" && (
                            <>
                                <Row className="g-2">
                                    <Col xs={7}><FormGroup><Label>Mês (início)</Label>
                                        <Input type="select" value={form.start_month} onChange={(e) => setF({ start_month: Number(e.target.value) })}>{MONTHS_PT.map((m, i) => <option key={i} value={i + 1}>{m}</option>)}</Input></FormGroup></Col>
                                    <Col xs={5}><FormGroup><Label>Dia (início)</Label>
                                        <Input type="number" min={1} max={31} value={form.start_day} onChange={(e) => setF({ start_day: Number(e.target.value) })} /></FormGroup></Col>
                                </Row>
                                <Row className="g-2">
                                    <Col xs={7}><FormGroup><Label>Mês (fim)</Label>
                                        <Input type="select" value={form.end_month} onChange={(e) => setF({ end_month: Number(e.target.value) })}>{MONTHS_PT.map((m, i) => <option key={i} value={i + 1}>{m}</option>)}</Input></FormGroup></Col>
                                    <Col xs={5}><FormGroup><Label>Dia (fim)</Label>
                                        <Input type="number" min={1} max={31} value={form.end_day} onChange={(e) => setF({ end_day: Number(e.target.value) })} /></FormGroup></Col>
                                </Row>
                            </>
                        )}
                        {form.rule_type === "relativa_pascoa" && (
                            <FormGroup>
                                <Label>Dias face à Páscoa (± inteiro)</Label>
                                <Input type="number" value={form.easter_offset} onChange={(e) => setF({ easter_offset: Number(e.target.value) })} />
                                <small className="text-muted">Ex.: −2 = Sexta-feira Santa · +60 = Corpo de Deus · 0 = Páscoa.</small>
                            </FormGroup>
                        )}
                        <FormGroup className="mb-0">
                            <Label>Gancho de conteúdo <span className="text-muted fw-normal">(opcional)</span></Label>
                            <Input type="textarea" rows={3} value={form.suggestion} onChange={(e) => setF({ suggestion: e.target.value })} placeholder="Abordagem sugerida para esta ocasião…" />
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
