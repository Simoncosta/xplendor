import { useCallback, useEffect, useMemo, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { Badge, Button, Col, Container, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Row, Spinner } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import XSelect from "Components/Common/Select";
import ReasonButton from "Components/Common/ReasonButton";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import { useCan } from "contexts/ModulesContext";
import { confirmAction } from "helpers/swal";
import { getWorkingCompanyId } from "helpers/workingCompany";
import { createProperty, deleteProperty, getDistricts, getMunicipalities, getParishes, getProperties, updateProperty } from "helpers/laravel_helper";
import type { Property, PropertyOptions } from "common/models/imoveisCompass.model";

/**
 * Imóveis (Bússola Master, R3): a carteira do consultor imobiliário, só com os campos que a
 * Bússola usa. Sem morada exata nem dados de proprietários, compradores ou inquilinos. A classe
 * energética é obrigatória em qualquer anúncio; o interesse são contagens manuais com a data.
 */

const today = () => new Date().toLocaleDateString("sv-SE", { timeZone: "Europe/Lisbon" });
const eur = (v: number, listing: string) => `${v.toLocaleString("pt-PT")} €${listing === "arrendamento" ? " por mês" : ""}`;
const day = (d: string | null) => (d ? new Date(`${d}T12:00:00`).toLocaleDateString("pt-PT") : "");
const errorText = (e: any, fallback: string) => (e?.errors ? (Object.values(e.errors).flat()[0] as string) : null) || e?.message || fallback;
const STATUS_COLOR: Record<string, string> = { disponivel: "success", reservado: "warning", fechado: "secondary" };

type Form = {
    reference: string; title: string; kind: string; typology: string; area_m2: string; district_id: number | null; municipality_id: number | null; parish_id: number | null;
    listing: string; price: string; energy_class: string; status: string; listed_at: string; closed_at: string; contacts_30: string; visits_30: string;
};
const EMPTY: Form = { reference: "", title: "", kind: "apartamento", typology: "T2", area_m2: "", district_id: null, municipality_id: null, parish_id: null,
    listing: "venda", price: "", energy_class: "", status: "disponivel", listed_at: today(), closed_at: "", contacts_30: "0", visits_30: "0" };

type Opt = { value: number; label: string };
const asOpts = (r: any): Opt[] => ((r?.data ?? r ?? []) as any[]).map((x) => ({ value: Number(x.id), label: String(x.name) }));

function PropertyModal({ companyId, editing, options, onClose, onSaved }: {
    companyId: number; editing: Property | "new" | null; options: PropertyOptions | null; onClose: () => void; onSaved: () => void;
}) {
    const [f, setF] = useState<Form>(EMPTY);
    const [districts, setDistricts] = useState<Opt[]>([]);
    const [municipalities, setMunicipalities] = useState<Opt[]>([]);
    const [parishes, setParishes] = useState<Opt[]>([]);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const set = (k: keyof Form, v: any) => setF((x) => ({ ...x, [k]: v }));

    useEffect(() => {
        if (!editing) return;
        setError(null);
        setF(editing === "new" ? EMPTY : {
            reference: editing.reference, title: editing.title, kind: editing.kind, typology: editing.typology ?? "", area_m2: editing.area_m2 ? String(editing.area_m2) : "",
            district_id: editing.district_id, municipality_id: editing.municipality_id, parish_id: editing.parish_id, listing: editing.listing, price: String(editing.price),
            energy_class: editing.energy_class ?? "", status: editing.status, listed_at: editing.listed_at ?? today(), closed_at: editing.closed_at ?? "",
            contacts_30: String(editing.contacts_30), visits_30: String(editing.visits_30),
        });
        getDistricts().then((r: any) => setDistricts(asOpts(r))).catch(() => setDistricts([]));
    }, [editing]);
    useEffect(() => { if (f.district_id) getMunicipalities(f.district_id).then((r: any) => setMunicipalities(asOpts(r))).catch(() => setMunicipalities([])); else setMunicipalities([]); }, [f.district_id]);
    useEffect(() => { if (f.municipality_id) getParishes(f.municipality_id).then((r: any) => setParishes(asOpts(r))).catch(() => setParishes([])); else setParishes([]); }, [f.municipality_id]);

    const invalid = !f.reference.trim() ? "Indique a referência." : !f.title.trim() ? "Indique o título." : !f.price ? "Indique o preço."
        : f.status === "fechado" && !f.closed_at ? "Indique a data de fecho." : null;
    const save = async () => {
        if (!editing || invalid) return;
        setSaving(true);
        setError(null);
        const body = {
            reference: f.reference.trim(), title: f.title.trim(), kind: f.kind, typology: f.typology || null, area_m2: f.area_m2 ? Number(f.area_m2) : null,
            parish_id: f.parish_id, listing: f.listing, price: Number(f.price), energy_class: f.energy_class || null, status: f.status,
            listed_at: f.listed_at, closed_at: f.status === "fechado" ? f.closed_at : null, contacts_30: Number(f.contacts_30 || 0), visits_30: Number(f.visits_30 || 0),
        };
        try {
            const res: any = editing === "new" ? await createProperty(companyId, body) : await updateProperty(companyId, editing.id, body);
            toast.success(res?.message ?? "Imóvel guardado.");
            onSaved();
        } catch (e: any) {
            setError(errorText(e, "Não foi possível guardar o imóvel."));
        } finally {
            setSaving(false);
        }
    };
    const o = options;
    return (
        <Modal isOpen={!!editing} toggle={onClose} size="lg" centered scrollable data-testid="property-modal">
            <ModalHeader toggle={onClose}>{editing === "new" ? "Novo imóvel" : "Editar imóvel"}</ModalHeader>
            <ModalBody>
                <p className="text-muted fs-13">Só o que a Bússola usa. Sem morada nem dados de proprietários, compradores ou inquilinos.</p>
                <Row className="g-3">
                    <Col sm={4}><Label for="p-ref">Referência</Label><Input id="p-ref" maxLength={40} value={f.reference} onChange={(e) => set("reference", e.target.value)} /></Col>
                    <Col sm={8}><Label for="p-title">Título</Label><Input id="p-title" maxLength={120} value={f.title} onChange={(e) => set("title", e.target.value)} /></Col>
                    <Col sm={4}><Label for="p-kind">Tipo</Label><Input id="p-kind" type="select" value={f.kind} onChange={(e) => set("kind", e.target.value)}>{o && Object.entries(o.kinds).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</Input></Col>
                    <Col sm={4}><Label for="p-typ">Tipologia</Label><Input id="p-typ" type="select" value={f.typology} onChange={(e) => set("typology", e.target.value)}><option value="">Sem tipologia</option>{o?.typologies.map((t) => <option key={t}>{t}</option>)}</Input></Col>
                    <Col sm={4}><Label for="p-area">Área útil (m²)</Label><Input id="p-area" type="number" min={1} value={f.area_m2} onChange={(e) => set("area_m2", e.target.value)} /></Col>
                    <Col sm={4}><Label>Distrito</Label><XSelect<number> ariaLabel="Distrito" options={districts} value={f.district_id} searchable onChange={(v) => setF((x) => ({ ...x, district_id: v, municipality_id: null, parish_id: null }))} /></Col>
                    <Col sm={4}><Label>Concelho</Label><XSelect<number> ariaLabel="Concelho" options={municipalities} value={f.municipality_id} searchable disabled={!f.district_id} onChange={(v) => setF((x) => ({ ...x, municipality_id: v, parish_id: null }))} /></Col>
                    <Col sm={4}><Label>Freguesia</Label><XSelect<number> ariaLabel="Freguesia" options={parishes} value={f.parish_id} searchable disabled={!f.municipality_id} onChange={(v) => set("parish_id", v)} /></Col>
                    <Col sm={4}><Label for="p-listing">Negócio</Label><Input id="p-listing" type="select" value={f.listing} onChange={(e) => set("listing", e.target.value)}>{o && Object.entries(o.listings).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</Input></Col>
                    <Col sm={4}><Label for="p-price">{f.listing === "arrendamento" ? "Renda (€ por mês)" : "Preço (€)"}</Label><Input id="p-price" type="number" min={0} value={f.price} onChange={(e) => set("price", e.target.value)} /></Col>
                    <Col sm={4}><Label for="p-energy">Classe energética</Label>
                        <Input id="p-energy" type="select" value={f.energy_class} onChange={(e) => set("energy_class", e.target.value)}>
                            <option value="">Por preencher</option>{o?.energy_classes.map((c) => <option key={c} value={c}>{c === "em_processo" ? "Em processo" : c === "isento" ? "Isento" : c}</option>)}
                        </Input>
                        <small className="text-muted">Obrigatória em qualquer anúncio de venda ou arrendamento.</small>
                    </Col>
                    <Col sm={4}><Label for="p-status">Estado</Label><Input id="p-status" type="select" value={f.status} onChange={(e) => set("status", e.target.value)}>{o && Object.entries(o.statuses).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</Input></Col>
                    <Col sm={4}><Label for="p-listed">Entrada em carteira</Label><Input id="p-listed" type="date" max={today()} value={f.listed_at} onChange={(e) => set("listed_at", e.target.value)} /></Col>
                    {f.status === "fechado" && <Col sm={4}><Label for="p-closed">Data de fecho</Label><Input id="p-closed" type="date" max={today()} value={f.closed_at} onChange={(e) => set("closed_at", e.target.value)} /></Col>}
                    <Col sm={6}><Label for="p-contacts">Contactos recebidos nos últimos 30 dias</Label><Input id="p-contacts" type="number" min={0} value={f.contacts_30} onChange={(e) => set("contacts_30", e.target.value)} /></Col>
                    <Col sm={6}><Label for="p-visits">Visitas marcadas</Label><Input id="p-visits" type="number" min={0} value={f.visits_30} onChange={(e) => set("visits_30", e.target.value)} />
                        {editing && editing !== "new" && editing.interest_updated_at && <small className="text-muted">Contagens atualizadas a {day(editing.interest_updated_at)} (à mão).</small>}</Col>
                </Row>
                {error && <div className="alert alert-warning fs-13 mt-3 mb-0" role="alert">{error}</div>}
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onClose}>Cancelar</Button>
                <ReasonButton color="primary" reason={invalid} disabled={saving} onClick={save} data-testid="property-save">{saving ? <Spinner size="sm" /> : "Guardar"}</ReasonButton>
            </ModalFooter>
        </Modal>
    );
}

export default function ImoveisPage() {
    document.title = "Imóveis | Xplendor";
    const companyId = getWorkingCompanyId();
    const [params] = useSearchParams();
    const [rows, setRows] = useState<Property[]>([]);
    const [options, setOptions] = useState<PropertyOptions | null>(null);
    const [loading, setLoading] = useState(true);
    const [status, setStatus] = useState<string>("");
    const [editing, setEditing] = useState<Property | "new" | null>(null);
    const [canCreate, createReason] = useCan("imoveis.criar");
    const [canEdit] = useCan("imoveis.editar");
    const [canDelete] = useCan("imoveis.apagar");

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const r: any = await getProperties(companyId, status ? { status } : {});
            setRows(r?.data?.properties ?? []);
            setOptions(r?.data?.options ?? null);
        } catch (e: any) {
            toast.error(errorText(e, "Não foi possível carregar os imóveis."));
        } finally {
            setLoading(false);
        }
    }, [companyId, status]);
    useEffect(() => { void load(); }, [load]);
    // "Ver imóvel" a partir da Bússola: abre a ficha (o formulário) desse imóvel.
    useEffect(() => {
        const id = Number(params.get("id"));
        if (id && rows.length) { const p = rows.find((r) => r.id === id); if (p) setEditing(p); }
    }, [params, rows]);

    const remove = async (p: Property) => {
        if (!(await confirmAction({ title: `Apagar o imóvel ${p.reference}?`, text: "Sai da carteira e da Bússola.", confirmText: "Apagar", icon: "warning", confirmVariant: "danger" }))) return;
        try {
            await deleteProperty(companyId, p.id);
            toast.success("Imóvel apagado.");
            void load();
        } catch (e: any) {
            toast.error(errorText(e, "Não foi possível apagar o imóvel."));
        }
    };

    const cols = useDataColumns<Property>("imoveis.carteira", [
        { id: "reference", header: "Referência", value: (p) => p.reference, hideable: false, mobile: "subtitle", nowrap: true },
        { id: "title", header: "Imóvel", value: (p) => p.title, mobile: "title", cell: (p) => <span className="text-wrap d-inline-block" style={{ maxWidth: 280 }}>{p.title}<div className="text-muted fs-12">{p.kind_label}{p.typology ? ` ${p.typology}` : ""}{p.area_m2 ? ` · ${p.area_m2} m²` : ""}</div></span> },
        { id: "parish", header: "Freguesia", value: (p) => p.parish ?? "", cell: (p) => p.parish ? <>{p.parish}<div className="text-muted fs-12">{p.municipality}</div></> : <span className="text-muted">Por indicar</span> },
        { id: "price", header: "Preço", align: "end", value: (p) => p.price, cell: (p) => <>{eur(p.price, p.listing)}{p.ine && p.listing === "venda" ? <div className="text-muted fs-12">INE: {p.ine.median_m2.toLocaleString("pt-PT")} €/m² ({p.ine.period})</div> : null}</>, nowrap: true },
        { id: "energy", header: "Classe energética", value: (p) => p.energy_class ?? "", cell: (p) => p.energy_class_missing
            ? <Badge color="warning-subtle" className="text-warning fw-normal">{p.energy_class === "em_processo" ? "Em processo" : "Em falta"}</Badge> : (p.energy_class === "isento" ? "Isento" : p.energy_class) },
        { id: "status", header: "Estado", value: (p) => p.status, cell: (p) => <Badge color={`${STATUS_COLOR[p.status]}-subtle`} className={`text-${STATUS_COLOR[p.status]} fw-normal`}>{options?.statuses[p.status] ?? p.status}</Badge> },
        { id: "days", header: "Dias em carteira", align: "end", value: (p) => p.days ?? 0 },
        { id: "interest", header: "Interesse (manual)", value: (p) => p.contacts_30, cell: (p) => <>{p.contacts_30} contactos · {p.visits_30} visitas{p.interest_updated_at ? <div className="text-muted fs-12">a {day(p.interest_updated_at)}</div> : null}</> },
        { id: "actions", header: "", hideable: false, cell: (p) => (
            <div className="d-flex gap-1 justify-content-end">
                {canEdit && <Button size="sm" color="secondary" outline onClick={() => setEditing(p)} aria-label={`Editar ${p.reference}`}><i className="ri-pencil-line" /></Button>}
                {canDelete && <Button size="sm" color="danger" outline onClick={() => void remove(p)} aria-label={`Apagar ${p.reference}`}><i className="ri-delete-bin-line" /></Button>}
            </div>
        ) },
    ] as DTColumn<Property>[]);
    const statusOptions = useMemo(() => [{ value: "", label: "Todos os estados" }, ...Object.entries(options?.statuses ?? {}).map(([k, l]) => ({ value: k, label: l }))], [options]);

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Imóveis" breadcrumbs={[{ label: "Imobiliário" }]}
                    info="A carteira de imóveis que a Bússola do imobiliário usa: os dias em carteira, a referência oficial do INE da freguesia e as contagens manuais de contactos e visitas. Sem morada nem dados de pessoas." />
                <PageCard title="Carteira" data-testid="imoveis-page"
                    actions={<>
                        <div style={{ minWidth: 180 }}><XSelect<string> small ariaLabel="Estado" options={statusOptions} value={status} onChange={setStatus} /></div>
                        <ReasonButton size="sm" color="primary" reason={canCreate ? null : createReason} onClick={() => setEditing("new")} data-testid="property-new"><i className="ri-add-line me-1" />Novo imóvel</ReasonButton>
                    </>}>
                    <DataTable columns={cols} data={rows} rowKey={(p) => p.id} loading={loading} caption="Imóveis" empty={{ message: "Ainda não há imóveis na carteira." }} />
                </PageCard>
            </Container>
            <PropertyModal companyId={companyId} editing={editing} options={options} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); void load(); }} />
        </div>
    );
}
