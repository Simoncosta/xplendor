import React, { useEffect, useState } from "react";
import { Badge, Button, Col, Container, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Row, Spinner } from "reactstrap";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import ReasonButton from "Components/Common/ReasonButton";
import { ToastContainer, toast } from "react-toastify";
import { createServiceCatalogItem, getServiceCatalog, updateServiceCatalogItem } from "helpers/laravel_helper";
import { BILLING_LABEL, ICatalogItem, QuoteBilling, QuoteUnit, UNIT_LABEL, formatQuoteEuro } from "common/models/quote.model";
import QuoteSelect from "./QuoteSelect";
import XSelect from "pages/Editorial/XSelect";

/**
 * XPLENDOR — Catálogo de serviços (tabela padrão dos orçamentos). Só a equipa.
 * Preços sem IVA. Os serviços não se apagam: desativam-se (os orçamentos já feitos
 * guardam a sua própria cópia de cada linha).
 */
// checklist: a lista de arranque (tarefas copiadas para o ticket quando um orçamento com o serviço é aceite).
// Cada linha guarda a chave fixa (se tiver), para a tarefa se marcar sozinha.
type Task = { title: string; key: string | null };
type Draft = { id?: number; name: string; description: string; unit_price: string; unit: QuoteUnit; billing_type: QuoteBilling; active: boolean; checklist: Task[] };
const toTasks = (lines: ICatalogItem["onboarding_checklist"]): Task[] =>
    (lines ?? []).map((l) => (typeof l === "string" ? { title: l, key: null } : { title: l.title, key: l.key ?? null }));
const EMPTY: Draft = { name: "", description: "", unit_price: "", unit: "month", billing_type: "monthly", active: true, checklist: [] };
const MAX_TASKS = 30;
// Chaves fixas: a tarefa marca-se sozinha quando o cliente conclui o passo no link de configuração.
const TASK_KEYS = [
    { value: "", label: "Marca-se à mão" },
    { value: "social_access", label: "Sozinha: Facebook e Instagram ligados" },
    { value: "meta_ads_access", label: "Sozinha: anúncios da Meta ligados" },
    { value: "ga4_access", label: "Sozinha: Google Analytics ligado" },
];

const ServiceCatalogPage = () => {
    document.title = "Catálogo de serviços | Xplendor";
    const [items, setItems] = useState<ICatalogItem[]>([]);
    const [loading, setLoading] = useState(true);
    const [editing, setEditing] = useState<Draft | null>(null);
    const [saving, setSaving] = useState(false);

    const load = () => {
        setLoading(true);
        getServiceCatalog().then((r: any) => setItems(r?.data ?? [])).catch(() => setItems([])).finally(() => setLoading(false));
    };
    useEffect(load, []);

    const save = async () => {
        if (!editing) return;
        setSaving(true);
        const payload = {
            name: editing.name.trim(), description: editing.description.trim() || null, unit_price: Number(editing.unit_price),
            unit: editing.unit, billing_type: editing.billing_type, active: editing.active,
            onboarding_checklist: editing.checklist.map((t) => ({ title: t.title.trim(), key: t.key })).filter((t) => t.title),
        };
        try {
            if (editing.id) await updateServiceCatalogItem(editing.id, payload);
            else await createServiceCatalogItem(payload);
            toast.success("Serviço guardado.");
            setEditing(null);
            load();
        } catch (e: any) {
            const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
            toast.error((first as string) || e?.message || "Não foi possível guardar o serviço.");
        } finally {
            setSaving(false);
        }
    };

    const toggle = async (item: ICatalogItem) => {
        try {
            await updateServiceCatalogItem(item.id, { active: !item.active });
            setItems((prev) => prev.map((i) => (i.id === item.id ? { ...i, active: !i.active } : i)));
        } catch {
            toast.error("Não foi possível alterar o serviço.");
        }
    };

    const cols = useDataColumns<ICatalogItem>("administracao.catalogo-servicos", [
        {
            id: "name", header: "Serviço", value: (i) => i.name, hideable: false, mobile: "title",
            cell: (i) => (
                <div style={{ minWidth: 200 }}>
                    <div className="fw-medium">{i.name}</div>
                    {i.description && <small className="text-muted d-block">{i.description}</small>}
                    <small className={(i.onboarding_checklist?.length ?? 0) > 0 ? "text-muted" : "text-warning"}>
                        <i className="ri-rocket-2-line me-1" />
                        {(i.onboarding_checklist?.length ?? 0) > 0
                            ? `Lista de arranque: ${i.onboarding_checklist!.length} ${i.onboarding_checklist!.length === 1 ? "tarefa" : "tarefas"}`
                            : "Sem lista de arranque"}
                    </small>
                </div>
            ),
        },
        { id: "price", header: "Preço", value: (i) => i.unit_price, cell: (i) => <>{formatQuoteEuro(i.unit_price)} <small className="text-muted">{UNIT_LABEL[i.unit]}</small></>, align: "end", nowrap: true },
        { id: "billing", header: "Cobrança", value: (i) => BILLING_LABEL[i.billing_type], cell: (i) => <Badge color={i.billing_type === "monthly" ? "info" : "secondary"}>{BILLING_LABEL[i.billing_type]}</Badge> },
        {
            id: "active", header: "Estado", value: (i) => (i.active ? 1 : 0),
            cell: (i) => (
                <div className="form-check form-switch mb-0">
                    <Input className="form-check-input" type="switch" checked={i.active} onChange={() => void toggle(i)} aria-label="Ativo" />
                    <small className="text-muted">{i.active ? "Ativo" : "Desativado"}</small>
                </div>
            ),
        },
    ] as DTColumn<ICatalogItem>[]);

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Catálogo de serviços" breadcrumbs={[{ label: "Administração", to: "/admin" }, { label: "Orçamentos", to: "/admin/quotes" }]}
                    info="Preços sugeridos nos orçamentos (editáveis em cada orçamento). Valores sem IVA." />

                <PageCard
                    title="Serviços"
                    status={!loading ? <>{items.length} serviço{items.length === 1 ? "" : "s"}</> : undefined}
                    loading={loading && items.length > 0}
                    actions={<>
                        {cols.selector}
                        <Button size="sm" color="primary" onClick={() => setEditing({ ...EMPTY })}><i className="ri-add-line me-1" />Novo serviço</Button>
                    </>}
                >
                    <DataTable
                        columns={cols}
                        data={items}
                        rowKey={(i) => i.id}
                        loading={loading}
                        caption="Catálogo de serviços"
                        rowClassName={(i) => (i.active ? undefined : "opacity-50")}
                        empty={{ message: "Ainda não há serviços no catálogo." }}
                        rowActions={(i) => (
                            <Button size="sm" color="outline-primary" aria-label={`Editar ${i.name}`} onClick={() => setEditing({
                                id: i.id, name: i.name, description: i.description ?? "", unit_price: String(i.unit_price),
                                unit: i.unit, billing_type: i.billing_type, active: i.active, checklist: toTasks(i.onboarding_checklist),
                            })}><i className="ri-edit-line" /></Button>
                        )}
                    />
                </PageCard>

                <Modal isOpen={editing !== null} toggle={() => !saving && setEditing(null)} centered>
                    <ModalHeader toggle={() => !saving && setEditing(null)}>{editing?.id ? "Editar serviço" : "Novo serviço"}</ModalHeader>
                    {editing && (
                        <ModalBody>
                            <Label className="form-label">Nome</Label>
                            <Input className="mb-2" value={editing.name} onChange={(e) => setEditing({ ...editing, name: e.target.value })} />
                            <Label className="form-label">Descrição</Label>
                            <Input type="textarea" rows={2} className="mb-2" value={editing.description} onChange={(e) => setEditing({ ...editing, description: e.target.value })} />
                            <Row className="g-2">
                                <Col xs={6}>
                                    <Label className="form-label">Preço (sem IVA)</Label>
                                    <div className="input-group">
                                        <Input type="number" min={0} step="0.01" value={editing.unit_price} onChange={(e) => setEditing({ ...editing, unit_price: e.target.value })} />
                                        <span className="input-group-text">€</span>
                                    </div>
                                </Col>
                                <Col xs={6}>
                                    <Label className="form-label" for="catalog-unit">Unidade</Label>
                                    <QuoteSelect<QuoteUnit> inputId="catalog-unit" value={editing.unit}
                                        options={(Object.keys(UNIT_LABEL) as QuoteUnit[]).map((u) => ({ value: u, label: UNIT_LABEL[u] }))}
                                        onChange={(u) => u && setEditing({ ...editing, unit: u })} />
                                </Col>
                                <Col xs={12}>
                                    <Label className="form-label" for="catalog-billing">Tipo de cobrança</Label>
                                    <QuoteSelect<QuoteBilling> inputId="catalog-billing" value={editing.billing_type}
                                        options={[{ value: "monthly", label: "Mensal" }, { value: "one_off", label: "Valor único" }]}
                                        onChange={(b) => b && setEditing({ ...editing, billing_type: b })} />
                                </Col>
                            </Row>

                            {/* Lista de arranque */}
                            <div className="border-top mt-3 pt-3">
                                <Label className="form-label mb-1">Lista de arranque</Label>
                                <p className="text-muted fs-12 mb-2">
                                    Quando um orçamento com este serviço é aceite, estas tarefas entram no ticket de arranque. Sem lista, fica uma tarefa genérica. As tarefas de acesso podem marcar-se sozinhas quando o cliente conclui o passo no link de configuração.
                                </p>
                                <div className="vstack gap-2">
                                    {editing.checklist.map((t, idx) => (
                                        <div key={idx} className="d-flex flex-wrap flex-md-nowrap gap-2">
                                            <Input bsSize="sm" className="flex-grow-1" style={{ minWidth: 200 }} value={t.title} maxLength={200} placeholder={`Tarefa ${idx + 1}`} aria-label={`Tarefa ${idx + 1}`}
                                                onChange={(e) => setEditing({ ...editing, checklist: editing.checklist.map((x, j) => (j === idx ? { ...x, title: e.target.value } : x)) })} />
                                            <XSelect small width={250} ariaLabel={`Como se marca a tarefa ${idx + 1}`} options={TASK_KEYS} value={t.key ?? ""}
                                                onChange={(v) => setEditing({ ...editing, checklist: editing.checklist.map((x, j) => (j === idx ? { ...x, key: v || null } : x)) })} />
                                            <Button type="button" size="sm" color="outline-primary" className={idx === 0 ? "invisible" : ""} aria-label="Subir"
                                                onClick={() => { const c = [...editing.checklist]; [c[idx - 1], c[idx]] = [c[idx], c[idx - 1]]; setEditing({ ...editing, checklist: c }); }}>
                                                <i className="ri-arrow-up-line" />
                                            </Button>
                                            <Button type="button" size="sm" color="outline-danger" aria-label="Remover tarefa"
                                                onClick={() => setEditing({ ...editing, checklist: editing.checklist.filter((_, j) => j !== idx) })}>
                                                <i className="ri-delete-bin-line" />
                                            </Button>
                                        </div>
                                    ))}
                                </div>
                                <ReasonButton color="outline-primary" size="sm" className="mt-2" reason={editing.checklist.length >= MAX_TASKS ? `No máximo ${MAX_TASKS} tarefas por serviço.` : null}
                                    onClick={() => setEditing({ ...editing, checklist: [...editing.checklist, { title: "", key: null }] })}>
                                    <i className="ri-add-line me-1" />Acrescentar tarefa
                                </ReasonButton>
                            </div>
                        </ModalBody>
                    )}
                    <ModalFooter>
                        <Button color="light" onClick={() => setEditing(null)} disabled={saving}>Cancelar</Button>
                        <ReasonButton color="primary" onClick={() => void save()} disabled={saving}
                            reason={!editing?.name.trim() ? "Indique o nome do serviço." : editing?.unit_price === "" ? "Indique o preço." : null}>
                            {saving ? <Spinner size="sm" /> : "Guardar"}
                        </ReasonButton>
                    </ModalFooter>
                </Modal>
            </Container>
        </div>
    );
};

export default ServiceCatalogPage;
