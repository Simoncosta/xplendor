import { useRef, useState } from "react";
import { Badge, Button, Col, Input, Label, Row, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import ActionsMenu from "Components/Common/ActionsMenu";
import ReasonButton from "Components/Common/ReasonButton";
import { confirmAction } from "helpers/swal";
import { deleteListingImage, orderListingImages, updateListingImage, uploadListingImages } from "helpers/laravel_helper";
import type { ListingOwner } from "helpers/laravel_helper";
import { storageUrl, type ListingImage } from "common/models/consultantSite.model";

/**
 * Site do consultor: as fotografias públicas de um imóvel ou de um empreendimento. A primeira é a
 * capa; a ordem é a do site. O servidor tira os metadados (sem EXIF nem localização GPS) e gera as
 * larguras do site. Ficam também nos Ficheiros (pastas "Imóveis" e "Empreendimentos").
 */

const errorText = (e: any, fallback: string) => (e?.errors ? (Object.values(e.errors).flat()[0] as string) : null) || e?.message || fallback;

export default function ListingPhotos({ companyId, owner, ownerId, images, canEdit, editReason, onChange }: {
    companyId: number; owner: ListingOwner; ownerId: number; images: ListingImage[]; canEdit: boolean; editReason: string | null;
    onChange: (images: ListingImage[]) => void;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [alts, setAlts] = useState<Record<number, string>>({});

    const run = async (fn: () => Promise<any>, ok: string) => {
        setBusy(true);
        try {
            const r: any = await fn();
            onChange(r?.data ?? images);
            toast.success(r?.message ?? ok);
        } catch (e: any) {
            toast.error(errorText(e, "Não foi possível guardar as fotografias."));
        } finally {
            setBusy(false);
        }
    };
    const upload = (files: FileList | null) => {
        if (!files || files.length === 0) return;
        void run(() => uploadListingImages(companyId, owner, ownerId, Array.from(files)), "Fotografias guardadas.");
        if (input.current) input.current.value = "";
    };
    const move = (index: number, delta: number) => {
        const ids = images.map((i) => i.id);
        const to = index + delta;
        if (to < 0 || to >= ids.length) return;
        [ids[index], ids[to]] = [ids[to], ids[index]];
        void run(() => orderListingImages(companyId, owner, ownerId, ids, ids[0]), "Ordem guardada."); // a primeira é a capa
    };
    const makeCover = (img: ListingImage) => {
        const ids = [img.id, ...images.filter((i) => i.id !== img.id).map((i) => i.id)];
        void run(() => orderListingImages(companyId, owner, ownerId, ids, img.id), "Capa escolhida.");
    };
    const remove = async (img: ListingImage) => {
        if (!(await confirmAction({ title: "Apagar esta fotografia?", text: "Sai do site e dos Ficheiros.", confirmText: "Apagar", icon: "warning", confirmVariant: "danger" }))) return;
        void run(() => deleteListingImage(companyId, owner, ownerId, img.id), "Fotografia apagada.");
    };
    const saveAlt = (img: ListingImage) => {
        const value = alts[img.id];
        if (value === undefined || value === (img.alt ?? "")) return;
        void run(() => updateListingImage(companyId, owner, ownerId, img.id, value), "Descrição guardada.");
    };

    return (
        <div data-testid="listing-photos">
            <div className="d-flex align-items-center gap-2 mb-3 flex-wrap">
                <span className="text-muted fs-13">{images.length === 0 ? "Ainda sem fotografias." : `${images.length} ${images.length === 1 ? "fotografia" : "fotografias"}. A primeira é a capa.`}</span>
                <input ref={input} type="file" accept="image/jpeg,image/png,image/webp" multiple hidden data-testid="photos-input" onChange={(e) => upload(e.target.files)} />
                <ReasonButton size="sm" color="primary" className="ms-auto" reason={canEdit ? null : editReason} disabled={busy} onClick={() => input.current?.click()} data-testid="photos-add">
                    {busy ? <Spinner size="sm" /> : <><i className="ri-image-add-line me-1" />Carregar fotografias</>}
                </ReasonButton>
            </div>
            <p className="text-muted fs-12">JPEG, PNG ou WebP, até 10 MB cada. A localização e os outros metadados das fotografias são retirados ao guardar.</p>
            <Row className="g-3">
                {images.map((img, i) => (
                    <Col key={img.id} xs={6} md={4} xl={3}>
                        <div className="border rounded overflow-hidden h-100 d-flex flex-column">
                            <div className="position-relative" style={{ aspectRatio: "3 / 2", background: "var(--vz-secondary-bg)" }}>
                                <img src={storageUrl(img.thumb_url) ?? ""} alt={img.alt ?? `Fotografia ${i + 1}`} className="w-100 h-100" style={{ objectFit: "cover" }} loading="lazy" />
                                {img.is_cover && <Badge color="primary" className="position-absolute top-0 start-0 m-2">Capa</Badge>}
                            </div>
                            <div className="p-2 d-flex flex-column gap-2">
                                <div>
                                    <Label className="form-label fs-12 mb-1" for={`alt-${img.id}`}>Descrição (para leitores de ecrã)</Label>
                                    <Input id={`alt-${img.id}`} bsSize="sm" maxLength={160} disabled={!canEdit} value={alts[img.id] ?? img.alt ?? ""}
                                        onChange={(e) => setAlts((a) => ({ ...a, [img.id]: e.target.value }))} onBlur={() => saveAlt(img)} />
                                </div>
                                {canEdit && (
                                    <div className="d-flex gap-1 justify-content-end">
                                        <Button size="sm" color="secondary" outline disabled={busy || i === 0} onClick={() => move(i, -1)} aria-label={`Mover a fotografia ${i + 1} para a frente`}><i className="ri-arrow-left-line" /></Button>
                                        <Button size="sm" color="secondary" outline disabled={busy || i === images.length - 1} onClick={() => move(i, 1)} aria-label={`Mover a fotografia ${i + 1} para trás`}><i className="ri-arrow-right-line" /></Button>
                                        <ActionsMenu size="sm" label={`Mais ações: fotografia ${i + 1}`} items={[
                                            { label: "Usar como capa", icon: "ri-star-line", onClick: () => makeCover(img), hidden: img.is_cover },
                                            { label: "Apagar", icon: "ri-delete-bin-line", danger: true, onClick: () => void remove(img) },
                                        ]} />
                                    </div>
                                )}
                            </div>
                        </div>
                    </Col>
                ))}
            </Row>
        </div>
    );
}
