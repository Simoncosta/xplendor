import { useCallback, useEffect, useMemo, useState } from "react";
import { useNavigate, useParams, useLocation } from "react-router-dom";
import {
    Card, CardBody, Container, Row, Col, Spinner, Label, Input, Button,
    Nav, NavItem, NavLink, TabContent, TabPane, Form,
    Modal, ModalHeader, ModalBody, ModalFooter,
} from "reactstrap";
import classnames from "classnames";
import Select from "react-select";
import { toast, ToastContainer } from "react-toastify";
import { reactSelectTheme } from "../../helpers/reactSelectStyles";
import {
    getArticleFormLookups, getArticle, getArticleRead,
    createArticle, getArticleCreation, updateArticle, getArticleEdition,
    deleteArticle, getArticleDeletion, getPingwinArticleSupplierPrices,
} from "helpers/laravel_helper";
import { useArticleWritePolling } from "./useArticleWritePolling";
import { useArticleReadPolling } from "./useArticleReadPolling";
import { useSupplierPricesStaging } from "./useSupplierPricesStaging";
import ArtigoComprasTab from "./ArtigoComprasTab";

/**
 * XPLENDOR — Restauração › Artigo (criar / abrir / editar). Mesmo form nos 2 modos.
 *
 * ⚠️ DOIS IDENTIFICADORES (não trocar):
 *   • pingwinId     = id no PingWin → usado para LER (readProduct).
 *   • catalogItemId = id do espelho local → usado para EDITAR/ANULAR.
 * A lista traz ambos; o pingwinId vem no URL e o catalogItemId no location.state.
 * ⚠️ Fallback F5: se o state se perder (recarregar), o catalogItemId vem no próprio
 * readProduct (resolvido no backend pelo pingwin_id).
 */

type Opt = { value: string; label: string };

const toOpts = (arr: any[] | undefined, labelKey = "description"): Opt[] =>
    (arr ?? []).map((x) => ({ value: String(x.id), label: String(x[labelKey] ?? x.shortname ?? x.id) }));

/** "12,34" | "12.34" → cêntimos inteiros (ou null se vazio/ inválido). */
const eurToCents = (s: string): number | null => {
    const t = (s ?? "").trim().replace(",", ".");
    if (t === "") return null;
    const n = parseFloat(t);
    return isNaN(n) ? null : Math.round(n * 100);
};
/** cêntimos → "12,34" (ou "" se null). */
const centsToEur = (c: number | null | undefined): string =>
    c === null || c === undefined ? "" : (Number(c) / 100).toFixed(2).replace(".", ",");

const EMPTY_FORM = {
    code: "", description: "", status: "1", family_id: "",
    forsale: true, forpurchase: false, forproduction: false,
    base_unit_id: "", sale_unit_id: "", purchase_unit_id: "",
    saleprice: "", change_sale_price: false, purchaseprice: "",
    product_type: "1", taxgroup_id: "", stockconfig_id: "", setexpireday: "",
    shortname: "", button_name: "",
    stock_unit_id: "", label_unit_id: "", volume_unit_id: "", weight: "",
};

export default function ArtigoFormPage() {
    const navigate = useNavigate();
    const location = useLocation();
    const { pingwinId } = useParams<{ pingwinId?: string }>();
    const isCreate = !pingwinId;

    document.title = (isCreate ? "Novo artigo" : "Editar artigo") + " | Restauração | Xplendor";

    const companyId = useMemo(() => {
        const a = sessionStorage.getItem("authUser");
        try { return a ? Number(JSON.parse(a).company_id || 0) : 0; } catch { return 0; }
    }, []);

    const { busy, submit } = useArticleWritePolling(companyId);
    const { read } = useArticleReadPolling(companyId);

    const [loading, setLoading] = useState(true);
    const [lookups, setLookups] = useState<any | null>(null);
    const [tab, setTab] = useState<"geral" | "frontoffice" | "unidades" | "compras">("geral");
    const compras = useSupplierPricesStaging();
    const [supplierTables, setSupplierTables] = useState<any[]>([]);
    const [created, setCreated] = useState<{ code?: string } | null>(null);
    const [anularOpen, setAnularOpen] = useState(false);
    // id do espelho local (state da navegação; ou fallback do readProduct).
    const [catId, setCatId] = useState<number | null>((location.state as any)?.catalogItemId ?? null);
    // preços à carga (para no editar só reenviar o preço se mudou).
    const [loaded, setLoaded] = useState<{ saleprice: string; purchaseprice: string }>({ saleprice: "", purchaseprice: "" });

    const [f, setF] = useState({ ...EMPTY_FORM });
    const set = (k: string, v: any) => setF((p) => ({ ...p, [k]: v }));

    const [touched, setTouched] = useState<Record<string, boolean>>({});
    const mark = (k: string) => setTouched((p) => ({ ...p, [k]: true }));

    const SHORTNAME_MAX = 13;
    const BUTTON_MAX = 35;
    const onDescription = (v: string) => setF((p) => ({
        ...p,
        description: v,
        shortname: touched.shortname ? p.shortname : v.slice(0, SHORTNAME_MAX),
        button_name: touched.button_name ? p.button_name : v.slice(0, BUTTON_MAX),
    }));
    const onBaseUnit = (v: string) => setF((p) => ({
        ...p,
        base_unit_id: v,
        stock_unit_id: touched.stock_unit_id ? p.stock_unit_id : v,
        label_unit_id: touched.label_unit_id ? p.label_unit_id : v,
        volume_unit_id: touched.volume_unit_id ? p.volume_unit_id : v,
    }));

    const toggleCompra = (v: boolean) => setF((p) => ({ ...p, forpurchase: v, forproduction: v ? false : p.forproduction }));
    const toggleProducao = (v: boolean) => setF((p) => ({ ...p, forproduction: v, forpurchase: v ? false : p.forpurchase }));

    // ── CRIAR: lookups (read polling) ──
    useEffect(() => {
        if (!isCreate || !companyId) { setLoading(false); return; }
        let alive = true;
        (async () => {
            setLoading(true);
            const r = await read(() => getArticleFormLookups(companyId), (t) => getArticleRead(companyId, t));
            if (!alive) return;
            if (r.status === "ready") {
                const fm = r.data?.form ?? {};
                setLookups(fm);
                setF((p) => ({
                    ...p,
                    code: fm.next_code ?? "",
                    product_type: pickDefault(fm.product_type, "1"),
                    status: pickDefault(fm.lkstatus, "1"),
                    taxgroup_id: pickDefault(fm.lktaxgroup, "1003001"),
                    stockconfig_id: pickDefault(fm.lk_stockconfig, "7501"),
                }));
            } else {
                toast.error(r.error_message ?? "Não foi possível carregar o formulário.");
            }
            setLoading(false);
        })();
        return () => { alive = false; };
    }, [isCreate, companyId, read]);

    // ── EDITAR: carregar o artigo real (read polling) e preencher o form ──
    useEffect(() => {
        if (isCreate || !companyId || !pingwinId) return;
        let alive = true;
        (async () => {
            setLoading(true);
            const r = await read(() => getArticle(companyId, pingwinId), (t) => getArticleRead(companyId, t));
            if (!alive) return;
            if (r.status === "ready") {
                const art = r.data?.article ?? {};
                setLookups(art.lookups ?? {});
                const m = art.maindataset ?? {};
                const saleStr = centsToEur(art.saleprice_cents);
                const purchStr = centsToEur(art.purchaseprice_cents);
                setF({
                    code: String(m.code ?? ""),
                    description: String(m.description ?? ""),
                    status: m.status != null ? String(m.status) : "1",
                    family_id: m.family_id ? String(m.family_id) : "",
                    forsale: !!Number(m.forsale),
                    forpurchase: !!Number(m.forpurchase),
                    forproduction: !!Number(m.forproduction),
                    base_unit_id: m.base_unit_id ? String(m.base_unit_id) : "",
                    sale_unit_id: m.default_sale_unit_id ? String(m.default_sale_unit_id) : "",
                    purchase_unit_id: m.default_purchase_unit_id ? String(m.default_purchase_unit_id) : "",
                    stock_unit_id: m.default_stock_unit_id ? String(m.default_stock_unit_id) : "",
                    label_unit_id: m.label_unit_id ? String(m.label_unit_id) : "",
                    volume_unit_id: m.volume_unit_id ? String(m.volume_unit_id) : "",
                    product_type: m.product_type != null ? String(m.product_type) : "1",
                    taxgroup_id: m.taxgroup_id ? String(m.taxgroup_id) : "",
                    stockconfig_id: m.stockconfig_id ? String(m.stockconfig_id) : "",
                    setexpireday: m.setexpireday != null && m.setexpireday !== "" ? String(m.setexpireday) : "",
                    shortname: String(m.shortname ?? ""),
                    button_name: String(m.button_name ?? ""),
                    change_sale_price: !!Number(m.change_sale_price),
                    weight: m.weight != null && m.weight !== "" ? String(m.weight).replace(".", ",") : "",
                    saleprice: saleStr,
                    purchaseprice: purchStr,
                });
                setLoaded({ saleprice: saleStr, purchaseprice: purchStr });
                setCatId((location.state as any)?.catalogItemId ?? art.catalog_item_id ?? null);
                // Tab Compras (C3): linhas de fornecedor (staging) + tabelas p/ a cascata.
                compras.setClean(art.supplier_prices ?? []);
                setSupplierTables(art.supplier_tables ?? []);
                // Não auto-preencher por cima dos valores carregados.
                setTouched({ shortname: true, button_name: true, stock_unit_id: true, label_unit_id: true, volume_unit_id: true });
            } else {
                toast.error(r.error_message ?? "Não foi possível carregar o artigo.");
            }
            setLoading(false);
        })();
        return () => { alive = false; };
    }, [isCreate, companyId, pingwinId, read]);

    // Aviso do browser ao fechar/recarregar com alterações de fornecedores por gravar.
    useEffect(() => {
        const h = (e: BeforeUnloadEvent) => { if (compras.hasPending) { e.preventDefault(); e.returnValue = ""; } };
        window.addEventListener("beforeunload", h);
        return () => window.removeEventListener("beforeunload", h);
    }, [compras.hasPending]);

    // Voltar à lista — confirma se houver alterações de fornecedores por gravar (perdem-se).
    const goBack = useCallback(() => {
        if (compras.hasPending && !window.confirm("Tens alterações de fornecedores por gravar. Sair mesmo assim?")) return;
        navigate("/restauracao/artigos");
    }, [compras.hasPending, navigate]);

    // ── Opções dos selects ──
    const familyOptions = useMemo<Opt[]>(() => {
        const cleanPath = (cn: string, fallback: string): string => {
            const parts = String(cn ?? "").split("\\").map((s) => s.trim()).filter(Boolean);
            const noRoot = parts.length > 1 && parts[0].toLowerCase() === "família" ? parts.slice(1) : parts;
            return noRoot.length ? noRoot.join(" › ") : fallback;
        };
        return (lookups?.families ?? [])
            .filter((x: any) => !x.deleted && Number(x.isleaf) === 1)
            .map((x: any) => ({ value: String(x.id), label: cleanPath(x.complete_name, String(x.description ?? x.id)) }))
            .sort((a: Opt, b: Opt) => a.label.localeCompare(b.label, "pt"));
    }, [lookups]);

    const typeOptions = useMemo(() => toOpts(lookups?.product_type), [lookups]);
    const statusOptions = useMemo(() => toOpts(lookups?.lkstatus), [lookups]);
    const taxOptions = useMemo(() => toOpts(lookups?.lktaxgroup), [lookups]);
    const stockOptions = useMemo(() => toOpts(lookups?.lk_stockconfig), [lookups]);
    const baseUnitOptions = useMemo(() => toOpts(lookups?.units?.base), [lookups]);
    const saleUnitOptions = useMemo(() => toOpts(lookups?.units?.sale), [lookups]);
    const purchaseUnitOptions = useMemo(() => toOpts(lookups?.units?.purchase), [lookups]);

    const sel = (opts: Opt[], v: string) => opts.find((o) => o.value === v) ?? null;

    // Campos do maindataset (nomes PingWin) — partilhado por criar e editar.
    const buildMaindataset = useCallback(() => {
        const base = f.base_unit_id || undefined;
        return {
            description: f.description.trim(),
            shortname: f.shortname.trim() || undefined,
            button_name: f.button_name.trim() || undefined,
            family_id: f.family_id || undefined,
            product_type: f.product_type || undefined,
            status: f.status || undefined,
            taxgroup_id: f.taxgroup_id || undefined,
            stockconfig_id: f.stockconfig_id || undefined,
            base_unit_id: base,
            default_sale_unit_id: f.forsale ? (f.sale_unit_id || base) : undefined,
            default_purchase_unit_id: f.forpurchase ? (f.purchase_unit_id || base) : undefined,
            default_stock_unit_id: f.stock_unit_id || undefined,
            label_unit_id: f.label_unit_id || undefined,
            volume_unit_id: f.volume_unit_id || undefined,
            forsale: f.forsale ? 1 : 0,
            forpurchase: f.forpurchase ? 1 : 0,
            forproduction: f.forproduction ? 1 : 0,
            change_sale_price: f.change_sale_price ? 1 : 0,
            setexpireday: f.setexpireday !== "" ? Number(f.setexpireday) : undefined,
            weight: f.weight !== "" ? Number(f.weight.replace(",", ".")) : undefined,
        } as Record<string, any>;
    }, [f]);

    const onSubmit = async () => {
        if (!f.description.trim()) { toast.error("A descrição é obrigatória."); setTab("geral"); return; }
        const md = buildMaindataset();

        if (isCreate) {
            const payload = {
                confirm: true, ...md,
                saleprice_cents: eurToCents(f.saleprice) ?? undefined,
                purchaseprice_cents: eurToCents(f.purchaseprice) ?? undefined,
            };
            const r = await submit(() => createArticle(companyId, payload), (id) => getArticleCreation(companyId, id));
            if (r.status === "ok") {
                setCreated({ code: r.row?.code ?? f.code });
                toast.success(`Artigo criado no PingWin (código ${r.row?.code ?? f.code}).`);
            } else {
                toast.error(r.row?.error_message ?? "Não foi possível criar o artigo.");
            }
            return;
        }

        // EDITAR
        if (!catId) { toast.error("Sem id local do artigo — recarrega a página e tenta de novo."); return; }
        const payload: Record<string, any> = { confirm: true, ...md };
        // ⚠️ preço só se mudou (senão não se toca no preço).
        if (f.saleprice !== loaded.saleprice) { const c = eurToCents(f.saleprice); if (c !== null) payload.saleprice_cents = c; }
        if (f.purchaseprice !== loaded.purchaseprice) { const c = eurToCents(f.purchaseprice); if (c !== null) payload.purchaseprice_cents = c; }
        // C3 — mudanças de fornecedor pendentes (só se houver; senão o editar é igual).
        if (compras.hasPending) { payload.supplier_prices_changes = compras.buildPackage(); }

        const r = await submit(() => updateArticle(companyId, catId, payload), (id) => getArticleEdition(companyId, id));
        if (r.status === "ok") {
            setLoaded({ saleprice: f.saleprice, purchaseprice: f.purchaseprice });
            // Recarrega as linhas do ESPELHO → tudo volta a clean (cores somem).
            try {
                const sp: any = await getPingwinArticleSupplierPrices(companyId, catId);
                compras.setClean(sp?.data?.supplier_prices ?? []);
            } catch { /* o banner desaparece na próxima abertura */ }
            toast.success("Artigo atualizado no PingWin.");
        } else {
            toast.error(r.row?.error_message ?? "Não foi possível editar o artigo.");
        }
    };

    // ⚠️ ANULAR (DELETE definitivo). Usa o fallback do catalogItemId (state ?? backend).
    const onAnular = async () => {
        if (!catId) { toast.error("Sem id local do artigo — recarrega a página e tenta de novo."); return; }
        const r = await submit(() => deleteArticle(companyId, catId), (id) => getArticleDeletion(companyId, id));
        if (r.status === "ok") {
            setAnularOpen(false);
            toast.success("Artigo anulado no PingWin.");
            navigate("/restauracao/artigos"); // volta à lista (o item fica esbatido/inativo no re-fetch)
        } else {
            toast.error(r.row?.error_message ?? "Não foi possível anular o artigo.");
        }
    };

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <div className="page-title-box d-sm-flex align-items-center justify-content-between">
                    <div>
                        <h4 className="mb-sm-0">{isCreate ? "Novo artigo" : "Editar artigo"}</h4>
                        <small className="text-muted">{isCreate ? "Criar um artigo no PingWin." : "Alterar o artigo no PingWin (inclui o Estado)."}</small>
                    </div>
                </div>

                {loading ? (
                    <div className="text-center py-5 text-muted"><Spinner color="primary" className="me-2" /> A carregar do PingWin…</div>
                ) : (
                    <Form onSubmit={(e) => { e.preventDefault(); onSubmit(); }}>
                        {created && (
                            <div className="alert alert-success d-flex align-items-center gap-2" role="alert">
                                <i className="ri-checkbox-circle-line fs-5" />
                                <div>Artigo criado no PingWin (código <strong>{created.code}</strong>). Podes continuar a ajustar ou voltar à lista.</div>
                            </div>
                        )}

                        {/* ── Cabeçalho ── */}
                        <Card className="mb-3">
                            <CardBody>
                                <Row className="g-3">
                                    <Col md={2}>
                                        <Label className="form-label">Código</Label>
                                        <Input value={f.code} readOnly disabled title="Atribuído pelo PingWin" />
                                    </Col>
                                    <Col md={6}>
                                        <Label className="form-label">Descrição *</Label>
                                        <Input value={f.description} onChange={(e) => onDescription(e.target.value)} maxLength={120} placeholder="Nome do artigo" />
                                    </Col>
                                    <Col md={4}>
                                        <Label className="form-label">Estado</Label>
                                        <Select styles={reactSelectTheme} menuPortalTarget={document.body} options={statusOptions}
                                            value={sel(statusOptions, f.status)} onChange={(o: any) => set("status", o?.value ?? "")} isSearchable={false} />
                                    </Col>

                                    <Col md={4}>
                                        <Label className="form-label">Família</Label>
                                        <Select styles={reactSelectTheme} menuPortalTarget={document.body} options={familyOptions}
                                            value={sel(familyOptions, f.family_id)} onChange={(o: any) => set("family_id", o?.value ?? "")} isSearchable placeholder="Escolher família…" />
                                    </Col>
                                    <Col md={8}>
                                        <Label className="form-label d-block">Tipo de artigo</Label>
                                        <div className="d-flex gap-4 flex-wrap align-items-center" style={{ minHeight: 38 }}>
                                            <div className="form-check"><Input type="checkbox" className="form-check-input" id="c-venda" checked={f.forsale} onChange={(e) => set("forsale", e.target.checked)} /><Label className="form-check-label" for="c-venda">Artigo de Venda</Label></div>
                                            <div className="form-check"><Input type="checkbox" className="form-check-input" id="c-compra" checked={f.forpurchase} onChange={(e) => toggleCompra(e.target.checked)} /><Label className="form-check-label" for="c-compra">Artigo de Compra</Label></div>
                                            <div className="form-check"><Input type="checkbox" className="form-check-input" id="c-prod" checked={f.forproduction} onChange={(e) => toggleProducao(e.target.checked)} /><Label className="form-check-label" for="c-prod">Artigo de Produção</Label></div>
                                        </div>
                                    </Col>

                                    <Col md={4}>
                                        <Label className="form-label">Unidade base</Label>
                                        <Select styles={reactSelectTheme} menuPortalTarget={document.body} options={baseUnitOptions}
                                            value={sel(baseUnitOptions, f.base_unit_id)} onChange={(o: any) => onBaseUnit(o?.value ?? "")} isSearchable placeholder="Escolher…" />
                                    </Col>
                                    {f.forsale && (
                                        <Col md={4}>
                                            <Label className="form-label">Unidade de venda</Label>
                                            <Select styles={reactSelectTheme} menuPortalTarget={document.body} options={saleUnitOptions}
                                                value={sel(saleUnitOptions, f.sale_unit_id)} onChange={(o: any) => set("sale_unit_id", o?.value ?? "")} isSearchable placeholder="(usa a base)" />
                                        </Col>
                                    )}
                                    {f.forpurchase && (
                                        <Col md={4}>
                                            <Label className="form-label">Unidade de compra</Label>
                                            <Select styles={reactSelectTheme} menuPortalTarget={document.body} options={purchaseUnitOptions}
                                                value={sel(purchaseUnitOptions, f.purchase_unit_id)} onChange={(o: any) => set("purchase_unit_id", o?.value ?? "")} isSearchable placeholder="(usa a base)" />
                                        </Col>
                                    )}

                                    <Col md={4}>
                                        <Label className="form-label">Preço de venda (€)</Label>
                                        <Input value={f.saleprice} onChange={(e) => set("saleprice", e.target.value)} inputMode="decimal" placeholder="0,00" />
                                    </Col>
                                    <Col md={4}>
                                        <Label className="form-label">Preço de compra (€)</Label>
                                        <Input value={f.purchaseprice} onChange={(e) => set("purchaseprice", e.target.value)} inputMode="decimal" placeholder="0,00" />
                                    </Col>
                                    <Col md={4} className="d-flex align-items-end">
                                        <div className="form-check mb-2"><Input type="checkbox" className="form-check-input" id="c-varprice" checked={f.change_sale_price} onChange={(e) => set("change_sale_price", e.target.checked)} /><Label className="form-check-label" for="c-varprice">Preço de venda variável</Label></div>
                                    </Col>
                                </Row>
                            </CardBody>
                        </Card>

                        {/* ── Tabs ── */}
                        <Card>
                            <CardBody>
                                <Nav tabs className="nav-tabs-custom mb-3">
                                    <NavItem><NavLink className={classnames({ active: tab === "geral" })} onClick={() => setTab("geral")} style={{ cursor: "pointer" }}>Geral</NavLink></NavItem>
                                    <NavItem><NavLink className={classnames({ active: tab === "frontoffice" })} onClick={() => setTab("frontoffice")} style={{ cursor: "pointer" }}>FrontOffice</NavLink></NavItem>
                                    <NavItem><NavLink className={classnames({ active: tab === "unidades" })} onClick={() => setTab("unidades")} style={{ cursor: "pointer" }}>Unidades por defeito</NavLink></NavItem>
                                    <NavItem><NavLink className={classnames({ active: tab === "compras" })} onClick={() => setTab("compras")} style={{ cursor: "pointer" }}>
                                        Compras{compras.hasPending && <span className="badge bg-warning-subtle text-warning ms-1">•</span>}
                                    </NavLink></NavItem>
                                    {["Ficha técnica", "Stocks", "Atributos"].map((t) => (
                                        <NavItem key={t}><NavLink disabled className="text-muted" style={{ cursor: "not-allowed" }} title="Em breve">{t}</NavLink></NavItem>
                                    ))}
                                </Nav>

                                <TabContent activeTab={tab}>
                                    <TabPane tabId="geral">
                                        <Row className="g-3">
                                            <Col md={3}><Label className="form-label">Tipo de artigo</Label>
                                                <Select styles={reactSelectTheme} menuPortalTarget={document.body} options={typeOptions} value={sel(typeOptions, f.product_type)} onChange={(o: any) => set("product_type", o?.value ?? "")} isSearchable={false} /></Col>
                                            <Col md={3}><Label className="form-label">Taxa (IVA)</Label>
                                                <Select styles={reactSelectTheme} menuPortalTarget={document.body} options={taxOptions} value={sel(taxOptions, f.taxgroup_id)} onChange={(o: any) => set("taxgroup_id", o?.value ?? "")} isSearchable={false} /></Col>
                                            <Col md={3}><Label className="form-label">Stocks</Label>
                                                <Select styles={reactSelectTheme} menuPortalTarget={document.body} options={stockOptions} value={sel(stockOptions, f.stockconfig_id)} onChange={(o: any) => set("stockconfig_id", o?.value ?? "")} isSearchable={false} /></Col>
                                            <Col md={3}><Label className="form-label">Validade (dias)</Label>
                                                <Input type="number" min={0} value={f.setexpireday} onChange={(e) => set("setexpireday", e.target.value)} placeholder="0" /></Col>
                                        </Row>
                                    </TabPane>

                                    <TabPane tabId="frontoffice">
                                        <Row className="g-3">
                                            <Col md={4}><Label className="form-label">Nome curto</Label>
                                                <Input value={f.shortname} onChange={(e) => { mark("shortname"); set("shortname", e.target.value); }} maxLength={SHORTNAME_MAX} placeholder="(da descrição)" />
                                                <small className="text-muted">Máx. {SHORTNAME_MAX} caracteres.</small></Col>
                                            <Col md={4}><Label className="form-label">Nome do botão</Label>
                                                <Input value={f.button_name} onChange={(e) => { mark("button_name"); set("button_name", e.target.value); }} maxLength={BUTTON_MAX} placeholder="(da descrição)" />
                                                <small className="text-muted">Máx. {BUTTON_MAX} caracteres.</small></Col>
                                        </Row>
                                        <p className="text-muted fs-13 mt-3 mb-0"><i className="ri-information-line me-1" />Cor, ordem e zona de impressão chegam numa fase seguinte.</p>
                                    </TabPane>

                                    <TabPane tabId="unidades">
                                        <p className="text-muted fs-13">Por defeito herdam a unidade base do cabeçalho; ajusta se precisares.</p>
                                        <Row className="g-3">
                                            <Col md={4}><Label className="form-label">Stock</Label>
                                                <Select styles={reactSelectTheme} menuPortalTarget={document.body} options={baseUnitOptions} value={sel(baseUnitOptions, f.stock_unit_id)} onChange={(o: any) => { mark("stock_unit_id"); set("stock_unit_id", o?.value ?? ""); }} isSearchable placeholder="(usa a base)" /></Col>
                                            <Col md={4}><Label className="form-label">Legenda</Label>
                                                <Select styles={reactSelectTheme} menuPortalTarget={document.body} options={baseUnitOptions} value={sel(baseUnitOptions, f.label_unit_id)} onChange={(o: any) => { mark("label_unit_id"); set("label_unit_id", o?.value ?? ""); }} isSearchable placeholder="(usa a base)" /></Col>
                                            <Col md={4}><Label className="form-label">Volume</Label>
                                                <Select styles={reactSelectTheme} menuPortalTarget={document.body} options={baseUnitOptions} value={sel(baseUnitOptions, f.volume_unit_id)} onChange={(o: any) => { mark("volume_unit_id"); set("volume_unit_id", o?.value ?? ""); }} isSearchable placeholder="(usa a base)" /></Col>
                                            <Col md={4}><Label className="form-label">Peso</Label>
                                                <Input value={f.weight} onChange={(e) => set("weight", e.target.value)} inputMode="decimal" placeholder="0,000" /></Col>
                                        </Row>
                                    </TabPane>

                                    <TabPane tabId="compras">
                                        <ArtigoComprasTab
                                            companyId={companyId}
                                            isCreate={isCreate}
                                            staging={compras}
                                            supplierTables={supplierTables}
                                            unitOptions={baseUnitOptions}
                                        />
                                    </TabPane>
                                </TabContent>
                            </CardBody>
                        </Card>

                        {compras.hasPending && (
                            <div className="alert alert-warning d-flex align-items-center gap-2 mt-3 mb-0" role="alert">
                                <i className="ri-error-warning-line fs-5" />
                                <span>Tens alterações de fornecedores por gravar — clica em <strong>Salvar</strong> para as aplicar.</span>
                            </div>
                        )}
                        <div className="d-flex justify-content-between gap-2 mt-3 mb-5">
                            <div>
                                {!isCreate && (
                                    <Button type="button" color="outline-danger" onClick={() => setAnularOpen(true)} disabled={busy || loading}>
                                        <i className="ri-delete-bin-line me-1" /> Anular
                                    </Button>
                                )}
                            </div>
                            <div className="d-flex gap-2">
                                <Button type="submit" color="primary" disabled={busy}>
                                    {busy
                                        ? <><Spinner size="sm" className="me-1" /> {isCreate ? "A criar no PingWin…" : "A gravar no PingWin…"}</>
                                        : <><i className="ri-save-line me-1" /> {isCreate ? "Criar artigo" : "Salvar"}</>}
                                </Button>
                                <Button type="button" color="light" onClick={goBack}>
                                    <i className="ri-arrow-left-line me-1" /> Voltar
                                </Button>
                            </div>
                        </div>
                    </Form>
                )}

                {/* ⚠️ Anular = apagar DEFINITIVO no PingWin (confirm=accepted). */}
                <Modal isOpen={anularOpen} toggle={() => !busy && setAnularOpen(false)} centered>
                    <ModalHeader toggle={() => !busy && setAnularOpen(false)}>Anular artigo</ModalHeader>
                    <ModalBody>
                        <p className="mb-2">
                            Vais <strong>apagar definitivamente</strong> o artigo
                            {f.code ? <> <strong>{f.code}</strong></> : null}
                            {f.description ? <> «{f.description}»</> : null} no PingWin.
                            Esta ação <strong>não é reversível</strong> — no PingWin não há reativar um artigo apagado.
                        </p>
                        <p className="text-muted fs-13 mb-0">
                            <i className="ri-information-line me-1" />
                            Se só queres tirá-lo de circulação sem apagar, <strong>não anules</strong>: muda o
                            <strong> Estado</strong> para <strong>Descontinuado</strong> e grava.
                        </p>
                    </ModalBody>
                    <ModalFooter>
                        <Button color="light" onClick={() => setAnularOpen(false)} disabled={busy}>Cancelar</Button>
                        <Button color="danger" onClick={onAnular} disabled={busy}>
                            {busy ? <><Spinner size="sm" className="me-1" /> A anular…</> : <>Anular definitivamente</>}
                        </Button>
                    </ModalFooter>
                </Modal>
            </Container>
        </div>
    );
}

/** Escolhe um id default se existir na lista de lookups; senão o 1.º; senão "". */
function pickDefault(arr: any[] | undefined, preferredId: string): string {
    const list = arr ?? [];
    if (list.some((x) => String(x.id) === preferredId)) return preferredId;
    return list.length ? String(list[0].id) : "";
}
