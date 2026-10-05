import React, { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Badge, Card, CardBody, Col, Container, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Row, Spinner } from "reactstrap";
import { ToastContainer, toast } from "react-toastify";
import { createServiceCatalogItem, getServiceCatalog, updateServiceCatalogItem } from "helpers/laravel_helper";
import { BILLING_LABEL, ICatalogItem, QuoteBilling, QuoteUnit, UNIT_LABEL, formatQuoteEuro } from "common/models/quote.model";

/**
 * XPLENDOR — Catálogo de serviços (tabela padrão dos orçamentos). Só a equipa.
 * Preços sem IVA. Os serviços não se apagam: desativam-se (os orçamentos já feitos
 * guardam a sua própria cópia de cada linha).
 */
type Draft = { id?: number; name: string; description: string; unit_price: string; unit: QuoteUnit; billing_type: QuoteBilling; active: boolean };
const EMPTY: Draft = { name: "", description: "", unit_price: "", unit: "month", billing_type: "monthly", active: true };

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

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <Row className="mb-3 align-items-center g-2">
                    <Col>
                        <Link to="/admin/quotes" className="text-muted fs-13"><i className="ri-arrow-left-line me-1" />Orçamentos</Link>
                        <h4 className="mb-1 mt-1"><i className="ri-price-tag-3-line text-primary me-2" />Catálogo de serviços</h4>
                        <p className="text-muted mb-0">Preços sugeridos nos orçamentos (editáveis em cada orçamento). Valores sem IVA.</p>
                    </Col>
                    <Col xs="auto">
                        <button className="btn btn-primary btn-sm" onClick={() => setEditing({ ...EMPTY })}><i className="ri-add-line me-1" />Novo serviço</button>
                    </Col>
                </Row>

                <Card>
                    <CardBody>
                        {loading ? (
                            <div className="d-flex align-items-center gap-2 text-muted"><Spinner size="sm" /> A carregar…</div>
                        ) : items.length === 0 ? (
                            <p className="text-muted mb-0">Ainda não há serviços no catálogo.</p>
                        ) : (
                            <div className="table-responsive">
                                <table className="table align-middle mb-0">
                                    <thead className="table-light text-muted">
                                        <tr><th>Serviço</th><th className="text-end">Preço</th><th>Cobrança</th><th>Estado</th><th /></tr>
                                    </thead>
                                    <tbody>
                                        {items.map((i) => (
                                            <tr key={i.id} className={i.active ? "" : "opacity-50"}>
                                                <td style={{ minWidth: 200 }}>
                                                    <div className="fw-medium">{i.name}</div>
                                                    {i.description && <small className="text-muted">{i.description}</small>}
                                                </td>
                                                <td className="text-end text-nowrap">{formatQuoteEuro(i.unit_price)} <small className="text-muted">{UNIT_LABEL[i.unit]}</small></td>
                                                <td><Badge color={i.billing_type === "monthly" ? "info" : "secondary"}>{BILLING_LABEL[i.billing_type]}</Badge></td>
                                                <td>
                                                    <div className="form-check form-switch mb-0">
                                                        <Input className="form-check-input" type="switch" checked={i.active} onChange={() => void toggle(i)} aria-label="Ativo" />
                                                        <small className="text-muted">{i.active ? "Ativo" : "Desativado"}</small>
                                                    </div>
                                                </td>
                                                <td className="text-end">
                                                    <button className="btn btn-soft-secondary btn-sm" onClick={() => setEditing({
                                                        id: i.id, name: i.name, description: i.description ?? "", unit_price: String(i.unit_price),
                                                        unit: i.unit, billing_type: i.billing_type, active: i.active,
                                                    })}><i className="ri-edit-line" /></button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardBody>
                </Card>

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
                                    <Label className="form-label">Unidade</Label>
                                    <Input type="select" value={editing.unit} onChange={(e) => setEditing({ ...editing, unit: e.target.value as QuoteUnit })}>
                                        {(Object.keys(UNIT_LABEL) as QuoteUnit[]).map((u) => <option key={u} value={u}>{UNIT_LABEL[u]}</option>)}
                                    </Input>
                                </Col>
                                <Col xs={12}>
                                    <Label className="form-label">Tipo de cobrança</Label>
                                    <Input type="select" value={editing.billing_type} onChange={(e) => setEditing({ ...editing, billing_type: e.target.value as QuoteBilling })}>
                                        <option value="monthly">Mensal</option>
                                        <option value="one_off">Valor único</option>
                                    </Input>
                                </Col>
                            </Row>
                        </ModalBody>
                    )}
                    <ModalFooter>
                        <button className="btn btn-light" onClick={() => setEditing(null)} disabled={saving}>Cancelar</button>
                        <button className="btn btn-primary" onClick={() => void save()} disabled={saving || !editing?.name.trim() || editing?.unit_price === ""}>
                            {saving ? <Spinner size="sm" /> : "Guardar"}
                        </button>
                    </ModalFooter>
                </Modal>
            </Container>
        </div>
    );
};

export default ServiceCatalogPage;
