import { useCallback, useEffect, useState } from "react";
import { Badge, Button, Col, Container, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Nav, NavItem, NavLink, Row, Spinner } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import XSelect from "Components/Common/Select";
import ReasonButton from "Components/Common/ReasonButton";
import ActionsMenu from "Components/Common/ActionsMenu";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import { useCan } from "contexts/ModulesContext";
import { confirmAction } from "helpers/swal";
import { getWorkingCompanyId } from "helpers/workingCompany";
import {
    createDevelopment, deleteDevelopment, getDevelopment, getDevelopments, getDistricts, getListingsSummary, getMunicipalities, getParishes,
    publishDevelopment, unpublishDevelopment, updateDevelopment,
} from "helpers/laravel_helper";
import type { Development, DevelopmentOptions, ListingsSummary } from "common/models/consultantSite.model";
import ListingPhotos from "./ListingPhotos";
import SitePublishPanel, { focusField } from "./SitePublishPanel";

/**
 * Site do consultor: os empreendimentos em construção. As frações são imóveis da carteira ligados
 * ao empreendimento (na ficha do site de cada imóvel); o "preço desde" calcula-se das frações
 * publicadas e disponíveis. A localização é exata (é uma obra, não a casa de alguém).
 */

const errorText = (e: any, fallback: string) => (e?.errors ? (Object.values(e.errors).flat()[0] as string) : null) || e?.message || fallback;
const eur = (v: number) => `${v.toLocaleString("pt-PT")} €`;
type Opt = { value: number; label: string };
const asOpts = (r: any): Opt[] => ((r?.data ?? r ?? []) as any[]).map((x) => ({ value: Number(x.id), label: String(x.name) }));
const ENERGY = (c: string) => (c === "em_processo" ? "Em processo" : c === "isento" ? "Isento" : c);

type Form = {
    name: string; slug: string; short_description: string; description: string; district_id: number | null; municipality_id: number | null; parish_id: number | null;
    lat: string; lng: string; construction_phase: string; expected_completion: string; payment_plan: string; youtube: string; chave_nova_url: string;
    chave_nova_reference: string; energy_class_expected: string; featured: boolean;
};
const EMPTY: Form = { name: "", slug: "", short_description: "", description: "", district_id: null, municipality_id: null, parish_id: null, lat: "", lng: "",
    construction_phase: "projeto", expected_completion: "", payment_plan: "", youtube: "", chave_nova_url: "", chave_nova_reference: "", energy_class_expected: "", featured: false };
const toForm = (d: Development): Form => ({
    name: d.name, slug: d.slug, short_description: d.short_description ?? "", description: d.description ?? "", district_id: d.district_id, municipality_id: d.municipality_id,
    parish_id: d.parish_id, lat: d.lat?.toString() ?? "", lng: d.lng?.toString() ?? "", construction_phase: d.construction_phase, expected_completion: d.expected_completion ?? "",
    payment_plan: d.payment_plan ?? "", youtube: d.youtube_id ? `https://youtu.be/${d.youtube_id}` : "", chave_nova_url: d.chave_nova_url ?? "",
    chave_nova_reference: d.chave_nova_reference ?? "", energy_class_expected: d.energy_class_expected ?? "", featured: d.featured,
});
const num = (v: string) => (v.trim() === "" ? null : Number(v.replace(",", ".")));
type Tab = "data" | "photos" | "site" | "units";
/** O campo em falta → o separador e o elemento a focar. */
const FIELD_TARGET: Record<string, [Tab, string]> = {
    parish_id: ["data", "d-parish-box"], lat: ["data", "d-lat"], energy_class_expected: ["data", "d-energy-box"], photos: ["photos", "photos-box"],
};

function DevelopmentModal({ companyId, editing, options, canEdit, editReason, onClose, onSaved }: {
    companyId: number; editing: number | "new" | null; options: DevelopmentOptions | null; canEdit: boolean; editReason: string | null;
    onClose: () => void; onSaved: () => void;
}) {
    const [dev, setDev] = useState<Development | null>(null);
    const [f, setF] = useState<Form>(EMPTY);
    const [tab, setTab] = useState<Tab>("data");
    const [districts, setDistricts] = useState<Opt[]>([]);
    const [municipalities, setMunicipalities] = useState<Opt[]>([]);
    const [parishes, setParishes] = useState<Opt[]>([]);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const set = (k: keyof Form, v: any) => setF((x) => ({ ...x, [k]: v }));

    useEffect(() => {
        if (!editing) return;
        setError(null);
        setTab("data");
        getDistricts().then((r: any) => setDistricts(asOpts(r))).catch(() => setDistricts([]));
        if (editing === "new") { setDev(null); setF(EMPTY); return; }
        getDevelopment(companyId, editing).then((r: any) => { setDev(r.data); setF(toForm(r.data)); }).catch((e: any) => setError(errorText(e, "Não foi possível carregar o empreendimento.")));
    }, [companyId, editing]);
    useEffect(() => { if (f.district_id) getMunicipalities(f.district_id).then((r: any) => setMunicipalities(asOpts(r))).catch(() => setMunicipalities([])); else setMunicipalities([]); }, [f.district_id]);
    useEffect(() => { if (f.municipality_id) getParishes(f.municipality_id).then((r: any) => setParishes(asOpts(r))).catch(() => setParishes([])); else setParishes([]); }, [f.municipality_id]);

    const invalid = !f.name.trim() ? "Indique o nome." : null;
    const save = async (): Promise<Development | null> => {
        if (!editing || invalid) return null;
        setSaving(true);
        setError(null);
        const body = {
            name: f.name.trim(), slug: f.slug.trim() || null, short_description: f.short_description.trim() || null, description: f.description.trim() || null,
            parish_id: f.parish_id, lat: num(f.lat), lng: num(f.lng), construction_phase: f.construction_phase, expected_completion: f.expected_completion || null,
            payment_plan: f.payment_plan.trim() || null, youtube: f.youtube.trim() || null, chave_nova_url: f.chave_nova_url.trim() || null,
            chave_nova_reference: f.chave_nova_reference.trim() || null, energy_class_expected: f.energy_class_expected || null, featured: f.featured,
        };
        try {
            const id = dev?.id ?? (editing === "new" ? null : editing); // depois de criar, as gravações seguintes atualizam
            const r: any = id ? await updateDevelopment(companyId, id, body) : await createDevelopment(companyId, body);
            setDev(r.data);
            setF(toForm(r.data));
            onSaved();
            return r.data;
        } catch (e: any) {
            setError(errorText(e, "Não foi possível guardar o empreendimento."));
            return null;
        } finally {
            setSaving(false);
        }
    };
    const publish = async () => {
        const saved = await save();
        if (!saved) return;
        try {
            const r: any = await publishDevelopment(companyId, saved.id);
            setDev(r.data);
            toast.success(r?.message ?? "Empreendimento publicado no site.");
            onSaved();
        } catch (e: any) {
            setTab("site");
            await reload(saved.id);
            setError(((e?.errors?.publish as string[]) ?? []).join(" ") || errorText(e, "Ainda não pode publicar este empreendimento."));
        }
    };
    const go = (field: string) => {
        const [t, el] = FIELD_TARGET[field] ?? ["site", ""];
        setTab(t);
        if (el) focusField(el);
    };
    const reload = async (id: number) => {
        try { const r: any = await getDevelopment(companyId, id); setDev(r.data); } catch { /* fica o que está */ }
    };
    const unpublish = async () => {
        if (!dev || !(await confirmAction({ title: "Retirar o empreendimento do site?", text: "Deixa de aparecer no site. As frações continuam como estão.", confirmText: "Retirar", icon: "warning", confirmVariant: "danger" }))) return;
        try {
            const r: any = await unpublishDevelopment(companyId, dev.id);
            setDev(r.data);
            toast.success(r?.message ?? "Empreendimento retirado do site.");
            onSaved();
        } catch (e: any) {
            toast.error(errorText(e, "Não foi possível retirar o empreendimento."));
        }
    };

    const published = dev?.site_status === "publicado";
    const phaseOptions = Object.entries(options?.phases ?? {}).map(([value, label]) => ({ value, label }));
    const energyOptions = [{ value: "", label: "Por indicar" }, ...(options?.energy_classes ?? []).map((c) => ({ value: c, label: ENERGY(c) }))];
    const checklist = dev?.publish_checklist ?? (dev?.publish_missing ?? []).map((m, i) => ({ field: `item-${i}`, label: m, message: m }));
    const tabLink = (t: Tab, label: React.ReactNode, testId: string) => (
        <NavItem><NavLink href="#" active={tab === t} onClick={(e) => { e.preventDefault(); setTab(t); }} data-testid={testId}>{label}</NavLink></NavItem>
    );
    return (
        <Modal isOpen={!!editing} toggle={onClose} size="xl" centered scrollable data-testid="development-modal">
            <ModalHeader toggle={onClose}>{editing === "new" ? "Novo empreendimento" : dev?.name ?? "Empreendimento"}{" "}
                {dev && <Badge color={published ? "success-subtle" : "secondary-subtle"} className={`${published ? "text-success" : "text-secondary"} fw-normal ms-1`}>{published ? "Publicado" : "Rascunho"}</Badge>}
            </ModalHeader>
            <ModalBody>
                <Nav tabs className="nav-tabs-custom mb-3">
                    {tabLink("data", "Dados", "dev-tab-data")}
                    {dev ? <>
                        {tabLink("photos", `Fotografias (${dev.images?.length ?? 0})`, "dev-tab-photos")}
                        {tabLink("site", <>Site {checklist.length > 0 && !published ? <Badge color="warning" pill className="ms-1">{checklist.length}</Badge> : null}</>, "dev-tab-site")}
                        {tabLink("units", `Frações (${dev.units?.length ?? 0})`, "dev-tab-units")}
                    </> : <NavItem><span className="nav-link text-muted fs-13">Fotografias, Site e Frações: guarde primeiro os dados.</span></NavItem>}
                </Nav>
                {tab === "photos" && dev ? (
                    <div id="photos-box">
                        <ListingPhotos companyId={companyId} owner="developments" ownerId={dev.id} images={dev.images ?? []} canEdit={canEdit} editReason={editReason}
                            onChange={() => { void reload(dev.id); onSaved(); }} />
                    </div>
                ) : tab === "site" && dev ? (<>
                    <SitePublishPanel published={published} items={checklist} onGo={go} />
                    <Row className="g-3">
                        <Col md={6}><Label for="d-slug">Endereço no site</Label><Input id="d-slug" maxLength={160} value={f.slug} disabled={!canEdit} placeholder="cria-se a partir do nome" onChange={(e) => set("slug", e.target.value.toLowerCase())} /></Col>
                        <Col md={6} className="d-flex align-items-end">
                            <div className="form-check form-switch">
                                <Input id="d-feat" type="checkbox" className="form-check-input" checked={f.featured} disabled={!canEdit} onChange={(e) => set("featured", e.target.checked)} />
                                <Label className="form-check-label" for="d-feat">Destaque na página inicial</Label>
                            </div>
                        </Col>
                        <Col md={12}><Label for="d-short">Descrição curta</Label><Input id="d-short" maxLength={280} value={f.short_description} disabled={!canEdit} onChange={(e) => set("short_description", e.target.value)} /></Col>
                        <Col md={12}><Label for="d-desc">Descrição</Label><Input id="d-desc" type="textarea" rows={5} maxLength={8000} value={f.description} disabled={!canEdit} onChange={(e) => set("description", e.target.value)} /></Col>
                        <Col md={6}><Label for="d-yt">Vídeo do YouTube</Label><Input id="d-yt" maxLength={255} value={f.youtube} disabled={!canEdit} placeholder="link de um vídeo ou de um Short" onChange={(e) => set("youtube", e.target.value)} /></Col>
                        <Col md={4}><Label for="d-cn">Página na Chave Nova</Label><Input id="d-cn" type="url" maxLength={255} value={f.chave_nova_url} disabled={!canEdit} placeholder="https://www.chavenova.com/…" onChange={(e) => set("chave_nova_url", e.target.value)} /></Col>
                        <Col md={2}><Label for="d-cnr">Referência</Label><Input id="d-cnr" maxLength={40} value={f.chave_nova_reference} disabled={!canEdit} onChange={(e) => set("chave_nova_reference", e.target.value)} /></Col>
                    </Row>
                </>) : tab === "units" && dev ? (
                    <div>
                        <p className="text-muted fs-13">As frações são imóveis da carteira. Para ligar um imóvel a este empreendimento, abra o imóvel em Imóveis e escolha o empreendimento no separador Site.</p>
                        {(dev.units ?? []).length === 0 ? <p className="text-muted">Ainda sem frações.</p> : (
                            <ul className="list-group">{(dev.units ?? []).map((u) => (
                                <li key={u.id} className="list-group-item d-flex justify-content-between flex-wrap gap-2">
                                    <span><strong>{u.reference}</strong> {u.title}{u.typology ? ` · ${u.typology}` : ""}</span>
                                    <span className="text-muted">{u.price_on_request ? "Preço sob consulta" : eur(u.price)} · {u.site_status === "publicado" ? "publicado" : "rascunho"}</span>
                                </li>
                            ))}</ul>
                        )}
                    </div>
                ) : (
                    <Row className="g-3">
                        <Col md={12}><Label for="d-name">Nome</Label><Input id="d-name" maxLength={120} value={f.name} disabled={!canEdit} onChange={(e) => set("name", e.target.value)} /></Col>
                        <Col md={4}><Label>Fase da obra</Label><XSelect<string> ariaLabel="Fase da obra" options={phaseOptions} value={f.construction_phase} disabled={!canEdit} onChange={(v) => set("construction_phase", v)} /></Col>
                        <Col md={4}><Label for="d-end">Conclusão prevista</Label><Input id="d-end" type="month" value={f.expected_completion} disabled={!canEdit} onChange={(e) => set("expected_completion", e.target.value)} /></Col>
                        <Col md={4} id="d-energy-box"><Label>Classe energética prevista</Label><XSelect<string> ariaLabel="Classe energética prevista" options={energyOptions} value={f.energy_class_expected} disabled={!canEdit} onChange={(v) => set("energy_class_expected", v)} /></Col>
                        <Col md={4}><Label>Distrito</Label><XSelect<number> ariaLabel="Distrito" options={districts} value={f.district_id} searchable disabled={!canEdit} onChange={(v) => setF((x) => ({ ...x, district_id: v, municipality_id: null, parish_id: null }))} /></Col>
                        <Col md={4}><Label>Concelho</Label><XSelect<number> ariaLabel="Concelho" options={municipalities} value={f.municipality_id} searchable disabled={!canEdit || !f.district_id} onChange={(v) => setF((x) => ({ ...x, municipality_id: v, parish_id: null }))} /></Col>
                        <Col md={4} id="d-parish-box"><Label>Freguesia</Label><XSelect<number> ariaLabel="Freguesia" options={parishes} value={f.parish_id} searchable disabled={!canEdit || !f.municipality_id} onChange={(v) => set("parish_id", v)} /></Col>
                        <Col md={6}><Label for="d-lat">Latitude</Label><Input id="d-lat" inputMode="decimal" value={f.lat} disabled={!canEdit} onChange={(e) => set("lat", e.target.value)} /></Col>
                        <Col md={6}><Label for="d-lng">Longitude</Label><Input id="d-lng" inputMode="decimal" value={f.lng} disabled={!canEdit} onChange={(e) => set("lng", e.target.value)} />
                            <small className="text-muted">A localização da obra aparece exata no site.</small></Col>
                        <Col md={12}><Label for="d-plan">Plano de pagamentos</Label><Input id="d-plan" type="textarea" rows={3} maxLength={4000} value={f.payment_plan} disabled={!canEdit} onChange={(e) => set("payment_plan", e.target.value)} /></Col>
                    </Row>
                )}
                {error && <div className="alert alert-warning fs-13 mt-3 mb-0" role="alert">{error}</div>}
            </ModalBody>
            <ModalFooter>
                {published && canEdit && <ActionsMenu label="Mais ações: site do empreendimento" items={[{ label: "Retirar do site", icon: "ri-eye-off-line", danger: true, onClick: () => void unpublish() }]} />}
                <Button color="light" onClick={onClose}>Fechar</Button>
                {tab !== "units" && tab !== "photos" && <ReasonButton color={dev && !published ? "outline-primary" : "primary"} reason={!canEdit ? editReason : invalid} disabled={saving}
                    onClick={async () => { if (await save()) toast.success("Empreendimento guardado."); }} data-testid="development-save">{saving ? <Spinner size="sm" /> : "Guardar"}</ReasonButton>}
                {dev && !published && <ReasonButton color="primary" reason={!canEdit ? editReason : invalid} disabled={saving} onClick={() => void publish()}
                    data-testid="development-publish">Gravar e publicar</ReasonButton>}
            </ModalFooter>
        </Modal>
    );
}

export default function EmpreendimentosPage() {
    document.title = "Empreendimentos | Xplendor";
    const companyId = getWorkingCompanyId();
    const [rows, setRows] = useState<Development[]>([]);
    const [options, setOptions] = useState<DevelopmentOptions | null>(null);
    const [summary, setSummary] = useState<ListingsSummary | null>(null);
    const [loading, setLoading] = useState(true);
    const [editing, setEditing] = useState<number | "new" | null>(null);
    const [canCreate, createReason] = useCan("imoveis.criar");
    const [canEdit, editReason] = useCan("imoveis.editar");
    const [canDelete] = useCan("imoveis.apagar");

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const r: any = await getDevelopments(companyId);
            setRows(r?.data?.developments ?? []);
            setOptions(r?.data?.options ?? null);
            getListingsSummary(companyId).then((x: any) => setSummary(x?.data ?? null)).catch(() => setSummary(null));
        } catch (e: any) {
            toast.error(errorText(e, "Não foi possível carregar os empreendimentos."));
        } finally {
            setLoading(false);
        }
    }, [companyId]);
    useEffect(() => { void load(); }, [load]);

    const remove = async (d: Development) => {
        if (!(await confirmAction({ title: `Apagar o empreendimento ${d.name}?`, text: "Sai do site. As frações ficam na carteira, sem empreendimento.", confirmText: "Apagar", icon: "warning", confirmVariant: "danger" }))) return;
        try {
            await deleteDevelopment(companyId, d.id);
            toast.success("Empreendimento apagado.");
            void load();
        } catch (e: any) {
            toast.error(errorText(e, "Não foi possível apagar o empreendimento."));
        }
    };

    const cols = useDataColumns<Development>("imoveis.empreendimentos", [
        { id: "name", header: "Empreendimento", value: (d) => d.name, mobile: "title", hideable: false },
        { id: "parish", header: "Freguesia", value: (d) => d.parish ?? "", cell: (d) => d.parish ? <>{d.parish}<div className="text-muted fs-12">{d.municipality}</div></> : <span className="text-muted">Por indicar</span> },
        { id: "phase", header: "Fase da obra", value: (d) => d.construction_phase_label, cell: (d) => <>{d.construction_phase_label}{d.expected_completion ? <div className="text-muted fs-12">conclusão prevista {d.expected_completion.split("-").reverse().join("/")}</div> : null}</> },
        { id: "units", header: "Frações", align: "end", value: (d) => d.units_count },
        { id: "site", header: "Site", value: (d) => summary?.developments[d.id]?.views_30 ?? 0, cell: (d) => {
            const on = d.site_status === "publicado";
            return <><Badge color={on ? "success-subtle" : "secondary-subtle"} className={`${on ? "text-success" : "text-secondary"} fw-normal`}>{on ? "Publicado" : "Rascunho"}</Badge>
                <div className="text-muted fs-12">{summary?.developments[d.id]?.views_30 ?? 0} visualizações em 30 dias</div></>;
        } },
        { id: "actions", header: "", hideable: false, cell: (d) => (
            <div className="d-flex gap-1 justify-content-end">
                <Button size="sm" color="secondary" outline onClick={() => setEditing(d.id)} aria-label={`Abrir ${d.name}`}><i className="ri-pencil-line" /></Button>
                {canDelete && <ActionsMenu size="sm" label={`Mais ações: ${d.name}`} items={[{ label: "Apagar", icon: "ri-delete-bin-line", danger: true, onClick: () => void remove(d) }]} />}
            </div>
        ) },
    ] as DTColumn<Development>[]);

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Empreendimentos" breadcrumbs={[{ label: "Imobiliário" }]}
                    info="Os empreendimentos em construção do site. As frações são imóveis da carteira ligados ao empreendimento; o preço desde calcula-se das frações disponíveis." />
                <PageCard title="Empreendimentos" data-testid="developments-page"
                    actions={<>{cols.selector}<ReasonButton size="sm" color="primary" reason={canCreate ? null : createReason} onClick={() => setEditing("new")} data-testid="development-new"><i className="ri-add-line me-1" />Novo empreendimento</ReasonButton></>}>
                    <DataTable columns={cols} data={rows} rowKey={(d) => d.id} loading={loading} caption="Empreendimentos" empty={{ message: "Ainda não há empreendimentos." }} />
                </PageCard>
            </Container>
            <DevelopmentModal companyId={companyId} editing={editing} options={options} canEdit={canEdit} editReason={editReason}
                onClose={() => setEditing(null)} onSaved={() => void load()} />
        </div>
    );
}
