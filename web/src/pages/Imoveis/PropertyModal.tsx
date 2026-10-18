import { useEffect, useState } from "react";
import { Badge, Button, Col, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Nav, NavItem, NavLink, Row, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import XSelect from "Components/Common/Select";
import ReasonButton from "Components/Common/ReasonButton";
import ActionsMenu from "Components/Common/ActionsMenu";
import { confirmAction } from "helpers/swal";
import {
    createProperty, getDistricts, getMunicipalities, getParishes, getPropertySite, publishProperty, unpublishProperty, updateProperty, updatePropertySite,
} from "helpers/laravel_helper";
import type { Property, PropertyOptions } from "common/models/imoveisCompass.model";
import type { PropertySite } from "common/models/consultantSite.model";
import ListingPhotos from "./ListingPhotos";
import SitePublishPanel, { focusField } from "./SitePublishPanel";

/**
 * Um só modal por imóvel, com separadores: Dados (o que a Bússola usa: sem morada nem dados de
 * proprietários, compradores ou inquilinos), Fotografias e Site (o estado, o que falta, com a
 * ligação a cada campo, e os campos do anúncio). Gravar e publicar sem sair do modal. Sem o módulo
 * do site, só os Dados. A classe energética é obrigatória em qualquer anúncio; a localização de
 * um usado no site é só o centro de um círculo (nunca a casa), com pelo menos 300 m de raio.
 */

const today = () => new Date().toLocaleDateString("sv-SE", { timeZone: "Europe/Lisbon" });
const day = (d: string | null) => (d ? new Date(`${d}T12:00:00`).toLocaleDateString("pt-PT") : "");
const errorText = (e: any, fallback: string) => (e?.errors ? (Object.values(e.errors).flat()[0] as string) : null) || e?.message || fallback;
/** 92.1 → "92,1" (como se escreve à mão); o servidor aceita "92,1" e "92.1". */
const areaText = (v: number | null | undefined) => (v ? String(v).replace(".", ",") : "");
const blank = (v: string) => (v.trim() === "" ? null : v.trim());
const num = (v: string) => (v.trim() === "" ? null : Number(v.replace(",", ".")));
type Opt = { value: number; label: string };
const asOpts = (r: any): Opt[] => ((r?.data ?? r ?? []) as any[]).map((x) => ({ value: Number(x.id), label: String(x.name) }));

export type Tab = "data" | "photos" | "site";

type DataForm = {
    reference: string; title: string; kind: string; typology: string; area_m2: string; district_id: number | null; municipality_id: number | null; parish_id: number | null;
    listing: string; price: string; energy_class: string; status: string; listed_at: string; closed_at: string; contacts_30: string; visits_30: string;
};
const EMPTY: DataForm = { reference: "", title: "", kind: "apartamento", typology: "T2", area_m2: "", district_id: null, municipality_id: null, parish_id: null,
    listing: "venda", price: "", energy_class: "", status: "disponivel", listed_at: today(), closed_at: "", contacts_30: "0", visits_30: "0" };
const toData = (p: Property): DataForm => ({
    reference: p.reference, title: p.title, kind: p.kind, typology: p.typology ?? "", area_m2: areaText(p.area_m2),
    district_id: p.district_id, municipality_id: p.municipality_id, parish_id: p.parish_id, listing: p.listing, price: String(p.price),
    energy_class: p.energy_class ?? "", status: p.status, listed_at: p.listed_at ?? today(), closed_at: p.closed_at ?? "",
    contacts_30: String(p.contacts_30_manual ?? p.contacts_30), visits_30: String(p.visits_30_manual ?? p.visits_30),
});

type SiteForm = {
    slug: string; development_id: number | 0; bedrooms: string; bathrooms: string; gross_area_m2: string; construction_state: string;
    approx_lat: string; approx_lng: string; approx_radius_m: string; price_on_request: boolean; energy_precertificate: boolean;
    short_description: string; description: string; youtube: string; chave_nova_url: string; chave_nova_reference: string; show_as_sold: boolean; featured: boolean;
};
const toSite = (p: PropertySite): SiteForm => ({
    slug: p.slug ?? "", development_id: p.development_id ?? 0, bedrooms: p.bedrooms?.toString() ?? "", bathrooms: p.bathrooms?.toString() ?? "",
    gross_area_m2: areaText(p.gross_area_m2), construction_state: p.construction_state ?? "", approx_lat: p.approx_lat?.toString() ?? "",
    approx_lng: p.approx_lng?.toString() ?? "", approx_radius_m: p.approx_radius_m?.toString() ?? "500", price_on_request: p.price_on_request,
    energy_precertificate: p.energy_precertificate, short_description: p.short_description ?? "", description: p.description ?? "",
    youtube: p.youtube_id ? `https://youtu.be/${p.youtube_id}` : "", chave_nova_url: p.chave_nova_url ?? "", chave_nova_reference: p.chave_nova_reference ?? "",
    show_as_sold: p.show_as_sold, featured: p.featured,
});

/** O campo em falta → o separador e o elemento a focar. */
const FIELD_TARGET: Record<string, [Tab, string]> = {
    energy_class: ["data", "p-energy"], parish_id: ["data", "p-parish-box"], price: ["data", "p-price"], photos: ["photos", "photos-box"], show_as_sold: ["site", "s-sold"],
};

export default function PropertyModal({ companyId, editing, initialTab = "data", options, siteOn, canEdit, editReason, onClose, onChanged }: {
    companyId: number; editing: Property | "new" | null; initialTab?: Tab; options: PropertyOptions | null; siteOn: boolean;
    canEdit: boolean; editReason: string | null; onClose: () => void; onChanged: () => void;
}) {
    const [id, setId] = useState<number | null>(null);
    const [current, setCurrent] = useState<Property | null>(null);
    const [d, setD] = useState<DataForm>(EMPTY);
    const [site, setSite] = useState<PropertySite | null>(null);
    const [s, setS] = useState<SiteForm | null>(null);
    const [tab, setTab] = useState<Tab>("data");
    const [districts, setDistricts] = useState<Opt[]>([]);
    const [municipalities, setMunicipalities] = useState<Opt[]>([]);
    const [parishes, setParishes] = useState<Opt[]>([]);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const setData = (k: keyof DataForm, v: any) => setD((x) => ({ ...x, [k]: v }));
    const setSiteField = (k: keyof SiteForm, v: any) => setS((x) => (x ? { ...x, [k]: v } : x));

    const loadSite = async (pid: number) => {
        if (!siteOn) return;
        try {
            const r: any = await getPropertySite(companyId, pid);
            setSite(r?.data ?? null);
            setS(r?.data ? toSite(r.data) : null);
        } catch (e: any) {
            setError(errorText(e, "Não foi possível carregar o site do imóvel."));
        }
    };

    useEffect(() => {
        if (!editing) { setId(null); setCurrent(null); setSite(null); setS(null); return; }
        setError(null);
        const p = editing === "new" ? null : editing;
        setId(p?.id ?? null);
        setCurrent(p);
        setD(p ? toData(p) : EMPTY);
        setSite(null);
        setS(null);
        setTab(p && siteOn ? initialTab : "data");
        getDistricts().then((r: any) => setDistricts(asOpts(r))).catch(() => setDistricts([]));
        if (p) void loadSite(p.id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [editing]);
    useEffect(() => { if (d.district_id) getMunicipalities(d.district_id).then((r: any) => setMunicipalities(asOpts(r))).catch(() => setMunicipalities([])); else setMunicipalities([]); }, [d.district_id]);
    useEffect(() => { if (d.municipality_id) getParishes(d.municipality_id).then((r: any) => setParishes(asOpts(r))).catch(() => setParishes([])); else setParishes([]); }, [d.municipality_id]);

    const invalid = !d.reference.trim() ? "Indique a referência." : !d.title.trim() ? "Indique o título." : !d.price ? "Indique o preço."
        : d.status === "fechado" && !d.closed_at ? "Indique a data de fecho." : null;
    const sitePart = current && siteOn ? { contacts: current.contacts_30_site ?? 0, visits: current.visits_30_site ?? 0 } : null;

    /** Grava os Dados e, quando o site está carregado, os campos do anúncio. Fica no modal. */
    const save = async (): Promise<number | null> => {
        if (!editing || invalid) { if (invalid) { setTab("data"); setError(invalid); } return null; }
        setSaving(true);
        setError(null);
        const body = {
            reference: d.reference.trim(), title: d.title.trim(), kind: d.kind, typology: d.typology || null, area_m2: blank(d.area_m2),
            parish_id: d.parish_id, listing: d.listing, price: Number(d.price), energy_class: d.energy_class || null, status: d.status,
            listed_at: d.listed_at, closed_at: d.status === "fechado" ? d.closed_at : null,
            contacts_30_manual: Number(d.contacts_30 || 0), visits_30_manual: Number(d.visits_30 || 0),
        };
        try {
            const res: any = id ? await updateProperty(companyId, id, body) : await createProperty(companyId, body);
            const saved: Property | null = res?.data ?? null;
            const pid = saved?.id ?? id;
            if (saved) { setCurrent(saved); setD(toData(saved)); }
            if (pid && siteOn && s) {
                const r: any = await updatePropertySite(companyId, pid, {
                    slug: s.slug.trim() || null, development_id: s.development_id || null, bedrooms: num(s.bedrooms), bathrooms: num(s.bathrooms), gross_area_m2: blank(s.gross_area_m2),
                    construction_state: s.construction_state || null, approx_lat: num(s.approx_lat), approx_lng: num(s.approx_lng), approx_radius_m: num(s.approx_radius_m),
                    price_on_request: s.price_on_request, energy_precertificate: s.energy_precertificate, short_description: blank(s.short_description),
                    description: blank(s.description), youtube: blank(s.youtube), chave_nova_url: blank(s.chave_nova_url),
                    chave_nova_reference: blank(s.chave_nova_reference), show_as_sold: s.show_as_sold, featured: s.featured,
                });
                if (r?.data) { setSite(r.data); setS(toSite(r.data)); }
            } else if (pid && siteOn) {
                await loadSite(pid); // acabado de criar: o site passa a existir
            }
            if (pid) setId(pid);
            onChanged();
            return pid;
        } catch (e: any) {
            setError(errorText(e, "Não foi possível guardar o imóvel."));
            return null;
        } finally {
            setSaving(false);
        }
    };
    const publish = async () => {
        const pid = await save();
        if (!pid) return;
        setSaving(true);
        try {
            const r: any = await publishProperty(companyId, pid);
            setSite(r.data);
            setS(toSite(r.data));
            toast.success(r?.message ?? "Imóvel publicado no site.");
            onChanged();
        } catch (e: any) {
            setTab("site");
            await loadSite(pid);
            setError(((e?.errors?.publish as string[]) ?? []).join(" ") || errorText(e, "Ainda não pode publicar este imóvel."));
        } finally {
            setSaving(false);
        }
    };
    const unpublish = async () => {
        if (!id || !(await confirmAction({ title: "Retirar o imóvel do site?", text: "Deixa de aparecer no site. Pode voltar a publicá-lo.", confirmText: "Retirar", icon: "warning", confirmVariant: "danger" }))) return;
        try {
            const r: any = await unpublishProperty(companyId, id);
            setSite(r.data);
            toast.success(r?.message ?? "Imóvel retirado do site.");
            onChanged();
        } catch (e: any) {
            toast.error(errorText(e, "Não foi possível retirar o imóvel."));
        }
    };
    const go = (field: string) => {
        const [t, el] = FIELD_TARGET[field] ?? ["site", ""];
        setTab(t);
        if (el) focusField(el);
    };

    const o = options;
    const published = site?.site_status === "publicado";
    const checklist = site?.publish_checklist ?? (site?.publish_missing ?? []).map((m, i) => ({ field: `item-${i}`, label: m, message: m }));
    const tabsOn = siteOn && !!id;
    const devOptions = [{ value: 0, label: "Nenhum" }, ...(site?.options.developments ?? []).map((x) => ({ value: x.id, label: x.name }))];
    const stateOptions = [{ value: "", label: "Por indicar" }, ...Object.entries(site?.options.construction_states ?? {}).map(([value, label]) => ({ value, label }))];
    // Grava primeiro: o que falta pode estar a ser preenchido agora; se ainda faltar, o separador Site mostra o quê.
    const publishReason = !canEdit ? editReason : invalid;
    const tabLink = (t: Tab, label: React.ReactNode, testId: string) => (
        <NavItem><NavLink href="#" active={tab === t} onClick={(e) => { e.preventDefault(); setTab(t); }} data-testid={testId}>{label}</NavLink></NavItem>
    );

    return (
        <Modal isOpen={!!editing} toggle={onClose} size="xl" centered scrollable data-testid="property-modal">
            <ModalHeader toggle={onClose}>
                {editing === "new" && !id ? "Novo imóvel" : <>Imóvel <span className="text-muted fw-normal">{d.reference}</span></>}{" "}
                {site && <Badge color={published ? "success-subtle" : "secondary-subtle"} className={`${published ? "text-success" : "text-secondary"} fw-normal ms-1`}>{published ? "Publicado" : "Rascunho"}</Badge>}
            </ModalHeader>
            <ModalBody>
                {siteOn && (
                    <Nav tabs className="nav-tabs-custom mb-3">
                        {tabLink("data", "Dados", "tab-data")}
                        {tabsOn ? <>
                            {tabLink("photos", `Fotografias (${site?.images.length ?? 0})`, "tab-photos")}
                            {tabLink("site", <>Site {site && checklist.length > 0 && !published ? <Badge color="warning" pill className="ms-1">{checklist.length}</Badge> : null}</>, "tab-site")}
                        </> : <NavItem><span className="nav-link text-muted fs-13">Fotografias e Site: guarde primeiro os dados.</span></NavItem>}
                    </Nav>
                )}

                {tab === "data" && (<>
                    <p className="text-muted fs-13">Só o que a Bússola usa. Sem morada nem dados de proprietários, compradores ou inquilinos.</p>
                    <Row className="g-3">
                        <Col sm={4}><Label for="p-ref">Referência</Label><Input id="p-ref" maxLength={40} value={d.reference} disabled={!canEdit} onChange={(e) => setData("reference", e.target.value)} /></Col>
                        <Col sm={8}><Label for="p-title">Título</Label><Input id="p-title" maxLength={120} value={d.title} disabled={!canEdit} onChange={(e) => setData("title", e.target.value)} /></Col>
                        <Col sm={4}><Label for="p-kind">Tipo</Label><Input id="p-kind" type="select" value={d.kind} disabled={!canEdit} onChange={(e) => setData("kind", e.target.value)}>{o && Object.entries(o.kinds).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</Input></Col>
                        <Col sm={4}><Label for="p-typ">Tipologia</Label><Input id="p-typ" type="select" value={d.typology} disabled={!canEdit} onChange={(e) => setData("typology", e.target.value)}><option value="">Sem tipologia</option>{o?.typologies.map((t) => <option key={t}>{t}</option>)}</Input></Col>
                        <Col sm={4}><Label for="p-area">Área útil (m²)</Label><Input id="p-area" inputMode="decimal" placeholder="por exemplo, 92,1" value={d.area_m2} disabled={!canEdit} onChange={(e) => setData("area_m2", e.target.value)} /></Col>
                        <Col sm={4}><Label>Distrito</Label><XSelect<number> ariaLabel="Distrito" options={districts} value={d.district_id} searchable disabled={!canEdit} onChange={(v) => setD((x) => ({ ...x, district_id: v, municipality_id: null, parish_id: null }))} /></Col>
                        <Col sm={4}><Label>Concelho</Label><XSelect<number> ariaLabel="Concelho" options={municipalities} value={d.municipality_id} searchable disabled={!canEdit || !d.district_id} onChange={(v) => setD((x) => ({ ...x, municipality_id: v, parish_id: null }))} /></Col>
                        <Col sm={4} id="p-parish-box"><Label>Freguesia</Label><XSelect<number> ariaLabel="Freguesia" options={parishes} value={d.parish_id} searchable disabled={!canEdit || !d.municipality_id} onChange={(v) => setData("parish_id", v)} /></Col>
                        <Col sm={4}><Label for="p-listing">Negócio</Label><Input id="p-listing" type="select" value={d.listing} disabled={!canEdit} onChange={(e) => setData("listing", e.target.value)}>{o && Object.entries(o.listings).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</Input></Col>
                        <Col sm={4}><Label for="p-price">{d.listing === "arrendamento" ? "Renda (€ por mês)" : "Preço (€)"}</Label><Input id="p-price" type="number" min={0} value={d.price} disabled={!canEdit} onChange={(e) => setData("price", e.target.value)} /></Col>
                        <Col sm={4}><Label for="p-energy">Classe energética</Label>
                            <Input id="p-energy" type="select" value={d.energy_class} disabled={!canEdit} onChange={(e) => setData("energy_class", e.target.value)}>
                                <option value="">Por preencher</option>{o?.energy_classes.map((c) => <option key={c} value={c}>{c === "em_processo" ? "Em processo" : c === "isento" ? "Isento" : c}</option>)}
                            </Input>
                            <small className="text-muted">Obrigatória em qualquer anúncio de venda ou arrendamento.</small>
                        </Col>
                        <Col sm={4}><Label for="p-status">Estado</Label><Input id="p-status" type="select" value={d.status} disabled={!canEdit} onChange={(e) => setData("status", e.target.value)}>{o && Object.entries(o.statuses).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</Input></Col>
                        <Col sm={4}><Label for="p-listed">Entrada em carteira</Label><Input id="p-listed" type="date" max={today()} value={d.listed_at} disabled={!canEdit} onChange={(e) => setData("listed_at", e.target.value)} /></Col>
                        {d.status === "fechado" && <Col sm={4}><Label for="p-closed">Data de fecho</Label><Input id="p-closed" type="date" max={today()} value={d.closed_at} disabled={!canEdit} onChange={(e) => setData("closed_at", e.target.value)} /></Col>}
                        <Col sm={6}><Label for="p-contacts">{siteOn ? "Contactos de outros canais nos últimos 30 dias" : "Contactos recebidos nos últimos 30 dias"}</Label>
                            <Input id="p-contacts" type="number" min={0} value={d.contacts_30} disabled={!canEdit} onChange={(e) => setData("contacts_30", e.target.value)} />
                            {siteOn && <small className="text-muted">O portal da Chave Nova, o telefone, as indicações. O site soma a sua parte sozinho{sitePart ? `: ${sitePart.contacts} contactos` : ""}.</small>}</Col>
                        <Col sm={6}><Label for="p-visits">{siteOn ? "Visitas de outros canais" : "Visitas marcadas"}</Label>
                            <Input id="p-visits" type="number" min={0} value={d.visits_30} disabled={!canEdit} onChange={(e) => setData("visits_30", e.target.value)} />
                            {siteOn && <small className="text-muted d-block">Pedidos de visita pelo site{sitePart ? `: ${sitePart.visits}` : ": somam-se sozinhos"}.</small>}
                            {current?.interest_updated_at && <small className="text-muted">Contagens atualizadas a {day(current.interest_updated_at)}.</small>}</Col>
                    </Row>
                </>)}

                {tab === "photos" && id && site && (
                    <div id="photos-box">
                        <ListingPhotos companyId={companyId} owner="properties" ownerId={id} images={site.images} canEdit={canEdit} editReason={editReason}
                            onChange={() => { void loadSite(id); onChanged(); }} />
                    </div>
                )}

                {tab === "site" && id && (!site || !s ? <div className="text-center py-4"><Spinner /></div> : (<>
                    <SitePublishPanel published={published} items={checklist} onGo={go} />
                    <Row className="g-3">
                        <Col md={6}><Label for="s-slug">Endereço no site</Label>
                            <Input id="s-slug" maxLength={160} value={s.slug} disabled={!canEdit} placeholder="cria-se ao publicar" onChange={(e) => setSiteField("slug", e.target.value.toLowerCase())} />
                            <small className="text-muted">Letras minúsculas, números e hífenes.</small></Col>
                        <Col md={6}><Label>Empreendimento (se for uma fração)</Label>
                            <XSelect<number> ariaLabel="Empreendimento" options={devOptions} value={s.development_id} disabled={!canEdit} onChange={(v) => setSiteField("development_id", v)} /></Col>
                        <Col md={3}><Label>Estado da construção</Label>
                            <XSelect<string> ariaLabel="Estado da construção" options={stateOptions} value={s.construction_state} disabled={!canEdit} onChange={(v) => setSiteField("construction_state", v)} /></Col>
                        <Col md={3}><Label for="s-bed">Quartos</Label><Input id="s-bed" type="number" min={0} value={s.bedrooms} disabled={!canEdit} onChange={(e) => setSiteField("bedrooms", e.target.value)} /></Col>
                        <Col md={3}><Label for="s-bath">Casas de banho</Label><Input id="s-bath" type="number" min={0} value={s.bathrooms} disabled={!canEdit} onChange={(e) => setSiteField("bathrooms", e.target.value)} /></Col>
                        <Col md={3}><Label for="s-gross">Área bruta (m²)</Label><Input id="s-gross" inputMode="decimal" placeholder="por exemplo, 110,5" value={s.gross_area_m2} disabled={!canEdit} onChange={(e) => setSiteField("gross_area_m2", e.target.value)} /></Col>
                        <Col md={6}>
                            <div className="form-check form-switch mt-2">
                                <Input id="s-por" type="checkbox" className="form-check-input" checked={s.price_on_request} disabled={!canEdit} onChange={(e) => setSiteField("price_on_request", e.target.checked)} />
                                <Label className="form-check-label" for="s-por">Preço sob consulta (o site não mostra o preço)</Label>
                            </div>
                            <div className="form-check form-switch">
                                <Input id="s-pre" type="checkbox" className="form-check-input" checked={s.energy_precertificate} disabled={!canEdit} onChange={(e) => setSiteField("energy_precertificate", e.target.checked)} />
                                <Label className="form-check-label" for="s-pre">A classe energética é de um pré-certificado</Label>
                            </div>
                        </Col>
                        <Col md={6}>
                            <div className="form-check form-switch mt-2">
                                <Input id="s-feat" type="checkbox" className="form-check-input" checked={s.featured} disabled={!canEdit} onChange={(e) => setSiteField("featured", e.target.checked)} />
                                <Label className="form-check-label" for="s-feat">Destaque na página inicial</Label>
                            </div>
                            <div className="form-check form-switch">
                                <Input id="s-sold" type="checkbox" className="form-check-input" checked={s.show_as_sold} disabled={!canEdit} onChange={(e) => setSiteField("show_as_sold", e.target.checked)} />
                                <Label className="form-check-label" for="s-sold">Mostrar como vendido (com a autorização do proprietário)</Label>
                            </div>
                        </Col>
                        <Col md={12}><Label for="s-short">Descrição curta</Label>
                            <Input id="s-short" maxLength={280} value={s.short_description} disabled={!canEdit} onChange={(e) => setSiteField("short_description", e.target.value)} /></Col>
                        <Col md={12}><Label for="s-desc">Descrição</Label>
                            <Input id="s-desc" type="textarea" rows={6} maxLength={8000} value={s.description} disabled={!canEdit} onChange={(e) => setSiteField("description", e.target.value)} />
                            <small className="text-muted">Sem a morada nem o número da porta.</small></Col>
                        <Col md={12}><h6 className="mt-2 mb-0">Localização aproximada</h6>
                            <small className="text-muted">O site mostra um círculo à volta deste ponto, nunca a casa. Use um ponto da rua ou do bairro, não a porta.</small></Col>
                        <Col md={4}><Label for="s-lat">Latitude</Label><Input id="s-lat" inputMode="decimal" value={s.approx_lat} disabled={!canEdit} onChange={(e) => setSiteField("approx_lat", e.target.value)} /></Col>
                        <Col md={4}><Label for="s-lng">Longitude</Label><Input id="s-lng" inputMode="decimal" value={s.approx_lng} disabled={!canEdit} onChange={(e) => setSiteField("approx_lng", e.target.value)} /></Col>
                        <Col md={4}><Label for="s-rad">Raio (m)</Label><Input id="s-rad" type="number" min={300} max={5000} step={50} value={s.approx_radius_m} disabled={!canEdit} onChange={(e) => setSiteField("approx_radius_m", e.target.value)} />
                            <small className="text-muted">Pelo menos 300 m.</small></Col>
                        <Col md={6}><Label for="s-yt">Vídeo do YouTube</Label>
                            <Input id="s-yt" maxLength={255} value={s.youtube} disabled={!canEdit} placeholder="link de um vídeo ou de um Short" onChange={(e) => setSiteField("youtube", e.target.value)} /></Col>
                        <Col md={4}><Label for="s-cn">Página na Chave Nova</Label>
                            <Input id="s-cn" type="url" maxLength={255} value={s.chave_nova_url} disabled={!canEdit} placeholder="https://www.chavenova.com/imovel/…" onChange={(e) => setSiteField("chave_nova_url", e.target.value)} /></Col>
                        <Col md={2}><Label for="s-cnr">Referência</Label>
                            <Input id="s-cnr" maxLength={40} value={s.chave_nova_reference} disabled={!canEdit} onChange={(e) => setSiteField("chave_nova_reference", e.target.value)} /></Col>
                    </Row>
                </>))}
                {error && <div className="alert alert-warning fs-13 mt-3 mb-0" role="alert">{error}</div>}
            </ModalBody>
            <ModalFooter>
                {published && canEdit && <ActionsMenu label="Mais ações: site do imóvel" items={[{ label: "Retirar do site", icon: "ri-eye-off-line", danger: true, onClick: () => void unpublish() }]} />}
                <Button color="light" onClick={onClose}>Fechar</Button>
                <ReasonButton color={site && !published ? "outline-primary" : "primary"} reason={!canEdit ? editReason : invalid} disabled={saving}
                    onClick={async () => { if (await save()) toast.success("Imóvel guardado."); }} data-testid="property-save">{saving ? <Spinner size="sm" /> : "Guardar"}</ReasonButton>
                {site && !published && (
                    <ReasonButton color="primary" reason={publishReason} disabled={saving} onClick={() => void publish()} data-testid="property-publish">Gravar e publicar</ReasonButton>
                )}
            </ModalFooter>
        </Modal>
    );
}
