import { useEffect, useMemo, useState } from "react";
import {
    Row, Col, Label, Input, Button, Modal, ModalHeader, ModalBody, ModalFooter, Table, Alert,
    UncontrolledDropdown, DropdownToggle, DropdownMenu,
} from "reactstrap";
import Select from "react-select";
import { toast } from "react-toastify";
import { reactSelectTheme } from "../../helpers/reactSelectStyles";
import { getPingwinSuppliers } from "helpers/laravel_helper";
import { SupplierPricesStaging, LineDraft, SupplierLine } from "./useSupplierPricesStaging";

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

    // Colunas escondíveis (default off). Não persiste — só memória; refresh volta ao default.
    const [cols, setCols] = useState({ start: false, end: false, currency: false, discount1: false, discount2: false });
    const toggleCol = (k: keyof typeof cols) => setCols((p) => ({ ...p, [k]: !p[k] }));
    const COL_LABELS: { k: keyof typeof cols; label: string }[] = [
        { k: "start", label: "Data início" }, { k: "end", label: "Data fim" }, { k: "currency", label: "Moeda" },
        { k: "discount1", label: "Desconto 1 (%)" }, { k: "discount2", label: "Desc. mult. (%)" },
    ];
    // 7 default + as escondidas ligadas + 1 (ações) → colspan do estado vazio.
    const visibleCount = 7 + Object.values(cols).filter(Boolean).length + 1;

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
                    <UncontrolledDropdown>
                        <DropdownToggle color="light" size="sm" caret><i className="ri-layout-column-line me-1" />Colunas</DropdownToggle>
                        <DropdownMenu end className="p-2" style={{ minWidth: 200 }}>
                            <div className="text-muted fs-11 text-uppercase mb-1 px-1">Colunas opcionais</div>
                            {COL_LABELS.map(({ k, label }) => (
                                <div key={k} className="form-check px-1">
                                    <Input type="checkbox" className="form-check-input" id={`col-${k}`} checked={cols[k]} onChange={() => toggleCol(k)} />
                                    <Label className="form-check-label" for={`col-${k}`}>{label}</Label>
                                </div>
                            ))}
                        </DropdownMenu>
                    </UncontrolledDropdown>
                    <Button color="soft-primary" size="sm" onClick={openAdd}><i className="ri-add-line me-1" />Adicionar linha</Button>
                </div>
            </div>

            <div className="table-responsive">
                <Table className="align-middle table-sm mb-0">
                    <thead>
                        <tr className="text-muted fs-12 text-uppercase">
                            <th>Fornecedor</th><th>Tabela</th>
                            {cols.start && <th>Início</th>}
                            {cols.end && <th>Fim</th>}
                            {cols.currency && <th>Moeda</th>}
                            <th>Unidade</th><th>Descrição</th><th className="text-end">Preço</th>
                            {cols.discount1 && <th className="text-end">Desc.1</th>}
                            {cols.discount2 && <th className="text-end">Desc.mult.</th>}
                            <th>Código</th><th>Cód. barras</th><th></th>
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
                                    <td className={deleted ? "text-decoration-line-through" : ""}>{r.supplier.name ?? "—"}</td>
                                    <td>{r.table.name ?? "—"}</td>
                                    {cols.start && <td>{r.start_date ?? "—"}</td>}
                                    {cols.end && <td>{r.end_date ?? "—"}</td>}
                                    {cols.currency && <td>{r.currency ?? "—"}</td>}
                                    <td>{r.unit.name ?? "—"}</td>
                                    <td>{r.sup_product_description ?? "—"}</td>
                                    <td className="text-end">{r.price_cents != null ? `${centsToEur(r.price_cents)} €` : "—"}</td>
                                    {cols.discount1 && <td className="text-end">{r.discount1 ?? "—"}</td>}
                                    {cols.discount2 && <td className="text-end">{r.discount2_mul ?? "—"}</td>}
                                    <td>{r.sup_product_code ?? "—"}</td>
                                    <td>{r.sup_product_barcode ?? "—"}</td>
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
