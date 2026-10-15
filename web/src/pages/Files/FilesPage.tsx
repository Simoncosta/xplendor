import React, { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Link } from "react-router-dom";
import { Button, Card, CardBody, Col, Container, Input, Progress, Row, Spinner } from "reactstrap";
import SimpleBar from "simplebar-react";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import ActionsMenu from "Components/Common/ActionsMenu";
import DataTable, { DTColumn, useDataColumns } from "Components/Common/DataTable";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";
import {
    createFileFolder, deleteFileFolder, deleteFileShortcut, deleteStoredFile, getFileDetail, getFiles, getFilesTrash, restoreFileFolder, restoreFileShortcut,
    restoreStoredFile, saveFileToFolder, sendFileMediaChunk, startFileMediaUpload, updateFileFolder, updateStoredFile, uploadFileDocument,
} from "helpers/laravel_helper";
import {
    FileDetailDto, FileFolderDto, FilesPayload, FilesTrashPayload, KIND_META, MEDIA_EXTENSIONS, StoredFileDto, SYSTEM_ICON, fileSrc, fmtBytes, fmtDate,
} from "common/models/files.model";
import { ConfirmModal, FolderPickerModal, NameModal } from "./FileModals";
import "./files.css";
import Skeleton from "Components/Common/Skeleton";

/**
 * Ficheiros: o gestor de ficheiros da empresa, a partir da página File Manager do Velzon (pastas à
 * esquerda, conteúdo ao centro, pré-visualização à direita). As pastas das pessoas e as do
 * sistema (cada uma com a permissão da sua área), lista e grelha, pesquisa, envio (documentos;
 * imagens e vídeos em partes, com miniaturas), mudar o nome, mover, "Guardar numa pasta", onde
 * cada ficheiro está em uso, espaço usado e reciclagem (30 dias).
 */

type Layout = "lista" | "grelha";
type Upload = { key: string; name: string; progress: number; error?: string };
type Dialog =
    | { kind: "new-folder" }
    | { kind: "rename-folder"; folder: FileFolderDto }
    | { kind: "rename-file"; file: StoredFileDto }
    | { kind: "move-folder"; folder: FileFolderDto }
    | { kind: "move-file"; file: StoredFileDto }
    | { kind: "save-to"; file: StoredFileDto }
    | { kind: "delete-folder"; folder: FileFolderDto }
    | { kind: "delete-file"; file: StoredFileDto }
    | null;

const LAYOUT_KEY = "xp.files.layout";
const readLayout = (): Layout => {
    try {
        return window.localStorage.getItem(LAYOUT_KEY) === "grelha" ? "grelha" : "lista";
    } catch {
        return "lista";
    }
};

const errorOf = (e: any, fallback: string): string => {
    const body = e?.response?.data ?? e;
    const first = body?.errors ? (Object.values(body.errors).flat()[0] as string) : null;
    return first || body?.message || fallback;
};

const wait = (ms: number) => new Promise((r) => setTimeout(r, ms));

export default function FilesPage() {
    const companyId = useWorkingCompanyId();
    const [folderId, setFolderId] = useState<number | null>(null);
    const [trashView, setTrashView] = useState(false);
    const [q, setQ] = useState("");
    const [search, setSearch] = useState("");
    const [data, setData] = useState<FilesPayload | null>(null);
    const [trash, setTrash] = useState<FilesTrashPayload | null>(null);
    const [loading, setLoading] = useState(true);
    const [layout, setLayout] = useState<Layout>(readLayout);
    const [selected, setSelected] = useState<StoredFileDto | null>(null);
    const [detail, setDetail] = useState<FileDetailDto | null>(null);
    const [uploads, setUploads] = useState<Upload[]>([]);
    const [dialog, setDialog] = useState<Dialog>(null);
    const [busy, setBusy] = useState(false);
    const [menuOpen, setMenuOpen] = useState(false);
    const [openRoots, setOpenRoots] = useState<Record<string, boolean>>({ mine: true, system: true });
    const inputRef = useRef<HTMLInputElement>(null);

    const load = useCallback(async () => {
        if (!companyId) return;
        setLoading(true);
        try {
            if (trashView) {
                const r: any = await getFilesTrash(companyId);
                setTrash(r?.data ?? null);
            }
            const r: any = await getFiles(companyId, { folder_id: trashView ? null : folderId, q: trashView ? "" : search });
            setData(r?.data ?? null);
        } catch (e) {
            toast.error(errorOf(e, "Não foi possível abrir os ficheiros."));
            if (folderId) setFolderId(null);
        } finally {
            setLoading(false);
        }
    }, [companyId, folderId, search, trashView]);
    useEffect(() => { void load(); }, [load]);

    // Pesquisa com uma pequena pausa enquanto se escreve.
    useEffect(() => { const t = setTimeout(() => setSearch(q.trim()), 350); return () => clearTimeout(t); }, [q]);

    useEffect(() => {
        if (!selected || !companyId) { setDetail(null); return; }
        let alive = true;
        getFileDetail(companyId, selected.id).then((r: any) => { if (alive) setDetail(r?.data ?? null); }).catch(() => { if (alive) setDetail(null); });
        return () => { alive = false; };
    }, [selected, companyId]);

    const tree = useMemo(() => data?.tree ?? [], [data]);
    const byParent = useMemo(() => {
        const m = new Map<number | null, FileFolderDto[]>();
        tree.forEach((f) => m.set(f.parent_id, [...(m.get(f.parent_id) ?? []), f]));
        return m;
    }, [tree]);
    const userFolders = useMemo(() => tree.filter((f) => !f.system), [tree]);
    const current = data?.folder ?? null;
    const inSystem = !!current?.system;
    const canUpload = !!data?.can.create && !inSystem && !trashView && !search;

    const open = (id: number | null) => { setTrashView(false); setQ(""); setSearch(""); setFolderId(id); setSelected(null); setMenuOpen(false); };
    const changeLayout = (l: Layout) => { setLayout(l); try { window.localStorage.setItem(LAYOUT_KEY, l); } catch { /* sem armazenamento */ } };

    // ── Envio ────────────────────────────────────────────────────────────────

    const uploadOne = async (file: File) => {
        const key = `${file.name}-${file.size}-${Date.now()}`;
        const set = (patch: Partial<Upload>) => setUploads((u) => u.map((x) => (x.key === key ? { ...x, ...patch } : x)));
        setUploads((u) => [...u, { key, name: file.name, progress: 0 }]);
        const ext = (file.name.split(".").pop() ?? "").toLowerCase();
        try {
            if (MEDIA_EXTENSIONS.includes(ext)) {
                const mime = file.type || (ext === "mov" ? "video/quicktime" : "");
                const start: any = await startFileMediaUpload(companyId, { name: file.name, size: file.size, mime, folder_id: folderId });
                const id: string = start.data.id;
                const chunk: number = start.data.chunk_bytes;
                let offset = 0;
                let done = false;
                let retries = 0;
                while (!done && offset < file.size) {
                    const end = Math.min(offset + chunk, file.size);
                    try {
                        const base = offset;
                        const r: any = await sendFileMediaChunk(companyId, id, base, file.slice(base, end), (loaded) => set({ progress: Math.min(1, (base + loaded) / file.size) }));
                        offset = r.data.received_bytes;
                        done = !!r.data.completed;
                        retries = 0;
                    } catch (e: any) {
                        if (e?.__status === 409 && typeof e?.errors?.received_bytes === "number") { offset = e.errors.received_bytes; continue; }
                        if ((e?.__status && e.__status < 500 && e.__status !== 429) || ++retries > 5) throw e;
                        await wait(1000 * retries);
                    }
                }
            } else {
                await uploadFileDocument(companyId, file, folderId, (f) => set({ progress: f }));
            }
            setUploads((u) => u.filter((x) => x.key !== key));
        } catch (e) {
            set({ error: errorOf(e, "Não foi possível enviar.") });
        }
    };

    const onPick = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const files = Array.from(e.target.files ?? []);
        e.target.value = "";
        for (const f of files) {
            await uploadOne(f); // um de cada vez (o envio em partes já usa a ligação toda)
        }
        void load();
    };

    // ── Ações ────────────────────────────────────────────────────────────────

    const run = async (fn: () => Promise<any>, ok: string, after?: () => void) => {
        setBusy(true);
        try {
            await fn();
            toast.success(ok);
            setDialog(null);
            after?.();
            void load();
        } catch (e) {
            toast.error(errorOf(e, "Não foi possível concluir."));
        } finally {
            setBusy(false);
        }
    };

    const fileActions = (f: StoredFileDto) => [
        { label: "Descarregar", icon: "ri-download-2-line", hidden: !f.download_url, onClick: () => { window.location.href = fileSrc(f.download_url) ?? "#"; } },
        { label: "Mudar o nome", icon: "ri-edit-line", hidden: f.system || !!f.shortcut_id || !data?.can.edit, onClick: () => setDialog({ kind: "rename-file", file: f }) },
        { label: "Mover", icon: "ri-folder-transfer-line", hidden: f.system || !!f.shortcut_id || !data?.can.edit, onClick: () => setDialog({ kind: "move-file", file: f }) },
        { label: "Guardar numa pasta", icon: "ri-folder-add-line", hidden: !data?.can.create || userFolders.length === 0, onClick: () => setDialog({ kind: "save-to", file: f }) },
        {
            label: f.shortcut_id ? "Tirar desta pasta" : "Apagar", icon: "ri-delete-bin-line", danger: true,
            hidden: (f.system && !f.shortcut_id) || !data?.can.delete,
            disabledReason: !f.shortcut_id && f.in_use > 0 ? `Em uso em ${f.in_use} ${f.in_use === 1 ? "publicação" : "publicações"}: retire-o primeiro.` : null,
            onClick: () => (f.shortcut_id
                ? void run(() => deleteFileShortcut(companyId, f.shortcut_id!), "Atalho enviado para a reciclagem (o ficheiro continua onde estava).")
                : setDialog({ kind: "delete-file", file: f })),
        },
    ];

    const folderActions = (f: FileFolderDto) => [
        { label: "Abrir", icon: "ri-folder-open-line", onClick: () => open(f.id) },
        { label: "Mudar o nome", icon: "ri-edit-line", hidden: f.system || !data?.can.edit, onClick: () => setDialog({ kind: "rename-folder", folder: f }) },
        { label: "Mover", icon: "ri-folder-transfer-line", hidden: f.system || !data?.can.edit, onClick: () => setDialog({ kind: "move-folder", folder: f }) },
        { label: "Apagar", icon: "ri-delete-bin-line", danger: true, hidden: f.system || !data?.can.delete, onClick: () => setDialog({ kind: "delete-folder", folder: f }) },
    ];

    // ── Lista (DataTable) ────────────────────────────────────────────────────

    const rows: StoredFileDto[] = useMemo(() => [...(data?.files ?? []), ...(data?.shortcuts ?? [])], [data]);
    const nameCell = (f: StoredFileDto) => {
        const meta = KIND_META[f.kind];
        return (
            <div className="d-flex align-items-center gap-2 min-w-0">
                {f.thumb_url
                    ? <img src={fileSrc(f.thumb_url)} alt="" className="rounded flex-shrink-0" style={{ width: 32, height: 32, objectFit: "cover" }} />
                    : <i className={`${meta.icon} text-${meta.color} fs-20 flex-shrink-0`} aria-hidden />}
                <div className="min-w-0">
                    <div className="text-truncate fw-medium">{f.name}</div>
                    <div className="d-flex flex-wrap gap-1">
                        {f.shortcut_id && <span className="badge bg-info-subtle text-info fw-normal"><i className="ri-links-line me-1" />Atalho</span>}
                        {f.system && <span className="badge bg-light text-body fw-normal" title="Trata-se no seu módulo: não se apaga nem se move aqui.">Do sistema</span>}
                        {f.in_use > 0 && <span className="badge bg-success-subtle text-success fw-normal">Em uso em {f.in_use} {f.in_use === 1 ? "publicação" : "publicações"}</span>}
                        {f.media_status === "processing" && <span className="badge bg-warning-subtle text-warning fw-normal">A processar</span>}
                    </div>
                </div>
            </div>
        );
    };
    const cols = useDataColumns<StoredFileDto>("ficheiros.lista", [
        { id: "name", header: "Nome", value: (f) => f.name, cell: nameCell, mobile: "title" },
        { id: "kind", header: "Tipo", value: (f) => KIND_META[f.kind].label, nowrap: true },
        { id: "size", header: "Tamanho", value: (f) => f.size_bytes ?? 0, cell: (f) => fmtBytes(f.size_bytes), align: "end", nowrap: true },
        { id: "date", header: "Data", value: (f) => f.created_at ?? "", cell: (f) => fmtDate(f.created_at), nowrap: true },
    ] as DTColumn<StoredFileDto>[]);
    const trashCols = useDataColumns<StoredFileDto>("ficheiros.reciclagem", [
        { id: "name", header: "Nome", value: (f) => f.name, cell: nameCell, mobile: "title" },
        { id: "size", header: "Tamanho", value: (f) => f.size_bytes ?? 0, cell: (f) => fmtBytes(f.size_bytes), align: "end", nowrap: true },
        { id: "purge", header: "Apagado de vez a", value: (f) => f.purge_after ?? "", cell: (f) => fmtDate(f.purge_after), nowrap: true },
    ] as DTColumn<StoredFileDto>[]);

    // ── Árvore de pastas ─────────────────────────────────────────────────────

    const renderTree = (parent: number | null, system: boolean, depth = 0): React.ReactNode => {
        const list = (byParent.get(parent) ?? []).filter((f) => f.system === system);
        if (list.length === 0) return null;
        return (
            <ul className={depth === 0 ? "sub-menu list-unstyled" : "sub-menu list-unstyled"}>
                {list.map((f) => (
                    <li key={f.id}>
                        <button type="button" data-xp-link className={!trashView && folderId === f.id ? "active" : ""} onClick={(e) => { open(f.id); }}>
                            {f.system && depth === 0 && <i className={`${SYSTEM_ICON[f.system_root ?? ""] ?? "ri-folder-2-line"} me-1`} aria-hidden />}{f.name}
                        </button>
                        {renderTree(f.id, system, depth + 1)}
                    </li>
                ))}
            </ul>
        );
    };

    const usage = data?.usage;
    const usedPct = usage && usage.quota > 0 ? Math.min(100, Math.round((usage.total / usage.quota) * 100)) : 0;
    const subfolders = trashView ? [] : data?.folders ?? [];
    const title = trashView ? "Reciclagem" : search ? `Pesquisa: "${search}"` : current?.name ?? "Os meus ficheiros";

    return (
        <div className="page-content xp-files">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Ficheiros" info="Todos os ficheiros da empresa num só sítio: as suas pastas e as pastas da plataforma (cada uma com a permissão da sua área). Os ficheiros da plataforma tratam-se no seu módulo." />

                <div className={`chat-wrapper d-lg-flex gap-1 p-1 rounded ${selected ? "file-detail-show" : ""}`} data-testid="files-manager">
                    {/* ── Pastas ── */}
                    <div className={`file-manager-sidebar rounded ${menuOpen ? "menubar-show" : ""}`}>
                        <div className="p-3 d-flex flex-column h-100">
                            <div className="d-flex align-items-center mb-3">
                                <h5 className="mb-0 fw-semibold flex-grow-1">Pastas</h5>
                                <Button size="sm" color="light" className="d-lg-none" aria-label="Fechar as pastas" onClick={() => setMenuOpen(false)}><i className="ri-close-line" /></Button>
                            </div>
                            <div className="search-box">
                                <Input bsSize="sm" className="bg-light border-light" placeholder="Pesquisar ficheiros…" value={q} aria-label="Pesquisar ficheiros"
                                    onChange={(e) => { setQ(e.target.value); setTrashView(false); setSelected(null); }} />
                                <i className="ri-search-2-line search-icon" />
                            </div>
                            <SimpleBar className="mt-3 mx-n4 px-4 file-menu-sidebar-scroll">
                                <ul className="list-unstyled file-manager-menu">
                                    <li>
                                        <button type="button" data-xp-link className={!trashView && folderId === null && !search ? "active" : ""} onClick={(e) => { open(null); setOpenRoots((o) => ({ ...o, mine: !o.mine || folderId !== null })); }}>
                                            <i className="ri-folder-user-line align-bottom me-2" /><span className="file-list-link">Os meus ficheiros</span>
                                        </button>
                                        {openRoots.mine && renderTree(null, false)}
                                    </li>
                                    {tree.some((f) => f.system) && (
                                        <li>
                                            <button type="button" data-xp-link onClick={(e) => { setOpenRoots((o) => ({ ...o, system: !o.system })); }} aria-expanded={openRoots.system}>
                                                <i className="ri-stack-line align-bottom me-2" /><span className="file-list-link">Da plataforma</span>
                                            </button>
                                            {openRoots.system && renderTree(null, true)}
                                        </li>
                                    )}
                                    <li>
                                        <button type="button" data-xp-link className={trashView ? "active" : ""} onClick={(e) => { setTrashView(true); setSelected(null); setQ(""); setSearch(""); setMenuOpen(false); }}>
                                            <i className="ri-delete-bin-line align-bottom me-2" /><span className="file-list-link">Reciclagem</span>
                                        </button>
                                    </li>
                                </ul>
                            </SimpleBar>
                            {usage && (
                                <div className="mt-auto pt-3">
                                    <h6 className="fs-11 text-muted text-uppercase mb-3">Espaço usado</h6>
                                    <div className="d-flex align-items-center">
                                        <i className="ri-database-2-line fs-17 flex-shrink-0" aria-hidden />
                                        <div className="flex-grow-1 ms-3 overflow-hidden">
                                            <Progress value={usedPct} color={usedPct >= 90 ? "danger" : usedPct >= 70 ? "warning" : "success"} className="progress-sm mb-2" aria-label={`${usedPct}% do espaço`} />
                                            <span className="text-muted fs-12 d-block text-truncate" data-testid="files-usage"><b>{fmtBytes(usage.total)}</b> de <b>{fmtBytes(usage.quota)}</b></span>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* ── Conteúdo ── */}
                    <div className="file-manager-content w-100 p-3 rounded">
                        <div className="d-flex flex-wrap align-items-center gap-2 mb-3">
                            <Button size="sm" color="light" className="d-lg-none" onClick={() => setMenuOpen(true)}><i className="ri-folders-line me-1" />Pastas</Button>
                            <div className="flex-grow-1 min-w-0">
                                <h5 className="mb-0 text-truncate" data-testid="files-title">{title}{loading && <Spinner size="sm" className="ms-2" />}</h5>
                                {!trashView && !search && current?.path && (
                                    <div className="fs-12 text-muted text-truncate">
                                        <button type="button" data-xp-link onClick={(e) => { open(null); }}>{inSystem ? "Da plataforma" : "Os meus ficheiros"}</button>
                                        {current.path.slice(0, -1).map((p) => <span key={p.id}> › <button type="button" data-xp-link onClick={(e) => { open(p.id); }}>{p.name}</button></span>)}
                                    </div>
                                )}
                                {inSystem && <div className="fs-12 text-muted">Pasta da plataforma: os ficheiros veem-se e descarregam-se aqui, e tratam-se no seu módulo.</div>}
                                {trashView && trash && <div className="fs-12 text-muted">O que se apaga nos Ficheiros fica aqui {trash.days} dias e depois desaparece.</div>}
                            </div>
                            {!trashView && (
                                <div className="xp-seg" role="radiogroup" aria-label="Vista">
                                    <button type="button" role="radio" aria-checked={layout === "lista"} className={layout === "lista" ? "on" : ""} onClick={() => changeLayout("lista")}><i className="ri-list-check me-1" />Lista</button>
                                    <button type="button" role="radio" aria-checked={layout === "grelha"} className={layout === "grelha" ? "on" : ""} onClick={() => changeLayout("grelha")}><i className="ri-grid-line me-1" />Grelha</button>
                                </div>
                            )}
                            {canUpload && <Button size="sm" color="outline-primary" onClick={() => setDialog({ kind: "new-folder" })}><i className="ri-folder-add-line me-1" />Nova pasta</Button>}
                            {canUpload && <Button size="sm" color="primary" onClick={() => inputRef.current?.click()}><i className="ri-upload-2-line me-1" />Enviar</Button>}
                            <input ref={inputRef} type="file" multiple className="d-none" onChange={onPick} aria-label="Escolher ficheiros para enviar"
                                accept=".jpg,.jpeg,.png,.webp,.mp4,.mov,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.gif,.svg" />
                        </div>

                        <div className="file-manager-content-scroll overflow-x-hidden overflow-y-auto">
                            {uploads.length > 0 && (
                                <Card className="mb-3 shadow-none border" data-testid="files-uploads">
                                    <CardBody className="py-2">
                                        {uploads.map((u) => (
                                            <div key={u.key} className="py-1">
                                                <div className="d-flex justify-content-between fs-12"><span className="text-truncate">{u.name}</span>
                                                    {u.error ? <Button size="sm" color="link" className="p-0" onClick={() => setUploads((x) => x.filter((y) => y.key !== u.key))}>Fechar</Button> : <span>{Math.round(u.progress * 100)}%</span>}</div>
                                                {u.error ? <div className="text-danger fs-12">{u.error}</div> : <Progress value={u.progress * 100} className="progress-sm" />}
                                            </div>
                                        ))}
                                    </CardBody>
                                </Card>
                            )}

                            {subfolders.length > 0 && (
                                <Row className="g-2 mb-3">
                                    {subfolders.map((f) => (
                                        <Col key={f.id} xxl={3} lg={4} xs={6}>
                                            <Card className="bg-light shadow-none mb-0 h-100" data-testid={`folder-${f.id}`}>
                                                <CardBody className="p-3">
                                                    <div className="d-flex">
                                                        <button type="button" data-xp-link className="flex-grow-1 text-reset min-w-0" onClick={(e) => { open(f.id); }}>
                                                            <i className={`${f.system ? SYSTEM_ICON[f.system_root ?? ""] ?? "ri-folder-2-fill" : "ri-folder-2-fill"} text-warning display-6 d-block mb-2`} aria-hidden />
                                                            <h6 className="fs-14 mb-0 text-truncate">{f.name}</h6>
                                                            {f.system && <span className="fs-11 text-muted">Da plataforma</span>}
                                                        </button>
                                                        <ActionsMenu size="sm" label={`Mais ações: ${f.name}`} items={folderActions(f)} />
                                                    </div>
                                                </CardBody>
                                            </Card>
                                        </Col>
                                    ))}
                                </Row>
                            )}

                            {trashView ? (
                                <TrashList trash={trash} cols={trashCols} canRestore={!!data?.can.delete}
                                    onRestoreFile={(f) => run(() => (f.shortcut_id ? restoreFileShortcut(companyId, f.shortcut_id) : restoreStoredFile(companyId, f.id)), "Restaurado.")}
                                    onRestoreFolder={(f) => run(() => restoreFileFolder(companyId, f.id), "Pasta restaurada.")} />
                            ) : layout === "lista" ? (
                                <DataTable
                                    columns={cols}
                                    data={rows}
                                    rowKey={(f) => (f.shortcut_id ? `s${f.shortcut_id}` : `f${f.id}`)}
                                    loading={loading && rows.length === 0}
                                    pageSize={25}
                                    caption="Ficheiros"
                                    onRowClick={(f) => setSelected(f)}
                                    empty={{ message: search ? `Nenhum ficheiro com "${search}".` : subfolders.length ? "Sem ficheiros nesta pasta (só subpastas)." : "Esta pasta está vazia.",
                                        action: canUpload ? <Button size="sm" color="outline-primary" onClick={() => inputRef.current?.click()}><i className="ri-upload-2-line me-1" />Enviar ficheiros</Button> : undefined }}
                                    rowActions={(f) => (
                                        <>
                                            <Button size="sm" color="outline-primary" aria-label={`Ver: ${f.name}`} title="Ver" onClick={(e) => { e.stopPropagation(); setSelected(f); }}><i className="ri-eye-line" /></Button>
                                            <ActionsMenu size="sm" label={`Mais ações: ${f.name}`} items={fileActions(f)} />
                                        </>
                                    )}
                                />
                            ) : (
                                <Row className="g-2" data-testid="files-grid">
                                    {rows.length === 0 && !loading && <p className="text-muted">{search ? `Nenhum ficheiro com "${search}".` : "Esta pasta está vazia."}</p>}
                                    {rows.map((f) => (
                                        <Col key={f.shortcut_id ? `s${f.shortcut_id}` : f.id} xxl={2} lg={3} md={4} xs={6}>
                                            <Card className="shadow-none border mb-0 h-100 xp-file-card">
                                                <button type="button" className="xp-file-thumb bg-light border-0 w-100" onClick={() => setSelected(f)} aria-label={`Ver: ${f.name}`}>
                                                    {f.thumb_url ? <img src={fileSrc(f.thumb_url)} alt="" /> : <i className={`${KIND_META[f.kind].icon} text-${KIND_META[f.kind].color} display-5`} aria-hidden />}
                                                </button>
                                                <CardBody className="p-2 d-flex align-items-start gap-1">
                                                    <div className="min-w-0 flex-grow-1">
                                                        <div className="fs-13 fw-medium text-truncate" title={f.name}>{f.name}</div>
                                                        <div className="fs-11 text-muted">{fmtBytes(f.size_bytes)}{f.in_use > 0 ? ` · em uso (${f.in_use})` : ""}{f.shortcut_id ? " · atalho" : ""}</div>
                                                    </div>
                                                    <ActionsMenu size="sm" label={`Mais ações: ${f.name}`} items={fileActions(f)} />
                                                </CardBody>
                                            </Card>
                                        </Col>
                                    ))}
                                </Row>
                            )}
                        </div>
                    </div>

                    {/* ── Pré-visualização ── */}
                    <div className="file-manager-detail-content p-3 rounded" data-testid="files-detail">
                        {selected && <FileDetail file={detail ?? (selected as FileDetailDto)} loadingDetail={!detail} actions={fileActions(selected)} onClose={() => setSelected(null)} />}
                    </div>
                </div>
            </Container>

            <NameModal isOpen={dialog?.kind === "new-folder"} title="Nova pasta" label="Nome da pasta" initial="" busy={busy} confirmLabel="Criar pasta"
                onClose={() => setDialog(null)} onConfirm={(name) => run(() => createFileFolder(companyId, { name, parent_id: folderId }), "Pasta criada.")} />
            <NameModal isOpen={dialog?.kind === "rename-folder" || dialog?.kind === "rename-file"} title="Mudar o nome" label="Nome" busy={busy} confirmLabel="Guardar"
                initial={dialog?.kind === "rename-folder" ? dialog.folder.name : dialog?.kind === "rename-file" ? dialog.file.name : ""}
                hint={dialog?.kind === "rename-file" ? "A extensão do ficheiro não muda." : undefined}
                onClose={() => setDialog(null)}
                onConfirm={(name) => (dialog?.kind === "rename-folder"
                    ? run(() => updateFileFolder(companyId, dialog.folder.id, { name }), "Nome guardado.")
                    : dialog?.kind === "rename-file" ? run(() => updateStoredFile(companyId, dialog.file.id, { name }), "Nome guardado.", () => setSelected(null)) : undefined)} />
            <FolderPickerModal isOpen={dialog?.kind === "move-folder" || dialog?.kind === "move-file" || dialog?.kind === "save-to"} busy={busy}
                title={dialog?.kind === "save-to" ? "Guardar numa pasta" : "Mover"}
                hint={dialog?.kind === "save-to" ? "O ficheiro fica também nessa pasta, sem ser copiado (um atalho)." : "Mover não copia o ficheiro: só muda a pasta onde aparece."}
                confirmLabel={dialog?.kind === "save-to" ? "Guardar" : "Mover"}
                allowRoot={dialog?.kind !== "save-to"}
                folders={userFolders.filter((f) => dialog?.kind !== "move-folder" || f.id !== dialog.folder.id)}
                onClose={() => setDialog(null)}
                onConfirm={(target) => {
                    if (dialog?.kind === "move-folder") return run(() => updateFileFolder(companyId, dialog.folder.id, { parent_id: target }), "Pasta movida.");
                    if (dialog?.kind === "move-file") return run(() => updateStoredFile(companyId, dialog.file.id, { folder_id: target }), "Ficheiro movido.", () => setSelected(null));
                    if (dialog?.kind === "save-to" && target) return run(() => saveFileToFolder(companyId, dialog.file.id, target), "Guardado na pasta.");
                }} />
            <ConfirmModal isOpen={dialog?.kind === "delete-folder" || dialog?.kind === "delete-file"} busy={busy} title="Apagar"
                message={dialog?.kind === "delete-folder" ? `A pasta "${dialog.folder.name}" e o que tem dentro vão para a reciclagem (30 dias).` : dialog?.kind === "delete-file" ? `"${dialog.file.name}" vai para a reciclagem (30 dias).` : ""}
                confirmLabel="Apagar" onClose={() => setDialog(null)}
                onConfirm={() => (dialog?.kind === "delete-folder"
                    ? run(() => deleteFileFolder(companyId, dialog.folder.id), "Pasta enviada para a reciclagem.")
                    : dialog?.kind === "delete-file" ? run(() => deleteStoredFile(companyId, dialog.file.id), "Ficheiro enviado para a reciclagem.", () => setSelected(null)) : undefined)} />
        </div>
    );
}

function FileDetail({ file, loadingDetail, actions, onClose }: { file: FileDetailDto; loadingDetail: boolean; actions: any[]; onClose: () => void }) {
    const meta = KIND_META[file.kind];
    const preview = file.kind === "imagem" ? fileSrc(file.preview_url ?? file.url) : undefined;
    return (
        <SimpleBar className="file-detail-content-scroll">
            <div className="d-flex flex-column h-100">
                <div className="d-flex align-items-center pb-3 border-bottom border-bottom-dashed mb-3 gap-2">
                    <h5 className="flex-grow-1 fw-semibold mb-0">Pré-visualização</h5>
                    <Button size="sm" color="light" aria-label="Fechar a pré-visualização" onClick={onClose}><i className="ri-close-fill" /></Button>
                </div>
                <div className="pb-3 border-bottom border-bottom-dashed mb-3">
                    <div className="file-details-box bg-light p-2 text-center rounded-3 border border-light mb-3" data-testid="files-preview">
                        {preview ? <img src={preview} alt={file.name} style={{ maxWidth: "100%", maxHeight: 190, objectFit: "contain" }} />
                            : file.kind === "video" && file.url ? <video src={fileSrc(file.url)} controls preload="metadata" style={{ maxWidth: "100%", maxHeight: 190 }} />
                            : file.kind === "pdf" && file.url ? <iframe title={file.name} src={fileSrc(file.url)} className="w-100 h-100 border-0 rounded" />
                            : <i className={`${meta.icon} text-${meta.color} display-4`} aria-hidden />}
                    </div>
                    <h5 className="fs-15 mb-1 text-break">{file.name}</h5>
                    <p className="text-muted mb-0 fs-12">{fmtBytes(file.size_bytes)}, {fmtDate(file.created_at)}</p>
                </div>
                <table className="table table-borderless table-sm fs-13 mb-3">
                    <tbody>
                        <tr><th scope="row" className="text-muted fw-normal" style={{ width: "40%" }}>Tipo</th><td>{meta.label}{file.extension ? ` (.${file.extension})` : ""}</td></tr>
                        <tr><th scope="row" className="text-muted fw-normal">Origem</th><td>{file.system ? "Da plataforma (trata-se no seu módulo)" : "Enviado nos Ficheiros"}</td></tr>
                        {file.shortcuts?.length > 0 && <tr><th scope="row" className="text-muted fw-normal">Também em</th><td>{file.shortcuts.map((s) => s.folder).join(", ")}</td></tr>}
                    </tbody>
                </table>
                <div className="mb-3" data-testid="files-usage-list">
                    <h6 className="fs-12 text-uppercase text-muted mb-2">Onde está em uso</h6>
                    {loadingDetail ? <Skeleton variant="lines" lines={2} />
                        : file.usage?.length ? (
                            <ul className="list-unstyled mb-0 fs-13">
                                {file.usage.map((u) => <li key={u.id} className="mb-1"><Link to={`/editorial?publicacao=${u.id}`}><i className="ri-calendar-todo-line me-1" />{u.title}</Link>{u.publish_date ? <span className="text-muted"> · {fmtDate(u.publish_date)}</span> : null}</li>)}
                            </ul>
                        ) : <p className="text-muted fs-13 mb-0">Não está em uso em nenhuma publicação.</p>}
                </div>
                <div className="mt-auto border-top border-top-dashed pt-3 d-flex gap-2">
                    {file.download_url && <a className="btn btn-outline-primary flex-grow-1" href={fileSrc(file.download_url)}><i className="ri-download-2-line me-1" />Descarregar</a>}
                    <ActionsMenu label={`Mais ações: ${file.name}`} items={actions.filter((a) => a.label !== "Descarregar")} />
                </div>
            </div>
        </SimpleBar>
    );
}

function TrashList({ trash, cols, canRestore, onRestoreFile, onRestoreFolder }: {
    trash: FilesTrashPayload | null; cols: any; canRestore: boolean; onRestoreFile: (f: StoredFileDto) => void; onRestoreFolder: (f: FileFolderDto) => void;
}) {
    const files = [...(trash?.files ?? []), ...(trash?.shortcuts ?? []).map((s) => ({ ...s, purge_after: s.purge_after }))];
    return (
        <>
            {(trash?.folders ?? []).length > 0 && (
                <div className="mb-3">
                    {trash!.folders.map((f) => (
                        <div key={f.id} className="d-flex align-items-center gap-2 border rounded p-2 mb-2" data-testid={`trash-folder-${f.id}`}>
                            <i className="ri-folder-2-fill text-warning fs-20" aria-hidden />
                            <div className="flex-grow-1 min-w-0"><div className="fw-medium text-truncate">{f.name}</div><div className="fs-12 text-muted">Pasta, com o que tinha dentro. Apagada de vez a {fmtDate(f.purge_after)}.</div></div>
                            {canRestore && <Button size="sm" color="outline-primary" onClick={() => onRestoreFolder(f)}><i className="ri-arrow-go-back-line me-1" />Restaurar</Button>}
                        </div>
                    ))}
                </div>
            )}
            <DataTable columns={cols} data={files} rowKey={(f: any) => (f.shortcut_id ? `s${f.shortcut_id}` : `f${f.id}`)} pageSize={25} caption="Reciclagem"
                empty={{ message: "A reciclagem está vazia." }}
                rowActions={(f: StoredFileDto) => (canRestore ? <Button size="sm" color="outline-primary" onClick={() => onRestoreFile(f)}><i className="ri-arrow-go-back-line me-1" />Restaurar</Button> : null)} />
        </>
    );
}
