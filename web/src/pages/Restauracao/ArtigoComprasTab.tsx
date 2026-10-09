import { useEffect, useMemo, useState } from "react";
import {
    Row, Col, Label, Input, Button, Modal, ModalHeader, ModalBody, ModalFooter, Alert,
} from "reactstrap";
import { toast } from "react-toastify";
import XSelect from "Components/Common/Select";
import { getPingwinSuppliers } from "helpers/laravel_helper";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import { SupplierPricesStaging, LineDraft, SupplierLine } from "./useSupplierPricesStaging";


/**
 * XPLENDOR — Tab Compras (C3): tabela das linhas de fornecedor + modal com CASCATA
 * (fornecedor → tabela/moeda/datas auto e bloqueadas). Staging no frontend (diff verde/
 * amarela/vermelha); efetiva ao Salvar o artigo. Só em EDITAR (o artigo tem de existir).
 *
 * UI-2a: a tabela passa a DataTable (as colunas escolhidas ficam guardadas por pessoa).
 */

type SupplierTable = {
    supplier_id: string | null; supplier_name: string | null; supplier_code: string | null;
    table_id: string | null; table_name: string | null;
    start_date: string | null; end_date: string | null; currency: string | null;
};
type Opt = { value: string; label: string; pingwin_id: string | null };

const centsToEur = (c: number | null): string => c === null || c === undefined ? "" : (c / 100).toFixed(2).replace(".", ",");
const eurToCents = (s: string): number | null => {
    const t = (s ?? "").trim().replace(",", "."); if (t === "") return null;
    const n = parseFloat(t); return isNaN(n) ? null : Math.round(n * 100);
};
const rowBg = (s: string) =>
    s === "new" ? "bg-success-subtle" : s === "edited" ? "bg-warning-subtle" : s === "deleted" ? "bg-danger-subtle" : "";

type FormState = {
    editingKey: string | null;
    supplierId: number | null;
    tableId: string | null;
    unitId: string;
    description: string;
    code: string;
    barcode: string;
    price: string;      // €
    discount1: string;
    discount2_mul: string;
};
const EMPTY: FormState = { editingKey: null, supplierId: null, tableId: null, unitId: "", description: "", code: "", barcode: "", price: "", discount1: "", discount2_mul: "" };

export default function ArtigoComprasTab({
    companyId, isCreate, staging, supplierTables, unitOptions,
}: {
    companyId: number;
    isCreate: boolean;
    staging: SupplierPricesStaging;
    supplierTables: SupplierTable[];
    unitOptions: { value: string; label: string }[];
}) {
    const [suppliers, setSuppliers] = useState<Opt[]>([]);
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState<FormState>(EMPTY);
    const setF = (patch: Partial<FormState>) => setForm((p) => ({ ...p, ...patch }));

    // Colunas (as de omissão: fornecedor, tabela, unidade, descrição, preço e código).
    const strike = (r: SupplierLine) => (r._state === "deleted" ? "text-decoration-line-through" : undefined);
    const columns: DTColumn<SupplierLine>[] = [
        { id: "supplier", header: "Fornecedor", value: (r) => r.supplier.name, cellClassName: strike, mobile: "title" },
        { id: "table", header: "Tabela", value: (r) => r.table.name },
        { id: "start", header: "Data início", value: (r) => r.start_date, defaultVisible: false, nowrap: true },
        { id: "end", header: "Data fim", value: (r) => r.end_date, defaultVisible: false, nowrap: true },
        { id: "currency", header: "Moeda", value: (r) => r.currency, defaultVisible: false },
        { id: "unit", header: "Unidade", value: (r) => r.unit.name },
        { id: "description", header: "Descrição no fornecedor", value: (r) => r.sup_product_description },
        { id: "price", header: "Preço", value: (r) => r.price_cents, cell: (r) => (r.price_cents != null ? `${centsToEur(r.price_cents)} €` : "—"), align: "end", nowrap: true },
        { id: "discount1", header: "Desconto 1 (%)", value: (r) => (r.discount1 != null ? Number(r.discount1) : null), cell: (r) => r.discount1 ?? "—", align: "end", defaultVisible: false },
        { id: "discount2", header: "Desc. mult. (%)", value: (r) => (r.discount2_mul != null ? Number(r.discount2_mul) : null), cell: (r) => r.discount2_mul ?? "—", align: "end", defaultVisible: false },
        { id: "code", header: "Código do fornecedor", value: (r) => r.sup_product_code },
        { id: "barcode", header: "Código de barras", value: (r) => r.sup_product_barcode, defaultVisible: false },
    ];
    const cols = useDataColumns("restauracao.artigos.compras", columns);

    // Fornecedores unificados (para o dropdown). Cruzam-se com supplierTables pelo pingwin_id.
    useEffect(() => {
        if (isCreate || !companyId) return;
        // ⚠️ perPage ≤ 200 (o endpoint valida max:200) e shape do paginador: data.suppliers.data.
        getPingwinSuppliers(companyId, { perPage: 200, active: 1 } as any)
            .then((r: any) => {
                const list = r?.data?.suppliers?.data ?? r?.data?.suppliers ?? [];
                setSuppliers((Array.isArray(list) ? list : []).map((s: any) => ({
                    value: String(s.id), label: String(s.name ?? s.fiscal_name ?? s.id), pingwin_id: s.pingwin_id ? String(s.pingwin_id) : null,
                })));
            })
            .catch(() => toast.error("Não foi possível carregar os fornecedores."));
    }, [companyId, isCreate]);

    const supplierOf = (id: number | null) => suppliers.find((s) => Number(s.value) === id) ?? null;
    // Tabelas do fornecedor escolhido (cruzadas pelo pingwin_id).
    const tablesForSupplier = useMemo(() => {
        const sup = suppliers.find((s) => Number(s.value) === form.supplierId);
        if (!sup?.pingwin_id) return [];
        return supplierTables.filter((t) => t.supplier_id === sup.pingwin_id);
    }, [form.supplierId, suppliers, supplierTables]);
    const selectedTable = useMemo(() => tablesForSupplier.find((t) => t.table_id === form.tableId) ?? tablesForSupplier[0] ?? null, [tablesForSupplier, form.tableId]);

    // Ao mudar de fornecedor, auto-seleciona a tabela (se só uma) e limpa a escolha.
    const onSupplier = (id: number | null) => {
        const sup = suppliers.find((s) => Number(s.value) === id);
        const tables = sup?.pingwin_id ? supplierTables.filter((t) => t.supplier_id === sup.pingwin_id) : [];
        setF({ supplierId: id, tableId: tables.length ? tables[0].table_id : null });
    };

    const openAdd = () => { setForm(EMPTY); setOpen(true); };
    const openEdit = (r: SupplierLine) => {
        setForm({
            editingKey: r._key,
            supplierId: r.supplier.id,
            tableId: r.table.header_id,
            unitId: r.unit.id ?? "",
            description: r.sup_product_description ?? "",
            code: r.sup_product_code ?? "",
            barcode: r.sup_product_barcode ?? "",
            price: centsToEur(r.price_cents),
            discount1: r.discount1 != null ? String(r.discount1) : "",
            discount2_mul: r.discount2_mul != null ? String(r.discount2_mul) : "",
        });
        setOpen(true);
    };

    const isEditing = form.editingKey !== null;

    const submit = () => {
        const sup = supplierOf(form.supplierId);
        if (!sup) { toast.error("Escolha um fornecedor."); return; }
        if (!selectedTable) { toast.error("Este fornecedor não tem tabela de preços."); return; }
        const cents = eurToCents(form.price);
        if (cents === null) { toast.error("Indique um preço válido."); return; }
        const unit = unitOptions.find((u) => u.value === form.unitId);

        const draft: LineDraft = {
            supplier: { id: Number(sup.value), pingwin_id: sup.pingwin_id, name: sup.label },
            table: { header_id: selectedTable.table_id, name: selectedTable.table_name },
            start_date: selectedTable.start_date,
            end_date: selectedTable.end_date,
            currency: selectedTable.currency,
            unit: { id: form.unitId || null, name: unit?.label ?? null },
            sup_product_description: form.description.trim() || null,
            sup_product_code: form.code.trim() || null,
            sup_product_barcode: form.barcode.trim() || null,
            price_cents: cents,
            discount1: form.discount1.trim() || null,
            discount2_mul: form.discount2_mul.trim() || null,
        };

        if (form.editingKey) staging.editRow(form.editingKey, draft);
        else staging.addRow(draft);
        setOpen(false);
    };

    if (isCreate) {
        return <Alert color="info" className="mb-0">Guarde primeiro o artigo para poder gerir os fornecedores (separador Compras).</Alert>;
    }

    const rowActions = (r: SupplierLine) => r._state === "deleted" ? (
        <Button color="link" size="sm" className="text-success p-0" onClick={() => staging.restoreRow(r._key)}>
            <i className="ri-arrow-go-back-line me-1" />Restaurar
        </Button>
    ) : (
        <>
            <Button size="sm" color="outline-primary" title="Editar" aria-label={`Editar linha: ${r.supplier.name ?? "fornecedor"}`} onClick={() => openEdit(r)}><i className="ri-pencil-line" /></Button>
            {/* Exceção do padrão: a linha só se apaga ao guardar o artigo (staging). */}
            <Button size="sm" color="outline-danger" title="Apagar" aria-label={`Apagar linha: ${r.supplier.name ?? "fornecedor"}`} onClick={() => staging.deleteRow(r._key)}><i className="ri-delete-bin-line" /></Button>
        </>
    );

    return (
        <div>
            <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <h6 className="mb-0">Fornecedores</h6>
                <div className="d-flex gap-2">
                    {cols.selector}
                    <Button color="outline-primary" size="sm" onClick={openAdd}><i className="ri-add-line me-1" />Adicionar linha</Button>
                </div>
            </div>

            <div className="border rounded">
                <DataTable
                    columns={cols}
                    data={staging.rows}
                    rowKey={(r) => r._key}
                    rowClassName={(r) => rowBg(r._state) || undefined}
                    rowActions={rowActions}
                    caption="Fornecedores do artigo"
                    empty={{ message: "Sem fornecedores.", action: <Button color="outline-primary" size="sm" onClick={openAdd}><i className="ri-add-line me-1" />Adicionar linha</Button> }}
                />
            </div>

            {/* Modal inserir/editar com cascata */}
            <Modal isOpen={open} toggle={() => setOpen(false)} centered size="lg">
                <ModalHeader toggle={() => setOpen(false)}>{isEditing ? "Editar linha de fornecedor" : "Nova linha de fornecedor"}</ModalHeader>
                <ModalBody>
                    <Row className="g-3">
                        <Col md={6}>
                            <Label className="form-label">Fornecedor</Label>
                            <XSelect
                                ariaLabel="Fornecedor"
                                options={suppliers}
                                value={form.supplierId !== null ? String(form.supplierId) : null}
                                onChange={(v) => onSupplier(v ? Number(v) : null)}
                                disabled={isEditing}  // o fornecedor não muda numa linha existente
                                searchable placeholder="Escolha o fornecedor…"
                            />
                        </Col>
                        <Col md={6}>
                            <Label className="form-label">Tabela</Label>
                            {tablesForSupplier.length > 1 ? (
                                <XSelect
                                    ariaLabel="Tabela"
                                    options={tablesForSupplier.map((t) => ({ value: t.table_id ?? "", label: t.table_name ?? "—" }))}
                                    value={selectedTable?.table_id ?? null}
                                    onChange={(v) => setF({ tableId: v || null })}
                                    disabled={isEditing}
                                    searchable
                                />
                            ) : (
                                <Input value={selectedTable?.table_name ?? ""} disabled />
                            )}
                        </Col>
                        <Col md={4}><Label className="form-label">Moeda</Label><Input value={selectedTable?.currency ?? ""} disabled /></Col>
                        <Col md={4}><Label className="form-label">Data início</Label><Input value={selectedTable?.start_date ?? ""} disabled placeholder="—" /></Col>
                        <Col md={4}><Label className="form-label">Data fim</Label><Input value={selectedTable?.end_date ?? ""} disabled placeholder="—" /></Col>

                        <Col md={3}>
                            <Label className="form-label">Unidade</Label>
                            <XSelect ariaLabel="Unidade" options={unitOptions} value={form.unitId}
                                onChange={(v) => setF({ unitId: v })} searchable placeholder="Unidade…" />
                        </Col>
                        <Col md={3}><Label className="form-label">Preço (€)</Label><Input value={form.price} onChange={(e) => setF({ price: e.target.value })} inputMode="decimal" placeholder="0,00" /></Col>
                        <Col md={6}><Label className="form-label">Descrição no fornecedor</Label><Input value={form.description} onChange={(e) => setF({ description: e.target.value })} placeholder="Nome do artigo no fornecedor" /></Col>

                        <Col md={2}><Label className="form-label">Desconto 1 (%)</Label><Input value={form.discount1} onChange={(e) => setF({ discount1: e.target.value })} inputMode="decimal" placeholder="0" /></Col>
                        <Col md={2}><Label className="form-label">Desc. mult. (%)</Label><Input value={form.discount2_mul} onChange={(e) => setF({ discount2_mul: e.target.value })} inputMode="decimal" placeholder="0" /></Col>
                        <Col md={4}><Label className="form-label">Código do fornecedor</Label><Input value={form.code} onChange={(e) => setF({ code: e.target.value })} placeholder="Referência do fornecedor" /></Col>
                        <Col md={4}><Label className="form-label">Cód. de barras</Label><Input value={form.barcode} onChange={(e) => setF({ barcode: e.target.value })} /></Col>
                    </Row>
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setOpen(false)}>Cancelar</Button>
                    <Button color="primary" onClick={submit}><i className="ri-check-line me-1" />{isEditing ? "Guardar linha" : "Adicionar"}</Button>
                </ModalFooter>
            </Modal>
        </div>
    );
}
