import { useEffect, useMemo, useState } from "react";
import { Button, Input, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { getFiles } from "helpers/laravel_helper";
import { FileFolderDto, FilesPayload, KIND_META, StoredFileDto, SYSTEM_ICON, fileSrc, fmtBytes } from "common/models/files.model";

/**
 * "Escolher dos Ficheiros" (Linha Editorial): navegar pelas pastas a que a pessoa tem acesso e
 * escolher uma imagem ou um vídeo. O ficheiro não é copiado: a publicação usa a mesma media.
 * E-mail 5b: mode="attachment" escolhe qualquer ficheiro para anexar a um e-mail (sem os documentos
 * de financiamento nem as fotografias das retomas, que não saem por e-mail).
 */
export default function FilePickerModal({ isOpen, companyId, busy, onClose, onPick, mode = "media" }: {
    isOpen: boolean; companyId: number; busy: boolean; onClose: () => void; onPick: (file: StoredFileDto) => void; mode?: "media" | "attachment";
}) {
    const attachment = mode === "attachment";
    const [folderId, setFolderId] = useState<number | null>(null);
    const [q, setQ] = useState("");
    const [search, setSearch] = useState("");
    const [data, setData] = useState<FilesPayload | null>(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => { if (isOpen) { setFolderId(null); setQ(""); setSearch(""); } }, [isOpen]);
    useEffect(() => { const t = setTimeout(() => setSearch(q.trim()), 300); return () => clearTimeout(t); }, [q]);
    useEffect(() => {
        if (!isOpen || !companyId) return;
        let alive = true;
        setLoading(true);
        getFiles(companyId, { folder_id: folderId, q: search }).then((r: any) => { if (alive) setData(r?.data ?? null); })
            .catch(() => { if (alive) setData(null); }).finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [isOpen, companyId, folderId, search]);

    const files = useMemo(() => [...(data?.files ?? []), ...(data?.shortcuts ?? [])].filter((f) => (attachment
        ? f.source !== "financiamento" && f.source !== "retoma"
        : f.kind === "imagem" || f.kind === "video")), [data, attachment]);
    const folders: FileFolderDto[] = search ? [] : data?.folders ?? [];
    const path = data?.folder?.path ?? [];

    return (
        <Modal isOpen={isOpen} toggle={() => !busy && onClose()} centered size="lg" scrollable>
            <ModalHeader toggle={() => !busy && onClose()}>Escolher dos Ficheiros</ModalHeader>
            <ModalBody>
                <div className="d-flex flex-wrap gap-2 align-items-center mb-3">
                    <div className="flex-grow-1 fs-13 text-muted text-truncate" data-testid="picker-path">
                        <button type="button" className="btn btn-link p-0 fs-13" onClick={() => setFolderId(null)}>Ficheiros</button>
                        {path.map((p) => <span key={p.id}> › <button type="button" className="btn btn-link p-0 fs-13" onClick={() => setFolderId(p.id)}>{p.name}</button></span>)}
                    </div>
                    <Input bsSize="sm" style={{ maxWidth: 240 }} placeholder="Pesquisar…" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Pesquisar ficheiros" />
                </div>
                {loading && <Spinner size="sm" />}
                <div className="row g-2">
                    {folders.map((f) => (
                        <div key={`d${f.id}`} className="col-6 col-md-3">
                            <button type="button" className="btn btn-light w-100 text-start h-100" onClick={() => setFolderId(f.id)}>
                                <i className={`${f.system ? SYSTEM_ICON[f.system_root ?? ""] ?? "ri-folder-2-fill" : "ri-folder-2-fill"} text-warning fs-20 d-block`} aria-hidden />
                                <span className="fs-13 text-truncate d-block">{f.name}</span>
                            </button>
                        </div>
                    ))}
                    {files.map((f) => (
                        <div key={f.shortcut_id ? `s${f.shortcut_id}` : f.id} className="col-6 col-md-3">
                            <button type="button" className="btn btn-light border w-100 p-1 text-start h-100" disabled={busy} onClick={() => onPick(f)} aria-label={`Escolher: ${f.name}`}>
                                <div className="bg-light rounded d-flex align-items-center justify-content-center overflow-hidden" style={{ height: 90 }}>
                                    {f.thumb_url ? <img src={fileSrc(f.thumb_url)} alt="" style={{ width: "100%", height: "100%", objectFit: "cover" }} />
                                        : <i className={`${KIND_META[f.kind].icon} text-${KIND_META[f.kind].color} display-6`} aria-hidden />}
                                </div>
                                <div className="fs-12 text-truncate mt-1">{f.name}</div>
                                <div className="fs-11 text-muted">{fmtBytes(f.size_bytes)}</div>
                            </button>
                        </div>
                    ))}
                    {!loading && folders.length === 0 && files.length === 0 && <p className="text-muted fs-13 mb-0">{attachment ? (search ? `Nenhum ficheiro com "${search}".` : "Sem ficheiros nesta pasta.") : search ? `Nenhuma imagem ou vídeo com "${search}".` : "Sem imagens nem vídeos nesta pasta."}</p>}
                </div>
                <p className="text-muted fs-12 mt-3 mb-0">{attachment
                    ? "O ficheiro é anexado à mensagem (uma cópia temporária, apagada depois do envio). O original fica nos Ficheiros."
                    : "Só imagens (JPEG, PNG, WebP) e vídeos (MP4, MOV). O ficheiro não é copiado: a publicação usa o mesmo ficheiro, e ele passa a mostrar que está em uso."}</p>
            </ModalBody>
            <ModalFooter>
                <Button color="light" disabled={busy} onClick={onClose}>{busy ? <Spinner size="sm" /> : "Cancelar"}</Button>
            </ModalFooter>
        </Modal>
    );
}
