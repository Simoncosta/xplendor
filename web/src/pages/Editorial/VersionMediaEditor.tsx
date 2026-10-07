import { useRef, useState } from "react";
import { DragDropContext, Draggable, Droppable, type DropResult } from "@hello-pangea/dnd";
import { Badge, Button, Progress, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { setPostMedia } from "helpers/laravel_helper";
import { uploadMediaFile } from "helpers/mediaUpload";
import { MediaAssetDto, PostWorkflow, VersionMedia, fmtDuration, mediaSrc } from "common/models/editorialWorkflow.model";
import ReasonButton from "Components/Common/ReasonButton";

/**
 * Ficheiros da versão em edição: imagens e vídeos (até 10, por ordem), enviados em partes
 * e processados em fila; arrastar para ordenar; capa do vídeo (Reels e vídeos); erros e
 * avisos do formato vindos do servidor. Mudar os ficheiros de uma versão congelada cria a
 * versão seguinte (o servidor decide).
 */

const ACCEPT = "image/jpeg,image/png,image/webp,video/mp4,video/quicktime,.mov";
const COVER_FORMATS = ["ig_reel", "fb_reel", "fb_video"];

const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat().find((v) => typeof v === "string") : null;
    return (first as string) || e?.message || fallback;
};

type Uploading = { key: string; name: string; progress: number; error?: string };
type Props = {
    companyId: number;
    postId: number;
    media: VersionMedia;
    validation: PostWorkflow["media_validation"];
    mediaFormat: string | null;
    canEdit: boolean;
    onChanged: (detail: PostWorkflow) => void;
};

export default function VersionMediaEditor({ companyId, postId, media, validation, mediaFormat, canEdit, onChanged }: Props) {
    const [uploading, setUploading] = useState<Uploading[]>([]);
    const [saving, setSaving] = useState(false);
    const itemsRef = useRef<MediaAssetDto[]>(media.items);
    itemsRef.current = media.items;
    const fileInput = useRef<HTMLInputElement>(null);
    const coverInput = useRef<HTMLInputElement>(null);

    const save = async (items: number[], coverId: number | null) => {
        setSaving(true);
        try {
            const r: any = await setPostMedia(companyId, postId, items, coverId);
            onChanged(r.data);
            return true;
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível guardar os ficheiros."));
            return false;
        } finally {
            setSaving(false);
        }
    };

    const ids = (list: MediaAssetDto[]) => list.map((a) => a.id);

    const upload = async (files: FileList | null, asCover = false) => {
        if (!files?.length) return;
        for (const file of Array.from(files)) {
            const key = `${file.name}-${file.size}-${Date.now()}`;
            setUploading((u) => [...u, { key, name: file.name, progress: 0 }]);
            try {
                const asset = await uploadMediaFile(companyId, file, (p) => setUploading((u) => u.map((x) => (x.key === key ? { ...x, progress: p } : x))));
                if (asset.status === "rejected") throw new Error(asset.error ?? "Ficheiro recusado.");
                if (asCover) {
                    await save(ids(itemsRef.current), asset.id);
                } else if (!itemsRef.current.some((a) => a.id === asset.id)) {
                    await save([...ids(itemsRef.current), asset.id], media.cover?.id ?? null);
                }
                setUploading((u) => u.filter((x) => x.key !== key));
            } catch (e: any) {
                setUploading((u) => u.map((x) => (x.key === key ? { ...x, error: errorMessage(e, "Não foi possível enviar.") } : x)));
            }
        }
    };

    const onDragEnd = (r: DropResult) => {
        if (!r.destination || r.destination.index === r.source.index) return;
        const list = [...media.items];
        const [moved] = list.splice(r.source.index, 1);
        list.splice(r.destination.index, 0, moved);
        void save(ids(list), media.cover?.id ?? null);
    };

    const remove = (id: number) => void save(ids(media.items.filter((a) => a.id !== id)), media.cover?.id ?? null);

    return (
        <div className="mt-3">
            <div className="d-flex align-items-center justify-content-between mb-2">
                <h6 className="mb-0">Ficheiros <span className="text-muted fw-normal fs-12">({media.items.length}/10)</span></h6>
                {canEdit && (
                    <>
                        <input ref={fileInput} type="file" multiple accept={ACCEPT} className="d-none" onChange={(e) => { void upload(e.target.files); e.target.value = ""; }} />
                        <ReasonButton color="outline-primary" size="sm" disabled={saving} reason={media.items.length >= 10 ? "No máximo 10 ficheiros por versão." : null} onClick={() => fileInput.current?.click()}>
                            <i className="ri-upload-2-line me-1" />Adicionar ficheiros
                        </ReasonButton>
                    </>
                )}
            </div>

            {media.items.length === 0 && uploading.length === 0 && (
                <p className="text-muted fs-12 mb-2">Imagens JPEG, PNG ou WebP até 30 MB; vídeos MP4 ou MOV até 300 MB. O envio de vídeos retoma se a ligação falhar.</p>
            )}

            <DragDropContext onDragEnd={onDragEnd}>
                <Droppable droppableId="media" direction="horizontal" isDropDisabled={!canEdit}>
                    {(drop) => (
                        <div ref={drop.innerRef} {...drop.droppableProps} className="d-flex flex-wrap gap-2">
                            {media.items.map((a, i) => (
                                <Draggable key={a.id} draggableId={String(a.id)} index={i} isDragDisabled={!canEdit || saving}>
                                    {(drag) => (
                                        <div ref={drag.innerRef} {...drag.draggableProps} {...drag.dragHandleProps}
                                            className="position-relative rounded overflow-hidden border bg-light" style={{ width: 92, height: 92, ...drag.draggableProps.style }}
                                            title={`${i + 1}. ${a.original_name ?? ""}${a.width ? ` (${a.width} x ${a.height})` : ""}`}>
                                            {a.status === "ready" && <img src={mediaSrc(a.thumb_url)} alt="" className="w-100 h-100" style={{ objectFit: "cover" }} />}
                                            {a.status === "processing" && <div className="w-100 h-100 d-flex align-items-center justify-content-center"><Spinner size="sm" /></div>}
                                            {a.status === "rejected" && <div className="w-100 h-100 d-flex align-items-center justify-content-center text-danger fs-11 p-1 text-center">{a.error}</div>}
                                            <span className="position-absolute top-0 start-0 m-1 badge bg-dark bg-opacity-75">{i + 1}</span>
                                            {a.kind === "video" && <span className="position-absolute bottom-0 start-0 m-1 badge bg-dark bg-opacity-75"><i className="ri-play-fill" />{fmtDuration(a.duration_ms)}</span>}
                                            {canEdit && (
                                                <button type="button" aria-label="Remover" className="btn btn-sm btn-danger position-absolute top-0 end-0 m-1 p-0 lh-1" style={{ width: 20, height: 20 }}
                                                    disabled={saving} onClick={() => remove(a.id)}><i className="ri-close-line" /></button>
                                            )}
                                        </div>
                                    )}
                                </Draggable>
                            ))}
                            {drop.placeholder}
                        </div>
                    )}
                </Droppable>
            </DragDropContext>
            {media.items.length > 1 && canEdit && <p className="text-muted fs-11 mt-1 mb-0">Arraste para mudar a ordem.</p>}

            {uploading.map((u) => (
                <div key={u.key} className="mt-2 fs-12">
                    <div className="d-flex justify-content-between"><span className="text-truncate">{u.name}</span><span>{u.error ? "" : `${Math.round(u.progress * 100)}%`}</span></div>
                    {u.error
                        ? <div className="text-danger">{u.error} <button type="button" className="btn btn-link btn-sm p-0" onClick={() => setUploading((x) => x.filter((y) => y.key !== u.key))}>fechar</button></div>
                        : <Progress value={u.progress * 100} style={{ height: 4 }} />}
                </div>
            ))}

            {mediaFormat && COVER_FORMATS.includes(mediaFormat) && (
                <div className="d-flex align-items-center gap-2 mt-3 fs-13">
                    <strong>Capa:</strong>
                    {media.cover ? (
                        <>
                            <img src={mediaSrc(media.cover.thumb_url)} alt="" className="rounded border" style={{ width: 40, height: 40, objectFit: "cover" }} />
                            {canEdit && <Button color="link" size="sm" className="p-0" disabled={saving} onClick={() => void save(ids(media.items), null)}>Usar um instante do vídeo</Button>}
                        </>
                    ) : <span className="text-muted">um instante do vídeo</span>}
                    {canEdit && (
                        <>
                            <input ref={coverInput} type="file" accept="image/jpeg,image/png,image/webp" className="d-none" onChange={(e) => { void upload(e.target.files, true); e.target.value = ""; }} />
                            <Button color="outline-primary" size="sm" disabled={saving} onClick={() => coverInput.current?.click()}>Escolher imagem de capa</Button>
                        </>
                    )}
                </div>
            )}

            {(validation.errors.length > 0 || validation.warnings.length > 0) && (
                <ul className="list-unstyled fs-12 mt-2 mb-0">
                    {validation.errors.map((m) => <li key={m} className="text-danger"><i className="ri-close-circle-line me-1" />{m}</li>)}
                    {validation.warnings.map((m) => <li key={m} className="text-warning"><i className="ri-error-warning-line me-1" />{m}</li>)}
                </ul>
            )}
            {validation.errors.length > 0 && <Badge color="danger-subtle" className="text-danger fw-normal mt-1">Os erros impedem o envio</Badge>}
        </div>
    );
}
