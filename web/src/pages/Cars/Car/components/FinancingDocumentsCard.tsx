import { useCallback, useEffect, useState } from "react";
import { Badge, Button, Card, CardBody, CardHeader, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner, Table } from "reactstrap";
import { toast } from "react-toastify";
import ReasonButton from "Components/Common/ReasonButton";
import ConfirmModal from "Components/Common/ConfirmModal";
import { useCan } from "contexts/ModulesContext";
import {
    createCarFinancingLink, createLeadFinancingLink, deleteFinancingDocument, deleteFinancingLink, getCarFinancingLinks, getFinancingDocumentDownload,
    getFinancingLinkUrl, getLeadFinancingLinks, revokeFinancingLink, updateFinancingSettings,
} from "helpers/laravel_helper";
import { fileSrc, fmtBytes } from "common/models/files.model";

/**
 * Envio seguro de documentos para financiamento (na ficha da viatura e no detalhe da lead).
 * O vendedor gera um link privado para um cliente (o nome, os documentos pedidos e a validade) e
 * copia-o; aqui vê o estado de cada link (ativo, expirado, revogado, apagado) e os documentos que
 * chegaram. Descarregar pede um endereço assinado de curta duração (a descarga fica registada).
 */

export interface FinancingDocumentDto {
    id: number; kind: string; kind_label: string; name: string; mime: string; size_bytes: number; created_at: string | null;
    scan_status: "pendente" | "limpo" | "infetado" | "nao_analisado"; downloadable: boolean;
}
export interface FinancingLinkDto {
    id: number; customer_name: string | null; state: "ativo" | "expirado" | "revogado" | "apagado";
    items: { key: string; label: string }[]; expires_at: string; created_at: string | null; created_by: string | null;
    consented_at: string | null; last_upload_at: string | null; purge_on: string | null; documents: FinancingDocumentDto[];
}
interface Payload {
    links: FinancingLinkDto[];
    can: { create: boolean; delete: boolean; settings: boolean };
    items: { key: string; label: string; hint: string }[];
    defaults: { days: number; max_days: number };
    retention: { days: number; min: number; max: number; warn_days: number };
    antivirus_blocks: boolean;
}

const STATE: Record<FinancingLinkDto["state"], { label: string; color: string }> = {
    ativo: { label: "Ativo", color: "success" }, expirado: { label: "Expirado", color: "secondary" },
    revogado: { label: "Revogado", color: "dark" }, apagado: { label: "Apagado", color: "light" },
};
const SCAN: Partial<Record<FinancingDocumentDto["scan_status"], { label: string; color: string }>> = {
    pendente: { label: "Em análise", color: "warning" }, infetado: { label: "Recusado pelo antivírus", color: "danger" },
};
const fmtDay = (s?: string | null) => (s ? new Date(s).toLocaleDateString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric" }) : "—");
const errorOf = (e: any, fallback: string): string => {
    const errors = e?.response?.data?.errors;
    const first = errors ? Object.values(errors).flat()[0] : null;
    return String(first ?? e?.response?.data?.message ?? fallback);
};

async function copy(text: string) {
    try {
        await navigator.clipboard.writeText(text);
        toast.success("Link copiado. Envie-o só a este cliente.");
    } catch {
        toast.info(text);
    }
}

export default function FinancingDocumentsCard({ companyId, carId, leadId, embedded = false }: { companyId: number; carId?: number; leadId?: number; embedded?: boolean }) {
    const [canSee] = useCan("financiamento.ver");
    const [data, setData] = useState<Payload | null>(null);
    const [loading, setLoading] = useState(true);
    const [hidden, setHidden] = useState(false);
    const [creating, setCreating] = useState(false);
    const [confirm, setConfirm] = useState<{ kind: "revoke" | "delete-link" | "delete-doc"; id: number; label: string } | null>(null);
    const [busy, setBusy] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);

    const load = useCallback(() => {
        const req = leadId ? getLeadFinancingLinks(companyId, leadId) : carId ? getCarFinancingLinks(companyId, carId) : null;
        if (!req) return;
        setLoading(true);
        req.then((r: any) => setData(r?.data ?? null))
            .catch((e: any) => { if ([403, 404].includes(e?.response?.status)) setHidden(true); })
            .finally(() => setLoading(false));
    }, [companyId, carId, leadId]);

    useEffect(() => { if (canSee) load(); }, [canSee, load]);

    if (!canSee || hidden) return null;

    const download = async (doc: FinancingDocumentDto) => {
        try {
            const r: any = await getFinancingDocumentDownload(companyId, doc.id);
            window.location.href = fileSrc(r?.data?.url) ?? "#";
        } catch (e) {
            toast.error(errorOf(e, "Não foi possível descarregar o documento."));
        }
    };

    const copyLink = async (link: FinancingLinkDto) => {
        try {
            const r: any = await getFinancingLinkUrl(companyId, link.id);
            if (r?.data?.url) await copy(r.data.url);
        } catch (e) {
            toast.error(errorOf(e, "Não foi possível obter o link."));
        }
    };

    const runConfirm = async () => {
        if (!confirm) return;
        setBusy(true);
        try {
            if (confirm.kind === "revoke") await revokeFinancingLink(companyId, confirm.id);
            if (confirm.kind === "delete-link") await deleteFinancingLink(companyId, confirm.id);
            if (confirm.kind === "delete-doc") await deleteFinancingDocument(companyId, confirm.id);
            toast.success(confirm.kind === "revoke" ? "Link revogado: o cliente já não o pode usar." : "Apagado.");
            setConfirm(null);
            load();
        } catch (e) {
            toast.error(errorOf(e, "Não foi possível concluir."));
        } finally {
            setBusy(false);
        }
    };

    const createReason = !data ? null : !data.can.create ? "O seu perfil não permite pedir documentos." : data.antivirus_blocks
        ? "O envio de documentos fica disponível quando o antivírus estiver ativo." : null;

    const body = (
        <>
            {loading && !data ? <div className="text-center py-3"><Spinner size="sm" /></div> : null}
            {data && data.links.length === 0 && (
                <p className="text-muted mb-0" data-testid="fin-empty">Ainda não pediu documentos{leadId ? " a este cliente" : " para esta viatura"}.</p>
            )}
            {data?.links.map((link) => (
                <div key={link.id} className="border rounded p-3 mb-3" data-testid={`fin-link-${link.id}`}>
                    <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <div>
                            <strong>{link.customer_name ?? "Cliente (dados apagados)"}</strong>{" "}
                            <Badge color={STATE[link.state].color} className={link.state === "apagado" ? "text-body" : ""}>{STATE[link.state].label}</Badge>
                            <div className="text-muted fs-12">
                                Pedido n.º {link.id} · criado a {fmtDay(link.created_at)}{link.created_by ? ` por ${link.created_by}` : ""}
                                {link.state === "ativo" ? ` · válido até ${fmtDay(link.expires_at)}` : ""}
                                {link.purge_on ? ` · os documentos apagam-se a ${fmtDay(link.purge_on)}` : ""}
                            </div>
                        </div>
                        <div className="d-flex gap-2 flex-wrap">
                            {link.state === "ativo" && data.can.create && (
                                <>
                                    <Button size="sm" color="outline-primary" onClick={() => copyLink(link)}><i className="ri-file-copy-line me-1" />Copiar link</Button>
                                    <Button size="sm" color="outline-secondary" onClick={() => setConfirm({ kind: "revoke", id: link.id, label: link.customer_name ?? "" })}>Revogar</Button>
                                </>
                            )}
                            {data.can.delete && (
                                <Button size="sm" color="outline-danger" onClick={() => setConfirm({ kind: "delete-link", id: link.id, label: link.customer_name ?? "" })} aria-label={`Apagar o pedido n.º ${link.id}`}>
                                    <i className="ri-delete-bin-line" />
                                </Button>
                            )}
                        </div>
                    </div>
                    <div className="d-flex flex-wrap gap-1 mb-2">
                        {link.items.map((it) => {
                            const got = link.documents.some((d) => d.kind === it.key && d.scan_status !== "infetado");
                            return <Badge key={it.key} color={got ? "success" : "light"} className={got ? "" : "text-body border"}>{got ? "✓ " : ""}{it.label}</Badge>;
                        })}
                    </div>
                    {link.documents.length > 0 ? (
                        <div className="table-responsive">
                            <Table size="sm" className="align-middle mb-0">
                                <thead className="table-light"><tr><th>Documento</th><th>Ficheiro</th><th className="text-end">Tamanho</th><th>Recebido</th><th className="text-end">Ações</th></tr></thead>
                                <tbody>
                                    {link.documents.map((d) => (
                                        <tr key={d.id}>
                                            <td>{d.kind_label}</td>
                                            <td>{d.name} {SCAN[d.scan_status] && <Badge color={SCAN[d.scan_status]!.color}>{SCAN[d.scan_status]!.label}</Badge>}</td>
                                            <td className="text-end text-nowrap">{fmtBytes(d.size_bytes)}</td>
                                            <td className="text-nowrap">{fmtDay(d.created_at)}</td>
                                            <td className="text-end text-nowrap">
                                                {d.downloadable && <Button size="sm" color="outline-primary" onClick={() => download(d)} aria-label={`Descarregar ${d.name}`}><i className="ri-download-2-line" /></Button>}
                                                {data.can.delete && <Button size="sm" color="outline-danger" className="ms-1" onClick={() => setConfirm({ kind: "delete-doc", id: d.id, label: d.name })} aria-label={`Apagar ${d.name}`}><i className="ri-delete-bin-line" /></Button>}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </div>
                    ) : (
                        <p className="text-muted fs-13 mb-0">{link.state === "apagado" ? "Os documentos foram apagados automaticamente." : link.consented_at ? "O cliente aceitou o aviso; ainda não chegou nenhum documento." : "Ainda não chegou nenhum documento."}</p>
                    )}
                </div>
            ))}
            {data && (
                <p className="text-muted fs-12 mb-0">
                    Os documentos apagam-se automaticamente {data.retention.days} dias depois do último envio (com aviso aos administradores {data.retention.warn_days} dias antes).
                    {data.can.settings && <> <button type="button" className="btn btn-link btn-sm p-0 align-baseline" onClick={() => setSettingsOpen(true)}>Alterar o prazo</button></>}
                </p>
            )}
        </>
    );

    const modals = data && (
        <>
            <CreateLinkModal isOpen={creating} data={data} onClose={() => setCreating(false)} onCreated={load}
                create={(payload) => (leadId ? createLeadFinancingLink(companyId, leadId, payload) : createCarFinancingLink(companyId, carId as number, payload))} />
            <RetentionModal isOpen={settingsOpen} days={data.retention.days} min={data.retention.min} max={data.retention.max} onClose={() => setSettingsOpen(false)}
                save={async (days) => { await updateFinancingSettings(companyId, days); toast.success("Prazo de apagamento guardado."); setSettingsOpen(false); load(); }} />
            <ConfirmModal
                isOpen={!!confirm} loading={busy} onCancel={() => setConfirm(null)} onConfirm={runConfirm}
                variant={confirm?.kind === "revoke" ? "warning" : "danger"}
                title={confirm?.kind === "revoke" ? "Revogar o link?" : confirm?.kind === "delete-link" ? "Apagar o pedido e os documentos?" : "Apagar o documento?"}
                message={confirm?.kind === "revoke" ? "O cliente deixa de poder enviar documentos por este link. Os documentos já recebidos ficam."
                    : confirm?.kind === "delete-link" ? "Apaga o link e todos os documentos recebidos. Não se pode desfazer."
                        : `Apaga "${confirm?.label}". Não se pode desfazer.`}
                confirmText={confirm?.kind === "revoke" ? "Revogar" : "Apagar"}
            />
        </>
    );

    const createButton = (
        <ReasonButton color="outline-primary" type="button" onClick={() => setCreating(true)} reason={createReason} disabled={!data}>
            <i className="ri-shield-user-line me-1" />Pedir documentos
        </ReasonButton>
    );

    if (embedded) {
        return (
            <div className="mt-3" data-testid="financing-card">
                <div className="d-flex align-items-center justify-content-between gap-2 mb-2">
                    <span className="form-label text-uppercase fs-12 mb-0">Documentos para financiamento</span>
                    {createButton}
                </div>
                {body}
                {modals}
            </div>
        );
    }

    return (
        <Card className="mt-3" data-testid="financing-card">
            <CardHeader className="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <h5 className="card-title mb-0">Documentos para financiamento</h5>
                    <small className="text-muted">Um link privado para o cliente enviar os documentos, sem conta. Na XPLENDOR, só a sua empresa os vê.</small>
                </div>
                {createButton}
            </CardHeader>
            <CardBody>{body}{modals}</CardBody>
        </Card>
    );
}

function CreateLinkModal({ isOpen, data, onClose, onCreated, create }: {
    isOpen: boolean; data: Payload; onClose: () => void; onCreated: () => void;
    create: (p: { customer_name: string; items: string[]; other?: string; days?: number }) => Promise<any>;
}) {
    const [name, setName] = useState("");
    const [items, setItems] = useState<string[]>(["cc", "morada", "recibos", "irs", "iban", "mapa_bp"]);
    const [other, setOther] = useState("");
    const [days, setDays] = useState(data.defaults.days);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [created, setCreated] = useState<{ url: string; expires_at: string } | null>(null);

    useEffect(() => {
        if (isOpen) { setName(""); setOther(""); setDays(data.defaults.days); setError(null); setCreated(null); }
    }, [isOpen, data.defaults.days]);

    const toggle = (key: string) => setItems((list) => (list.includes(key) ? list.filter((k) => k !== key) : [...list, key]));

    const submit = async () => {
        setSaving(true);
        setError(null);
        try {
            const r: any = await create({ customer_name: name.trim(), items, other: items.includes("outro") ? other.trim() : undefined, days });
            setCreated({ url: r?.data?.url, expires_at: r?.data?.expires_at });
            onCreated();
        } catch (e) {
            setError(errorOf(e, "Não foi possível criar o link."));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal isOpen={isOpen} toggle={!saving ? onClose : undefined} centered scrollable>
            <ModalHeader toggle={!saving ? onClose : undefined}>Pedir documentos para financiamento</ModalHeader>
            <ModalBody>
                {created ? (
                    <div data-testid="fin-created">
                        <p className="mb-2">Link criado. Envie-o só a este cliente: quem tiver o link pode enviar documentos até {fmtDay(created.expires_at)}.</p>
                        <div className="d-flex gap-2">
                            <Input readOnly value={created.url} aria-label="Link para o cliente" onFocus={(e) => e.currentTarget.select()} />
                            <Button color="primary" onClick={() => copy(created.url)}><i className="ri-file-copy-line me-1" />Copiar</Button>
                        </div>
                        <p className="text-muted fs-12 mt-2 mb-0">O cliente não vê nem descarrega ficheiros: só envia. Pode revogar o link a qualquer momento.</p>
                    </div>
                ) : (
                    <>
                        <div className="mb-3">
                            <Label for="fin-name" className="form-label">Nome do cliente</Label>
                            <Input id="fin-name" value={name} maxLength={120} onChange={(e) => setName(e.target.value)} placeholder="Nome e apelido" />
                        </div>
                        <Label className="form-label">Documentos pedidos</Label>
                        {data.items.map((it) => (
                            <div className="form-check mb-1" key={it.key}>
                                <Input type="checkbox" className="form-check-input" id={`fin-item-${it.key}`} checked={items.includes(it.key)} onChange={() => toggle(it.key)} />
                                <Label className="form-check-label" for={`fin-item-${it.key}`}>{it.label}{it.hint ? <span className="text-muted fs-12"> · {it.hint}</span> : null}</Label>
                            </div>
                        ))}
                        {items.includes("outro") && (
                            <Input className="mt-1 mb-2" value={other} maxLength={120} onChange={(e) => setOther(e.target.value)} placeholder="Que documento? (ex.: declaração da entidade patronal)" aria-label="Descrição do outro documento" />
                        )}
                        <div className="mt-3">
                            <Label for="fin-days" className="form-label">Validade do link (dias)</Label>
                            <Input id="fin-days" type="number" min={1} max={data.defaults.max_days} value={days} style={{ maxWidth: 120 }}
                                onChange={(e) => setDays(Math.max(1, Math.min(data.defaults.max_days, Number(e.target.value) || 1)))} />
                        </div>
                        {error && <div className="alert alert-danger mt-3 mb-0 py-2" role="alert">{error}</div>}
                    </>
                )}
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onClose} disabled={saving}>{created ? "Fechar" : "Cancelar"}</Button>
                {!created && (
                    <Button color="primary" onClick={submit} disabled={saving || name.trim().length < 2 || items.length === 0}>
                        {saving ? <><Spinner size="sm" className="me-1" />A criar…</> : "Criar link"}
                    </Button>
                )}
            </ModalFooter>
        </Modal>
    );
}

function RetentionModal({ isOpen, days, min, max, onClose, save }: { isOpen: boolean; days: number; min: number; max: number; onClose: () => void; save: (d: number) => Promise<void> }) {
    const [value, setValue] = useState(days);
    const [saving, setSaving] = useState(false);
    useEffect(() => { if (isOpen) setValue(days); }, [isOpen, days]);
    const valid = value >= min && value <= max;

    return (
        <Modal isOpen={isOpen} toggle={!saving ? onClose : undefined} centered>
            <ModalHeader toggle={!saving ? onClose : undefined}>Prazo de apagamento</ModalHeader>
            <ModalBody>
                <Label for="fin-retention" className="form-label">Dias depois do último envio</Label>
                <Input id="fin-retention" type="number" min={min} max={max} value={value} style={{ maxWidth: 140 }} onChange={(e) => setValue(Number(e.target.value))} invalid={!valid} />
                <small className="text-muted d-block mt-2">Entre {min} e {max} dias. Vale para todos os pedidos da empresa, e o aviso da página de envio passa a dizer este prazo.</small>
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onClose} disabled={saving}>Cancelar</Button>
                <Button color="primary" disabled={!valid || saving} onClick={async () => {
                    setSaving(true);
                    try { await save(value); } catch (e) { toast.error(errorOf(e, "Não foi possível guardar.")); } finally { setSaving(false); }
                }}>{saving ? <Spinner size="sm" /> : "Guardar"}</Button>
            </ModalFooter>
        </Modal>
    );
}
