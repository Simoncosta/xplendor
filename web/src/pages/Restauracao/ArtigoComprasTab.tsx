import { useEffect, useMemo, useState } from "react";
import {
    Row, Col, Label, Input, Button, Modal, ModalHeader, ModalBody, ModalFooter, Table, Alert,
} from "reactstrap";
import Select from "react-select";
import { toast } from "react-toastify";
import { reactSelectTheme } from "../../helpers/reactSelectStyles";
import { getPingwinSuppliers } from "helpers/laravel_helper";
import ColumnSelector from "Components/Common/ColumnSelector";
import { SupplierPricesStaging, LineDraft, SupplierLine } from "./useSupplierPricesStaging";

// Todas as colunas da tabela de fornecedores + as visíveis por default.
const SUPPLIER_COLUMNS: { id: string; label: string }[] = [
    { id: "supplier", label: "Fornecedor" },
    { id: "table", label: "Tabela" },
    { id: "start", label: "Data início" },
    { id: "end", label: "Data fim" },
    { id: "currency", label: "Moeda" },
    { id: "unit", label: "Unidade" },
    { id: "description", label: "Descrição no fornecedor" },
    { id: "price", label: "Preço" },
    { id: "discount1", label: "Desconto 1 (%)" },
    { id: "discount2", label: "Desc. mult. (%)" },
    { id: "code", label: "Código do fornecedor" },
    { id: "barcode", label: "Código de barras" },
];
const DEFAULT_VISIBLE = ["supplier", "table", "unit", "description", "price", "code"];

/**
 * XPLENDOR — Tab Compras (C3): tabela das linhas de fornecedor + modal com CASCATA
 * (fornecedor → tabela/moeda/datas auto e bloqueadas). Staging no frontend (diff verde/
 * amarela/vermelha); efetiva ao Salvar o artigo. Só em EDITAR (o artigo tem de existir).
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

    // Visibilidade das colunas (memória; refresh volta ao default). Toda a coluna é toggleável.
    const [vis, setVis] = useState<Record<string, boolean>>(
        () => Object.fromEntries(SUPPLIER_COLUMNS.map((c) => [c.id, DEFAULT_VISIBLE.includes(c.id)]))
    );
    const setColVisible = (id: string, visible: boolean) => setVis((p) => ({ ...p, [id]: visible }));
    const columnsForSelector = SUPPLIER_COLUMNS.map((c) => ({ ...c, visible: !!vis[c.id] }));
    const show = (id: string) => !!vis[id];
    // colunas visíveis + 1 (ações) → colspan do estado vazio.
    const visibleCount = SUPPLIER_COLUMNS.filter((c) => vis[c.id]).length + 1;

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
        const sup = supplierOf(form.supplierId);
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
        if (!sup) { toast.error("Escolhe um fornecedor."); return; }
        if (!selectedTable) { toast.error("Este fornecedor não tem tabela de preços."); return; }
        const cents = eurToCents(form.price);
        if (cents === null) { toast.error("Indica um preço válido."); return; }
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
        return <Alert color="info" className="mb-0">Guarda o artigo primeiro para poderes gerir os fornecedores (tab Compras).</Alert>;
    }

    return (
        <div>
            <div className="d-flex justify-content-between align-items-center mb-2">
                <h6 className="mb-0">Fornecedores</h6>
                <div className="d-flex gap-2">
                    <ColumnSelector columns={columnsForSelector} onChange={setColVisible} defaults={DEFAULT_VISIBLE} />
                    <Button color="soft-primary" size="sm" onClick={openAdd}><i className="ri-add-line me-1" />Adicionar linha</Button>
                </div>
            </div>

            <div className="table-responsive">
                <Table className="align-middle table-sm mb-0">
                    <thead>
                        <tr className="text-muted fs-12 text-uppercase">
                            {show("supplier") && <th>Fornecedor</th>}
                            {show("table") && <th>Tabela</th>}
                            {show("start") && <th>Início</th>}
                            {show("end") && <th>Fim</th>}
                            {show("currency") && <th>Moeda</th>}
                            {show("unit") && <th>Unidade</th>}
                            {show("description") && <th>Descrição</th>}
                            {show("price") && <th className="text-end">Preço</th>}
                            {show("discount1") && <th className="text-end">Desc.1</th>}
                            {show("discount2") && <th className="text-end">Desc.mult.</th>}
                            {show("code") && <th>Código</th>}
                            {show("barcode") && <th>Cód. barras</th>}
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        {staging.rows.length === 0 && (
                            <tr><td colSpan={visibleCount} className="text-center text-muted py-3">Sem fornecedores.</td></tr>
                        )}
                        {staging.rows.map((r) => {
                            const deleted = r._state === "deleted";
                            return (
                                <tr key={r._key} className={rowBg(r._state)}>
                                    {show("supplier") && <td className={deleted ? "text-decoration-line-through" : ""}>{r.supplier.name ?? "—"}</td>}
                                    {show("table") && <td>{r.table.name ?? "—"}</td>}
                                    {show("start") && <td>{r.start_date ?? "—"}</td>}
                                    {show("end") && <td>{r.end_date ?? "—"}</td>}
                                    {show("currency") && <td>{r.currency ?? "—"}</td>}
                                    {show("unit") && <td>{r.unit.name ?? "—"}</td>}
                                    {show("description") && <td>{r.sup_product_description ?? "—"}</td>}
                                    {show("price") && <td className="text-end">{r.price_cents != null ? `${centsToEur(r.price_cents)} €` : "—"}</td>}
                                    {show("discount1") && <td className="text-end">{r.discount1 ?? "—"}</td>}
                                    {show("discount2") && <td className="text-end">{r.discount2_mul ?? "—"}</td>}
                                    {show("code") && <td>{r.sup_product_code ?? "—"}</td>}
                                    {show("barcode") && <td>{r.sup_product_barcode ?? "—"}</td>}
                                    <td className="text-end text-nowrap">
                                        {deleted ? (
                                            <Button color="link" size="sm" className="text-success p-0" onClick={() => staging.restoreRow(r._key)}>
                                                <i className="ri-arrow-go-back-line me-1" />Restaurar
                                            </Button>
                                        ) : (
                                            <>
                                                <button type="button" className="btn btn-sm btn-ghost-secondary p-1" title="Editar" onClick={() => openEdit(r)}><i className="ri-pencil-line" /></button>
                                                <button type="button" className="btn btn-sm btn-ghost-danger p-1" title="Apagar" onClick={() => staging.deleteRow(r._key)}><i className="ri-delete-bin-line" /></button>
                                            </>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </Table>
            </div>

            {/* Modal inserir/editar com cascata */}
            <Modal isOpen={open} toggle={() => setOpen(false)} centered size="lg">
                <ModalHeader toggle={() => setOpen(false)}>{isEditing ? "Editar linha de fornecedor" : "Nova linha de fornecedor"}</ModalHeader>
                <ModalBody>
                    <Row className="g-3">
                        <Col md={6}>
                            <Label className="form-label">Fornecedor</Label>
                            <Select
                                styles={reactSelectTheme} menuPortalTarget={document.body}
                                options={suppliers}
                                value={suppliers.find((s) => Number(s.value) === form.supplierId) ?? null}
                                onChange={(o: any) => onSupplier(o ? Number(o.value) : null)}
                                isDisabled={isEditing}  // o fornecedor não muda numa linha existente
                                isSearchable placeholder="Escolhe o fornecedor…"
                            />
                        </Col>
                        <Col md={6}>
                            <Label className="form-label">Tabela</Label>
                            {tablesForSupplier.length > 1 ? (
                                <Select
                                    styles={reactSelectTheme} menuPortalTarget={document.body}
                                    options={tablesForSupplier.map((t) => ({ value: t.table_id, label: t.table_name }))}
                                    value={selectedTable ? { value: selectedTable.table_id, label: selectedTable.table_name } : null}
                                    onChange={(o: any) => setF({ tableId: o?.value ?? null })}
                                    isDisabled={isEditing}
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
                            <Select styles={reactSelectTheme} menuPortalTarget={document.body}
                                options={unitOptions} value={unitOptions.find((u) => u.value === form.unitId) ?? null}
                                onChange={(o: any) => setF({ unitId: o?.value ?? "" })} isSearchable placeholder="Unidade…" />
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
