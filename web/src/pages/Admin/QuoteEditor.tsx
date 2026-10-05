import React, { useEffect, useLayoutEffect, useMemo, useRef, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { Alert, Badge, Card, CardBody, CardHeader, Col, Container, Input, Label, Row, Spinner } from "reactstrap";
import Select from "react-select";
import BreadCrumb from "Components/Common/BreadCrumb";
import { ToastContainer, toast } from "react-toastify";
import {
    createAdminQuote, updateAdminQuote, showAdminQuote, sendAdminQuote, decideAdminQuote, duplicateAdminQuote,
    deleteAdminQuote, getAdminQuoteCompanies, getAdminQuoteDefaults, searchAdminQuoteCustomers, getServiceCatalog,
    adminQuotePdfPath, adminQuoteVersionPdfPath, getAdminQuoteActivity,
} from "helpers/laravel_helper";
import { openPdfGet } from "helpers/download_helper";
import QuoteActivityCard from "./QuoteActivityCard";
import QuoteSelect from "./QuoteSelect";
import { reactSelectTheme } from "helpers/reactSelectStyles";
import { confirmAction } from "helpers/swal";
import {
    IQuote, IQuoteActivity, IQuoteLine, IQuoteCustomer, IQuoteCompanyOption, ICatalogItem, QuoteBilling, QuoteDiscountType, QuoteUnit,
    QUOTE_STATUS_META, UNIT_LABEL, BILLING_LABEL, DEFAULT_UNIT, VAT_NOTE, ADS_NOTE, formatQuoteEuro, longDate, longDateTime, quoteTotals,
} from "common/models/quote.model";

/**
 * XPLENDOR — Editor de orçamento de serviços (só a equipa XPLENDOR).
 * Cliente dos Clientes da XPLENDOR ou novo (grava no módulo Clientes); linhas do
 * catálogo (preço sugerido, editável) ou personalizadas; descontos por linha e de
 * pacote; totais MENSAL e VALOR ÚNICO separados, sem IVA. Os valores mostrados são
 * uma pré-visualização: o servidor recalcula ao guardar.
 * Enviado, recusado ou expirado: guardar cria a versão seguinte. Aceite: só leitura.
 */

interface FormState {
    customerMode: "existing" | "new";
    customer: IQuoteCustomer | null;
    newCustomer: { name: string; phone: string; email: string };
    company_id: number | null;
    title: string;
    intro: string;
    notes: string;
    lines: IQuoteLine[];
    global_discount_type: QuoteDiscountType | "";
    global_discount_value: string;
    global_discount_target: QuoteBilling | "";
    global_discount_label: string;
    minimum_contract_months: string;
    monthly_start_terms: string;
    payment_terms_monthly: string;
    payment_terms_one_off: string;
}

/** Distância ao topo do bloco fixo da coluna da direita (barra superior + folga). */
const STICKY_TOP = 90;
type StickyMode = "none" | "all" | "totals";

const EMPTY: FormState = {
    customerMode: "existing", customer: null, newCustomer: { name: "", phone: "", email: "" }, company_id: null,
    title: "", intro: "", notes: "", lines: [],
    global_discount_type: "", global_discount_value: "", global_discount_target: "", global_discount_label: "Desconto de pacote",
    minimum_contract_months: "3", monthly_start_terms: "", payment_terms_monthly: "", payment_terms_one_off: "",
};

const fromQuote = (q: IQuote): FormState => ({
    customerMode: "existing",
    customer: q.customer_id ? { id: q.customer_id, name: q.client_name, email: q.client_email, phone: q.client_phone } : null,
    newCustomer: { name: q.customer_id ? "" : q.client_name, phone: q.client_phone ?? "", email: q.client_email ?? "" },
    company_id: q.company_id,
    title: q.title ?? "",
    intro: q.intro ?? "",
    notes: q.notes ?? "",
    lines: (q.lines ?? []).map((l) => ({ ...l })),
    global_discount_type: q.global_discount_type ?? "",
    global_discount_value: q.global_discount_value != null ? String(q.global_discount_value) : "",
    global_discount_target: q.global_discount_target ?? "",
    global_discount_label: q.global_discount_label ?? "Desconto de pacote",
    minimum_contract_months: q.minimum_contract_months != null ? String(q.minimum_contract_months) : "",
    monthly_start_terms: q.monthly_start_terms ?? "",
    payment_terms_monthly: q.payment_terms_monthly ?? "",
    payment_terms_one_off: q.payment_terms_one_off ?? "",
});

const toPayload = (f: FormState) => ({
    ...(f.customerMode === "new"
        ? { new_customer: { name: f.newCustomer.name.trim(), phone: f.newCustomer.phone.trim() || null, email: f.newCustomer.email.trim() || null } }
        : { customer_id: f.customer?.id ?? null }),
    company_id: f.company_id,
    title: f.title,
    intro: f.intro,
    notes: f.notes,
    lines: f.lines.map((l) => ({
        catalog_item_id: l.catalog_item_id ?? null, name: l.name, description: l.description || null,
        unit: l.unit, billing_type: l.billing_type, quantity: Number(l.quantity), unit_price: Number(l.unit_price),
        discount_type: l.discount_value ? l.discount_type ?? "percent" : null, discount_value: l.discount_value ? Number(l.discount_value) : null,
        is_optional: !!l.is_optional, in_package: !!l.in_package,
    })),
    global_discount_type: f.global_discount_type || null,
    global_discount_value: f.global_discount_value === "" ? null : Number(f.global_discount_value),
    global_discount_target: f.global_discount_type === "amount" ? f.global_discount_target || null : null,
    global_discount_label: f.global_discount_label || null,
    minimum_contract_months: f.minimum_contract_months === "" ? null : Number(f.minimum_contract_months),
    monthly_start_terms: f.monthly_start_terms,
    payment_terms_monthly: f.payment_terms_monthly,
    payment_terms_one_off: f.payment_terms_one_off,
});

// Opções dos seletores (sempre o react-select da app, com o tema de claro e escuro).
const BILLING_OPTIONS: { value: QuoteBilling; label: string }[] = [{ value: "monthly", label: "Mensal" }, { value: "one_off", label: "Valor único" }];
const UNIT_OPTIONS: { value: QuoteUnit; label: string }[] = (Object.keys(UNIT_LABEL) as QuoteUnit[]).map((u) => ({ value: u, label: UNIT_LABEL[u] }));
const LINE_DISCOUNT_OPTIONS: { value: QuoteDiscountType; label: string }[] = [{ value: "percent", label: "%" }, { value: "amount", label: "€" }];
const PACKAGE_TYPE_OPTIONS: { value: string; label: string }[] = [
    { value: "", label: "Sem desconto" }, { value: "percent", label: "Percentagem (nos dois totais)" }, { value: "amount", label: "Valor em euros" },
];

const firstError = (e: any, fallback: string) => {
    const errs = e?.errors;
    if (errs && typeof errs === "object") {
        const first = Object.values(errs).flat()[0];
        if (typeof first === "string") return first;
    }
    return e?.message || fallback;
};

const QuoteEditor = () => {
    const { id } = useParams();
    const navigate = useNavigate();
    const isNew = !id || id === "new";

    const [quote, setQuote] = useState<IQuote | null>(null);
    const [form, setForm] = useState<FormState>(EMPTY);
    const [savedJson, setSavedJson] = useState(JSON.stringify(toPayload(EMPTY)));
    const [loading, setLoading] = useState(!isNew);
    const [busy, setBusy] = useState(false);
    const [catalog, setCatalog] = useState<ICatalogItem[]>([]);
    const [companies, setCompanies] = useState<IQuoteCompanyOption[]>([]);
    const [customerOptions, setCustomerOptions] = useState<IQuoteCustomer[]>([]);
    const [customerSearch, setCustomerSearch] = useState("");

    document.title = `${quote ? quote.display_number : "Novo orçamento"} | Xplendor`;

    useEffect(() => {
        getServiceCatalog(true).then((r: any) => setCatalog(r?.data ?? [])).catch(() => setCatalog([]));
        getAdminQuoteCompanies().then((r: any) => setCompanies(r?.data ?? [])).catch(() => setCompanies([]));
    }, []);

    useEffect(() => {
        const t = setTimeout(() => {
            searchAdminQuoteCustomers(customerSearch.trim() || undefined)
                .then((r: any) => setCustomerOptions(r?.data ?? []))
                .catch(() => setCustomerOptions([]));
        }, 250);
        return () => clearTimeout(t);
    }, [customerSearch]);

    // Link público, aberturas e respostas (só quando há versões enviadas).
    const [activity, setActivity] = useState<IQuoteActivity | null>(null);
    const [activityLoading, setActivityLoading] = useState(false);
    const versionsCount = quote?.versions?.length ?? 0;
    useEffect(() => {
        if (!quote?.id || versionsCount === 0) { setActivity(null); return; }
        let alive = true;
        setActivityLoading(true);
        getAdminQuoteActivity(quote.id)
            .then((r: any) => { if (alive) setActivity(r?.data ?? null); })
            .catch(() => { if (alive) setActivity(null); })
            .finally(() => { if (alive) setActivityLoading(false); });
        return () => { alive = false; };
    }, [quote?.id, quote?.status, quote?.version, versionsCount]);
    const lastChangeRequest = activity?.responses.find((r) => r.type === "changes_requested") ?? null;

    const apply = (q: IQuote) => {
        setQuote(q);
        const f = fromQuote(q);
        setForm(f);
        setSavedJson(JSON.stringify(toPayload(f)));
    };

    useEffect(() => {
        if (isNew) {
            setQuote(null); setForm(EMPTY); setSavedJson(JSON.stringify(toPayload(EMPTY)));
            // Condições por omissão (contrato mínimo, forma de pagamento), editáveis neste orçamento.
            getAdminQuoteDefaults().then((r: any) => {
                const d = r?.data ?? {};
                setForm((f) => ({
                    ...f,
                    minimum_contract_months: d.minimum_contract_months != null ? String(d.minimum_contract_months) : f.minimum_contract_months,
                    monthly_start_terms: d.monthly_start_terms ?? f.monthly_start_terms,
                    payment_terms_monthly: d.payment_terms_monthly ?? f.payment_terms_monthly,
                    payment_terms_one_off: d.payment_terms_one_off ?? f.payment_terms_one_off,
                    global_discount_label: d.global_discount_label ?? f.global_discount_label,
                }));
            }).catch(() => undefined);
            return;
        }
        setLoading(true);
        showAdminQuote(Number(id))
            .then((r: any) => apply(r.data))
            .catch(() => toast.error("Orçamento não encontrado."))
            .finally(() => setLoading(false));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [id]);

    const status = quote?.status ?? "draft";
    const readOnly = status === "accepted";
    const reopening = status === "sent" || status === "refused" || status === "expired";
    const dirty = JSON.stringify(toPayload(form)) !== savedJson;
    const set = <K extends keyof FormState>(key: K, value: FormState[K]) => setForm((f) => ({ ...f, [key]: value }));

    const totals = useMemo(() => quoteTotals(form.lines, {
        type: form.global_discount_type || null,
        value: form.global_discount_value === "" ? null : Number(form.global_discount_value),
        target: form.global_discount_target || null,
    }), [form.lines, form.global_discount_type, form.global_discount_value, form.global_discount_target]);

    // ── linhas ───────────────────────────────────────────────────────────────
    const addFromCatalog = (item: ICatalogItem) => set("lines", [...form.lines, {
        catalog_item_id: item.id, name: item.name, description: item.description ?? "", unit: item.unit, billing_type: item.billing_type,
        quantity: 1, unit_price: Number(item.unit_price), discount_type: "percent", discount_value: null,
    }]);
    const addCustom = () => set("lines", [...form.lines, {
        catalog_item_id: null, name: "", description: "", unit: "project", billing_type: "one_off", quantity: 1, unit_price: 0, discount_type: "percent", discount_value: null,
    }]);
    const setLine = (i: number, patch: Partial<IQuoteLine>) => set("lines", form.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)));
    const removeLine = (i: number) => set("lines", form.lines.filter((_, j) => j !== i));
    const moveLine = (i: number, dir: -1 | 1) => {
        const j = i + dir;
        if (j < 0 || j >= form.lines.length) return;
        const next = [...form.lines];
        [next[i], next[j]] = [next[j], next[i]];
        set("lines", next);
    };

    // ── ações ────────────────────────────────────────────────────────────────
    const save = async (): Promise<IQuote | null> => {
        if (reopening && quote) {
            const ok = await confirmAction({
                title: `Criar a versão ${quote.version + 1}?`,
                text: status === "sent"
                    ? `A versão ${quote.version} fica guardada com o PDF que o cliente recebeu. A nova versão fica em rascunho até a marcar como enviada.`
                    : `O orçamento reabre como versão ${quote.version + 1}, em rascunho.`,
                confirmText: "Criar nova versão", icon: "question",
            });
            if (!ok) return null;
        }
        setBusy(true);
        try {
            const r: any = isNew ? await createAdminQuote(toPayload(form)) : await updateAdminQuote(Number(id), toPayload(form));
            apply(r.data);
            toast.success(isNew ? "Rascunho criado." : "Orçamento guardado.");
            if (isNew) navigate(`/admin/quotes/${r.data.id}`, { replace: true });
            return r.data as IQuote;
        } catch (e: any) {
            toast.error(firstError(e, "Não foi possível guardar o orçamento."));
            return null;
        } finally {
            setBusy(false);
        }
    };

    const send = async () => {
        let current = quote;
        if (isNew || dirty) {
            current = await save();
            if (!current) return;
        }
        if (!current) return;
        const ok = await confirmAction({
            title: "Marcar como enviado?",
            text: "Envie o PDF ao cliente antes de confirmar. A versão fica congelada com o PDF, recebe número (no primeiro envio) e é válida durante 30 dias."
                + (current.company_id ? " A empresa ligada é avisada por email." : ""),
            confirmText: "Marcar como enviado", icon: "question",
        });
        if (!ok) return;
        setBusy(true);
        try {
            const r: any = await sendAdminQuote(current.id);
            apply(r.data);
            toast.success(`Orçamento ${r.data.number} marcado como enviado.`);
        } catch (e: any) {
            toast.error(firstError(e, "Não foi possível marcar como enviado."));
        } finally {
            setBusy(false);
        }
    };

    const decide = async (decision: "accept" | "refuse") => {
        if (!quote) return;
        const ok = await confirmAction(decision === "accept"
            ? { title: "Registar como aceite?", text: "O cliente aceitou este orçamento.", confirmText: "Registar aceite", icon: "question", confirmVariant: "success" }
            : { title: "Registar como recusado?", text: "O cliente recusou este orçamento.", confirmText: "Registar recusa", icon: "warning", confirmVariant: "danger" });
        if (!ok) return;
        setBusy(true);
        try {
            const r: any = await decideAdminQuote(quote.id, decision);
            apply(r.data);
            toast.success(decision === "accept" ? "Orçamento aceite." : "Orçamento recusado.");
        } catch (e: any) {
            toast.error(firstError(e, "Não foi possível registar a decisão."));
        } finally {
            setBusy(false);
        }
    };

    const duplicate = async () => {
        if (!quote) return;
        setBusy(true);
        try {
            const r: any = await duplicateAdminQuote(quote.id);
            toast.success("Orçamento duplicado para um rascunho novo.");
            navigate(`/admin/quotes/${r.data.id}`);
        } catch (e: any) {
            toast.error(firstError(e, "Não foi possível duplicar."));
        } finally {
            setBusy(false);
        }
    };

    const remove = async () => {
        if (!quote) return;
        const ok = await confirmAction({ title: "Apagar rascunho?", text: "O rascunho é apagado definitivamente.", confirmText: "Apagar", icon: "warning", confirmVariant: "danger" });
        if (!ok) return;
        try {
            await deleteAdminQuote(quote.id);
            toast.success("Rascunho apagado.");
            navigate("/admin/quotes");
        } catch (e: any) {
            toast.error(firstError(e, "Não foi possível apagar."));
        }
    };

    const preview = async () => {
        let current = quote;
        if (isNew || dirty) {
            if (reopening) { toast.info("Guarde primeiro as alterações (cria a nova versão) para pré-visualizar."); return; }
            current = await save();
            if (!current) return;
        }
        if (!current) return;
        const r = await openPdfGet(adminQuotePdfPath(current.id));
        if (!r.ok) toast.error("Não foi possível gerar o PDF.");
    };

    const openVersion = async (version: number) => {
        if (!quote) return;
        const r = await openPdfGet(adminQuoteVersionPdfPath(quote.id, version));
        if (!r.ok) toast.error("Não foi possível abrir o PDF desta versão.");
    };

    // Coluna da direita (só em ecrãs largos, onde fica ao lado do formulário): totais e
    // notas num só bloco fixo; se o bloco não couber no ecrã, só os totais ficam fixos e
    // as notas seguem o scroll. Abaixo de xl a coluna passa para baixo e nada fica fixo.
    const totalsRef = useRef<HTMLDivElement>(null);
    const notesRef = useRef<HTMLDivElement>(null);
    const [stickyMode, setStickyMode] = useState<StickyMode>("none");
    useLayoutEffect(() => {
        const calc = () => {
            if (window.innerWidth < 1200) { setStickyMode("none"); return; }
            const available = window.innerHeight - STICKY_TOP - 16;
            const totalsH = totalsRef.current?.offsetHeight ?? 0;
            const notesH = notesRef.current?.offsetHeight ?? 0;
            setStickyMode(totalsH + 16 + notesH <= available ? "all" : totalsH <= available ? "totals" : "none");
        };
        calc();
        window.addEventListener("resize", calc);
        const observer = typeof ResizeObserver !== "undefined" ? new ResizeObserver(calc) : null;
        if (totalsRef.current) observer?.observe(totalsRef.current);
        if (notesRef.current) observer?.observe(notesRef.current);
        return () => { window.removeEventListener("resize", calc); observer?.disconnect(); };
    }, [loading]);
    const stickyStyle: React.CSSProperties = { position: "sticky", top: STICKY_TOP, zIndex: 2 };

    if (loading) {
        return <div className="page-content"><Container fluid><div className="text-center py-5"><Spinner color="primary" /></div></Container></div>;
    }

    const sm = QUOTE_STATUS_META[status];
    const catalogOptions = catalog.map((c) => ({
        value: c.id,
        label: `${c.name} · ${formatQuoteEuro(c.unit_price)} ${UNIT_LABEL[c.unit]} · ${BILLING_LABEL[c.billing_type]}`,
        item: c,
    }));
    const versionsCard = (quote?.versions?.length ?? 0) > 0 ? (

                                <Card className="mb-3">
                                    <CardHeader><h6 className="mb-0">Versões enviadas</h6></CardHeader>
                                    <CardBody>
                                        <ul className="list-unstyled vstack gap-2 mb-0">
                                            {quote!.versions!.map((v) => (
                                                <li key={v.version} className="d-flex align-items-center justify-content-between gap-2">
                                                    <div className="fs-13">
                                                        <div className="fw-medium">{v.number} · versão {v.version}</div>
                                                        <small className="text-muted">Enviada a {longDate(v.sent_at)}{v.valid_until ? ` · válida até ${longDate(v.valid_until)}` : ""}</small>
                                                    </div>
                                                    <button type="button" className="btn btn-soft-secondary btn-sm flex-shrink-0" onClick={() => void openVersion(v.version)}>
                                                        <i className="ri-file-pdf-line me-1" />PDF
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    </CardBody>
                                </Card>
                            
    ) : null;
    const hasMonthly = totals.buckets.monthly.count > 0;
    const hasOneOff = totals.buckets.one_off.count > 0;

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                {/* Cabeçalho (breadcrumbs, como nos Tickets) e ações */}
                <BreadCrumb title={isNew ? "Novo orçamento" : "Orçamento"} pageTitle="Orçamentos" pageLink="/admin/quotes" />
                <Row className="mb-3 align-items-center g-2">
                    <Col>
                        <h5 className="mb-0 d-flex align-items-center gap-2 flex-wrap">
                            {quote ? quote.display_number : "Novo orçamento"}
                            {quote && <small className="text-muted fs-14 fw-normal">versão {quote.version}</small>}
                            <Badge color={sm.color} className="fs-12">{sm.label}</Badge>
                            {dirty && !readOnly && <small className="text-warning fs-12 fw-normal">Alterações por guardar</small>}
                        </h5>
                        {quote?.sent_at && (
                            <small className="text-muted">
                                Enviado a {longDate(quote.sent_at)}{quote.valid_until ? ` · válido até ${longDate(quote.valid_until)}` : ""}
                                {quote.legacy_status ? " · migrado do módulo anterior" : ""}
                            </small>
                        )}
                    </Col>
                    <Col xs="auto" className="d-flex gap-2 flex-wrap justify-content-end">
                        {!readOnly && (
                            <button className="btn btn-soft-primary btn-sm" onClick={() => void save()} disabled={busy || (!dirty && !isNew)}>
                                <i className="ri-save-line me-1" />{reopening ? `Guardar como versão ${(quote?.version ?? 1) + 1}` : "Guardar rascunho"}
                            </button>
                        )}
                        <button className="btn btn-soft-secondary btn-sm" onClick={() => void preview()} disabled={busy || form.lines.length === 0}>
                            <i className="ri-file-pdf-line me-1" />Pré-visualizar PDF
                        </button>
                        {status === "draft" && (
                            <button className="btn btn-primary btn-sm" onClick={() => void send()} disabled={busy || form.lines.length === 0}>
                                <i className="ri-send-plane-line me-1" />Marcar como enviado
                            </button>
                        )}
                        {status === "sent" && !quote?.company_id && (
                            <>
                                <button className="btn btn-success btn-sm" onClick={() => void decide("accept")} disabled={busy}><i className="ri-check-line me-1" />Aceite</button>
                                <button className="btn btn-outline-danger btn-sm" onClick={() => void decide("refuse")} disabled={busy}><i className="ri-close-line me-1" />Recusado</button>
                            </>
                        )}
                        {quote && <button className="btn btn-soft-secondary btn-sm" onClick={() => void duplicate()} disabled={busy}><i className="ri-file-copy-line me-1" />Duplicar</button>}
                        {quote && status === "draft" && !quote.number && (
                            <button className="btn btn-soft-danger btn-sm" onClick={() => void remove()} disabled={busy}><i className="ri-delete-bin-line" /></button>
                        )}
                    </Col>
                </Row>

                {readOnly && <Alert color="success" className="py-2">Orçamento aceite: já não se altera. Para propor outras condições, duplique-o.</Alert>}
                {status === "sent" && quote?.company_id && <Alert color="info" className="py-2">Ligado a {quote.company_name}: a empresa aceita ou recusa no painel dela.</Alert>}
                {reopening && (
                    <Alert color="warning" className="py-2">
                        {status === "sent"
                            ? `Se alterar e guardar, é criada a versão ${(quote?.version ?? 1) + 1}. A versão ${quote?.version} fica guardada com o PDF enviado.`
                            : `Se alterar e guardar, o orçamento reabre como versão ${(quote?.version ?? 1) + 1}, em rascunho.`}
                    </Alert>
                )}

                {lastChangeRequest && quote?.changes_requested_at && (status === "sent" || status === "draft") && (
                    <Alert color="warning" className="py-2">
                        <strong>O cliente pediu alterações</strong> a {longDateTime(lastChangeRequest.created_at)} (versão {lastChangeRequest.version}):
                        <div className="mt-1" style={{ whiteSpace: "pre-line" }}>{lastChangeRequest.message}</div>
                        {status === "sent" && <small className="d-block mt-1">Altere e guarde para criar a versão seguinte; depois envie o novo link.</small>}
                    </Alert>
                )}

                <Row className="g-3">
                        <Col xl={8}>
                            {quote && <QuoteActivityCard quote={quote} activity={activity} loading={activityLoading} />}
                            <fieldset disabled={readOnly}>
                            {/* Cliente */}
                            <Card className="mb-3">
                                <CardHeader><h6 className="mb-0">Cliente</h6></CardHeader>
                                <CardBody>
                                    <div className="d-flex gap-3 mb-3">
                                        <div className="form-check">
                                            <Input className="form-check-input" type="radio" id="cm-existing" checked={form.customerMode === "existing"} onChange={() => set("customerMode", "existing")} />
                                            <Label className="form-check-label" for="cm-existing">Cliente existente</Label>
                                        </div>
                                        <div className="form-check">
                                            <Input className="form-check-input" type="radio" id="cm-new" checked={form.customerMode === "new"} onChange={() => set("customerMode", "new")} />
                                            <Label className="form-check-label" for="cm-new">Novo cliente</Label>
                                        </div>
                                    </div>
                                    {form.customerMode === "existing" ? (
                                        <Select
                                            classNamePrefix="react-select"
                                            styles={reactSelectTheme}
                                            menuPortalTarget={document.body}
                                            placeholder="Pesquisar nos Clientes da XPLENDOR"
                                            isDisabled={readOnly}
                                            value={form.customer ? { value: form.customer.id, label: form.customer.name, c: form.customer } : null}
                                            options={customerOptions.map((c) => ({ value: c.id, label: [c.name, c.email, c.phone].filter(Boolean).join(" · "), c }))}
                                            onInputChange={(v: string, meta: { action: string }) => { if (meta.action === "input-change") setCustomerSearch(v); }}
                                            onChange={(opt: any) => set("customer", opt?.c ?? null)}
                                            noOptionsMessage={() => "Sem clientes. Use \"Novo cliente\"."}
                                        />
                                    ) : (
                                        <Row className="g-2">
                                            <Col md={6}><Label className="form-label">Nome</Label><Input value={form.newCustomer.name} onChange={(e) => set("newCustomer", { ...form.newCustomer, name: e.target.value })} /></Col>
                                            <Col md={3}><Label className="form-label">Telefone</Label><Input value={form.newCustomer.phone} onChange={(e) => set("newCustomer", { ...form.newCustomer, phone: e.target.value })} /></Col>
                                            <Col md={3}><Label className="form-label">Email</Label><Input type="email" value={form.newCustomer.email} onChange={(e) => set("newCustomer", { ...form.newCustomer, email: e.target.value })} /></Col>
                                            <Col xs={12}><small className="text-muted">O cliente fica gravado no módulo Clientes da XPLENDOR.</small></Col>
                                        </Row>
                                    )}
                                    <div className="mt-3">
                                        <Label className="form-label" for="quote-company">Ligar a uma empresa da plataforma (opcional)</Label>
                                        <QuoteSelect<number> inputId="quote-company" isSearchable isClearable isDisabled={readOnly}
                                            placeholder="Sem ligação (pesquisar por nome)"
                                            options={companies.map((c) => ({ value: c.id, label: c.name }))}
                                            value={form.company_id} onChange={(v) => set("company_id", v)} />
                                        <small className="text-muted">Com ligação, a empresa recebe um email quando o orçamento é enviado e decide no painel dela.</small>
                                    </div>
                                </CardBody>
                            </Card>

                            {/* Apresentação */}
                            <Card className="mb-3">
                                <CardHeader><h6 className="mb-0">Apresentação</h6></CardHeader>
                                <CardBody>
                                    <Label className="form-label">Título (opcional)</Label>
                                    <Input className="mb-2" value={form.title} onChange={(e) => set("title", e.target.value)} placeholder="Ex.: Presença digital e tráfego pago" />
                                    <Label className="form-label">Introdução (opcional, aparece no PDF)</Label>
                                    <Input type="textarea" rows={3} value={form.intro} onChange={(e) => set("intro", e.target.value)}
                                        placeholder="Ex.: Proposta para gestão das redes sociais e das campanhas de anúncios." />
                                    <small className="text-muted">Título e introdução são opcionais: se ficarem vazios, não aparecem no PDF.</small>
                                </CardBody>
                            </Card>

                            {/* Linhas */}
                            <Card className="mb-3">
                                <CardHeader className="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                                    <h6 className="mb-0">Serviços</h6>
                                    {!readOnly && (
                                        <div className="d-flex gap-2 flex-wrap" style={{ minWidth: 0 }}>
                                            <div style={{ minWidth: 260 }}>
                                                <Select classNamePrefix="react-select" styles={reactSelectTheme} menuPortalTarget={document.body} placeholder="Adicionar do catálogo" value={null}
                                                    noOptionsMessage={() => "Sem resultados"} options={catalogOptions} onChange={(opt: any) => opt && addFromCatalog(opt.item)} />
                                            </div>
                                            <button type="button" className="btn btn-soft-primary btn-sm" onClick={addCustom}><i className="ri-add-line me-1" />Linha personalizada</button>
                                        </div>
                                    )}
                                </CardHeader>
                                <CardBody>
                                    {form.lines.length === 0 ? (
                                        <p className="text-muted mb-0">Sem linhas. Adicione serviços do catálogo ou uma linha personalizada.</p>
                                    ) : (
                                        <div className="vstack gap-3">
                                            {form.lines.map((l, i) => (
                                                <div key={i} className="border rounded p-3">
                                                    <Row className="g-2 align-items-end">
                                                        <Col md={5}>
                                                            <Label className="form-label fs-12 text-muted mb-1">Serviço{l.catalog_item_id ? " (do catálogo)" : " (personalizado)"}</Label>
                                                            <Input value={l.name} onChange={(e) => setLine(i, { name: e.target.value })} />
                                                        </Col>
                                                        <Col xs={6} md={2}>
                                                            <Label className="form-label fs-12 text-muted mb-1" for={`billing-${i}`}>Cobrança</Label>
                                                            <QuoteSelect<QuoteBilling> inputId={`billing-${i}`} isDisabled={readOnly} options={BILLING_OPTIONS} value={l.billing_type}
                                                                onChange={(b) => b && setLine(i, { billing_type: b, unit: l.catalog_item_id ? l.unit : DEFAULT_UNIT[b] })} />
                                                        </Col>
                                                        <Col xs={6} md={2}>
                                                            <Label className="form-label fs-12 text-muted mb-1" for={`unit-${i}`}>Unidade</Label>
                                                            <QuoteSelect<QuoteUnit> inputId={`unit-${i}`} isDisabled={readOnly} options={UNIT_OPTIONS} value={l.unit}
                                                                onChange={(u) => u && setLine(i, { unit: u })} />
                                                        </Col>
                                                        <Col xs={12} md={3} className="text-md-end">
                                                            <div className="fs-12 text-muted">Total da linha</div>
                                                            <div className="fw-semibold">{formatQuoteEuro(totals.lineTotals[i] ?? 0)}{l.billing_type === "monthly" ? "/mês" : ""}</div>
                                                        </Col>
                                                        <Col xs={12}>
                                                            <Input type="textarea" rows={1} placeholder="Descrição (opcional, aparece no PDF)" value={l.description ?? ""} onChange={(e) => setLine(i, { description: e.target.value })} />
                                                        </Col>
                                                        <Col xs={4} md={2}>
                                                            <Label className="form-label fs-12 text-muted mb-1">Quantidade</Label>
                                                            <Input type="number" min={0} step="0.5" value={l.quantity} onChange={(e) => setLine(i, { quantity: e.target.value as any })} />
                                                        </Col>
                                                        <Col xs={8} md={3}>
                                                            <Label className="form-label fs-12 text-muted mb-1">Preço (sem IVA)</Label>
                                                            <div className="input-group">
                                                                <Input type="number" min={0} step="0.01" value={l.unit_price} onChange={(e) => setLine(i, { unit_price: e.target.value as any })} />
                                                                <span className="input-group-text">€</span>
                                                            </div>
                                                        </Col>
                                                        <Col xs={8} md={4}>
                                                            <Label className="form-label fs-12 text-muted mb-1">Desconto da linha</Label>
                                                            <div className="d-flex gap-2">
                                                                <Input type="number" min={0} step="0.01" value={l.discount_value ?? ""} placeholder="0" onChange={(e) => setLine(i, { discount_value: e.target.value === "" ? null : (e.target.value as any) })} />
                                                                <div style={{ width: 92, flexShrink: 0 }}>
                                                                    <QuoteSelect<QuoteDiscountType> ariaLabel="Tipo de desconto da linha" isDisabled={readOnly} options={LINE_DISCOUNT_OPTIONS}
                                                                        value={l.discount_type ?? "percent"} onChange={(t) => t && setLine(i, { discount_type: t })} />
                                                                </div>
                                                            </div>
                                                        </Col>
                                                        <Col xs={4} md={3} className="d-flex gap-1 justify-content-end">
                                                            {!readOnly && (
                                                                <>
                                                                    <button type="button" className="btn btn-light btn-sm" onClick={() => moveLine(i, -1)} disabled={i === 0} aria-label="Subir"><i className="ri-arrow-up-line" /></button>
                                                                    <button type="button" className="btn btn-light btn-sm" onClick={() => moveLine(i, 1)} disabled={i === form.lines.length - 1} aria-label="Descer"><i className="ri-arrow-down-line" /></button>
                                                                    <button type="button" className="btn btn-soft-danger btn-sm" onClick={() => removeLine(i)} aria-label="Remover"><i className="ri-delete-bin-line" /></button>
                                                                </>
                                                            )}
                                                        </Col>
                                                        <Col xs={12} className="d-flex flex-wrap gap-4">
                                                            <div className="form-check form-switch mb-0">
                                                                <Input className="form-check-input" type="switch" id={`opt-${i}`} checked={!!l.is_optional}
                                                                    onChange={(e) => setLine(i, { is_optional: e.target.checked })} />
                                                                <Label className="form-check-label fs-13" for={`opt-${i}`}>Opcional <span className="text-muted">(o cliente pode desmarcar)</span></Label>
                                                            </div>
                                                            <div className="form-check form-switch mb-0">
                                                                <Input className="form-check-input" type="switch" id={`pkg-${i}`} checked={!!l.in_package}
                                                                    onChange={(e) => setLine(i, { in_package: e.target.checked })} />
                                                                <Label className="form-check-label fs-13" for={`pkg-${i}`}>Do pacote <span className="text-muted">(se sair, o desconto de pacote sai)</span></Label>
                                                            </div>
                                                        </Col>
                                                    </Row>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </CardBody>
                            </Card>

                            {/* Desconto de pacote */}
                            <Card className="mb-3">
                                <CardHeader><h6 className="mb-0">Desconto de pacote (opcional)</h6></CardHeader>
                                <CardBody>
                                    <Row className="g-2 align-items-end">
                                        <Col md={4}>
                                            <Label className="form-label" for="package-type">Tipo</Label>
                                            <QuoteSelect<string> inputId="package-type" isDisabled={readOnly} options={PACKAGE_TYPE_OPTIONS}
                                                value={form.global_discount_type} onChange={(v) => set("global_discount_type", (v ?? "") as any)} />
                                        </Col>
                                        {form.global_discount_type && (
                                            <>
                                                <Col md={2}>
                                                    <Label className="form-label">{form.global_discount_type === "percent" ? "%" : "€"}</Label>
                                                    <Input type="number" min={0} step="0.01" value={form.global_discount_value} onChange={(e) => set("global_discount_value", e.target.value)} />
                                                </Col>
                                                {form.global_discount_type === "amount" && (
                                                    <Col md={3}>
                                                        <Label className="form-label" for="package-target">Aplica-se ao total</Label>
                                                        <QuoteSelect<QuoteBilling> inputId="package-target" isDisabled={readOnly} options={BILLING_OPTIONS} placeholder="Escolher"
                                                            value={form.global_discount_target || null} onChange={(v) => set("global_discount_target", (v ?? "") as any)} />
                                                    </Col>
                                                )}
                                                <Col md={form.global_discount_type === "amount" ? 3 : 6}>
                                                    <Label className="form-label">Texto no PDF</Label>
                                                    <Input value={form.global_discount_label} onChange={(e) => set("global_discount_label", e.target.value)} />
                                                </Col>
                                                <Col xs={12}>
                                                    {(() => {
                                                        const pkg = form.lines.filter((l) => l.in_package).map((l) => l.name || "linha sem nome");
                                                        const optionalPkg = form.lines.filter((l) => l.in_package && l.is_optional).map((l) => l.name || "linha sem nome");
                                                        return pkg.length === 0 ? (
                                                            <small className="text-muted d-block">
                                                                Nenhuma linha marcada como "Do pacote": o desconto aplica-se sempre ao que o cliente aceitar.
                                                            </small>
                                                        ) : (
                                                            <small className="text-muted d-block">
                                                                Linhas do pacote: {pkg.join(", ")}.{" "}
                                                                {optionalPkg.length > 0
                                                                    ? `Se o cliente deixar de fora ${optionalPkg.length === 1 ? optionalPkg[0] : "alguma delas"}, o desconto deixa de se aplicar e a página explica porquê.`
                                                                    : "Como nenhuma delas é opcional, o desconto aplica-se sempre."}
                                                            </small>
                                                        );
                                                    })()}
                                                </Col>
                                            </>
                                        )}
                                    </Row>
                                </CardBody>
                            </Card>

                            {/* Condições */}
                            <Card className="mb-3">
                                <CardHeader><h6 className="mb-0">Condições</h6></CardHeader>
                                <CardBody>
                                    {/* Cada condição só aparece (aqui e no PDF) quando há linhas do tipo a que se refere. */}
                                    <Row className="g-2">
                                        {!hasMonthly && !hasOneOff && (
                                            <Col xs={12}><p className="text-muted mb-1">As condições de pagamento aparecem quando acrescentar serviços mensais ou de valor único.</p></Col>
                                        )}
                                        {hasMonthly && (
                                            <>
                                                <Col xs={12}><div className="text-muted text-uppercase fs-11 fw-semibold">Serviços mensais</div></Col>
                                                <Col md={9}>
                                                    <Label className="form-label">Início dos serviços mensais</Label>
                                                    <Input value={form.monthly_start_terms} onChange={(e) => set("monthly_start_terms", e.target.value)} placeholder="Vazio: não aparece no PDF" />
                                                </Col>
                                                <Col md={3}>
                                                    <Label className="form-label">Contrato mínimo (meses)</Label>
                                                    <Input type="number" min={1} max={60} value={form.minimum_contract_months} onChange={(e) => set("minimum_contract_months", e.target.value)} placeholder="Sem mínimo" />
                                                </Col>
                                                <Col xs={12}>
                                                    <Label className="form-label">Pagamento dos serviços mensais</Label>
                                                    <Input type="textarea" rows={2} value={form.payment_terms_monthly} onChange={(e) => set("payment_terms_monthly", e.target.value)} placeholder="Vazio: não aparece no PDF" />
                                                </Col>
                                            </>
                                        )}
                                        {hasOneOff && (
                                            <>
                                                <Col xs={12}><div className={`text-muted text-uppercase fs-11 fw-semibold${hasMonthly ? " mt-2" : ""}`}>Valor único</div></Col>
                                                <Col xs={12}>
                                                    <Label className="form-label">Pagamento do valor único</Label>
                                                    <Input type="textarea" rows={2} value={form.payment_terms_one_off} onChange={(e) => set("payment_terms_one_off", e.target.value)} placeholder="Vazio: não aparece no PDF" />
                                                </Col>
                                            </>
                                        )}
                                        <Col xs={12}>
                                            <small className="text-muted d-block mt-1">Sempre no PDF: "{VAT_NOTE}" e "{ADS_NOTE}"</small>
                                        </Col>
                                    </Row>
                                </CardBody>
                            </Card>
                            </fieldset>
                        </Col>

                        {/* Coluna lateral: versões (em cima, para nada ficar por baixo do bloco fixo), totais e notas. */}
                        <Col xl={4}>
                            {stickyMode !== "none" && versionsCard}
                            {/* "all": o bloco inteiro fica fixo. "totals": o invólucro deixa de ser uma caixa
                                (display: contents) para os totais ficarem fixos em toda a coluna e as notas seguirem o scroll. */}
                            <div style={stickyMode === "all" ? stickyStyle : stickyMode === "totals" ? { display: "contents" } : undefined}>
                            <div ref={totalsRef} style={stickyMode === "totals" ? stickyStyle : undefined}>
                            <Card className="mb-3">
                                <CardHeader><h6 className="mb-0">Totais (sem IVA)</h6></CardHeader>
                                <CardBody>
                                    {!hasMonthly && !hasOneOff && <p className="text-muted mb-0">Acrescente linhas para ver os totais.</p>}
                                    {hasMonthly && (
                                        <div className="border rounded p-3 mb-2">
                                            <div className="text-muted text-uppercase fs-11 fw-semibold">Total mensal</div>
                                            <div className="fs-4 fw-semibold">{formatQuoteEuro(totals.buckets.monthly.total)}<small className="text-muted fs-13">/mês</small></div>
                                            {totals.buckets.monthly.discount > 0 && (
                                                <small className="text-success">{form.global_discount_label || "Desconto de pacote"}: −{formatQuoteEuro(totals.buckets.monthly.discount)}/mês</small>
                                            )}
                                        </div>
                                    )}
                                    {hasOneOff && (
                                        <div className="border rounded p-3 mb-2">
                                            <div className="text-muted text-uppercase fs-11 fw-semibold">Total valor único</div>
                                            <div className="fs-4 fw-semibold">{formatQuoteEuro(totals.buckets.one_off.total)}</div>
                                            {totals.buckets.one_off.discount > 0 && (
                                                <small className="text-success">{form.global_discount_label || "Desconto de pacote"}: −{formatQuoteEuro(totals.buckets.one_off.discount)}</small>
                                            )}
                                        </div>
                                    )}
                                    {(hasMonthly || hasOneOff) && <p className="fw-semibold fs-13 mb-0">{VAT_NOTE}</p>}
                                </CardBody>
                            </Card>
                            </div>

                            <div ref={notesRef}>
                            <Card className="mb-3">
                                <CardHeader><h6 className="mb-0">Notas internas</h6></CardHeader>
                                <CardBody>
                                    <Input type="textarea" rows={3} disabled={readOnly} value={form.notes} onChange={(e) => set("notes", e.target.value)} placeholder="Não aparecem no PDF nem para o cliente." />
                                </CardBody>
                            </Card>
                            </div>
                            </div>

                            {stickyMode === "none" && versionsCard}
                        </Col>
                </Row>
            </Container>
        </div>
    );
};

export default QuoteEditor;
