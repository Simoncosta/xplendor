import { useCallback, useEffect, useState } from "react";
import { Badge, Button, Col, Container, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Row, Spinner, Table } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import ConfirmModal from "Components/Common/ConfirmModal";
import ReasonButton from "Components/Common/ReasonButton";
import FilePickerModal from "pages/Files/FilePickerModal";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import { createSitePopup, deleteSitePopup, getSitePopup, getSitePopups, updateSitePopup } from "helpers/laravel_helper";
import { fileSrc, StoredFileDto } from "common/models/files.model";
import PopupImageCropper, { CropPixels, CropState, PopupDevicePreviews, RECOMMENDED } from "./PopupImageCropper";
import { apiErrorMessage } from "helpers/apiError";

/**
 * "Avisos do site": os avisos (pop-up) que o site da empresa mostra aos visitantes (os stands e
 * os sites dos consultores, pela API pública). Vários podem estar programados: o site mostra, em
 * cada página, o primeiro da lista (por prioridade) que se aplica a essa página. As contagens
 * (vistas, cliques e fechos) são só números, sem dados de quem visita.
 */

export interface SitePopupDto {
    id: number; title: string; body: string | null; image_file_id: number | null; image_path: string | null; image_url: string | null;
    button_label: string | null; button_url: string | null; starts_at: string | null; ends_at: string | null; pages: string[];
    delay_seconds: number; frequency: "once_per_visitor" | "once_per_day" | "once_per_session"; priority: number; is_active: boolean;
    state: "ativo" | "programado" | "terminado" | "desligado"; overlaps: number[];
    stats: { days: number; views: number; clicks: number; closes: number; click_rate: number | null; series?: { day: string; views: number; clicks: number; closes: number }[] };
}

export const PAGE_LABEL: Record<string, string> = { all: "Todas as páginas", home: "Página inicial", vehicles: "Lista das viaturas", vehicle: "Fichas das viaturas" };
export const FREQ_LABEL: Record<string, string> = {
    once_per_visitor: "Uma vez por visitante (volta a aparecer se o aviso for editado)", once_per_day: "Uma vez por dia", once_per_session: "Uma vez por visita",
};
const STATE: Record<SitePopupDto["state"], { label: string; color: string }> = {
    ativo: { label: "Ativo", color: "success" }, programado: { label: "Programado", color: "info" }, terminado: { label: "Terminado", color: "secondary" }, desligado: { label: "Desligado", color: "light" },
};
const PUBLIC_URL = process.env.REACT_APP_PUBLIC_URL ?? "";
const plural = (n: number, one: string, many: string) => `${n.toLocaleString("pt-PT")} ${n === 1 ? one : many}`;
const fmt = (s?: string | null) => (s ? new Date(s).toLocaleString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "");
const toLocalInput = (s?: string | null) => {
    if (!s) return "";
    const d = new Date(s);
    const pad = (n: number) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};
const errorOf = (e: any, fallback: string): string => apiErrorMessage(e, fallback);

interface Payload { popups: SitePopupDto[]; can: { create: boolean; edit: boolean; delete: boolean } }

export default function SitePopupsPage() {
    const companyId = useWorkingCompanyId();
    const [data, setData] = useState<Payload | null>(null);
    const [loading, setLoading] = useState(true);
    const [editing, setEditing] = useState<SitePopupDto | "new" | null>(null);
    const [removing, setRemoving] = useState<SitePopupDto | null>(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => {
        if (!companyId) return;
        setLoading(true);
        getSitePopups(companyId).then((r: any) => setData(r?.data ?? null)).catch(() => setData(null)).finally(() => setLoading(false));
    }, [companyId]);
    useEffect(() => { load(); }, [load]);

    document.title = "Avisos do site | Xplendor";
    const titleOf = (id: number) => data?.popups.find((p) => p.id === id)?.title ?? `n.º ${id}`;

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Avisos do site" breadcrumbs={[{ label: "Marketing" }]}
                    info="Os avisos (pop-up) que o site mostra aos visitantes. Em cada página, o site mostra o primeiro aviso ativo da lista (por prioridade) que se aplica a essa página." />
                <PageCard title="Avisos" loading={loading && !!data}
                    status={data ? <>{data.popups.filter((p) => p.state === "ativo").length} ativo(s)</> : undefined}
                    actions={<ReasonButton color="primary" onClick={() => setEditing("new")} reason={data && !data.can.create ? "O seu perfil não permite criar avisos." : null} disabled={!data}>
                        <i className="ri-add-line me-1" />Novo aviso
                    </ReasonButton>}>
                    {loading && !data ? <div className="text-center py-4"><Spinner /></div> : null}
                    {data && data.popups.length === 0 && <p className="text-muted p-3 mb-0" data-testid="popups-empty">Ainda não há avisos. Crie o primeiro para o site mostrar uma campanha, um evento ou uma novidade.</p>}
                    {data && data.popups.length > 0 && (
                        <div className="table-responsive">
                            <Table className="align-middle mb-0" data-testid="popups-table">
                                <thead className="table-light"><tr><th>Aviso</th><th>Estado</th><th>Quando</th><th>Onde</th><th className="text-end">Prioridade</th><th className="text-end">Últimos 30 dias</th><th className="text-end">Ações</th></tr></thead>
                                <tbody>
                                    {data.popups.map((p) => (
                                        <tr key={p.id} data-testid={`popup-${p.id}`}>
                                            <td style={{ maxWidth: 260 }}>
                                                <div className="d-flex align-items-center gap-2">
                                                    {p.image_path ? <img src={`${PUBLIC_URL}${p.image_path}`} alt="" style={{ width: 48, height: 32, objectFit: "cover" }} className="rounded" /> : null}
                                                    <div className="text-truncate"><strong>{p.title}</strong><div className="text-muted fs-12">{FREQ_LABEL[p.frequency].split(" (")[0]} · abre ao fim de {p.delay_seconds} s</div></div>
                                                </div>
                                                {p.overlaps.length > 0 && <div className="text-warning fs-12 mt-1"><i className="ri-error-warning-line me-1" />Sobrepõe-se a {p.overlaps.map(titleOf).join(", ")}: aparece o de maior prioridade.</div>}
                                            </td>
                                            <td><Badge color={STATE[p.state].color} className={p.state === "desligado" ? "text-body" : ""}>{STATE[p.state].label}</Badge></td>
                                            <td className="fs-13">{p.starts_at || p.ends_at ? <>{p.starts_at ? `De ${fmt(p.starts_at)}` : "Desde já"}<br />{p.ends_at ? `até ${fmt(p.ends_at)}` : "sem fim"}</> : "Sempre"}</td>
                                            <td className="fs-13">{p.pages.map((x) => PAGE_LABEL[x] ?? x).join(", ")}</td>
                                            <td className="text-end">{p.priority}</td>
                                            <td className="text-end fs-13 text-nowrap">{plural(p.stats.views, "vista", "vistas")} · {plural(p.stats.clicks, "clique", "cliques")} · {plural(p.stats.closes, "fecho", "fechos")}{p.stats.click_rate !== null ? <><br /><span className="text-muted">{p.stats.click_rate.toLocaleString("pt-PT")}% de cliques</span></> : null}</td>
                                            <td className="text-end text-nowrap">
                                                <Button size="sm" color="outline-primary" onClick={() => setEditing(p)} aria-label={`Editar ${p.title}`}><i className="ri-edit-line" /></Button>
                                                {data.can.delete && <Button size="sm" color="outline-danger" className="ms-1" onClick={() => setRemoving(p)} aria-label={`Apagar ${p.title}`}><i className="ri-delete-bin-line" /></Button>}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </div>
                    )}
                    <p className="text-muted fs-12 p-3 mb-0">As contagens são só números (vistas, cliques e fechos), sem cookies nem dados de quem visita; um visitante sem JavaScript não conta.</p>
                </PageCard>
            </Container>
            {editing && companyId && (
                <PopupEditor companyId={companyId} popup={editing === "new" ? null : editing} canEdit={editing === "new" ? !!data?.can.create : !!data?.can.edit}
                    onClose={() => setEditing(null)} onSaved={() => { setEditing(null); load(); }} />
            )}
            <ConfirmModal isOpen={!!removing} loading={busy} variant="danger" title="Apagar o aviso?" confirmText="Apagar"
                message={`O site deixa de mostrar "${removing?.title}" e as contagens são apagadas. A cópia pública da imagem também é apagada.`}
                onCancel={() => setRemoving(null)} onConfirm={async () => {
                    if (!removing || !companyId) return;
                    setBusy(true);
                    try { await deleteSitePopup(companyId, removing.id); toast.success("Aviso apagado."); setRemoving(null); load(); }
                    catch (e) { toast.error(errorOf(e, "Não foi possível apagar.")); }
                    finally { setBusy(false); }
                }} />
        </div>
    );
}

function PopupEditor({ companyId, popup, canEdit, onClose, onSaved }: { companyId: number; popup: SitePopupDto | null; canEdit: boolean; onClose: () => void; onSaved: () => void }) {
    const [title, setTitle] = useState(popup?.title ?? "");
    const [body, setBody] = useState(popup?.body ?? "");
    const [buttonLabel, setButtonLabel] = useState(popup?.button_label ?? "");
    const [buttonUrl, setButtonUrl] = useState(popup?.button_url ?? "");
    const [startsAt, setStartsAt] = useState(toLocalInput(popup?.starts_at));
    const [endsAt, setEndsAt] = useState(toLocalInput(popup?.ends_at));
    const [pages, setPages] = useState<string[]>(popup?.pages ?? ["all"]);
    const [delay, setDelay] = useState(popup?.delay_seconds ?? 3);
    const [frequency, setFrequency] = useState<string>(popup?.frequency ?? "once_per_visitor");
    const [priority, setPriority] = useState(popup?.priority ?? 0);
    const [active, setActive] = useState(popup?.is_active ?? true);
    const [image, setImage] = useState<{ id: number | null; src: string | null; remove: boolean; original?: string | null }>({ id: popup?.image_file_id ?? null, src: popup?.image_path ? `${PUBLIC_URL}${popup.image_path}` : null, remove: false });
    // O recorte 4:3 da imagem escolhida agora (o servidor recorta, redimensiona e comprime).
    const [crop, setCrop] = useState<CropState | null>(null);
    const [picking, setPicking] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [series, setSeries] = useState<SitePopupDto["stats"]["series"] | null>(null);

    useEffect(() => {
        if (popup) getSitePopup(companyId, popup.id).then((r: any) => setSeries(r?.data?.stats?.series ?? null)).catch(() => setSeries(null));
    }, [companyId, popup]);

    const togglePage = (p: string) => {
        if (p === "all") { setPages(["all"]); return; }
        setPages((list) => { const next = list.filter((x) => x !== "all"); return next.includes(p) ? next.filter((x) => x !== p) : [...next, p]; });
    };

    const pick = (f: StoredFileDto) => {
        if (f.kind !== "imagem" || !["image/jpeg", "image/png", "image/webp"].includes(String(f.mime))) {
            toast.error("Escolha uma imagem JPG, PNG ou WebP.");
            return;
        }
        setImage({ id: f.id, src: fileSrc(f.thumb_url ?? f.url) ?? null, remove: false, original: fileSrc(f.url ?? f.preview_url) ?? null });
        setCrop(null);
        setPicking(false);
    };

    const save = async () => {
        setSaving(true);
        setError(null);
        const payload: any = {
            title: title.trim(), body: body.trim() || null, button_label: buttonLabel.trim() || null, button_url: buttonUrl.trim() || null,
            starts_at: startsAt ? new Date(startsAt).toISOString() : null, ends_at: endsAt ? new Date(endsAt).toISOString() : null,
            pages, delay_seconds: delay, frequency, priority, is_active: active,
            // Uma imagem escolhida agora vai sempre (mesmo que seja a mesma): o recorte pode ter mudado.
            image_file_id: image.original ? image.id ?? undefined : (image.id && image.id !== popup?.image_file_id ? image.id : undefined), remove_image: image.remove || undefined,
            image_crop: image.original && crop ? crop.fraction : undefined,
        };
        try {
            if (popup) await updateSitePopup(companyId, popup.id, payload); else await createSitePopup(companyId, payload);
            toast.success(popup ? "Aviso guardado." : "Aviso criado.");
            onSaved();
        } catch (e) {
            setError(errorOf(e, "Não foi possível guardar."));
        } finally {
            setSaving(false);
        }
    };

    const maxViews = Math.max(1, ...(series ?? []).map((d) => d.views));

    return (
        <Modal isOpen toggle={!saving ? onClose : undefined} size="xl" centered scrollable>
            <ModalHeader toggle={!saving ? onClose : undefined}>{popup ? "Editar aviso" : "Novo aviso"}</ModalHeader>
            <ModalBody>
                <Row className="g-4">
                    <Col lg={7}>
                        <Label for="pop-title" className="form-label">Título</Label>
                        <Input id="pop-title" value={title} maxLength={120} onChange={(e) => setTitle(e.target.value)} disabled={!canEdit} />
                        <Label for="pop-body" className="form-label mt-3">Texto</Label>
                        <Input id="pop-body" type="textarea" rows={4} value={body} maxLength={1000} onChange={(e) => setBody(e.target.value)} disabled={!canEdit} />
                        <small className="text-muted">Texto simples; as mudanças de linha mantêm-se.</small>

                        <Label className="form-label d-block mt-3">Imagem (opcional)</Label>
                        <div className="d-flex align-items-center gap-2">
                            {image.src && !image.remove ? <img src={image.src} alt="" style={{ width: 96, height: 64, objectFit: "cover" }} className="rounded border" /> : <span className="text-muted fs-13">Sem imagem</span>}
                            <Button size="sm" color="outline-primary" onClick={() => setPicking(true)} disabled={!canEdit}>Escolher nos Ficheiros</Button>
                            {image.src && !image.remove && <Button size="sm" color="outline-secondary" onClick={() => setImage({ id: null, src: null, remove: true })} disabled={!canEdit}>Tirar</Button>}
                        </div>
                        <small className="text-muted d-block" data-testid="popup-recommended">Recomendado: {RECOMMENDED.width} × {RECOMMENDED.height} px (4:3), até {RECOMMENDED.kb} KB. O site publica {RECOMMENDED.width} × {RECOMMENDED.height} px; o original fica nos Ficheiros.</small>
                        {image.original && !image.remove && canEdit && (
                            <div className="mt-2">
                                <PopupImageCropper src={image.original} onChange={setCrop} />
                                <CropPixels state={crop} />
                            </div>
                        )}

                        <Row className="g-2 mt-2">
                            <Col sm={5}><Label for="pop-btn" className="form-label">Botão: texto (opcional)</Label><Input id="pop-btn" value={buttonLabel} maxLength={60} onChange={(e) => setButtonLabel(e.target.value)} disabled={!canEdit} /></Col>
                            <Col sm={7}><Label for="pop-url" className="form-label">Botão: ligação</Label><Input id="pop-url" value={buttonUrl} maxLength={500} placeholder="https://… ou /viaturas" onChange={(e) => setButtonUrl(e.target.value)} disabled={!canEdit} /></Col>
                        </Row>
                        <Row className="g-2 mt-2">
                            <Col sm={6}><Label for="pop-start" className="form-label">Início (opcional)</Label><Input id="pop-start" type="datetime-local" value={startsAt} onChange={(e) => setStartsAt(e.target.value)} disabled={!canEdit} /></Col>
                            <Col sm={6}><Label for="pop-end" className="form-label">Fim (opcional)</Label><Input id="pop-end" type="datetime-local" value={endsAt} onChange={(e) => setEndsAt(e.target.value)} disabled={!canEdit} /></Col>
                        </Row>
                        <Label className="form-label d-block mt-3">Onde aparece</Label>
                        <div className="d-flex flex-wrap gap-3">
                            {Object.keys(PAGE_LABEL).map((p) => (
                                <div className="form-check" key={p}>
                                    <Input type="checkbox" className="form-check-input" id={`pop-page-${p}`} checked={pages.includes(p)} onChange={() => togglePage(p)} disabled={!canEdit} />
                                    <Label className="form-check-label" for={`pop-page-${p}`}>{PAGE_LABEL[p]}</Label>
                                </div>
                            ))}
                        </div>
                        <Row className="g-2 mt-2">
                            <Col sm={7}>
                                <Label for="pop-freq" className="form-label">Frequência</Label>
                                <Input id="pop-freq" type="select" value={frequency} onChange={(e) => setFrequency(e.target.value)} disabled={!canEdit}>
                                    {Object.entries(FREQ_LABEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                </Input>
                            </Col>
                            <Col sm={3}><Label for="pop-delay" className="form-label">Abre ao fim de (s)</Label><Input id="pop-delay" type="number" min={0} max={120} value={delay} onChange={(e) => setDelay(Math.max(0, Math.min(120, Number(e.target.value) || 0)))} disabled={!canEdit} /></Col>
                            <Col sm={2}><Label for="pop-prio" className="form-label">Prioridade</Label><Input id="pop-prio" type="number" min={-100} max={100} value={priority} onChange={(e) => setPriority(Number(e.target.value) || 0)} disabled={!canEdit} /></Col>
                        </Row>
                        <div className="form-check form-switch mt-3">
                            <Input type="checkbox" className="form-check-input" id="pop-active" checked={active} onChange={(e) => setActive(e.target.checked)} disabled={!canEdit} />
                            <Label className="form-check-label" for="pop-active">Ligado</Label>
                        </div>
                        {error && <div className="alert alert-danger mt-3 mb-0 py-2" role="alert">{error}</div>}
                    </Col>
                    <Col lg={5}>
                        <Label className="form-label">Pré-visualização</Label>
                        <div className="border rounded p-3 bg-light" data-testid="popup-preview">
                            <div className="bg-white rounded shadow-sm p-3 mx-auto" style={{ maxWidth: 360 }}>
                                {image.src && !image.remove ? <img src={image.src} alt="" style={{ width: "100%", maxHeight: 180, objectFit: "cover" }} className="rounded mb-2" /> : null}
                                <h5 className="mb-2">{title || "Título do aviso"}</h5>
                                {body ? <p className="mb-2" style={{ whiteSpace: "pre-wrap" }}>{body}</p> : null}
                                {buttonLabel && buttonUrl ? <span className="btn btn-primary btn-sm">{buttonLabel}</span> : null}
                            </div>
                        </div>
                        {(image.original || image.src) && !image.remove && (
                            <div className="mt-3">
                                <Label className="form-label">A imagem no site</Label>
                                <PopupDevicePreviews src={(image.original ?? image.src) as string} fraction={image.original ? crop?.fraction ?? null : null} />
                            </div>
                        )}
                        {series && (
                            <div className="mt-3" data-testid="popup-series">
                                <Label className="form-label">Vistas nos últimos 30 dias</Label>
                                <div className="d-flex align-items-end gap-1" style={{ height: 80 }} aria-label="Vistas por dia">
                                    {series.map((d) => <div key={d.day} title={`${d.day}: ${d.views} vistas, ${d.clicks} cliques, ${d.closes} fechos`} className="bg-primary rounded-top" style={{ flex: 1, height: `${Math.max(2, (d.views / maxViews) * 100)}%`, opacity: d.views ? 1 : 0.15 }} />)}
                                </div>
                            </div>
                        )}
                    </Col>
                </Row>
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onClose} disabled={saving}>Cancelar</Button>
                {canEdit && <Button color="primary" onClick={save} disabled={saving || !title.trim() || pages.length === 0}>{saving ? <Spinner size="sm" /> : "Guardar"}</Button>}
            </ModalFooter>
            <FilePickerModal isOpen={picking} companyId={companyId} busy={false} onClose={() => setPicking(false)} onPick={pick} />
        </Modal>
    );
}
