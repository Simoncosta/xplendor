import { useCallback, useEffect, useRef, useState } from "react";
import { Badge, Button, Card, CardBody, CardHeader, Col, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Row, Spinner, Table } from "reactstrap";
import { toast } from "react-toastify";
import ReasonButton from "Components/Common/ReasonButton";
import ConfirmModal from "Components/Common/ConfirmModal";
import { useCan } from "contexts/ModulesContext";
import {
    addFinancingSecondHolder, createCarFinancingLink, createLeadFinancingLink, deleteFinancingDocument, deleteFinancingLink, deleteFinancingRequest,
    getCarFinancingLinks, getFinancingDocumentDownload, getFinancingLinkUrl, getLeadFinancingLinks, revokeFinancingLink, updateFinancingSettings,
} from "helpers/laravel_helper";
import { fileSrc, fmtBytes } from "common/models/files.model";

/**
 * Envio seguro de documentos para financiamento (na ficha da viatura e no detalhe da lead).
 * Cada pedido junta 1 ou 2 titulares; cada titular tem o seu link (copiar, revogar), os seus
 * dados (telemóvel, email, habitação e valor mensal), os seus documentos e o seu consentimento.
 * Descarregar pede um endereço assinado de curta duração (a descarga fica registada).
 */

export interface FinancingDocumentDto {
    id: number; kind: string; kind_label: string; name: string; mime: string; size_bytes: number; created_at: string | null;
    scan_status: "pendente" | "limpo" | "infetado" | "nao_analisado"; downloadable: boolean; personal?: boolean; thumb_url?: string | null;
}
export interface FinancingHolderDto {
    link_id: number; holder: number; customer_name: string | null; state: "ativo" | "expirado" | "revogado" | "apagado";
    expires_at: string; revoked_at: string | null; consented_at: string | null; signed_name: string | null;
    relationship: string | null; relationship_label: string | null; phone: string | null; email: string | null;
    housing: string | null; housing_label: string | null; housing_monthly_eur: number | null; details_at: string | null;
    hidden_documents: number; documents: FinancingDocumentDto[];
}
export interface FinancingRequestDto {
    id: number; purpose: "financiamento" | "retoma"; state: FinancingHolderDto["state"]; items: { key: string; label: string }[];
    irs_year: number | null; notes: string | null; created_at: string | null; created_by: string | null; last_upload_at: string | null;
    purge_on: string | null; purged_at: string | null; can_add_second_holder: boolean; holders: FinancingHolderDto[];
}
interface Payload {
    requests: FinancingRequestDto[];
    can: { create: boolean; delete: boolean; settings: boolean };
    items: { key: string; label: string; hint: string }[];
    default_items: string[];
    relationships: { value: string; label: string }[];
    defaults: { days: number; max_days: number; irs_year: number; irs_years: number[] };
    retention: { days: number; min: number; max: number; warn_days: number };
    antivirus_blocks: boolean;
}

export const STATE: Record<FinancingHolderDto["state"], { label: string; color: string }> = {
    ativo: { label: "Ativo", color: "success" }, expirado: { label: "Expirado", color: "secondary" },
    revogado: { label: "Revogado", color: "dark" }, apagado: { label: "Apagado", color: "light" },
};
const SCAN: Partial<Record<FinancingDocumentDto["scan_status"], { label: string; color: string }>> = {
    pendente: { label: "Em análise", color: "warning" }, infetado: { label: "Recusado pelo antivírus", color: "danger" },
};
export const fmtDay = (s?: string | null) => (s ? new Date(s).toLocaleDateString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric" }) : "—");
const eur = (v: number) => `${new Intl.NumberFormat("pt-PT", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v)} €`;
export const errorOf = (e: any, fallback: string): string => {
    const errors = e?.response?.data?.errors;
    const first = errors ? Object.values(errors).flat()[0] : null;
    return String(first ?? e?.response?.data?.message ?? fallback);
};

export async function copyText(text: string, message = "Link copiado. Envie-o só a esta pessoa.") {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(message);
    } catch {
        toast.info(text);
    }
}

/** A tabela dos documentos de um titular (também usada na retoma, para o DUA). */
export function DocumentsTable({ docs, onDownload, onDelete }: { docs: FinancingDocumentDto[]; onDownload: (d: FinancingDocumentDto) => void; onDelete?: (d: FinancingDocumentDto) => void }) {
    return (
        <div className="table-responsive">
            <Table size="sm" className="align-middle mb-0">
                <thead className="table-light"><tr><th>Documento</th><th>Ficheiro</th><th className="text-end">Tamanho</th><th>Recebido</th><th className="text-end">Ações</th></tr></thead>
                <tbody>
                    {docs.map((d) => (
                        <tr key={d.id}>
                            <td>{d.kind_label}</td>
                            <td>{d.name} {SCAN[d.scan_status] && <Badge color={SCAN[d.scan_status]!.color}>{SCAN[d.scan_status]!.label}</Badge>}</td>
                            <td className="text-end text-nowrap">{fmtBytes(d.size_bytes)}</td>
                            <td className="text-nowrap">{fmtDay(d.created_at)}</td>
                            <td className="text-end text-nowrap">
                                {d.downloadable && <Button size="sm" color="outline-primary" onClick={() => onDownload(d)} aria-label={`Descarregar ${d.name}`}><i className="ri-download-2-line" /></Button>}
                                {onDelete && <Button size="sm" color="outline-danger" className="ms-1" onClick={() => onDelete(d)} aria-label={`Apagar ${d.name}`}><i className="ri-delete-bin-line" /></Button>}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </Table>
        </div>
    );
}

type Confirm = { kind: "revoke" | "delete-link" | "delete-request" | "delete-doc"; id: number; label: string };

export default function FinancingDocumentsCard({ companyId, carId, leadId, embedded = false }: { companyId: number; carId?: number; leadId?: number; embedded?: boolean }) {
    const [canSee] = useCan("financiamento.ver");
    const [data, setData] = useState<Payload | null>(null);
    const [loading, setLoading] = useState(true);
    const [hidden, setHidden] = useState(false);
    const [creating, setCreating] = useState(false);
    const [second, setSecond] = useState<FinancingRequestDto | null>(null);
    const [confirm, setConfirm] = useState<Confirm | null>(null);
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

    const copyLink = async (h: FinancingHolderDto) => {
        try {
            const r: any = await getFinancingLinkUrl(companyId, h.link_id);
            if (r?.data?.url) await copyText(r.data.url);
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
            if (confirm.kind === "delete-request") await deleteFinancingRequest(companyId, confirm.id);
            if (confirm.kind === "delete-doc") await deleteFinancingDocument(companyId, confirm.id);
            toast.success(confirm.kind === "revoke" ? "Link revogado: o titular já não o pode usar." : "Apagado.");
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

    const holderBlock = (r: FinancingRequestDto, h: FinancingHolderDto) => (
        <div className="border rounded p-2 h-100" data-testid={`fin-holder-${h.link_id}`}>
            <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
                <div>
                    <strong>Titular {h.holder}{h.relationship_label ? ` · ${h.relationship_label}` : ""}</strong>{" "}
                    <Badge color={STATE[h.state].color} className={h.state === "apagado" ? "text-body" : ""}>{STATE[h.state].label}</Badge>
                    <div className="fs-13">{h.customer_name ?? (r.purged_at ? "Dados apagados" : "Nome por indicar")}</div>
                </div>
                <div className="d-flex gap-1">
                    {h.state === "ativo" && data?.can.create && (
                        <>
                            <Button size="sm" color="outline-primary" onClick={() => copyLink(h)}><i className="ri-file-copy-line me-1" />Copiar link</Button>
                            <Button size="sm" color="outline-secondary" onClick={() => setConfirm({ kind: "revoke", id: h.link_id, label: `titular ${h.holder}` })}>Revogar</Button>
                        </>
                    )}
                    {h.holder === 2 && data?.can.delete && (
                        <Button size="sm" color="outline-danger" onClick={() => setConfirm({ kind: "delete-link", id: h.link_id, label: "titular 2" })} aria-label="Apagar o segundo titular"><i className="ri-delete-bin-line" /></Button>
                    )}
                </div>
            </div>
            <div className="text-muted fs-12 mb-2">
                {h.state === "ativo" ? `Válido até ${fmtDay(h.expires_at)}` : null}
                {h.consented_at ? `${h.state === "ativo" ? " · " : ""}Aviso aceite a ${fmtDay(h.consented_at)}${h.signed_name ? ` por ${h.signed_name}` : ""}` : ""}
            </div>
            <dl className="row fs-13 mb-2">
                <dt className="col-5 fw-normal text-muted">Telemóvel</dt><dd className="col-7 mb-1">{h.phone ?? "—"}</dd>
                <dt className="col-5 fw-normal text-muted">Email</dt><dd className="col-7 mb-1 text-break">{h.email ?? "—"}</dd>
                <dt className="col-5 fw-normal text-muted">Habitação</dt><dd className="col-7 mb-1">{h.housing_label ?? "—"}</dd>
                {h.housing_monthly_eur !== null && (<><dt className="col-5 fw-normal text-muted">{h.housing === "arrendada" ? "Renda mensal" : "Prestação mensal"}</dt><dd className="col-7 mb-1">{eur(h.housing_monthly_eur)}</dd></>)}
            </dl>
            {h.documents.length > 0
                ? <DocumentsTable docs={h.documents} onDownload={download} onDelete={data?.can.delete ? (d) => setConfirm({ kind: "delete-doc", id: d.id, label: d.name }) : undefined} />
                : <p className="text-muted fs-13 mb-0">{r.purged_at ? "Os documentos foram apagados automaticamente." : "Ainda não chegou nenhum documento."}</p>}
        </div>
    );

    const body = (
        <>
            {loading && !data ? <div className="text-center py-3"><Spinner size="sm" /></div> : null}
            {data && data.requests.length === 0 && (
                <p className="text-muted mb-0" data-testid="fin-empty">Ainda não pediu documentos{leadId ? " a este cliente" : " para esta viatura"}.</p>
            )}
            {data?.requests.map((r) => (
                <div key={r.id} className="border rounded p-3 mb-3" data-testid={`fin-request-${r.id}`}>
                    <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <div>
                            <strong>Pedido n.º {r.id}</strong>{" "}
                            <Badge color={STATE[r.state].color} className={r.state === "apagado" ? "text-body" : ""}>{STATE[r.state].label}</Badge>
                            <div className="text-muted fs-12">
                                Criado a {fmtDay(r.created_at)}{r.created_by ? ` por ${r.created_by}` : ""}
                                {r.purge_on ? ` · os documentos apagam-se a ${fmtDay(r.purge_on)}` : ""}
                            </div>
                        </div>
                        <div className="d-flex gap-2">
                            {r.can_add_second_holder && data.can.create && !data.antivirus_blocks && (
                                <Button size="sm" color="outline-primary" onClick={() => setSecond(r)}><i className="ri-user-add-line me-1" />Acrescentar segundo titular</Button>
                            )}
                            {data.can.delete && (
                                <Button size="sm" color="outline-danger" onClick={() => setConfirm({ kind: "delete-request", id: r.id, label: `pedido n.º ${r.id}` })} aria-label={`Apagar o pedido n.º ${r.id}`}>
                                    <i className="ri-delete-bin-line" />
                                </Button>
                            )}
                        </div>
                    </div>
                    <div className="d-flex flex-wrap gap-1 mb-2">
                        {r.items.map((it) => {
                            const got = r.holders.some((h) => h.documents.some((d) => d.kind === it.key && d.scan_status !== "infetado"));
                            return <Badge key={it.key} color={got ? "success" : "light"} className={got ? "" : "text-body border"}>{got ? "✓ " : ""}{it.label}</Badge>;
                        })}
                    </div>
                    <Row className="g-2">
                        {r.holders.map((h) => <Col key={h.link_id} lg={r.holders.length > 1 ? 6 : 12}>{holderBlock(r, h)}</Col>)}
                    </Row>
                </div>
            ))}
            {data && (
                <p className="text-muted fs-12 mb-0">
                    Os documentos apagam-se automaticamente {data.retention.days} dias depois do último envio de qualquer titular (com aviso aos administradores {data.retention.warn_days} dias antes).
                    {data.can.settings && <> <button type="button" className="btn btn-link btn-sm p-0 align-baseline" onClick={() => setSettingsOpen(true)}>Definições do envio de documentos</button></>}
                </p>
            )}
        </>
    );

    const modals = data && (
        <>
            <CreateRequestModal isOpen={creating} data={data} onClose={() => setCreating(false)} onCreated={load}
                create={(payload) => (leadId ? createLeadFinancingLink(companyId, leadId, payload) : createCarFinancingLink(companyId, carId as number, payload))} />
            <SecondHolderModal isOpen={!!second} relationships={data.relationships} onClose={() => setSecond(null)}
                add={async (payload) => { const r: any = await addFinancingSecondHolder(companyId, second!.id, payload); load(); return r?.data?.url as string; }} />
            <SettingsModal isOpen={settingsOpen} data={data} onClose={() => setSettingsOpen(false)}
                save={async (days, items) => { await updateFinancingSettings(companyId, days, items); toast.success("Definições guardadas."); setSettingsOpen(false); load(); }} />
            <ConfirmModal
                isOpen={!!confirm} loading={busy} onCancel={() => setConfirm(null)} onConfirm={runConfirm}
                variant={confirm?.kind === "revoke" ? "warning" : "danger"}
                title={confirm?.kind === "revoke" ? "Revogar o link?" : confirm?.kind === "delete-request" ? "Apagar o pedido e os documentos?" : confirm?.kind === "delete-link" ? "Apagar o segundo titular?" : "Apagar o documento?"}
                message={confirm?.kind === "revoke" ? `O ${confirm.label} deixa de poder enviar documentos por este link. Os documentos já recebidos ficam.`
                    : confirm?.kind === "delete-request" ? "Apaga os links de todos os titulares, os dados preenchidos e todos os documentos recebidos. Não se pode desfazer."
                        : confirm?.kind === "delete-link" ? "Apaga o link, os dados e os documentos do segundo titular. Não se pode desfazer."
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
                    <small className="text-muted">Um link privado para cada titular enviar os documentos, sem conta. Na XPLENDOR, só a sua empresa os vê.</small>
                </div>
                {createButton}
            </CardHeader>
            <CardBody>{body}{modals}</CardBody>
        </Card>
    );
}

function RelationshipFields({ relationships, value, other, onChange, onOther, idPrefix }: {
    relationships: { value: string; label: string }[]; value: string; other: string; onChange: (v: string) => void; onOther: (v: string) => void; idPrefix: string;
}) {
    return (
        <>
            <Label for={`${idPrefix}-rel`} className="form-label">Relação ou grau de parentesco com o primeiro titular</Label>
            <Input id={`${idPrefix}-rel`} type="select" value={value} onChange={(e) => onChange(e.target.value)}>
                <option value="">Escolha…</option>
                {relationships.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
            </Input>
            {value === "outro" && <Input className="mt-2" value={other} maxLength={60} onChange={(e) => onOther(e.target.value)} placeholder="Qual?" aria-label="Qual é a relação" />}
        </>
    );
}

function LinkBox({ label, url }: { label: string; url: string }) {
    return (
        <div className="mb-3">
            <Label className="form-label">{label}</Label>
            <div className="d-flex gap-2">
                <Input readOnly value={url} aria-label={label} onFocus={(e) => e.currentTarget.select()} />
                <Button color="primary" onClick={() => copyText(url)}><i className="ri-file-copy-line me-1" />Copiar</Button>
            </div>
        </div>
    );
}

function CreateRequestModal({ isOpen, data, onClose, onCreated, create }: {
    isOpen: boolean; data: Payload; onClose: () => void; onCreated: () => void; create: (p: any) => Promise<any>;
}) {
    const [holders, setHolders] = useState<1 | 2>(1);
    const [name, setName] = useState("");
    const [name2, setName2] = useState("");
    const [rel, setRel] = useState("");
    const [relOther, setRelOther] = useState("");
    const [items, setItems] = useState<string[]>(data.default_items);
    const [other, setOther] = useState("");
    const [irsYear, setIrsYear] = useState(data.defaults.irs_year);
    const [days, setDays] = useState(data.defaults.days);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [created, setCreated] = useState<{ urls: Record<string, string>; expires_at: string } | null>(null);

    // Repõe o formulário só quando a janela abre (a lista recarrega depois de criar e os links não podem sumir).
    const wasOpen = useRef(false);
    useEffect(() => {
        if (isOpen && !wasOpen.current) {
            setHolders(1); setName(""); setName2(""); setRel(""); setRelOther(""); setItems(data.default_items); setOther("");
            setIrsYear(data.defaults.irs_year); setDays(data.defaults.days); setError(null); setCreated(null);
        }
        wasOpen.current = isOpen;
    }, [isOpen, data.default_items, data.defaults.irs_year, data.defaults.days]);

    const toggle = (key: string) => setItems((list) => (list.includes(key) ? list.filter((k) => k !== key) : [...list, key]));

    const submit = async () => {
        setSaving(true);
        setError(null);
        try {
            const r: any = await create({
                customer_name: name.trim(), holders, second_customer_name: holders === 2 ? name2.trim() || undefined : undefined,
                relationship: holders === 2 && rel ? rel : undefined, relationship_other: holders === 2 && rel === "outro" ? relOther.trim() : undefined,
                items, other: items.includes("outro") ? other.trim() : undefined, irs_year: irsYear, days,
            });
            setCreated({ urls: r?.data?.urls ?? {}, expires_at: r?.data?.holders?.[0]?.expires_at });
            onCreated();
        } catch (e) {
            setError(errorOf(e, "Não foi possível criar o pedido."));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal isOpen={isOpen} toggle={!saving ? onClose : undefined} centered scrollable size="lg">
            <ModalHeader toggle={!saving ? onClose : undefined}>Pedir documentos para financiamento</ModalHeader>
            <ModalBody>
                {created ? (
                    <div data-testid="fin-created">
                        <p className="mb-3">Pedido criado. Cada titular tem o seu link: envie a cada um só o seu. Os links valem até {fmtDay(created.expires_at)}.</p>
                        {created.urls["1"] && <LinkBox label="Link do primeiro titular" url={created.urls["1"]} />}
                        {created.urls["2"] && <LinkBox label="Link do segundo titular" url={created.urls["2"]} />}
                        <p className="text-muted fs-12 mb-0">Os titulares não veem nem descarregam ficheiros: só enviam. Cada um aceita o seu aviso e o seu consentimento.</p>
                    </div>
                ) : (
                    <>
                        <div className="mb-3">
                            <Label className="form-label d-block">Titulares</Label>
                            <div className="btn-group" role="group" aria-label="Número de titulares">
                                <Button color={holders === 1 ? "primary" : "outline-primary"} onClick={() => setHolders(1)}>1 titular</Button>
                                <Button color={holders === 2 ? "primary" : "outline-primary"} onClick={() => setHolders(2)}>2 titulares</Button>
                            </div>
                            <small className="text-muted d-block mt-1">O primeiro titular também pode incluir o segundo na página dele, e pode acrescentá-lo aqui mais tarde.</small>
                        </div>
                        <Row className="g-3 mb-3">
                            <Col md={holders === 2 ? 6 : 12}>
                                <Label for="fin-name" className="form-label">Nome do primeiro titular</Label>
                                <Input id="fin-name" value={name} maxLength={120} onChange={(e) => setName(e.target.value)} placeholder="Nome e apelido" />
                            </Col>
                            {holders === 2 && (
                                <Col md={6}>
                                    <Label for="fin-name2" className="form-label">Nome do segundo titular (opcional)</Label>
                                    <Input id="fin-name2" value={name2} maxLength={120} onChange={(e) => setName2(e.target.value)} placeholder="Nome e apelido" />
                                    <div className="mt-2">
                                        <RelationshipFields relationships={data.relationships} value={rel} other={relOther} onChange={setRel} onOther={setRelOther} idPrefix="fin-create" />
                                    </div>
                                </Col>
                            )}
                        </Row>
                        <Label className="form-label">Documentos pedidos (a cada titular)</Label>
                        {data.items.map((it) => (
                            <div className="form-check mb-1" key={it.key}>
                                <Input type="checkbox" className="form-check-input" id={`fin-item-${it.key}`} checked={items.includes(it.key)} onChange={() => toggle(it.key)} />
                                <Label className="form-check-label" for={`fin-item-${it.key}`}>
                                    {it.key === "irs_declaracao" ? `Declaração de IRS de ${irsYear}` : it.label}
                                    {it.hint ? <span className="text-muted fs-12"> · {it.key === "irs_declaracao" ? `A declaração completa, referente aos rendimentos de ${irsYear}.` : it.hint}</span> : null}
                                </Label>
                            </div>
                        ))}
                        {items.includes("irs_declaracao") && (
                            <div className="d-flex align-items-center gap-2 ms-4 mb-2">
                                <Label for="fin-irs-year" className="mb-0 fs-13">Ano dos rendimentos</Label>
                                <Input id="fin-irs-year" type="select" bsSize="sm" style={{ maxWidth: 110 }} value={irsYear} onChange={(e) => setIrsYear(Number(e.target.value))}>
                                    {data.defaults.irs_years.map((y) => <option key={y} value={y}>{y}</option>)}
                                </Input>
                            </div>
                        )}
                        {items.includes("outro") && (
                            <Input className="mt-1 mb-2" value={other} maxLength={120} onChange={(e) => setOther(e.target.value)} placeholder="Que documento? (ex.: declaração da entidade patronal)" aria-label="Descrição do outro documento" />
                        )}
                        <div className="mt-3">
                            <Label for="fin-days" className="form-label">Validade dos links (dias)</Label>
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
                    <Button color="primary" onClick={submit} disabled={saving || name.trim().length < 2 || items.length === 0 || (items.includes("outro") && other.trim() === "")}>
                        {saving ? <><Spinner size="sm" className="me-1" />A criar…</> : "Criar pedido"}
                    </Button>
                )}
            </ModalFooter>
        </Modal>
    );
}

function SecondHolderModal({ isOpen, relationships, onClose, add }: {
    isOpen: boolean; relationships: { value: string; label: string }[]; onClose: () => void;
    add: (p: { customer_name?: string; relationship?: string; relationship_other?: string }) => Promise<string>;
}) {
    const [name, setName] = useState("");
    const [rel, setRel] = useState("");
    const [relOther, setRelOther] = useState("");
    const [url, setUrl] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    useEffect(() => { if (isOpen) { setName(""); setRel(""); setRelOther(""); setUrl(null); } }, [isOpen]);

    return (
        <Modal isOpen={isOpen} toggle={!saving ? onClose : undefined} centered>
            <ModalHeader toggle={!saving ? onClose : undefined}>Acrescentar segundo titular</ModalHeader>
            <ModalBody>
                {url ? <LinkBox label="Link do segundo titular" url={url} /> : (
                    <>
                        <Label for="fin-second-name" className="form-label">Nome do segundo titular (opcional)</Label>
                        <Input id="fin-second-name" className="mb-3" value={name} maxLength={120} onChange={(e) => setName(e.target.value)} />
                        <RelationshipFields relationships={relationships} value={rel} other={relOther} onChange={setRel} onOther={setRelOther} idPrefix="fin-second" />
                    </>
                )}
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onClose} disabled={saving}>{url ? "Fechar" : "Cancelar"}</Button>
                {!url && (
                    <Button color="primary" disabled={saving || (rel === "outro" && relOther.trim() === "")} onClick={async () => {
                        setSaving(true);
                        try { setUrl(await add({ customer_name: name.trim() || undefined, relationship: rel || undefined, relationship_other: rel === "outro" ? relOther.trim() : undefined })); }
                        catch (e) { toast.error(errorOf(e, "Não foi possível acrescentar o segundo titular.")); }
                        finally { setSaving(false); }
                    }}>{saving ? <Spinner size="sm" /> : "Criar o link"}</Button>
                )}
            </ModalFooter>
        </Modal>
    );
}

function SettingsModal({ isOpen, data, onClose, save }: { isOpen: boolean; data: Payload; onClose: () => void; save: (d: number, items: string[]) => Promise<void> }) {
    const [days, setDays] = useState(data.retention.days);
    const [items, setItems] = useState<string[]>(data.default_items);
    const [saving, setSaving] = useState(false);
    const wasOpen = useRef(false);
    useEffect(() => {
        if (isOpen && !wasOpen.current) { setDays(data.retention.days); setItems(data.default_items); }
        wasOpen.current = isOpen;
    }, [isOpen, data.retention.days, data.default_items]);
    const valid = days >= data.retention.min && days <= data.retention.max && items.length > 0;
    const toggle = (key: string) => setItems((list) => (list.includes(key) ? list.filter((k) => k !== key) : [...list, key]));

    return (
        <Modal isOpen={isOpen} toggle={!saving ? onClose : undefined} centered scrollable>
            <ModalHeader toggle={!saving ? onClose : undefined}>Definições do envio de documentos</ModalHeader>
            <ModalBody>
                <Label className="form-label">Lista por omissão</Label>
                <small className="text-muted d-block mb-2">Os documentos que já vêm marcados ao pedir documentos (o vendedor pode mudar em cada pedido).</small>
                {data.items.filter((it) => it.key !== "outro").map((it) => (
                    <div className="form-check mb-1" key={it.key}>
                        <Input type="checkbox" className="form-check-input" id={`fin-default-${it.key}`} checked={items.includes(it.key)} onChange={() => toggle(it.key)} />
                        <Label className="form-check-label" for={`fin-default-${it.key}`}>{it.key === "irs_declaracao" ? "Declaração de IRS (do ano anterior, por omissão)" : it.label}</Label>
                    </div>
                ))}
                <Label for="fin-retention" className="form-label mt-3">Prazo de apagamento: dias depois do último envio</Label>
                <Input id="fin-retention" type="number" min={data.retention.min} max={data.retention.max} value={days} style={{ maxWidth: 140 }} onChange={(e) => setDays(Number(e.target.value))} invalid={days < data.retention.min || days > data.retention.max} />
                <small className="text-muted d-block mt-2">Entre {data.retention.min} e {data.retention.max} dias. Vale para todos os pedidos da empresa (financiamento e retomas), e o aviso da página de envio passa a dizer este prazo.</small>
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onClose} disabled={saving}>Cancelar</Button>
                <Button color="primary" disabled={!valid || saving} onClick={async () => {
                    setSaving(true);
                    try { await save(days, items); } catch (e) { toast.error(errorOf(e, "Não foi possível guardar.")); } finally { setSaving(false); }
                }}>{saving ? <Spinner size="sm" /> : "Guardar"}</Button>
            </ModalFooter>
        </Modal>
    );
}
