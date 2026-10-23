import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Button, Card, CardBody, Container, Row, Col, Spinner, Label, Alert, Modal, ModalHeader, ModalBody, ModalFooter } from "reactstrap";
import { Link, useNavigate, useParams } from "react-router-dom";
import { toast, ToastContainer } from "react-toastify";
import Select from "react-select";
import { reactSelectTheme } from "../../helpers/reactSelectStyles";
import PageHeader, { Crumb } from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import XSelect from "Components/Common/Select";
import ActionsMenu from "Components/Common/ActionsMenu";
import ReasonButton from "Components/Common/ReasonButton";
import ArticlePicker from "./faturas/ArticlePicker";
import CreateArticleModal, { CreateArticleData } from "./faturas/CreateArticleModal";
import LineArticleCell from "./faturas/LineArticleCell";
import LaunchCard, { LaunchTrigger, launchState } from "./faturas/LaunchCard";
import VatByRateCard from "./faturas/VatByRateCard";
import RereadModal from "./faturas/RereadModal";
import PromoModal, { promoTag } from "./faturas/PromoModal";
import GuidesCard from "./faturas/GuidesCard";
import {
    getOcrInvoice, updateOcrInvoice, getOcrInvoiceImageBlob, reprocessOcrInvoice, revalidateOcrInvoice,
    searchOcrPingwinLink, confirmOcrPingwinLink, unlinkOcrPingwinLink,
    associateOcrLineArticle, unlinkOcrLineArticle, createOcrLineArticle, acceptOcrLineSuggestions,
    deleteOcrInvoice, makeOcrLineCodePrincipal, correctOcrLineArticleVat,
} from "helpers/laravel_helper";
import {
    OcrInvoiceDetail, OcrInvoiceLine, OcrInvoiceSummary, OcrVatBreakdownRow, OcrSupplierOption,
    OcrPingwinBlock, OcrPingwinDoc, OcrLinkMethod, OCR_LINK_STATUS,
    OcrLineLink, OcrArticlesSummary, OcrLineLinksPayload, OCR_DOC_TYPES, NOTE_BLOCKED, OcrLaunchPreview,
} from "common/models/ocr.model";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import Skeleton from "Components/Common/Skeleton";

/**
 * XPLENDOR — Restauração › Faturas › Validação (OCR Fase A, o CORE). Mostra o que
 * a leitura trouxe (linhas + sumário) EDITÁVEL, com a imagem ao lado para conferir, e
 * avisa quando os totais não fecham (não bloqueia — o utilizador decide). Ao
 * guardar, valida NA XPLENDOR. ⚠️ NÃO escreve no PingWin.
 *
 * F2a: com QR da AT o cabeçalho (NIFs, nº, data, ATCUD) vem do QR — só leitura — e as
 * linhas são conferidas por taxa de IVA contra as bases do QR. "Reprocessar" volta a ler
 * (async, mesmo polling).
 *
 * F3: bloco "No PingWin" — o documento lançado no PingWin a que a fatura está ligada (ou os
 * candidatos, ou o modo guias com seleção e soma). Só lê os espelhos: nada é gravado no PingWin.
 *
 * FB-1: cartão "Lançar no PingWin" (rascunho → fechar / anular), com as guardas que faltam.
 *
 * Descontos de promoção (ex.: "Leve Mais Pague Menos" da Makro): cada um fica no artigo com o mesmo
 * código; a linha mostra o valor, o desconto em € com o código e o líquido. "IVA por taxa" mostra o
 * lido e o calculado. "Revalidar" volta a correr as validações sobre as linhas de agora; "Reler do
 * documento" volta a correr o OCR (mantendo ou substituindo as alterações manuais). Depois de
 * "Validar e guardar" fica-se no ecrã, com os passos seguintes. A imagem pode ser ocultada (a escolha
 * fica no navegador); oculta, a tabela das linhas ocupa a largura toda.
 *
 * F2b: coluna "Artigo" em cada linha (ligada / sugerida / por ligar), pesquisa de artigos,
 * "Criar artigo" e "Aceitar sugestões". A fatura fica "pronta para lançar" com todas as linhas
 * ligadas. As escritas no PingWin (artigo novo, código do fornecedor no artigo) são assíncronas:
 * a página acompanha-as sem mexer no que está a ser editado.
 */

const VAT_OPTS = [0, 6, 13, 23].map((v) => ({ value: v, label: v === 0 ? "Isento" : `${v}%` }));
const num = (v: any): number | null => (v === "" || v === null || v === undefined || isNaN(Number(v)) ? null : Number(v));
const eur = (n?: number | null) => (n ?? 0).toLocaleString("pt-PT", { style: "currency", currency: "EUR" });
const approx = (a: number, b: number) => Math.abs(a - b) <= Math.max(0.05, Math.abs(b) * 0.01);

const emptyLine = (): OcrInvoiceLine => ({ supplier_code: "", item: "", quantity: null, unit: "", unit_price: null, discount_pct: null, line_total: null, vat_rate: null, promo_code: "", discount: 0 });
const IMAGE_KEY = "xp-fatura-imagem-oculta";
const readImageHidden = () => { try { return window.localStorage.getItem(IMAGE_KEY) === "1"; } catch { return false; } };
const writeImageHidden = (v: boolean) => { try { window.localStorage.setItem(IMAGE_KEY, v ? "1" : "0"); } catch { /* sem armazenamento: fica só nesta visita */ } };

/** O corpo de "Validar e guardar" e de "Revalidar" (euros; o valor da linha é o da fatura, antes do desconto). */
const bodyOf = (supplierId: number | null, supplierName: string, supplierNif: string, number: string, issueDate: string, lines: OcrInvoiceLine[], summary: OcrInvoiceSummary) => ({
    supplier_id: supplierId, supplier_name: supplierName || null, supplier_nif: supplierNif || null,
    number: number || null, issue_date: issueDate || null,
    lines: lines.map((l) => ({
        id: l.id ?? null, supplier_code: l.supplier_code || null, item: l.item || null, quantity: num(l.quantity), unit: l.unit || null,
        unit_price: num(l.unit_price), discount_pct: num(l.discount_pct), line_total: num(l.line_total), vat_rate: l.vat_rate ?? null,
        promo_code: l.promo_code || null,
    })),
    summary: {
        goods_total: summary.goods_total, commercial_discount: summary.commercial_discount, taxable_base: summary.taxable_base,
        vat_total: summary.vat_total, withholding: summary.withholding, financial_discount: summary.financial_discount, total: summary.total,
        vat_breakdown: summary.vat_breakdown.filter((b) => b.rate).map((b) => ({ rate: b.rate, base: num(b.base), vat: num(b.vat) })),
    },
});
const emptySummary = (): OcrInvoiceSummary => ({ goods_total: 0, commercial_discount: 0, taxable_base: 0, vat_total: 0, withholding: 0, financial_discount: 0, total: 0, vat_breakdown: [] });

export default function FaturaValidacaoPage() {
    document.title = "Validar fatura | Restauração | Xplendor";
    const navigate = useNavigate();
    const { id } = useParams();
    const invoiceId = Number(id);

    const companyId = useWorkingCompanyId();

    const [inv, setInv] = useState<OcrInvoiceDetail | null>(null);
    const [suppliers, setSuppliers] = useState<OcrSupplierOption[]>([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [imageUrl, setImageUrl] = useState<string | null>(null);
    const [imageIsPdf, setImageIsPdf] = useState(false);
    const [confirmReprocess, setConfirmReprocess] = useState(false);
    const [reprocessing, setReprocessing] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);   // F2c
    const [deleting, setDeleting] = useState(false);
    const [pingwin, setPingwin] = useState<OcrPingwinBlock | null>(null);
    // F2b — ligação das linhas a artigos (separada do formulário: as ações não apagam edições)
    const [links, setLinks] = useState<Record<number, OcrLineLink>>({});
    const [artSummary, setArtSummary] = useState<OcrArticlesSummary | null>(null);
    const [picker, setPicker] = useState<number | null>(null);       // índice da linha
    const [creating, setCreating] = useState<number | null>(null);   // índice da linha
    const [createBusy, setCreateBusy] = useState(false);
    const [lineBusy, setLineBusy] = useState<number | null>(null);   // id da linha
    // F2d: confirmações das ações da linha ("Tornar principal", IVA da fatura, corrigir o IVA do artigo).
    const [lineAction, setLineAction] = useState<{ kind: "principal" | "vat_info" | "vat_fix"; lineId: number } | null>(null);
    const [acceptOpen, setAcceptOpen] = useState(false);
    const [minConf, setMinConf] = useState(0.8);
    // Ficar no ecrã depois de "Validar e guardar", Revalidar, Reler, a imagem e o lançamento a partir do painel.
    const [saved, setSaved] = useState(false);
    const [revalidating, setRevalidating] = useState(false);
    const [rereadOpen, setRereadOpen] = useState(false);
    const [imageHidden, setImageHidden] = useState<boolean>(readImageHidden);
    const [launchTrigger, setLaunchTrigger] = useState<LaunchTrigger>(null);
    const [launchRefresh, setLaunchRefresh] = useState(0);
    // A última pré-visualização do cartão do lançamento: o painel "Fatura validada" usa as mesmas guardas.
    const [launchPreview, setLaunchPreview] = useState<OcrLaunchPreview | null>(null);
    const savedBody = useRef<string>("");
    const [promoLine, setPromoLine] = useState<number | null>(null);   // "Promoção…" da linha

    // Estado editável
    const [supplierId, setSupplierId] = useState<number | null>(null);
    const [supplierName, setSupplierName] = useState("");
    const [supplierNif, setSupplierNif] = useState("");
    const [number, setNumber] = useState("");
    const [issueDate, setIssueDate] = useState("");
    const [lines, setLines] = useState<OcrInvoiceLine[]>([]);
    const [summary, setSummary] = useState<OcrInvoiceSummary>(emptySummary());

    const linksFrom = (ls: any[]): Record<number, OcrLineLink> =>
        Object.fromEntries((ls ?? []).filter((l) => l.id).map((l) => [l.id, l as OcrLineLink]));

    const hydrate = useCallback((d: OcrInvoiceDetail) => {
        setInv(d);
        setLinks(linksFrom(d.lines ?? []));
        setArtSummary(d.articles_summary ?? null);
        setSupplierId(d.supplier_id);
        setSupplierName(d.supplier_name ?? "");
        setSupplierNif(d.supplier_nif ?? "");
        setNumber(d.number ?? "");
        setIssueDate(d.issue_date ?? "");
        setLines(d.lines?.length ? d.lines : []);
        setSummary(d.summary ?? emptySummary());
        savedBody.current = JSON.stringify(bodyOf(d.supplier_id, d.supplier_name ?? "", d.supplier_nif ?? "", d.number ?? "", d.issue_date ?? "", d.lines ?? [], d.summary ?? emptySummary()));
    }, []);

    const fetchInvoice = useCallback(async () => {
        if (!companyId || !invoiceId) return;
        try {
            const res: any = await getOcrInvoice(companyId, invoiceId);
            setSuppliers(res?.data?.suppliers ?? []);
            setPingwin(res?.data?.pingwin ?? null);
            const d: OcrInvoiceDetail = res?.data?.invoice;
            if (d) hydrate(d);
        } catch {
            toast.error("Não foi possível carregar a fatura.");
        } finally {
            setLoading(false);
        }
    }, [companyId, invoiceId, hydrate]);

    useEffect(() => { fetchInvoice(); }, [fetchInvoice]);

    // Poll enquanto a IA processa.
    useEffect(() => {
        if (inv?.status !== "processing") return;
        const t = setInterval(fetchInvoice, 3000);
        return () => clearInterval(t);
    }, [inv?.status, fetchInvoice]);

    // F3: só o bloco "No PingWin" (sem tocar no formulário) — polling da pesquisa no worker.
    const refreshPingwin = useCallback(async () => {
        if (!companyId || !invoiceId) return;
        try {
            const res: any = await getOcrInvoice(companyId, invoiceId);
            setPingwin(res?.data?.pingwin ?? null);
        } catch { /* fica o que está */ }
    }, [companyId, invoiceId]);

    useEffect(() => {
        if (!pingwin?.search_pending) return;
        const t = setInterval(refreshPingwin, 3000);
        return () => clearInterval(t);
    }, [pingwin?.search_pending, refreshPingwin]);

    // F2b: acompanha as escritas no PingWin (artigo a criar, código do fornecedor) sem mexer no formulário.
    const linksPending = Object.values(links).some((l) => (l.creating && !["ok", "erro"].includes(l.creating.status)) || l.supplier_code_status === "pendente"
        || l.vat_write?.status === "a_editar");
    useEffect(() => {
        if (!linksPending || !companyId || !invoiceId) return;
        const t = setInterval(async () => {
            try {
                const res: any = await getOcrInvoice(companyId, invoiceId);
                const d = res?.data?.invoice;
                if (d) { setLinks(linksFrom(d.lines ?? [])); setArtSummary(d.articles_summary ?? null); }
            } catch { /* tenta na próxima */ }
        }, 4000);
        return () => clearInterval(t);
    }, [linksPending, companyId, invoiceId]);

    const applyLinks = (p?: OcrLineLinksPayload) => {
        if (!p) return;
        setLinks(linksFrom(p.line_links));
        setArtSummary(p.articles_summary);
    };

    // F2d: se o artigo já tiver outro código deste fornecedor, não há pergunta: o do PingWin fica
    // principal e o da linha fica alternativo (a mensagem do servidor diz quais).
    const associate = async (lineId: number, articleId: number, method = "manual") => {
        setLineBusy(lineId);
        try {
            const r: any = await associateOcrLineArticle(companyId, invoiceId, lineId, { article_id: articleId, method });
            applyLinks(r?.data);
            setPicker(null);
            toast.success(r?.message ?? "Linha ligada ao artigo.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível ligar a linha.");
        } finally {
            setLineBusy(null);
        }
    };

    const runLineAction = async () => {
        if (!lineAction || lineAction.kind === "vat_info") { setLineAction(null); return; }
        const { kind, lineId } = lineAction;
        setLineBusy(lineId);
        try {
            const r: any = kind === "principal"
                ? await makeOcrLineCodePrincipal(companyId, invoiceId, lineId)
                : await correctOcrLineArticleVat(companyId, invoiceId, lineId);
            applyLinks(r?.data);
            toast.info(r?.message ?? "Pedido enviado ao PingWin.");
            setLineAction(null);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível pedir a alteração no PingWin.");
        } finally {
            setLineBusy(null);
        }
    };

    const unlinkLine = async (lineId: number) => {
        setLineBusy(lineId);
        try {
            const r: any = await unlinkOcrLineArticle(companyId, invoiceId, lineId);
            applyLinks(r?.data);
            toast.success("Ligação desfeita.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível desfazer a ligação.");
        } finally {
            setLineBusy(null);
        }
    };

    const createArticle = async (d: CreateArticleData) => {
        const line = creating !== null ? lines[creating] : null;
        if (!line?.id) return;
        setCreateBusy(true);
        try {
            const r: any = await createOcrLineArticle(companyId, invoiceId, line.id, d);
            applyLinks(r?.data);
            setCreating(null);
            toast.info("A criar o artigo no PingWin… a linha liga-se quando terminar.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível pedir a criação do artigo.");
        } finally {
            setCreateBusy(false);
        }
    };

    const acceptAll = async () => {
        setLineBusy(-1);
        try {
            const r: any = await acceptOcrLineSuggestions(companyId, invoiceId, minConf);
            applyLinks(r?.data);
            setAcceptOpen(false);
            const skipped = r?.data?.result?.skipped?.length ?? 0;
            toast.success(`${r?.data?.result?.accepted ?? 0} sugestões aceites${skipped ? ` (${skipped} ficaram por o artigo ter outro código deste fornecedor)` : ""}.`);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível aceitar as sugestões.");
        } finally {
            setLineBusy(null);
        }
    };

    // Imagem (disco privado → blob).
    useEffect(() => {
        if (!companyId || !invoiceId || inv?.status === "processing") return;
        let revoked: string | null = null;
        getOcrInvoiceImageBlob(companyId, invoiceId)
            .then((blob: any) => {
                const b = blob as Blob;
                setImageIsPdf((b.type || "").includes("pdf"));
                const u = URL.createObjectURL(b);
                revoked = u;
                setImageUrl(u);
            })
            .catch(() => setImageUrl(null));
        return () => { if (revoked) URL.revokeObjectURL(revoked); };
    }, [companyId, invoiceId, inv?.status]);

    const supplierOptions = useMemo(
        () => suppliers.map((s) => ({ value: s.id, label: `${s.name ?? "—"}${s.tax_number ? ` · ${s.tax_number}` : ""}` })),
        [suppliers]
    );

    const setLine = (i: number, patch: Partial<OcrInvoiceLine>) =>
        setLines((prev) => prev.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));
    const setSum = (patch: Partial<OcrInvoiceSummary>) => setSummary((prev) => ({ ...prev, ...patch }));
    const setVat = (i: number, patch: Partial<OcrVatBreakdownRow>) =>
        setSummary((prev) => ({ ...prev, vat_breakdown: prev.vat_breakdown.map((b, idx) => (idx === i ? { ...b, ...patch } : b)) }));

    // ⚠️ A Xplendor calcula a soma das linhas (mais fiável que o sumário da IA).
    // Recalcula ao vivo quando o utilizador edita uma linha.
    // Líquido: o valor de cada linha menos o desconto de promoção que lhe ficou.
    const linesSum = useMemo(() => lines.reduce((a, l) => a + (l.line_total ?? 0) - (l.discount ?? 0), 0), [lines]);
    const round2 = (n: number) => Math.round(n * 100) / 100;

    // Avisos de coerência (não bloqueiam). Com QR a conferência por taxa (abaixo) substitui
    // o aviso "linhas vs base".
    const hasQr = !!inv?.qr_ok;
    const warnings = useMemo(() => {
        const w: string[] = [];
        if (!hasQr && lines.length > 0 && !approx(linesSum, summary.taxable_base))
            w.push(`As linhas somam ${eur(linesSum)}, mas o sumário lido diz base tributável ${eur(summary.taxable_base)}. Confira.`);
        if (!approx(summary.goods_total - summary.commercial_discount, summary.taxable_base))
            w.push(`Mercadorias − desconto comercial (${eur(summary.goods_total - summary.commercial_discount)}) ≠ base tributável (${eur(summary.taxable_base)}).`);
        const chain = summary.taxable_base + summary.vat_total - summary.withholding - summary.financial_discount;
        if (!approx(chain, summary.total))
            w.push(`Base + IVA − retenção − desc. financeiro (${eur(chain)}) ≠ total (${eur(summary.total)}).`);
        return w;
    }, [hasQr, lines.length, linesSum, summary]);

    // Reler do documento (async): a fatura volta a 'processing' e o polling acima faz o resto.
    const reprocess = async (keepManual = false) => {
        setReprocessing(true);
        try {
            const r: any = await reprocessOcrInvoice(companyId, invoiceId, { keep_manual: keepManual });
            setConfirmReprocess(false);
            setRereadOpen(false);
            setSaved(false);
            if (r?.message) toast.info(r.message);
            setInv((prev) => (prev ? { ...prev, status: "processing" } : prev));
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível reler a fatura.");
        } finally {
            setReprocessing(false);
        }
    };

    const body = bodyOf(supplierId, supplierName, supplierNif, number, issueDate, lines, summary);
    const dirty = JSON.stringify(body) !== savedBody.current;

    // Validar e guardar: fica no ecrã, com a confirmação e os passos seguintes.
    const save = async () => {
        setSaving(true);
        try {
            const r: any = await updateOcrInvoice(companyId, invoiceId, body);
            if (r?.data?.invoice) hydrate(r.data.invoice);
            toast.success("Fatura validada e guardada.");
            setSaved(true);
            setLaunchRefresh((n) => n + 1);
            refreshPingwin();
            window.scrollTo?.({ top: 0, behavior: "smooth" });
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível guardar a fatura.");
        } finally {
            setSaving(false);
        }
    };

    // Revalidar: grava as linhas de agora (sem mudar o estado) e volta a correr as validações. Sem IA.
    const revalidate = async (override?: OcrInvoiceLine[]) => {
        setRevalidating(true);
        try {
            const r: any = await revalidateOcrInvoice(companyId, invoiceId, override ? bodyOf(supplierId, supplierName, supplierNif, number, issueDate, override, summary) : body);
            if (r?.data?.invoice) hydrate(r.data.invoice);
            setLaunchRefresh((n) => n + 1);
            toast.success("Validações feitas de novo.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível revalidar a fatura.");
        } finally {
            setRevalidating(false);
        }
    };

    // "Promoção…": muda o código da linha e revalida (a repartição é a do servidor).
    const applyPromo = async (i: number, code: string | null) => {
        const next = lines.map((l, k) => (k === i ? { ...l, promo_code: code } : l));
        setLines(next);
        await revalidate(next);
        setPromoLine(null);
    };

    const toggleImage = () => setImageHidden((v) => { writeImageHidden(!v); return !v; });
    const explained = new Set((inv?.vat_by_rate?.rows ?? []).flatMap((r) => r.explained_by));

    // Cabeçalho comum (página de detalhe: o título é o registo, o breadcrumb é curto).
    const crumbs: Crumb[] = [{ label: "Restauração" }, { label: "Faturas", to: "/restauracao/faturas" }];
    // O tipo de documento (do QR ou lido): uma nota de crédito diz-se como tal e os totais são um crédito.
    const docType = (inv?.doc_type ?? "").toUpperCase();
    const docLabel = OCR_DOC_TYPES[docType] ?? null;
    const isCredit = docType === "NC";
    const noteBlocked = NOTE_BLOCKED[docType] ?? null;
    // Os botões do painel "Fatura validada": os motivos das guardas do lançamento (as mesmas do cartão).
    const launch = launchPreview ? launchState(launchPreview) : null;
    const checking = "A verificar o que falta para gravar no PingWin…";
    const savedDraftReason = noteBlocked ?? (launch ? launch.draft : checking);
    const savedClosedReason = noteBlocked ?? (launch ? launch.closed : checking);
    const pageTitle = inv?.number ? `${docLabel && docType !== "FT" ? docLabel : "Fatura"} ${inv.number}` : "Validar fatura";

    if (loading) {
        return <div className="page-content"><Container fluid><PageHeader title="Validar fatura" breadcrumbs={crumbs} crumbLabel="Validar" /><div className="card"><div className="card-body"><Skeleton variant="lines" lines={10} /></div></div></Container></div>;
    }

    if (inv?.status === "processing") {
        return (
            <div className="page-content"><ToastContainer /><Container fluid>
                <PageHeader title={pageTitle} breadcrumbs={crumbs} crumbLabel="Validar" />
                <div className="text-center py-5">
                    <Spinner className="mb-3" style={{ width: 48, height: 48 }} />
                    <h5>A ler a fatura…</h5>
                    <p className="text-muted">Isto demora alguns segundos. A página atualiza sozinha.</p>
                </div>
            </Container></div>
        );
    }

    // F2c — apagar (fica 30 dias em "Mostrar apagadas").
    const doDelete = async () => {
        setDeleting(true);
        try {
            await deleteOcrInvoice(companyId, invoiceId);
            toast.success("Fatura apagada. Pode repô-la durante 30 dias em “Mostrar apagadas”.");
            navigate("/restauracao/faturas");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível apagar a fatura.");
            setDeleting(false);
            setConfirmDelete(false);
        }
    };
    const deleteMenu = (size: "sm" | "md") => (
        <ActionsMenu size={size} label="Mais ações da fatura" items={[
            { label: "Apagar fatura", icon: "ri-delete-bin-line", danger: true, disabledReason: inv?.delete_block ?? null, onClick: () => setConfirmDelete(true) },
        ]} />
    );
    const deleteModal = (
        <Modal isOpen={confirmDelete} toggle={() => !deleting && setConfirmDelete(false)} centered data-testid="delete-modal">
            <ModalHeader toggle={() => !deleting && setConfirmDelete(false)}>Apagar a fatura?</ModalHeader>
            <ModalBody>
                <p className="mb-2">A fatura sai da lista. Pode repô-la durante 30 dias em “Mostrar apagadas”; depois o ficheiro é apagado.</p>
                {(pingwin?.status === "lancada" || pingwin?.status === "lancada_guias") && (
                    <p className="mb-2 fs-13 text-muted">Está ligada a um documento no PingWin: o documento no PingWin fica; só a fatura carregada é apagada e desligada.</p>
                )}
                <p className="mb-0 fs-13 text-muted">A leitura não volta ao limite mensal.</p>
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={() => setConfirmDelete(false)} disabled={deleting}>Cancelar</Button>
                <Button color="danger" onClick={doDelete} disabled={deleting}>{deleting ? <Spinner size="sm" /> : "Apagar"}</Button>
            </ModalFooter>
        </Modal>
    );

    const reprocessModal = (
        <Modal isOpen={confirmReprocess} toggle={() => !reprocessing && setConfirmReprocess(false)} centered>
            <ModalHeader toggle={() => !reprocessing && setConfirmReprocess(false)}>Reprocessar a fatura?</ModalHeader>
            <ModalBody>
                A fatura volta a ser lida (QR e linhas). O que está neste ecrã e ainda não foi guardado é substituído pela nova leitura.
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={() => setConfirmReprocess(false)} disabled={reprocessing}>Cancelar</Button>
                <Button color="primary" onClick={() => reprocess(false)} disabled={reprocessing}>
                    {reprocessing ? <><Spinner size="sm" className="me-1" /> A pedir…</> : <><i className="ri-refresh-line me-1" />Reprocessar</>}
                </Button>
            </ModalFooter>
        </Modal>
    );

    if (inv?.status === "erro") {
        return (
            <div className="page-content"><ToastContainer /><Container fluid>
                <PageHeader title={pageTitle} breadcrumbs={crumbs} crumbLabel="Validar" />
                <Alert color="danger" className="mt-3">
                    <h5 className="alert-heading">Não foi possível ler a fatura</h5>
                    <p className="mb-2" style={{ whiteSpace: "pre-line" }} data-testid="ocr-error">{inv.error_message || "Erro ao processar. Envie uma imagem mais nítida."}</p>
                    <div className="d-flex gap-2">
                        <Button size="sm" color="primary" onClick={() => setConfirmReprocess(true)}><i className="ri-refresh-line me-1" />Reprocessar</Button>
                        <Button size="sm" color="outline-primary" onClick={() => navigate("/restauracao/faturas")}>Voltar às faturas</Button>
                        {deleteMenu("sm")}
                    </div>
                </Alert>
                {reprocessModal}
                {deleteModal}
            </Container></div>
        );
    }

    if (inv?.status === "nao_desta_empresa") {
        return (
            <div className="page-content"><ToastContainer /><Container fluid>
                <PageHeader title={pageTitle} breadcrumbs={crumbs} crumbLabel="Validar" />
                <Alert color="secondary" className="mt-3">
                    <h5 className="alert-heading"><i className="ri-qr-code-line me-1" />Esta fatura não é desta empresa</h5>
                    <p className="mb-2">{inv.error_message}</p>
                    <p className="mb-2 fs-13 text-muted" data-testid="not-ours-read">{hasQr ? "Lido do QR" : "Lido da fatura"}: emitente {inv.supplier_nif ?? "—"} · adquirente {inv.buyer_nif ?? "—"} · {docLabel ?? inv.doc_type ?? ""} {inv.number ?? ""} · {inv.issue_date ?? ""} · ATCUD {inv.atcud ?? "—"}</p>
                    <Button size="sm" color="primary" onClick={() => navigate("/restauracao/faturas")}>Voltar às faturas</Button>
                </Alert>
            </Container></div>
        );
    }

    // Campos do QR: só leitura, com tooltip "lido do QR".
    const qrLocked = hasQr ? { readOnly: true, title: "lido do QR", className: "form-control bg-light" } : {};
    const qrMark = hasQr ? <i className="ri-qr-code-line ms-1 text-success" title="lido do QR" aria-label="lido do QR" /> : null;

    // Grelha das linhas: sm (célula de tabela); sumário: normal (formulário).
    const numInput = (value: number | null, onChange: (v: number | null) => void, extra: any = {}, small = true) => (
        <input type="number" step="any" className={`form-control ${small ? "form-control-sm" : ""} text-end`}
            value={value ?? ""} onChange={(e) => onChange(num(e.target.value))} {...extra} />
    );

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader
                    title={pageTitle}
                    crumbLabel="Validar"
                    breadcrumbs={crumbs}
                />
                {/* As ações da fatura no cabeçalho deste cartão; o estado da leitura no status (design-system §1 e §2). */}
                <PageCard
                    title="Fatura"
                    status={<>
                        {docLabel && <span className={`badge me-2 ${isCredit ? "bg-primary text-white" : "bg-light text-body"}`} data-testid="doc-type">{docLabel}</span>}
                        <span className="badge bg-info-subtle text-info me-2"><i className="ri-scan-2-line me-1" />Lida automaticamente: verifique os dados</span>
                        {inv?.qr_ok !== null && inv?.qr_ok !== undefined && (
                            inv.qr_ok
                                ? <span className="badge bg-success-subtle text-success me-2" title="Cabeçalho e totais lidos do QR da AT"><i className="ri-qr-code-line me-1" />QR ✓</span>
                                : <span className="badge bg-warning-subtle text-warning me-2" title="O QR não se conseguiu ler: o cabeçalho foi lido do documento"><i className="ri-qr-code-line me-1" />QR ilegível</span>
                        )}
                        {inv?.lines_source && (
                            <span className="badge bg-light text-body me-2">
                                <i className={`${inv.lines_source === "texto" ? "ri-file-text-line" : "ri-image-line"} me-1`} />
                                Linhas {inv.lines_source === "texto" ? "do texto do PDF" : "da imagem"}
                            </span>
                        )}
                        {inv?.confidence ? `${inv.confidence}% confiança` : ""}
                        {inv?.model ? ` · ${inv.model}` : ""}
                        {inv?.attempts && inv.attempts > 1 ? ` · ${inv.attempts} tentativas` : ""}
                    </>}
                    actions={<>
                        <Button size="sm" color="outline-primary" onClick={() => navigate("/restauracao/faturas")} disabled={saving}>Voltar</Button>
                        <Button size="sm" color="outline-secondary" onClick={toggleImage} aria-pressed={imageHidden} data-testid="toggle-image">
                            <i className={`${imageHidden ? "ri-image-line" : "ri-image-off-line"} me-1`} />{imageHidden ? "Mostrar imagem" : "Ocultar imagem"}
                        </Button>
                        <Button size="sm" color="outline-primary" onClick={() => revalidate()} disabled={saving || revalidating} data-testid="revalidate">
                            {revalidating ? <><Spinner size="sm" className="me-1" />A revalidar…</> : <><i className="ri-checkbox-multiple-line me-1" />Revalidar</>}
                        </Button>
                        <Button size="sm" color="outline-primary" onClick={() => setRereadOpen(true)} disabled={saving || revalidating} data-testid="reread">
                            <i className="ri-refresh-line me-1" />Reler do documento
                        </Button>
                        <Button size="sm" color="primary" onClick={save} disabled={saving}>
                            {saving ? <><Spinner size="sm" className="me-1" /> A guardar…</> : <><i className="ri-check-double-line me-1" /> Validar e guardar</>}
                        </Button>
                        {deleteMenu("sm")}
                    </>}
                />
                {saved && (
                    <Alert color="success" className="d-flex flex-wrap align-items-center justify-content-between gap-2" data-testid="saved-panel">
                        <span><i className="ri-checkbox-circle-line me-1" /><strong>Fatura validada e guardada.</strong> {dirty ? "Há alterações por guardar." : "O que fazer a seguir?"}</span>
                        <span className="d-flex flex-wrap gap-2">
                            <ReasonButton size="sm" color="primary" reason={savedDraftReason} data-testid="saved-launch-draft"
                                onClick={() => { setLaunchTrigger({ action: "draft", at: Date.now() }); document.getElementById("xp-launch")?.scrollIntoView({ behavior: "smooth", block: "start" }); }}>
                                <i className="ri-upload-cloud-2-line me-1" />Gravar no PingWin como rascunho
                            </ReasonButton>
                            <ReasonButton size="sm" color="outline-primary" reason={savedClosedReason} data-testid="saved-launch-closed"
                                onClick={() => { setLaunchTrigger({ action: "closed", at: Date.now() }); document.getElementById("xp-launch")?.scrollIntoView({ behavior: "smooth", block: "start" }); }}>
                                <i className="ri-lock-line me-1" />Gravar no PingWin como fechada
                            </ReasonButton>
                            <ReasonButton size="sm" color="outline-secondary" reason={inv?.next_to_launch_id ? null : "Não há outra fatura por lançar."}
                                onClick={() => inv?.next_to_launch_id && navigate(`/restauracao/faturas/${inv.next_to_launch_id}`)} data-testid="next-invoice">
                                Próxima fatura por lançar<i className="ri-arrow-right-line ms-1" />
                            </ReasonButton>
                            <Button size="sm" color="light" onClick={() => navigate("/restauracao/faturas")}>Voltar à lista</Button>
                        </span>
                        {!noteBlocked && launch && launch.draft && (
                            <div className="w-100 fs-13" data-testid="saved-launch-blocked">
                                <i className="ri-error-warning-line me-1" />Ainda não se pode gravar no PingWin: {launch.draft}
                                {launch.fixes.length > 0 && (
                                    <span className="d-flex flex-wrap gap-3 mt-1">
                                        {launch.fixes.map((f) => <Link key={f.to} to={f.to}><i className="ri-arrow-right-line me-1" />{f.label}</Link>)}
                                    </span>
                                )}
                            </div>
                        )}
                    </Alert>
                )}
                {(inv?.reread_unmatched ?? []).length > 0 && (
                    <Alert color="warning" className="py-2" data-testid="reread-unmatched">
                        <i className="ri-error-warning-line me-1" />Estas alterações manuais não encontraram a sua linha na nova leitura e não foram aplicadas: {inv!.reread_unmatched!.join(" · ")}.
                    </Alert>
                )}
                {inv?.status === "por_validar" && inv.error_message && (
                    <Alert color="warning" className="mb-3" data-testid="ocr-manual-review">
                        <i className="ri-error-warning-line me-1" />{inv.error_message}
                    </Alert>
                )}

                {inv?.prices_include_vat && (
                    <Alert color="info" className="py-2" data-testid="vat-included">
                        <i className="ri-percent-line me-1" /><strong>Preços com IVA incluído:</strong> convertidos para valores sem IVA. Cada linha mostra os dois valores; a soma das bases de cada taxa é a do quadro do IVA.
                    </Alert>
                )}
                {isCredit && (
                    <Alert color="primary" className="py-2" data-testid="credit-note">
                        <i className="ri-arrow-go-back-line me-1" /><strong>Nota de crédito:</strong> os valores são um crédito do fornecedor a favor da empresa (por exemplo, vasilhame devolvido). Revê-se como uma fatura e lança-se no PingWin como Nota de crédito de fornecedor, em rascunho, com a data da nota e o número dela como Documento de referência.
                    </Alert>
                )}
                {docType === "ND" && (
                    <Alert color="primary" className="py-2" data-testid="debit-note">
                        <i className="ri-file-add-line me-1" /><strong>Nota de débito:</strong> revê-se como uma fatura; ainda se lança à mão no PingWin.
                    </Alert>
                )}
                {inv?.confidence !== undefined && inv.confidence < 60 && (
                    <Alert color="warning" className="py-2"><i className="ri-alert-line me-1" /> Confiança baixa ({inv.confidence}%): confira tudo com atenção contra a imagem.</Alert>
                )}
                {warnings.length > 0 && (
                    <Alert color="warning" className="py-2">
                        <strong><i className="ri-error-warning-line me-1" />Os totais não fecham:</strong>
                        <ul className="mb-0 mt-1">{warnings.map((w, i) => <li key={i} className="fs-13">{w}</li>)}</ul>
                    </Alert>
                )}

                {inv?.check_status === "confere" && (
                    <Alert color="success" className="py-2"><i className="ri-checkbox-circle-line me-1" /><strong>Confere com o QR:</strong> a soma das linhas por taxa de IVA bate com as bases do QR da AT.</Alert>
                )}
                {inv?.check_status === "nao_confere" && (
                    <Alert color="warning" className="py-2">
                        <strong><i className="ri-error-warning-line me-1" />As linhas não conferem com o QR</strong>
                        <span className="fs-13"> — verifique as linhas contra a imagem (falta, sobra ou valor mal lido).</span>
                        <table className="table table-sm table-borderless mb-0 mt-1 fs-13" style={{ maxWidth: 520 }}>
                            <thead><tr className="text-muted"><th>Taxa</th><th className="text-end">Base no QR</th><th className="text-end">Soma das linhas</th><th className="text-end">Diferença</th></tr></thead>
                            <tbody>
                                {inv.check_diff.map((r, i) => (
                                    <tr key={i} className={r.ok ? "text-muted" : "fw-semibold"}>
                                        <td>{r.rate === null ? "Sem taxa" : r.rate === 0 ? "Isento" : `${r.rate}%`}</td>
                                        <td className="text-end">{eur(r.qr)}</td>
                                        <td className="text-end">{eur(r.lines)}</td>
                                        <td className="text-end">{r.ok ? "✓" : `${r.diff > 0 ? "+" : ""}${eur(r.diff)}`}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </Alert>
                )}
                {inv?.check_status === "sem_qr" && (
                    <Alert color="info" className="py-2" data-testid="no-qr">
                        <i className="ri-qr-code-line me-1" />QR ilegível: o cabeçalho foi lido do documento e não há conferência com o QR. Confira com a imagem; o IVA por taxa compara as linhas com o quadro do IVA lido.
                        {inv.buyer_nif && <div className="fs-13 mt-1" data-testid="buyer-text-check"><i className="ri-checkbox-circle-line me-1 text-success" />Adquirente {inv.buyer_nif}: verificado pelo texto; o QR não se conseguiu ler.</div>}
                    </Alert>
                )}

                <Row className="g-3">
                    {/* Coluna dados (editável): com a imagem oculta, ocupa a largura toda. */}
                    <Col lg={imageHidden ? 12 : 7}>
                        <Card>
                            <div className="card-header"><h5 className="card-title mb-0">Fornecedor & fatura</h5></div>
                            <CardBody>
                                {inv?.handwritten_notes && (
                                    <div className="fs-13 mb-2 p-2 rounded bg-light" data-testid="handwritten-notes">
                                        <i className="ri-quill-pen-line me-1" /><strong>Notas escritas à mão:</strong> {inv.handwritten_notes}
                                        <span className="d-block fs-12 text-muted">Vão para a Nota da folha da contabilidade, se estiver vazia. Não registam pagamentos nem mudam o fornecedor.</span>
                                    </div>
                                )}
                                <Row className="g-2">
                                    <Col md={12}>
                                        <Label className="form-label">Fornecedor (ligar ao sincronizado)</Label>
                                        <Select styles={reactSelectTheme} menuPortalTarget={document.body} isClearable
                                            options={supplierOptions}
                                            value={supplierOptions.find((o) => o.value === supplierId) ?? null}
                                            onChange={(o: any) => setSupplierId(o?.value ?? null)}
                                            placeholder="Escolher fornecedor…" />
                                    </Col>
                                    <Col md={6}><Label className="form-label">Nome (lido)</Label><input className="form-control" value={supplierName} onChange={(e) => setSupplierName(e.target.value)} /></Col>
                                    <Col md={6}><Label className="form-label">NIF{qrMark}</Label><input className="form-control" value={supplierNif} onChange={(e) => setSupplierNif(e.target.value)} {...qrLocked} /></Col>
                                    <Col md={6}><Label className="form-label">Nº fatura{qrMark}</Label><input className="form-control" value={number} onChange={(e) => setNumber(e.target.value)} {...qrLocked} /></Col>
                                    <Col md={6}><Label className="form-label">Data emissão{qrMark}</Label><input type="date" className="form-control" value={issueDate} onChange={(e) => setIssueDate(e.target.value)} {...qrLocked} /></Col>
                                    {(inv?.buyer_nif || inv?.atcud) && <>
                                        <Col md={6}><Label className="form-label">NIF adquirente{qrMark}</Label><input className="form-control bg-light" value={inv?.buyer_nif ?? ""} readOnly title={hasQr ? "lido do QR" : "lido do documento"} /></Col>
                                        <Col md={6}><Label className="form-label">ATCUD{qrMark}</Label><input className="form-control bg-light" value={inv?.atcud ?? ""} readOnly title={hasQr ? "lido do QR" : "lido do documento"} /></Col>
                                    </>}
                                </Row>
                            </CardBody>
                        </Card>

                        <Card>
                            <div className="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <div>
                                    <h5 className="card-title mb-0">Linhas</h5>
                                    {artSummary && artSummary.total > 0 && (
                                        <div className="fs-12 text-muted mt-1" data-testid="articles-summary">
                                            {artSummary.linked} de {artSummary.total} ligadas a artigos
                                            {artSummary.suggested > 0 && <> · {artSummary.suggested} sugeridas</>}
                                            {artSummary.unlinked > 0 && <> · {artSummary.unlinked} por ligar</>}
                                            {artSummary.ready
                                                ? <span className="badge bg-success-subtle text-success ms-2"><i className="ri-check-double-line me-1" />Pronta para lançar</span>
                                                : <span className="badge bg-warning-subtle text-warning ms-2" title="Para lançar no PingWin, todas as linhas têm de estar ligadas a um artigo">Faltam {artSummary.total - artSummary.linked} linhas</span>}
                                        </div>
                                    )}
                                </div>
                                <div className="d-flex gap-2">
                                    {artSummary && artSummary.suggested > 0 && (
                                        <Button size="sm" color="outline-primary" onClick={() => setAcceptOpen(true)} disabled={lineBusy !== null}><i className="ri-check-line me-1" />Aceitar sugestões</Button>
                                    )}
                                    <Button size="sm" color="outline-primary" onClick={() => setLines((p) => [...p, emptyLine()])}><i className="ri-add-line me-1" />Adicionar linha</Button>
                                </div>
                            </div>
                            {/* Larguras pelo conteúdo; tudo alinhado pelo topo; o Item e o menu da linha ficam à vista
                                com scroll horizontal (o PDF visível tira largura). */}
                            <div className="table-responsive xp-lines-scroll">
                                <table className="table table-bordered align-top mb-0 xp-lines" style={{ tableLayout: "fixed", width: "100%", minWidth: 1150 }} data-testid="lines-table">
                                    <colgroup>
                                        <col style={{ width: 128 }} /><col style={{ minWidth: 220 }} /><col style={{ width: 84 }} /><col style={{ width: 66 }} />
                                        <col style={{ width: 98 }} /><col style={{ width: 66 }} /><col style={{ width: 112 }} /><col style={{ width: 100 }} />
                                        <col style={{ width: 230 }} /><col style={{ width: 46 }} />
                                    </colgroup>
                                    <thead className="text-muted table-light">
                                        <tr>
                                            <th className="xp-sticky-1">Cód. fornecedor</th><th className="xp-sticky-2">Item</th><th>Qtd</th><th>Un.</th><th>Preço un.</th><th>Desc.%</th>
                                            <th className="text-end">Total</th><th>IVA</th><th>Artigo</th><th className="xp-sticky-end"><span className="visually-hidden">Ações da linha</span></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {lines.length === 0 ? (
                                            <tr><td colSpan={10} className="text-center text-muted py-3">Sem linhas. Use “Adicionar linha”.</td></tr>
                                        ) : lines.map((l, i) => {
                                            const tag = promoTag(l, inv?.discounts ?? []);
                                            const disc = l.discount ?? 0;
                                            return (
                                            <tr key={i} className={l.id && explained.has(l.id) ? "table-warning" : undefined} data-testid={`line-${i}`}>
                                                <td className="xp-sticky-1"><input className="form-control form-control-sm font-monospace" value={l.supplier_code ?? ""} title={l.supplier_code ?? ""} onChange={(e) => setLine(i, { supplier_code: e.target.value })} aria-label={`Código do fornecedor, linha ${i + 1}`} /></td>
                                                <td className="xp-sticky-2">
                                                    <input className="form-control form-control-sm" value={l.item ?? ""} title={l.item ?? ""} onChange={(e) => setLine(i, { item: e.target.value })} aria-label={`Item, linha ${i + 1}`} />
                                                    {tag && (
                                                        <button type="button" className={`badge border-0 mt-1 ${tag.warn ? "bg-warning-subtle text-warning" : "bg-light text-body"}`} onClick={() => setPromoLine(i)} data-testid={`line-promo-${i}`}>
                                                            <i className="ri-price-tag-3-line me-1" />{tag.text}
                                                        </button>
                                                    )}
                                                    {!!l.iec && <div className="fs-11 text-muted mt-1" data-testid={`line-iec-${i}`} title="O IEC vem numa coluna à parte e soma-se ao valor da linha">Inclui IEC de {eur(l.iec)}</div>}
                                                    {l.vat_included !== null && l.vat_included !== undefined && (
                                                        <div className="fs-11 text-muted mt-1" data-testid={`line-vat-included-${i}`}>Com IVA {eur(l.vat_included)} · sem IVA {eur(l.line_total ?? 0)}</div>
                                                    )}
                                                </td>
                                                <td>{numInput(l.quantity, (v) => setLine(i, { quantity: v }), { "aria-label": `Quantidade, linha ${i + 1}`, title: l.quantity ?? "" })}</td>
                                                <td><input className="form-control form-control-sm" value={l.unit ?? ""} title={l.unit ?? ""} onChange={(e) => setLine(i, { unit: e.target.value })} aria-label={`Unidade, linha ${i + 1}`} /></td>
                                                <td>{numInput(l.unit_price, (v) => setLine(i, { unit_price: v }), { "aria-label": `Preço unitário, linha ${i + 1}`, title: l.unit_price ?? "" })}</td>
                                                <td>{numInput(l.discount_pct, (v) => setLine(i, { discount_pct: v }), { "aria-label": `Desconto em percentagem, linha ${i + 1}` })}</td>
                                                <td className="text-end">
                                                    {disc > 0 ? (
                                                        <div data-testid={`line-discount-${i}`}>
                                                            <div className="form-control form-control-sm form-control-plaintext text-end fw-semibold px-1" title="Líquido: o valor da linha menos o desconto da promoção">{eur((l.line_total ?? 0) - disc)}</div>
                                                            <div className="d-flex align-items-center justify-content-end gap-1 mt-1 fs-11 text-muted">
                                                                <span>bruto</span>
                                                                <input type="number" step="any" className="form-control form-control-sm text-end py-0 px-1 fs-11" style={{ maxWidth: 70, height: 22 }}
                                                                    value={l.line_total ?? ""} onChange={(e) => setLine(i, { line_total: num(e.target.value) })} aria-label={`Valor da linha ${i + 1}`} />
                                                            </div>
                                                        </div>
                                                    ) : numInput(l.line_total, (v) => setLine(i, { line_total: v }), { "aria-label": `Valor da linha ${i + 1}` })}
                                                </td>
                                                <td>
                                                    <XSelect small ariaLabel="Taxa de IVA" options={VAT_OPTS} value={l.vat_rate as any} onChange={(v) => setLine(i, { vat_rate: v as number })} placeholder="Taxa" searchable={false} />
                                                </td>
                                                <td>
                                                    <LineArticleCell
                                                        link={l.id ? links[l.id] ?? null : null}
                                                        saved={!!l.id}
                                                        busy={lineBusy !== null}
                                                        onAccept={(articleId) => l.id && associate(l.id, articleId, "sugestao")}
                                                        onAssociate={() => setPicker(i)}
                                                        onCreate={() => setCreating(i)}
                                                        onUnlink={() => l.id && unlinkLine(l.id)}
                                                        onMakePrincipal={() => l.id && setLineAction({ kind: "principal", lineId: l.id })}
                                                        onVatInfo={() => l.id && setLineAction({ kind: "vat_info", lineId: l.id })}
                                                        onVatFix={() => l.id && setLineAction({ kind: "vat_fix", lineId: l.id })}
                                                    />
                                                </td>
                                                <td className="xp-sticky-end text-center">
                                                    <ActionsMenu size="sm" label={`Ações da linha ${i + 1}`} items={[
                                                        { label: "Promoção…", icon: "ri-price-tag-3-line", onClick: () => setPromoLine(i) },
                                                        { label: "Remover linha", icon: "ri-delete-bin-line", danger: true, onClick: () => setLines((p) => p.filter((_, idx) => idx !== i)) },
                                                    ]} />
                                                </td>
                                            </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </Card>

                        <VatByRateCard vat={inv?.vat_by_rate} discounts={inv?.discounts ?? []} lines={lines} ignored={inv?.ignored_lines ?? []} />

                        <Card>
                            <div className="card-header"><h5 className="card-title mb-0">Sumário</h5></div>
                            <CardBody>
                                {/* ⚠️ Soma das linhas calculada pela Xplendor (mais fiável que o sumário lido). */}
                                <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 p-2 mb-3"
                                    style={{ background: "var(--vz-primary-bg-subtle)", border: "1px solid var(--vz-border-color)", borderRadius: 8 }}>
                                    <div>
                                        <span className="text-muted fs-12 text-uppercase fw-semibold">Soma das linhas (Xplendor){isCredit ? ", crédito" : ""}</span>
                                        <div className="fw-semibold fs-18">{eur(linesSum)}</div>
                                        {!approx(linesSum, summary.goods_total) && (
                                            <div className="text-muted fs-12">Sumário lido (mercadorias): {eur(summary.goods_total)}</div>
                                        )}
                                    </div>
                                    <Button size="sm" color="outline-primary"
                                        onClick={() => setSum({ goods_total: round2(linesSum), taxable_base: summary.taxable_base ? summary.taxable_base : round2(linesSum) })}>
                                        <i className="ri-download-line me-1" />Usar nas mercadorias
                                    </Button>
                                </div>
                                <Row className="g-2">
                                    {([
                                        ["Total mercadorias", "goods_total"], ["Desconto comercial", "commercial_discount"],
                                        ["Base tributável", "taxable_base"], ["Valor IVA total", "vat_total"],
                                        ["Retenção na fonte", "withholding"], ["Desconto financeiro", "financial_discount"],
                                    ] as [string, keyof OcrInvoiceSummary][]).map(([label, key]) => (
                                        <Col md={4} key={key}>
                                            <Label className="form-label">{label}</Label>
                                            {numInput(summary[key] as number, (v) => setSum({ [key]: v ?? 0 } as any), {}, false)}
                                        </Col>
                                    ))}
                                    <Col md={4}>
                                        <Label className="form-label fw-semibold">{isCredit ? "Total do crédito" : "Total"}</Label>
                                        {numInput(summary.total, (v) => setSum({ total: v ?? 0 }), { className: "form-control text-end fw-semibold" }, false)}
                                    </Col>
                                </Row>

                                <div className="d-flex justify-content-between align-items-center mt-3 mb-1">
                                    <span className="fs-12 text-muted text-uppercase fw-semibold">IVA por taxa</span>
                                    <Button size="sm" color="outline-primary" aria-label="Adicionar taxa de IVA" title="Adicionar taxa de IVA" onClick={() => setSum({ vat_breakdown: [...summary.vat_breakdown, { rate: 23, base: null, vat: null }] })}><i className="ri-add-line" /></Button>
                                </div>
                                {summary.vat_breakdown.map((b, i) => (
                                    <Row className="g-2 mb-1 align-items-center" key={i}>
                                        <Col xs={4}><XSelect ariaLabel="Taxa de IVA" options={VAT_OPTS} value={b.rate as any} onChange={(v) => setVat(i, { rate: v as number })} placeholder="Taxa" searchable={false} /></Col>
                                        <Col xs={3}>{numInput(b.base, (v) => setVat(i, { base: v }), {}, false)}</Col>
                                        <Col xs={3}>{numInput(b.vat, (v) => setVat(i, { vat: v }), {}, false)}</Col>
                                        <Col xs={2}><button type="button" className="btn btn-sm btn-outline-danger" aria-label={`Remover taxa ${i + 1}`} onClick={() => setSum({ vat_breakdown: summary.vat_breakdown.filter((_, idx) => idx !== i) })}><i className="ri-delete-bin-line" /></button></Col>
                                    </Row>
                                ))}
                            </CardBody>
                        </Card>

                        {pingwin && (
                            <PingwinLinkCard companyId={companyId} invoiceId={invoiceId} block={pingwin} onBlock={setPingwin}
                                onCreateSupplier={(nif, name) => navigate(`/restauracao/fornecedores?${new URLSearchParams({ novo: "1", nif: nif ?? "", nome: name ?? "" }).toString()}`)}
                                onOpenInvoice={(id) => navigate(`/restauracao/faturas/${id}`)} />
                        )}
                        {/* FB-1: lançar no PingWin em rascunho, fechar, anular */}
                        {inv && (inv.status === "por_validar" || inv.status === "validada") && (
                            <div id="xp-launch">
                                <LaunchCard companyId={companyId} invoiceId={invoiceId} onChanged={refreshPingwin} trigger={launchTrigger} refreshKey={launchRefresh} onPreview={setLaunchPreview} />
                            </div>
                        )}
                        {/* Fatia 5: a conferência com as guias; "Lançar guia" leva ao lançamento acima (nada se grava sozinho). */}
                        {inv && (
                            <GuidesCard companyId={companyId} invoiceId={invoiceId} onLaunch={(g) => {
                                document.getElementById("xp-launch")?.scrollIntoView({ behavior: "smooth", block: "start" });
                                toast.info(`Para lançar a ${g.ref}${g.date ? ` de ${g.date.split("-").reverse().join("/")}` : ""}, use o lançamento no PingWin: confirme as linhas desta guia antes de gravar.`);
                            }} />
                        )}
                    </Col>

                    {/* Coluna imagem: oculta fica montada (o visor do PDF guarda o zoom). */}
                    <Col lg={5} className={imageHidden ? "d-none" : undefined} data-testid="image-col">
                        <Card className="sticky-top" style={{ top: 80 }}>
                            <div className="card-header d-flex justify-content-between align-items-center">
                                <h5 className="card-title mb-0">Imagem da fatura</h5>
                                <Button size="sm" color="link" className="p-0" onClick={toggleImage}>Ocultar imagem</Button>
                            </div>
                            <CardBody className="text-center">
                                {!imageUrl ? (
                                    <Skeleton variant="block" height="70vh" label="A carregar a imagem…" />
                                ) : imageIsPdf ? (
                                    <iframe title="fatura" src={imageUrl} style={{ width: "100%", height: "70vh", border: "1px solid var(--vz-border-color)", borderRadius: 8 }} />
                                ) : (
                                    <img src={imageUrl} alt="Fatura" style={{ maxWidth: "100%", borderRadius: 8, border: "1px solid var(--vz-border-color)" }} />
                                )}
                            </CardBody>
                        </Card>
                    </Col>
                </Row>
                {reprocessModal}
                {deleteModal}
                <PromoModal isOpen={promoLine !== null} index={promoLine} lines={lines} discounts={inv?.discounts ?? []} busy={revalidating}
                    onClose={() => setPromoLine(null)} onApply={applyPromo} />
                <RereadModal isOpen={rereadOpen} companyId={companyId} invoiceId={invoiceId} busy={reprocessing} dirty={dirty}
                    onClose={() => setRereadOpen(false)} onConfirm={(keep) => reprocess(keep)} />

                {/* F2b — pesquisa de artigos, criar artigo, código diferente, aceitar sugestões */}
                <ArticlePicker
                    isOpen={picker !== null}
                    companyId={companyId}
                    supplierNif={supplierNif || null}
                    supplierName={supplierName || null}
                    initialQuery={picker !== null ? (lines[picker]?.item ?? "") : ""}
                    lineLabel={picker !== null ? `${lines[picker]?.supplier_code ? `${lines[picker]?.supplier_code} · ` : ""}${lines[picker]?.item ?? ""}` : ""}
                    onPick={(a) => { const id = picker !== null ? lines[picker]?.id : undefined; if (id) associate(id, a.id); }}
                    onCreate={() => { const i = picker; setPicker(null); setCreating(i); }}
                    onClose={() => setPicker(null)}
                />
                <CreateArticleModal
                    isOpen={creating !== null}
                    companyId={companyId}
                    line={creating !== null ? lines[creating] ?? null : null}
                    supplierName={supplierName || null}
                    busy={createBusy}
                    onSubmit={createArticle}
                    onClose={() => setCreating(null)}
                />
                {/* F2d — ações da linha */}
                {(() => {
                    const la = lineAction ? links[lineAction.lineId] : null;
                    const ln = lineAction ? lines.find((x) => x.id === lineAction.lineId) : null;
                    const vat = la?.vat_check;
                    const title = lineAction?.kind === "principal" ? "Tornar principal no PingWin?"
                        : lineAction?.kind === "vat_fix" ? "Corrigir o IVA do artigo no PingWin?" : "IVA da fatura nesta linha";
                    return (
                        <Modal isOpen={!!lineAction} toggle={() => setLineAction(null)} centered data-testid="line-action-modal">
                            <ModalHeader toggle={() => setLineAction(null)}>{title}</ModalHeader>
                            <ModalBody>
                                {lineAction?.kind === "principal" && (
                                    <>
                                        <p className="mb-2">O artigo <strong>{la?.article?.code} {la?.article?.description}</strong> passa a ter, no PingWin, o código <strong className="font-monospace">{ln?.supplier_code}</strong> para este fornecedor.</p>
                                        <p className="mb-0 fs-13 text-muted">O código atual (<span className="font-monospace">{la?.principal_code ?? "—"}</span>) continua na XPLENDOR como alternativo: as faturas com ele continuam a ligar-se. A gravação é confirmada pela releitura do artigo.</p>
                                    </>
                                )}
                                {lineAction?.kind === "vat_info" && vat && (
                                    <>
                                        <p className="mb-2">Ao lançar no PingWin, esta linha vai com o IVA <strong>da fatura ({vat.invoice_rate}%)</strong>: o lançamento troca o grupo de IVA do artigo ({vat.article_rate}%) só nesta linha do documento.</p>
                                        <p className="mb-0 fs-13 text-muted">É o que o "Lançar no PingWin" já faz; o artigo no catálogo fica como está.</p>
                                    </>
                                )}
                                {lineAction?.kind === "vat_fix" && vat && (
                                    <>
                                        <p className="mb-2">O artigo <strong>{la?.article?.code} {la?.article?.description}</strong> passa de <strong>{vat.article_rate}%</strong> para <strong>{vat.invoice_rate}%</strong> no catálogo do PingWin.</p>
                                        <p className="mb-0 fs-13 text-muted">Vale para todas as vendas e compras deste artigo daqui em diante. A alteração só fica confirmada se a releitura do artigo mostrar o novo IVA.</p>
                                    </>
                                )}
                            </ModalBody>
                            <ModalFooter>
                                {lineAction?.kind === "vat_info" ? (
                                    <Button color="primary" onClick={() => setLineAction(null)}>Entendido</Button>
                                ) : (
                                    <>
                                        <Button color="light" onClick={() => setLineAction(null)} disabled={lineBusy !== null}>Cancelar</Button>
                                        <Button color="primary" onClick={runLineAction} disabled={lineBusy !== null}>
                                            {lineBusy !== null ? <Spinner size="sm" /> : lineAction?.kind === "principal" ? "Tornar principal" : `Mudar para ${vat?.invoice_rate}%`}
                                        </Button>
                                    </>
                                )}
                            </ModalFooter>
                        </Modal>
                    );
                })()}
                <Modal isOpen={acceptOpen} toggle={() => setAcceptOpen(false)} centered>
                    <ModalHeader toggle={() => setAcceptOpen(false)}>Aceitar as sugestões?</ModalHeader>
                    <ModalBody>
                        <p>Liga cada linha sugerida ao 1.º artigo sugerido, quando a semelhança da descrição for pelo menos:</p>
                        <Label className="form-label" for="min-conf">Semelhança mínima</Label>
                        <XSelect id="min-conf" ariaLabel="Semelhança mínima" searchable={false} value={minConf}
                            options={[0.9, 0.8, 0.7, 0.6].map((v) => ({ value: v, label: `${Math.round(v * 100)}%` }))} onChange={(v) => setMinConf(v)} />
                        <p className="fs-12 text-muted mt-2 mb-0">
                            {Object.values(links).filter((l) => l.link_state === "sugerida" && (l.suggestions[0]?.score ?? 0) >= minConf).length} linhas serão ligadas. Pode desfazer cada uma depois.
                        </p>
                    </ModalBody>
                    <ModalFooter>
                        <Button color="light" onClick={() => setAcceptOpen(false)}>Cancelar</Button>
                        <Button color="primary" onClick={acceptAll} disabled={lineBusy !== null}>Aceitar sugestões</Button>
                    </ModalFooter>
                </Modal>
            </Container>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// F3 — bloco "No PingWin"
// ─────────────────────────────────────────────────────────────────────────────

const METHOD_LABEL: Record<OcrLinkMethod, string> = {
    numero: "Pelo nº da fatura",
    total_data: "Pelo total e pela data",
    guias: "Fatura de guias",
    manual: "Escolhido à mão",
};
const fmtDay = (d?: string | null) => (d ? d.split("-").reverse().join("/") : "—");
const signedEur = (n: number) => `${n > 0 ? "+" : ""}${eur(n)}`;

type PingwinLinkCardProps = {
    companyId: number;
    invoiceId: number;
    block: OcrPingwinBlock;
    onBlock: (b: OcrPingwinBlock) => void;
    onCreateSupplier: (nif: string | null, name: string | null) => void;
    onOpenInvoice: (id: number) => void;
};

/** Tabela de documentos do PingWin, com escolha única (radio) ou múltipla (checkbox). */
function PingwinDocsTable({ docs, select, selected, onToggle, caption }: {
    docs: OcrPingwinDoc[];
    select?: "radio" | "checkbox";
    selected?: string[];
    onToggle?: (id: string) => void;
    caption: string;
}) {
    return (
        <div className="table-responsive">
            <table className="table table-sm align-middle mb-0 fs-13">
                <caption className="visually-hidden">{caption}</caption>
                <thead className="text-muted table-light">
                    <tr>
                        {select && <th style={{ width: 32 }}><span className="visually-hidden">Escolher</span></th>}
                        <th>Documento</th><th>Lançado em</th><th>Nº doc. fornecedor</th><th className="text-end">Total</th><th className="text-center">Liquidado</th><th>Loja</th>
                    </tr>
                </thead>
                <tbody>
                    {docs.map((d) => {
                        const taken = d.linked_to_invoice !== null;
                        const checked = !!selected?.includes(d.docheader_id);
                        return (
                            <tr key={d.docheader_id} className={checked ? "table-primary" : undefined}>
                                {select && (
                                    <td>
                                        <input type={select} className="form-check-input" checked={checked} disabled={taken}
                                            name={select === "radio" ? `pw-${caption}` : undefined}
                                            onChange={() => onToggle?.(d.docheader_id)} aria-label={`Escolher ${d.document ?? d.docheader_id}`} />
                                    </td>
                                )}
                                <td className="fw-medium text-nowrap">
                                    {d.document ?? d.docheader_id}
                                    {taken && <span className="badge bg-secondary-subtle text-secondary ms-1" title="Já está ligado a outra fatura carregada">fatura #{d.linked_to_invoice}</span>}
                                </td>
                                <td className="text-nowrap">{fmtDay(d.doc_date)}</td>
                                <td>{d.docreference_number || <span className="text-muted">—</span>}</td>
                                <td className="text-end text-nowrap">{eur(d.total)}</td>
                                <td className="text-center">{d.paid ? <span className="badge bg-success-subtle text-success">Sim</span> : <span className="text-muted">Não</span>}</td>
                                <td>{d.store_name ?? "—"}</td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function PingwinLinkCard({ companyId, invoiceId, block, onBlock, onCreateSupplier, onOpenInvoice }: PingwinLinkCardProps) {
    const [busy, setBusy] = useState<string | null>(null);
    const [choosing, setChoosing] = useState(false);
    const [choice, setChoice] = useState<string | null>(null);
    const [guidesOpen, setGuidesOpen] = useState(false);
    const [guideSel, setGuideSel] = useState<string[]>([]);

    const st = block.status ? OCR_LINK_STATUS[block.status] : null;
    const linked = block.linked;
    const unconfirmed = linked.some((l) => !l.confirmed);
    const guideMode = block.candidates_mode === "guias";
    const total = block.invoice_total ?? 0;

    // Modo guias: por omissão escolhe todos os candidatos livres.
    useEffect(() => {
        setGuideSel(guideMode ? block.candidates.filter((d) => d.linked_to_invoice === null).map((d) => d.docheader_id) : []);
        setGuidesOpen(block.status === "possivel" && guideMode);
        setChoosing(false);
        setChoice(null);
    }, [block.status, block.candidates_mode, block.candidates, guideMode]);

    const run = async (key: string, fn: () => Promise<any>, ok: string) => {
        setBusy(key);
        try {
            const res: any = await fn();
            if (res?.data?.pingwin) onBlock(res.data.pingwin);
            toast.success(ok);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível concluir.");
        } finally {
            setBusy(null);
        }
    };
    const search = () => run("search", () => searchOcrPingwinLink(companyId, invoiceId), "Ligação ao PingWin verificada.");
    const confirm = (ids?: string[], method?: string) =>
        run("confirm", () => confirmOcrPingwinLink(companyId, invoiceId, ids ? { docheader_ids: ids, method } : {}), "Ligação ao PingWin guardada.");
    const unlink = () => run("unlink", () => unlinkOcrPingwinLink(companyId, invoiceId), "Documento desligado.");

    const guideDocs = block.candidates;
    const guideSum = guideDocs.filter((d) => guideSel.includes(d.docheader_id)).reduce((a, d) => a + d.total, 0);
    const toggleGuide = (id: string) => setGuideSel((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]));

    const statusLine = (
        <span>
            {st && <span className={`badge ${st.cls} me-2`} title={st.title}>{st.label}</span>}
            {block.checked_at && <span className="text-muted">Verificado {new Date(block.checked_at).toLocaleString("pt-PT", { dateStyle: "short", timeStyle: "short" })}</span>}
        </span>
    );

    return (
        <PageCard
            title="No PingWin"
            info="A fatura ligada ao documento lançado no PingWin. Só lê os dados do PingWin já sincronizados: nada é gravado no PingWin."
            status={statusLine}
            flush={false}
            data-testid="pingwin-link-card"
            actions={<Button size="sm" color="outline-primary" onClick={search} disabled={!!busy || block.search_pending}>
                {busy === "search" || block.search_pending ? <><Spinner size="sm" className="me-1" />A procurar…</> : <><i className="ri-search-line me-1" />Procurar no PingWin</>}
            </Button>}
        >
            {block.note && <Alert color="warning" className="py-2 fs-13"><i className="ri-error-warning-line me-1" />{block.note}</Alert>}

            {block.search_pending && (
                <div className="text-muted fs-13 mb-2"><Spinner size="sm" className="me-1" />A procurar o fornecedor (NIF {block.supplier.nif}) no PingWin…</div>
            )}

            {block.status === "duplicada" && block.duplicate_of && (
                <p className="mb-0 fs-13">
                    Esta fatura já foi carregada antes ({block.duplicate_of.number ?? `fatura #${block.duplicate_of.id}`}).{" "}
                    <Button size="sm" color="link" className="p-0 align-baseline" onClick={() => onOpenInvoice(block.duplicate_of!.id)}>Abrir a original</Button>
                </p>
            )}

            {block.status === "fornecedor_em_falta" && !block.search_pending && (
                <div className="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <span className="fs-13">
                        {block.supplier.nif
                            ? <>O fornecedor com NIF <strong>{block.supplier.nif}</strong> não existe no PingWin.</>
                            : block.supplier.own_nif
                                ? <>O NIF lido como fornecedor é o da própria empresa: corrija o NIF do fornecedor e valide, ou reprocesse a fatura.</>
                                : <>A fatura não tem o NIF do fornecedor.</>}
                    </span>
                    {block.supplier.nif && (
                        <Button size="sm" color="primary" onClick={() => onCreateSupplier(block.supplier.prefill.nif, block.supplier.prefill.name)}>
                            <i className="ri-user-add-line me-1" />Criar fornecedor
                        </Button>
                    )}
                </div>
            )}

            {/* Documento(s) ligado(s) */}
            {linked.length > 0 && (
                <>
                    <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <span className="fs-13">
                            {METHOD_LABEL[linked[0].method]}
                            {unconfirmed && <span className="badge bg-warning-subtle text-warning ms-2" title="Ligado automaticamente: confirme que é este o documento">Por confirmar</span>}
                            {!unconfirmed && <span className="badge bg-success-subtle text-success ms-2">Confirmado</span>}
                        </span>
                        <div className="d-flex gap-2">
                            {unconfirmed && <Button size="sm" color="primary" onClick={() => confirm()} disabled={!!busy}><i className="ri-check-line me-1" />Confirmar</Button>}
                            <Button size="sm" color="outline-secondary" onClick={() => setChoosing((v) => !v)} disabled={!!busy}>Escolher outro</Button>
                            <Button size="sm" color="outline-danger" onClick={unlink} disabled={!!busy}>Desligar</Button>
                        </div>
                    </div>
                    <PingwinDocsTable docs={linked} caption="Documentos ligados" />
                    {(linked.length > 1 || (block.diff !== null && block.diff !== 0)) && (
                        <div className="fs-13 mt-2">
                            {linked.length > 1 && <>Soma dos documentos: <strong>{eur(linked.reduce((a, d) => a + d.total, 0))}</strong> · </>}
                            Total da fatura: {eur(total)}
                            {block.diff !== null && block.diff !== 0 && <> · <span className="text-warning fw-semibold">Diferença {signedEur(block.diff)}</span></>}
                        </div>
                    )}
                    {block.compare && <PingwinCompare c={block.compare} />}
                </>
            )}

            {/* Possível: vários documentos com o mesmo total e data */}
            {block.status === "possivel" && block.candidates_mode === "total_data" && (
                <>
                    <p className="fs-13 mb-2">Há {block.candidates.length} documentos do fornecedor com o mesmo total e data próxima. Escolha o que corresponde a esta fatura:</p>
                    <PingwinDocsTable docs={block.candidates} select="radio" selected={choice ? [choice] : []} onToggle={setChoice} caption="Candidatos" />
                    <div className="text-end mt-2">
                        <Button size="sm" color="primary" disabled={!choice || !!busy} onClick={() => choice && confirm([choice], "total_data")}>Ligar a este documento</Button>
                    </div>
                </>
            )}

            {/* Sem ligação: escolher um documento à mão, ou várias guias */}
            {linked.length === 0 && ["nao_lancada", "possivel"].includes(block.status ?? "") && !guidesOpen && block.candidates_mode !== "total_data" && (
                <div className="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <span className="fs-13">{block.status === "nao_lancada" ? "Não foi encontrada no PingWin: falta lançar, ou escolha o documento à mão." : ""}</span>
                    <div className="d-flex gap-2">
                        {block.choices.length > 0 && <Button size="sm" color="outline-secondary" onClick={() => setChoosing((v) => !v)}>Escolher documento</Button>}
                        {guideMode && guideDocs.length > 0 && <Button size="sm" color="outline-secondary" onClick={() => setGuidesOpen(true)}>Fatura de guias…</Button>}
                    </div>
                </div>
            )}

            {/* Modo guias */}
            {linked.length === 0 && guideMode && guidesOpen && guideDocs.length > 0 && (
                <>
                    <p className="fs-13 mb-2">
                        {block.guides.length > 0
                            ? <>A fatura refere <strong>{block.guides.length} guias</strong> ({fmtDay(block.period?.from)} a {fmtDay(block.period?.to)}). </>
                            : <>Documentos do fornecedor nos 35 dias até à data da fatura ({fmtDay(block.period?.from)} a {fmtDay(block.period?.to)}). </>}
                        Escolha os documentos que esta fatura junta:
                    </p>
                    <PingwinDocsTable docs={guideDocs} select="checkbox" selected={guideSel} onToggle={toggleGuide} caption="Guias" />
                    <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-2">
                        <span className="fs-13" data-testid="guides-sum">
                            {guideSel.length} escolhidos · soma <strong>{eur(guideSel.length ? guideSum : 0)}</strong> · fatura {eur(total)} ·{" "}
                            <span className={Math.abs(guideSum - total) < 0.005 ? "text-success fw-semibold" : "text-warning fw-semibold"}>
                                diferença {signedEur(Math.round((guideSum - total) * 100) / 100)}
                            </span>
                        </span>
                        <div className="d-flex gap-2">
                            {block.status !== "possivel" && <Button size="sm" color="light" onClick={() => setGuidesOpen(false)}>Cancelar</Button>}
                            <Button size="sm" color="primary" disabled={guideSel.length === 0 || !!busy} onClick={() => confirm(guideSel, "guias")}>
                                <i className="ri-check-double-line me-1" />Confirmar guias
                            </Button>
                        </div>
                    </div>
                </>
            )}

            {/* Escolher outro / escolher à mão */}
            {choosing && (
                <div className="mt-3">
                    {block.choices.length === 0 ? (
                        <p className="text-muted fs-13 mb-0">Sem documentos deste fornecedor perto da data da fatura.</p>
                    ) : (
                        <>
                            <p className="fs-13 mb-2">Documentos do fornecedor perto da data da fatura:</p>
                            <PingwinDocsTable docs={block.choices} select="radio" selected={choice ? [choice] : []} onToggle={setChoice} caption="Escolher documento" />
                            <div className="text-end mt-2 d-flex justify-content-end gap-2">
                                <Button size="sm" color="light" onClick={() => setChoosing(false)}>Cancelar</Button>
                                <Button size="sm" color="primary" disabled={!choice || !!busy} onClick={() => choice && confirm([choice], "manual")}>Ligar a este documento</Button>
                            </div>
                        </>
                    )}
                </div>
            )}
        </PageCard>
    );
}

/** Linhas OCR × PingWin (só informativo, com 1 documento ligado). */
function PingwinCompare({ c }: { c: NonNullable<OcrPingwinBlock["compare"]> }) {
    if (!c.pw_synced) {
        return <p className="text-muted fs-13 mt-3 mb-0">As linhas deste documento do PingWin ainda não foram sincronizadas.</p>;
    }
    const same = c.unmatched_ocr.length === 0 && c.unmatched_pw.length === 0;
    return (
        <div className="mt-3 p-2" style={{ border: "1px solid var(--vz-border-color)", borderRadius: 8 }} data-testid="pingwin-compare">
            <div className="fs-12 text-muted text-uppercase fw-semibold mb-1">Linhas: fatura × PingWin (informativo)</div>
            <div className="fs-13">
                Fatura: {c.ocr_count} linhas, {eur(c.ocr_sum)} · PingWin: {c.pw_count} linhas, {eur(c.pw_sum)}
                {same && <span className="badge bg-success-subtle text-success ms-2">Todas as linhas batem</span>}
            </div>
            {!same && (
                <Row className="g-2 mt-1 fs-13">
                    <Col md={6}>
                        <div className="fw-semibold mb-1">Só na fatura ({c.unmatched_ocr.length})</div>
                        {c.unmatched_ocr.length === 0 ? <span className="text-muted">—</span> : (
                            <ul className="mb-0 ps-3">{c.unmatched_ocr.map((l, i) => <li key={i}>{l.code ? `${l.code} · ` : ""}{l.description ?? "—"} — {eur(l.total)}</li>)}</ul>
                        )}
                    </Col>
                    <Col md={6}>
                        <div className="fw-semibold mb-1">Só no PingWin ({c.unmatched_pw.length})</div>
                        {c.unmatched_pw.length === 0 ? <span className="text-muted">—</span> : (
                            <ul className="mb-0 ps-3">{c.unmatched_pw.map((l, i) => <li key={i}>{l.code ? `${l.code} · ` : ""}{l.description ?? "—"} — {eur(l.total)}</li>)}</ul>
                        )}
                    </Col>
                </Row>
            )}
        </div>
    );
}
