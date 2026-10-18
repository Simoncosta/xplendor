import { useCallback, useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";
import { Badge, Button, Col, Container, Input, Label, Row, Spinner } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import XSelect, { XMultiSelect } from "Components/Common/Select";
import ReasonButton from "Components/Common/ReasonButton";
import ActionsMenu from "Components/Common/ActionsMenu";
import { useCan } from "contexts/ModulesContext";
import { confirmAction } from "helpers/swal";
import { sessionIsPlatformRoot } from "helpers/roles";
import { getWorkingCompanyId } from "helpers/workingCompany";
import {
    deleteConsultantSiteAsset, deleteConsultantSiteZonePhoto, getConsultantSite, getConsultantSiteHosts, getDistricts, getMunicipalities, getParishes, previewConsultantSite, publishConsultantSite,
    unpublishConsultantSite, updateConsultantSite, updateConsultantSiteHosts, uploadConsultantSiteAsset, uploadConsultantSiteZonePhoto,
} from "helpers/laravel_helper";
import { storageUrl, type ConsultantSiteProfile, type WhatsappTexts } from "common/models/consultantSite.model";

/**
 * Site do consultor: o perfil do site público, só com o que é exclusivo do site. O nome, o
 * telemóvel (WhatsApp), as redes, o Pixel e os dados fiscais vêm da empresa (Empresa › Dados).
 * Publicar o site é do Administrador e exige a mediação, o responsável pelo tratamento dos dados e
 * as ligações legais. Os hosts são configurados pela equipa XPLENDOR.
 */

const errorText = (e: any, fallback: string) => (e?.errors ? (Object.values(e.errors).flat()[0] as string) : null) || e?.message || fallback;
type Opt = { value: number; label: string };
const asOpts = (r: any): Opt[] => ((r?.data ?? r ?? []) as any[]).map((x) => ({ value: Number(x.id), label: String(x.name) }));

const ASSETS: { slot: string; label: string; accept: string; hint: string }[] = [
    { slot: "signature", label: "Assinatura (SVG)", accept: ".svg,image/svg+xml", hint: "SVG com contornos, exportado do Figma. Até 512 KB." },
    { slot: "photo", label: "Fotografia recortada (sem fundo)", accept: "image/png,image/webp", hint: "PNG ou WebP com transparência, com pelo menos 1600 px de altura. Até 10 MB." },
    { slot: "about_photo", label: "Fotografia com fundo", accept: "image/jpeg,image/png,image/webp", hint: "Para a página Sobre e a imagem de partilha nas redes (1200 × 630). Até 10 MB." },
    { slot: "hero_video_mp4", label: "Vídeo do topo (MP4)", accept: "video/mp4", hint: "Sem som, em loop. Até 10 MB; recomenda-se até 5 MB." },
    { slot: "hero_video_webm", label: "Vídeo do topo (WebM, opcional)", accept: "video/webm", hint: "A mesma versão em WebM, mais leve. Até 10 MB." },
    { slot: "hero_poster", label: "Capa do vídeo do topo", accept: "image/jpeg,image/png,image/webp", hint: "A imagem que aparece antes do vídeo e no telemóvel com poupança de dados." },
    { slot: "network_logo", label: "Logótipo da rede", accept: ".svg,image/svg+xml,image/png,image/webp", hint: "Fornecido pela agência. SVG, PNG ou WebP." },
    { slot: "credit_partner_logo", label: "Logótipo do parceiro de crédito", accept: ".svg,image/svg+xml,image/png,image/webp", hint: "SVG, PNG ou WebP." },
];
const COLOR_LABELS: Record<string, string> = { navy: "Azul escuro", blue: "Azul", amber: "Âmbar", cream: "Fundo creme", slate: "Texto secundário" };
const WA_LABELS: Record<string, string> = { general: "Geral e vender", property: "Página de um imóvel", development: "Página de um empreendimento", buy: "Comprar" };

type Form = Omit<ConsultantSiteProfile, "company" | "data_controller" | "assets" | "hosts" | "site_status" | "published_at" | "publish_missing" | "options" | "parishes" | "intro_youtube_id"
    | "featured_videos" | "zone_photos"> & { intro_youtube: string; parish_ids: number[]; featured_videos: string };
const toForm = (s: ConsultantSiteProfile): Form => ({
    hero_promise: s.hero_promise, bio_short: s.bio_short, intro_youtube: s.intro_youtube_id ? `https://youtu.be/${s.intro_youtube_id}` : "", intro_video_enabled: s.intro_video_enabled,
    featured_videos: (s.featured_videos ?? []).map((id) => `https://www.youtube.com/shorts/${id}`).join("\n"),
    brokerage_legal_name: s.brokerage_legal_name, brokerage_ami: s.brokerage_ami, network_label: s.network_label, network_text: s.network_text,
    legal_links: { complaints_book_url: s.legal_links.complaints_book_url, ral: s.legal_links.ral ?? [], legal_info_text: s.legal_links.legal_info_text },
    controller_is_company: s.controller_is_company, controller_legal_name: s.controller_legal_name, controller_nif: s.controller_nif,
    controller_address: s.controller_address, controller_email: s.controller_email, privacy_policy_version: s.privacy_policy_version,
    credit_partner: { ...s.credit_partner }, credit_partner_enabled: s.credit_partner_enabled, whatsapp_messages: { ...s.whatsapp_messages },
    ga4_id: s.ga4_id, colors: { ...s.colors }, sections: { ...s.sections }, show_made_with: s.show_made_with, parish_ids: s.parishes.map((p) => p.id),
});
const blankToNull = (v: string | null | undefined) => (v == null || v.trim() === "" ? null : v.trim());

function Switch({ id, label, checked, disabled, onChange }: { id: string; label: string; checked: boolean; disabled?: boolean; onChange: (v: boolean) => void }) {
    return (
        <div className="form-check form-switch mb-2">
            <Input id={id} type="checkbox" className="form-check-input" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
            <Label className="form-check-label" for={id}>{label}</Label>
        </div>
    );
}

function AssetField({ slot, label, accept, hint, path, canEdit, editReason, onUpload, onRemove }: {
    slot: string; label: string; accept: string; hint: string; path: string | null; canEdit: boolean; editReason: string | null;
    onUpload: (slot: string, file: File) => Promise<void>; onRemove: (slot: string) => Promise<void>;
}) {
    const ref = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const isVideo = slot.startsWith("hero_video");
    return (
        <div className="border rounded p-2 h-100 d-flex flex-column gap-2" data-testid={`asset-${slot}`}>
            <div className="d-flex justify-content-between align-items-start gap-2">
                <div><div className="fw-medium fs-13">{label}</div><small className="text-muted">{hint}</small></div>
                {path && canEdit && <ActionsMenu size="sm" label={`Mais ações: ${label}`} items={[{ label: "Retirar", icon: "ri-delete-bin-line", danger: true, onClick: async () => {
                    if (!(await confirmAction({ title: `Retirar: ${label}?`, text: "Sai do site.", confirmText: "Retirar", icon: "warning", confirmVariant: "danger" }))) return;
                    setBusy(true); await onRemove(slot); setBusy(false);
                } }]} />}
            </div>
            {path ? (isVideo
                ? <video src={storageUrl(path) ?? ""} muted playsInline controls preload="none" className="w-100 rounded" style={{ maxHeight: 140, background: "var(--vz-secondary-bg)" }} />
                : <img src={storageUrl(path) ?? ""} alt={label} className="rounded" style={{ maxHeight: 120, maxWidth: "100%", objectFit: "contain", alignSelf: "flex-start", background: "var(--vz-secondary-bg)" }} />)
                : <span className="text-muted fs-12">Ainda não carregado.</span>}
            <input ref={ref} type="file" accept={accept} hidden onChange={async (e) => {
                const file = e.target.files?.[0];
                if (!file) return;
                setBusy(true); await onUpload(slot, file); setBusy(false);
                if (ref.current) ref.current.value = "";
            }} />
            <ReasonButton size="sm" color="outline-primary" className="mt-auto align-self-start" reason={canEdit ? null : editReason} disabled={busy} onClick={() => ref.current?.click()}>
                {busy ? <Spinner size="sm" /> : path ? "Substituir" : "Carregar"}
            </ReasonButton>
        </div>
    );
}

/** As fotografias das zonas: fundos e separadores do site. Recodificadas no servidor, sem EXIF nem GPS. */
function ZonePhotos({ photos, parishes, canEdit, editReason, onUpload, onRemove }: {
    photos: ConsultantSiteProfile["zone_photos"]; parishes: ConsultantSiteProfile["parishes"]; canEdit: boolean; editReason: string | null;
    onUpload: (file: File, alt: string | null, parishId: number | null) => Promise<boolean>; onRemove: (id: string) => Promise<void>;
}) {
    const ref = useRef<HTMLInputElement>(null);
    const [alt, setAlt] = useState("");
    const [parish, setParish] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);
    const names = Object.fromEntries(parishes.map((p) => [p.id, p.name]));
    return (
        <div data-testid="zone-photos">
            {photos.length === 0 ? <p className="text-muted fs-13">Ainda sem fotografias das zonas: os fundos e os separadores ficam escondidos.</p> : (
                <Row className="g-2 mb-3">
                    {photos.map((p) => (
                        <Col key={p.id} xs={6} md={4} xl={2}>
                            <div className="border rounded p-1 h-100 d-flex flex-column gap-1">
                                <img src={storageUrl(p.path_960 ?? p.path) ?? ""} alt={p.alt ?? "Fotografia de uma zona"} className="rounded w-100" style={{ aspectRatio: "4 / 3", objectFit: "cover" }} />
                                <div className="d-flex justify-content-between align-items-start gap-1">
                                    <small className="text-muted">{[p.alt, p.parish_id ? names[p.parish_id] : null].filter(Boolean).join(" · ") || "Sem descrição"}</small>
                                    {canEdit && <ActionsMenu size="sm" label="Mais ações: fotografia da zona" items={[{ label: "Retirar", icon: "ri-delete-bin-line", danger: true, onClick: async () => {
                                        if (!(await confirmAction({ title: "Retirar esta fotografia?", text: "Sai do site.", confirmText: "Retirar", icon: "warning", confirmVariant: "danger" }))) return;
                                        await onRemove(p.id);
                                    } }]} />}
                                </div>
                            </div>
                        </Col>
                    ))}
                </Row>
            )}
            <Row className="g-2 align-items-end">
                <Col md={5}><Label for="zp-alt">Descrição (o que se vê)</Label><Input id="zp-alt" maxLength={160} value={alt} disabled={!canEdit} placeholder="por exemplo, a rua principal ao fim da tarde" onChange={(e) => setAlt(e.target.value)} /></Col>
                <Col md={4}><Label>Freguesia (opcional)</Label><XSelect<number> ariaLabel="Freguesia da fotografia" options={parishes.map((p) => ({ value: p.id, label: p.name }))} value={parish} disabled={!canEdit} onChange={setParish} /></Col>
                <Col md={3}>
                    <input ref={ref} type="file" accept="image/jpeg,image/png,image/webp" hidden data-testid="zone-photo-input" onChange={async (e) => {
                        const file = e.target.files?.[0];
                        if (!file) return;
                        setBusy(true);
                        if (await onUpload(file, alt.trim() || null, parish)) { setAlt(""); setParish(null); }
                        setBusy(false);
                        if (ref.current) ref.current.value = "";
                    }} />
                    <ReasonButton size="sm" color="outline-primary" reason={canEdit ? null : editReason} disabled={busy} onClick={() => ref.current?.click()}>
                        {busy ? <Spinner size="sm" /> : <><i className="ri-upload-2-line me-1" />Carregar fotografia</>}
                    </ReasonButton>
                </Col>
            </Row>
            <small className="text-muted d-block mt-2">Só fotografias suas. JPEG, PNG ou WebP, até 15 MB. A localização (GPS) e os outros dados da câmara saem sempre.</small>
        </div>
    );
}

function ParishPicker({ selected, names, disabled, onChange }: { selected: number[]; names: Record<number, string>; disabled: boolean; onChange: (ids: number[], names: Record<number, string>) => void }) {
    const [districts, setDistricts] = useState<Opt[]>([]);
    const [municipalities, setMunicipalities] = useState<Opt[]>([]);
    const [parishes, setParishes] = useState<Opt[]>([]);
    const [district, setDistrict] = useState<number | null>(null);
    const [municipality, setMunicipality] = useState<number | null>(null);
    useEffect(() => { getDistricts().then((r: any) => setDistricts(asOpts(r))).catch(() => setDistricts([])); }, []);
    useEffect(() => { if (district) getMunicipalities(district).then((r: any) => setMunicipalities(asOpts(r))).catch(() => setMunicipalities([])); else setMunicipalities([]); }, [district]);
    useEffect(() => { if (municipality) getParishes(municipality).then((r: any) => setParishes(asOpts(r))).catch(() => setParishes([])); else setParishes([]); }, [municipality]);
    const inMunicipality = parishes.map((p) => p.value);
    return (
        <Row className="g-2">
            <Col md={4}><Label>Distrito</Label><XSelect<number> ariaLabel="Distrito" options={districts} value={district} searchable disabled={disabled} onChange={(v) => { setDistrict(v); setMunicipality(null); }} /></Col>
            <Col md={4}><Label>Concelho</Label><XSelect<number> ariaLabel="Concelho" options={municipalities} value={municipality} searchable disabled={disabled || !district} onChange={setMunicipality} /></Col>
            <Col md={4}><Label>Freguesias deste concelho</Label>
                <XMultiSelect<number> ariaLabel="Freguesias" options={parishes} value={selected.filter((id) => inMunicipality.includes(id))} searchable disabled={disabled || !municipality}
                    onChange={(ids) => {
                        const others = selected.filter((id) => !inMunicipality.includes(id));
                        const n = { ...names };
                        parishes.forEach((p) => { n[p.value] = p.label; });
                        onChange([...others, ...ids], n);
                    }} /></Col>
            <Col md={12}>
                {selected.length === 0 ? <span className="text-muted fs-13">Nenhuma freguesia escolhida.</span> : (
                    <div className="d-flex flex-wrap gap-1">{selected.map((id) => (
                        <Badge key={id} color="primary-subtle" className="text-primary fw-normal d-inline-flex align-items-center gap-1">
                            {names[id] ?? `Freguesia ${id}`}
                            {!disabled && <button type="button" className="btn btn-link p-0 text-primary lh-1" aria-label={`Retirar ${names[id] ?? id}`} onClick={() => onChange(selected.filter((x) => x !== id), names)}><i className="ri-close-line" /></button>}
                        </Badge>
                    ))}</div>
                )}
            </Col>
        </Row>
    );
}

export default function SiteConsultorPage() {
    document.title = "Site do consultor | Xplendor";
    const companyId = getWorkingCompanyId();
    const [site, setSite] = useState<ConsultantSiteProfile | null>(null);
    const [f, setF] = useState<Form | null>(null);
    const [parishNames, setParishNames] = useState<Record<number, string>>({});
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [hosts, setHosts] = useState<{ host: string; is_primary: boolean }[]>([]);
    const [canEdit, editReason] = useCan("site_consultor.editar");
    const [canPublish, publishPermReason] = useCan("site_consultor.aprovar");
    const [canView, viewReason] = useCan("site_consultor.ver");
    const isRoot = sessionIsPlatformRoot();

    const apply = (s: ConsultantSiteProfile) => {
        setSite(s);
        setF(toForm(s));
        setHosts(s.hosts);
        setParishNames(Object.fromEntries(s.parishes.map((p) => [p.id, p.municipality ? `${p.name} (${p.municipality})` : p.name])));
    };
    const load = useCallback(async () => {
        try {
            const r: any = await getConsultantSite(companyId);
            apply(r.data);
        } catch (e: any) {
            setError(errorText(e, "Não foi possível carregar o perfil do site."));
        }
    }, [companyId]);
    useEffect(() => { void load(); }, [load]);
    useEffect(() => { if (isRoot) getConsultantSiteHosts(companyId).then((r: any) => setHosts(r?.data?.hosts ?? [])).catch(() => undefined); }, [companyId, isRoot]);

    const set = <K extends keyof Form>(k: K, v: Form[K]) => setF((x) => (x ? { ...x, [k]: v } : x));
    const save = async () => {
        if (!f) return;
        setSaving(true);
        setError(null);
        try {
            const r: any = await updateConsultantSite(companyId, {
                ...f, hero_promise: blankToNull(f.hero_promise), bio_short: blankToNull(f.bio_short), intro_youtube: blankToNull(f.intro_youtube),
                brokerage_legal_name: blankToNull(f.brokerage_legal_name), brokerage_ami: blankToNull(f.brokerage_ami), network_label: blankToNull(f.network_label),
                network_text: blankToNull(f.network_text), controller_legal_name: blankToNull(f.controller_legal_name), controller_nif: blankToNull(f.controller_nif),
                controller_address: blankToNull(f.controller_address), controller_email: blankToNull(f.controller_email), privacy_policy_version: blankToNull(f.privacy_policy_version),
                ga4_id: blankToNull(f.ga4_id), featured_videos: f.featured_videos.split(/\n+/).map((l) => l.trim()).filter(Boolean),
                legal_links: { complaints_book_url: blankToNull(f.legal_links.complaints_book_url), legal_info_text: blankToNull(f.legal_links.legal_info_text),
                    ral: f.legal_links.ral.filter((e) => e.name.trim() || e.url.trim()) },
            });
            apply(r.data);
            toast.success(r?.message ?? "Perfil do site guardado.");
        } catch (e: any) {
            setError(errorText(e, "Não foi possível guardar o perfil do site."));
        } finally {
            setSaving(false);
        }
    };
    const upload = async (slot: string, file: File) => {
        try { const r: any = await uploadConsultantSiteAsset(companyId, slot, file); setSite(r.data); toast.success(r?.message ?? "Ficheiro guardado."); }
        catch (e: any) { toast.error(errorText(e, "Não foi possível guardar o ficheiro.")); }
    };
    const removeAsset = async (slot: string) => {
        try { const r: any = await deleteConsultantSiteAsset(companyId, slot); setSite(r.data); toast.success(r?.message ?? "Ficheiro retirado."); }
        catch (e: any) { toast.error(errorText(e, "Não foi possível retirar o ficheiro.")); }
    };
    const uploadZone = async (file: File, alt: string | null, parishId: number | null) => {
        try { const r: any = await uploadConsultantSiteZonePhoto(companyId, file, alt, parishId); setSite(r.data); toast.success(r?.message ?? "Fotografia guardada."); return true; }
        catch (e: any) { toast.error(errorText(e, "Não foi possível guardar a fotografia.")); return false; }
    };
    const removeZone = async (id: string) => {
        try { const r: any = await deleteConsultantSiteZonePhoto(companyId, id); setSite(r.data); toast.success(r?.message ?? "Fotografia retirada."); }
        catch (e: any) { toast.error(errorText(e, "Não foi possível retirar a fotografia.")); }
    };
    const publish = async () => {
        try { const r: any = await publishConsultantSite(companyId); apply(r.data); toast.success(r?.message ?? "Site publicado."); }
        catch (e: any) { toast.error(((e?.errors?.publish as string[]) ?? [])[0] ?? errorText(e, "Ainda não pode publicar o site.")); }
    };
    const unpublish = async () => {
        if (!(await confirmAction({ title: "Retirar o site do ar?", text: "O site deixa de estar disponível para os visitantes.", confirmText: "Retirar", icon: "warning", confirmVariant: "danger" }))) return;
        try { const r: any = await unpublishConsultantSite(companyId); apply(r.data); toast.success(r?.message ?? "Site retirado do ar."); }
        catch (e: any) { toast.error(errorText(e, "Não foi possível retirar o site.")); }
    };
    // Pré-visualizar: o site (também em rascunho) por um link assinado e temporário, noutro separador.
    const preview = async () => {
        const tab = window.open("", "_blank"); // aberto já, no clique, para o navegador não o bloquear
        try {
            const r: any = await previewConsultantSite(companyId);
            const url: string | undefined = r?.data?.url;
            if (!url) throw new Error("Sem link.");
            if (tab) { tab.opener = null; tab.location.href = url; } else window.open(url, "_blank", "noopener");
        } catch (e: any) {
            tab?.close();
            toast.error(errorText(e, "Não foi possível abrir a pré-visualização."));
        }
    };
    const saveHosts = async () => {
        try { const r: any = await updateConsultantSiteHosts(companyId, hosts.filter((h) => h.host.trim())); setHosts(r?.data?.hosts ?? []); toast.success("Endereços guardados."); void load(); }
        catch (e: any) { toast.error(errorText(e, "Não foi possível guardar os endereços.")); }
    };

    if (!site || !f) {
        return <div className="page-content"><Container fluid><PageHeader title="Site do consultor" breadcrumbs={[{ label: "Imobiliário" }]} />
            {error ? <div className="alert alert-warning">{error}</div> : <div className="text-center py-5"><Spinner /></div>}</Container></div>;
    }
    const published = site.site_status === "publicado";
    const publishReason = !canPublish ? publishPermReason : (site.publish_missing[0] ?? null);
    const c = site.company;

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Site do consultor" breadcrumbs={[{ label: "Imobiliário" }]}
                    info="O perfil do site público: os textos, os ficheiros, a mediação, as ligações legais e o aspeto. O nome, o WhatsApp, as redes e os dados fiscais vêm dos dados da empresa." />

                <PageCard title="Estado do site" data-testid="site-status"
                    status={<Badge color={published ? "success-subtle" : "secondary-subtle"} className={`${published ? "text-success" : "text-secondary"} fw-normal`}>{published ? "Publicado" : "Rascunho"}</Badge>}
                    actions={<>
                        {published && canPublish && <ActionsMenu size="sm" label="Mais ações: site" items={[{ label: "Retirar o site do ar", icon: "ri-eye-off-line", danger: true, onClick: () => void unpublish() }]} />}
                        <ReasonButton size="sm" color="outline-primary" reason={!canView ? viewReason : (site.hosts.length ? null : "Falta o endereço do site (a equipa XPLENDOR configura-o).")}
                            onClick={() => void preview()} data-testid="site-preview"><i className="ri-eye-line me-1" />Pré-visualizar</ReasonButton>
                        {!published && <ReasonButton size="sm" color="primary" reason={publishReason} onClick={() => void publish()} data-testid="site-publish"><i className="ri-global-line me-1" />Publicar o site</ReasonButton>}
                    </>}>
                    <div className="p-3">
                        <div className="mb-2"><strong>Endereço:</strong> {site.hosts.length ? site.hosts.map((h) => h.host).join(", ") : <span className="text-muted">a configurar pela equipa XPLENDOR</span>}</div>
                        {site.publish_missing.length > 0 && (
                            <div className="alert alert-info fs-13 mb-0" role="status" data-testid="site-publish-missing"><strong>Para publicar:</strong>
                                <ul className="mb-0 ps-3">{site.publish_missing.map((m) => <li key={m}>{m}</li>)}</ul></div>
                        )}
                        {isRoot && (
                            <div className="mt-3 border-top pt-3" data-testid="site-hosts">
                                <h6>Endereços do site (equipa XPLENDOR)</h6>
                                {hosts.map((h, i) => (
                                    <Row key={i} className="g-2 mb-2 align-items-center">
                                        <Col md={7}><Input bsSize="sm" aria-label={`Endereço ${i + 1}`} value={h.host} placeholder="nome.xplendor.tech" onChange={(e) => setHosts((x) => x.map((y, j) => (j === i ? { ...y, host: e.target.value } : y)))} /></Col>
                                        <Col md={3}><div className="form-check"><Input type="radio" className="form-check-input" id={`host-p-${i}`} checked={h.is_primary} onChange={() => setHosts((x) => x.map((y, j) => ({ ...y, is_primary: j === i })))} />
                                            <Label className="form-check-label fs-13" for={`host-p-${i}`}>Principal</Label></div></Col>
                                        <Col md={2}><Button size="sm" color="danger" outline aria-label={`Retirar o endereço ${i + 1}`} onClick={() => setHosts((x) => x.filter((_, j) => j !== i))}><i className="ri-delete-bin-line" /></Button></Col>
                                    </Row>
                                ))}
                                <div className="d-flex gap-2">
                                    <Button size="sm" color="outline-primary" onClick={() => setHosts((x) => [...x, { host: "", is_primary: x.length === 0 }])}><i className="ri-add-line me-1" />Acrescentar endereço</Button>
                                    <Button size="sm" color="primary" onClick={() => void saveHosts()}>Guardar endereços</Button>
                                </div>
                            </div>
                        )}
                    </div>
                </PageCard>

                <PageCard title="Da empresa" info="Estes dados vêm de Empresa › Dados e servem também o resto da XPLENDOR. Edite-os lá.">
                    <div className="p-3">
                        <Row className="g-2 fs-13">
                            <Col md={4}><div className="text-muted">Nome no site</div>{c.public_name || <span className="text-warning">por indicar</span>}</Col>
                            <Col md={4}><div className="text-muted">WhatsApp (telemóvel da empresa)</div>{c.whatsapp_number ?? <span className="text-muted">sem número: o botão do WhatsApp fica escondido</span>}</Col>
                            <Col md={4}><div className="text-muted">Pixel da Meta</div>{c.facebook_pixel_id || <span className="text-muted">sem Pixel</span>}</Col>
                            <Col md={12}><div className="text-muted">Redes</div>{Object.keys(c.socials).length ? Object.entries(c.socials).map(([k, v]) => <div key={k}>{k}: {v}</div>) : <span className="text-muted">nenhuma</span>}</Col>
                        </Row>
                        <Link to={`/companies/${companyId}`} className="btn btn-sm btn-outline-primary mt-3">Editar os dados da empresa</Link>
                    </div>
                </PageCard>

                <PageCard title="Perfil do site" data-testid="site-profile"
                    actions={<ReasonButton size="sm" color="primary" reason={canEdit ? null : editReason} disabled={saving} onClick={() => void save()} data-testid="site-save">{saving ? <Spinner size="sm" /> : "Guardar"}</ReasonButton>}>
                    <div className="p-3">
                        {error && <div className="alert alert-warning fs-13" role="alert">{error}</div>}
                        <h6>Topo da página inicial</h6>
                        <Row className="g-3 mb-4">
                            <Col md={12}><Label for="f-promise">A promessa do topo</Label><Input id="f-promise" maxLength={160} value={f.hero_promise ?? ""} disabled={!canEdit} onChange={(e) => set("hero_promise", e.target.value)} />
                                <small className="text-muted">Sem promessas de preço nem de prazo.</small></Col>
                            <Col md={12}><Label for="f-bio">Biografia curta</Label><Input id="f-bio" type="textarea" rows={3} maxLength={1200} value={f.bio_short ?? ""} disabled={!canEdit} onChange={(e) => set("bio_short", e.target.value)} /></Col>
                            <Col md={8}><Label for="f-intro">Vídeo de apresentação (YouTube)</Label><Input id="f-intro" maxLength={255} value={f.intro_youtube} disabled={!canEdit} onChange={(e) => set("intro_youtube", e.target.value)} /></Col>
                            <Col md={4} className="d-flex align-items-end"><Switch id="f-intro-on" label="Mostrar o vídeo de apresentação" checked={f.intro_video_enabled} disabled={!canEdit} onChange={(v) => set("intro_video_enabled", v)} /></Col>
                            <Col md={12}><Label for="f-featured">Vídeos em destaque (YouTube)</Label>
                                <Input id="f-featured" type="textarea" rows={4} value={f.featured_videos} disabled={!canEdit} placeholder={"https://www.youtube.com/shorts/…\nhttps://youtu.be/…"} onChange={(e) => set("featured_videos", e.target.value)} />
                                <small className="text-muted">Um link (ou ID) por linha, pela ordem em que aparecem na página inicial. Até 12. Sem vídeos, a fila fica escondida.</small></Col>
                        </Row>
                        <Row className="g-3 mb-4">
                            {ASSETS.map((a) => (
                                <Col key={a.slot} md={6} xl={4}><AssetField {...a} path={site.assets[a.slot] ?? null} canEdit={canEdit} editReason={editReason} onUpload={upload} onRemove={removeAsset} /></Col>
                            ))}
                        </Row>

                        <h6>Fotografias das zonas</h6>
                        <div className="mb-4"><ZonePhotos photos={site.zone_photos ?? []} parishes={site.parishes} canEdit={canEdit} editReason={editReason} onUpload={uploadZone} onRemove={removeZone} /></div>

                        <h6>Rede e mediação</h6>
                        <Row className="g-3 mb-4">
                            <Col md={4}><Label for="f-net">Endosso no site</Label><Input id="f-net" maxLength={80} value={f.network_label ?? ""} disabled={!canEdit} onChange={(e) => set("network_label", e.target.value)} /></Col>
                            <Col md={5}><Label for="f-brok">Empresa de mediação (nome legal)</Label><Input id="f-brok" maxLength={160} value={f.brokerage_legal_name ?? ""} disabled={!canEdit} onChange={(e) => set("brokerage_legal_name", e.target.value)} /></Col>
                            <Col md={3}><Label for="f-ami">Licença AMI</Label><Input id="f-ami" inputMode="numeric" maxLength={20} value={f.brokerage_ami ?? ""} disabled={!canEdit} onChange={(e) => set("brokerage_ami", e.target.value)} /></Col>
                            <Col md={12}><Label for="f-nettext">Texto da rede (fornecido pela agência)</Label><Input id="f-nettext" type="textarea" rows={3} maxLength={2000} value={f.network_text ?? ""} disabled={!canEdit} onChange={(e) => set("network_text", e.target.value)} /></Col>
                        </Row>

                        <h6>Informação legal</h6>
                        <Row className="g-3 mb-2">
                            <Col md={6}><Label for="f-book">Livro de reclamações (ligação)</Label><Input id="f-book" type="url" maxLength={255} value={f.legal_links.complaints_book_url ?? ""} disabled={!canEdit}
                                onChange={(e) => set("legal_links", { ...f.legal_links, complaints_book_url: e.target.value })} /></Col>
                            <Col md={6}><Label for="f-pol">Versão da política de privacidade</Label><Input id="f-pol" maxLength={20} value={f.privacy_policy_version ?? ""} disabled={!canEdit} placeholder="por exemplo, 2026-10"
                                onChange={(e) => set("privacy_policy_version", e.target.value)} /><small className="text-muted">Fica gravada em cada contacto, com o consentimento.</small></Col>
                            <Col md={12}><Label>Entidades de resolução alternativa de litígios</Label>
                                {f.legal_links.ral.map((r, i) => (
                                    <Row key={i} className="g-2 mb-2">
                                        <Col md={5}><Input aria-label={`Entidade ${i + 1}: nome`} maxLength={160} value={r.name} disabled={!canEdit} placeholder="Nome"
                                            onChange={(e) => set("legal_links", { ...f.legal_links, ral: f.legal_links.ral.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)) })} /></Col>
                                        <Col md={6}><Input aria-label={`Entidade ${i + 1}: ligação`} type="url" maxLength={255} value={r.url} disabled={!canEdit} placeholder="https://"
                                            onChange={(e) => set("legal_links", { ...f.legal_links, ral: f.legal_links.ral.map((x, j) => (j === i ? { ...x, url: e.target.value } : x)) })} /></Col>
                                        <Col md={1}>{canEdit && <Button color="danger" outline size="sm" aria-label={`Retirar a entidade ${i + 1}`} onClick={() => set("legal_links", { ...f.legal_links, ral: f.legal_links.ral.filter((_, j) => j !== i) })}><i className="ri-delete-bin-line" /></Button>}</Col>
                                    </Row>
                                ))}
                                {canEdit && f.legal_links.ral.length < 5 && <Button size="sm" color="outline-primary" onClick={() => set("legal_links", { ...f.legal_links, ral: [...f.legal_links.ral, { name: "", url: "" }] })}><i className="ri-add-line me-1" />Acrescentar entidade</Button>}
                            </Col>
                            <Col md={12}><Label for="f-legal">Informação legal adicional</Label><Input id="f-legal" type="textarea" rows={3} maxLength={4000} value={f.legal_links.legal_info_text ?? ""} disabled={!canEdit}
                                onChange={(e) => set("legal_links", { ...f.legal_links, legal_info_text: e.target.value })} /></Col>
                        </Row>
                        <Row className="g-3 mb-4">
                            <Col md={12} role="radiogroup" aria-labelledby="f-ctrl-title">
                                <div id="f-ctrl-title" className="form-label">Responsável pelo tratamento dos dados</div>
                                <div className="form-check">
                                    <Input type="radio" className="form-check-input" id="f-ctrl-company" name="f-ctrl" checked={f.controller_is_company === true} disabled={!canEdit} onChange={() => set("controller_is_company", true)} />
                                    <Label className="form-check-label" for="f-ctrl-company">A própria empresa</Label>
                                </div>
                                <div className="form-check">
                                    <Input type="radio" className="form-check-input" id="f-ctrl-other" name="f-ctrl" checked={f.controller_is_company === false} disabled={!canEdit} onChange={() => set("controller_is_company", false)} />
                                    <Label className="form-check-label" for="f-ctrl-other">Outra entidade (por exemplo, a agência)</Label>
                                </div>
                                {f.controller_is_company === null && <small className="text-warning d-block">Por escolher: o site não pode ser publicado sem o responsável pelo tratamento dos dados.</small>}
                                {f.controller_is_company === true && <small className="text-muted">Usa o nome fiscal, o NIPC, a morada e o email da empresa: {[c.fiscal_name, c.nipc, c.address, c.email].filter(Boolean).join(", ") || "por preencher em Empresa › Dados"}.</small>}</Col>
                            {f.controller_is_company === false && <>
                                <Col md={6}><Label for="f-cn">Nome legal do responsável</Label><Input id="f-cn" maxLength={160} value={f.controller_legal_name ?? ""} disabled={!canEdit} onChange={(e) => set("controller_legal_name", e.target.value)} /></Col>
                                <Col md={3}><Label for="f-cnif">NIF</Label><Input id="f-cnif" inputMode="numeric" maxLength={9} value={f.controller_nif ?? ""} disabled={!canEdit} onChange={(e) => set("controller_nif", e.target.value)} /></Col>
                                <Col md={3}><Label for="f-cmail">Email para os pedidos sobre dados pessoais</Label><Input id="f-cmail" type="email" maxLength={160} value={f.controller_email ?? ""} disabled={!canEdit} onChange={(e) => set("controller_email", e.target.value)} /></Col>
                                <Col md={12}><Label for="f-caddr">Morada</Label><Input id="f-caddr" maxLength={255} value={f.controller_address ?? ""} disabled={!canEdit} onChange={(e) => set("controller_address", e.target.value)} /></Col>
                            </>}
                        </Row>

                        <h6>Parceiro de crédito</h6>
                        <Row className="g-3 mb-4">
                            <Col md={12}><Switch id="f-credit" label="Mostrar a secção do parceiro de crédito" checked={f.credit_partner_enabled} disabled={!canEdit} onChange={(v) => set("credit_partner_enabled", v)} /></Col>
                            <Col md={6}><Label for="f-cpn">Nome legal</Label><Input id="f-cpn" maxLength={160} value={f.credit_partner.legal_name ?? ""} disabled={!canEdit} onChange={(e) => set("credit_partner", { ...f.credit_partner, legal_name: e.target.value })} /></Col>
                            <Col md={6}><Label for="f-cpbp">Registo no Banco de Portugal</Label><Input id="f-cpbp" maxLength={40} value={f.credit_partner.bp_registration ?? ""} disabled={!canEdit} onChange={(e) => set("credit_partner", { ...f.credit_partner, bp_registration: e.target.value })} /></Col>
                            <Col md={12}><Label for="f-cpt">Texto</Label><Input id="f-cpt" type="textarea" rows={2} maxLength={1200} value={f.credit_partner.text ?? ""} disabled={!canEdit} onChange={(e) => set("credit_partner", { ...f.credit_partner, text: e.target.value })} />
                                <small className="text-muted">Sem taxas nem prestações.</small></Col>
                        </Row>

                        <h6>Mensagens do WhatsApp</h6>
                        <p className="text-muted fs-13">Na mensagem preenchida, {"{nome}"} é o primeiro nome do consultor, {"{referencia}"} a referência do imóvel e {"{empreendimento}"} o nome do empreendimento.</p>
                        <Row className="g-3 mb-4">
                            {Object.keys(WA_LABELS).map((ctx) => {
                                const m: WhatsappTexts = f.whatsapp_messages[ctx] ?? { popup: "", prefill: "" };
                                const put = (k: keyof WhatsappTexts, v: string) => set("whatsapp_messages", { ...f.whatsapp_messages, [ctx]: { ...m, [k]: v } });
                                return (
                                    <Col key={ctx} md={6}>
                                        <div className="border rounded p-2">
                                            <div className="fw-medium fs-13 mb-2">{WA_LABELS[ctx]}</div>
                                            <Label for={`wa-${ctx}-p`} className="fs-12">Mensagem do popup</Label>
                                            <Input id={`wa-${ctx}-p`} type="textarea" rows={2} maxLength={280} value={m.popup} disabled={!canEdit} onChange={(e) => put("popup", e.target.value)} />
                                            <Label for={`wa-${ctx}-f`} className="fs-12 mt-2">Mensagem preenchida</Label>
                                            <Input id={`wa-${ctx}-f`} maxLength={280} value={m.prefill} disabled={!canEdit} onChange={(e) => put("prefill", e.target.value)} />
                                        </div>
                                    </Col>
                                );
                            })}
                        </Row>

                        <h6>Freguesias de atuação</h6>
                        <div className="mb-4"><ParishPicker selected={f.parish_ids} names={parishNames} disabled={!canEdit} onChange={(ids, names) => { set("parish_ids", ids); setParishNames(names); }} /></div>

                        <h6>Medição</h6>
                        <Row className="g-3 mb-4">
                            <Col md={4}><Label for="f-ga4">ID do GA4</Label><Input id="f-ga4" maxLength={20} value={f.ga4_id ?? ""} disabled={!canEdit} placeholder="G-…" onChange={(e) => set("ga4_id", e.target.value.toUpperCase())} />
                                <small className="text-muted">Só carrega depois do consentimento do visitante. O Pixel é o da empresa.</small></Col>
                        </Row>

                        <h6>Aspeto e secções</h6>
                        <Row className="g-3 mb-3">
                            {Object.keys(site.options.default_colors).map((k) => (
                                <Col key={k} xs={6} md={4} xl={2}>
                                    <Label for={`c-${k}`}>{COLOR_LABELS[k] ?? k}</Label>
                                    <div className="d-flex gap-2 align-items-center">
                                        <Input id={`c-${k}`} type="color" className="form-control-color" value={f.colors[k] ?? site.options.default_colors[k]} disabled={!canEdit} onChange={(e) => set("colors", { ...f.colors, [k]: e.target.value.toUpperCase() })} />
                                        <code className="fs-12">{f.colors[k] ?? site.options.default_colors[k]}</code>
                                    </div>
                                </Col>
                            ))}
                        </Row>
                        <p className="text-muted fs-13">O âmbar nunca é texto sobre fundo claro: só em botões com texto azul escuro ou como texto sobre fundo escuro.</p>
                        <Row className="g-1">
                            {Object.entries(site.options.sections).map(([k, label]) => (
                                <Col key={k} md={6} xl={4}><Switch id={`sec-${k}`} label={label} checked={!!f.sections[k]} disabled={!canEdit} onChange={(v) => set("sections", { ...f.sections, [k]: v })} /></Col>
                            ))}
                            <Col md={12} className="mt-2"><Switch id="f-made" label='Mostrar "Feito com XPLENDOR" no rodapé' checked={f.show_made_with} disabled={!canEdit} onChange={(v) => set("show_made_with", v)} /></Col>
                        </Row>
                        <p className="text-muted fs-12 mt-2 mb-0">Uma secção ligada só aparece no site quando tem conteúdo real (por exemplo, testemunhos autorizados ou imóveis publicados).</p>
                    </div>
                </PageCard>
            </Container>
        </div>
    );
}
