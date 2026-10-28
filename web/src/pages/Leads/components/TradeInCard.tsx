import { useCallback, useEffect, useState } from "react";
import { Badge, Button, Input, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import ConfirmModal from "Components/Common/ConfirmModal";
import SendLinkByEmail from "Components/Common/mailbox/SendLinkByEmail";
import ReasonButton from "Components/Common/ReasonButton";
import {
    createLeadTradeInLink, deleteFinancingDocument, deleteTradeInFile, getFinancingDocumentDownload, getLeadTradeIn, getTradeInFileDownload,
    getTradeInLinkUrl, revokeTradeInLink,
} from "helpers/laravel_helper";
import { fileSrc } from "common/models/files.model";
import {
    copyText, DocumentsTable, errorOf, FinancingDocumentDto, FinancingRequestDto, fmtDay, STATE,
} from "pages/Cars/Car/components/FinancingDocumentsCard";

/**
 * A retoma do pedido de proposta, no detalhe da lead: a viatura (marca, modelo, ano, km), as
 * observações do cliente, as fotografias em grelha e o DUA. As fotografias seguem a lead; o DUA
 * (nome e morada do proprietário) só aparece a quem tem a área "Financiamento". O vendedor pode
 * pedir as fotografias depois, com um link do mesmo tipo dos documentos.
 */
interface Payload {
    trade_in: { brand?: string; model?: string; year?: number; km?: number } | null;
    requests: FinancingRequestDto[];
    can: { request: boolean; delete: boolean; see_dua: boolean; delete_dua: boolean };
    defaults: { days: number; max_days: number };
    antivirus_blocks: boolean;
}

const KIND: Record<string, string> = { exterior: "Exterior", interior: "Interior", odometro: "Conta-quilómetros" };

export default function TradeInCard({ companyId, leadId }: { companyId: number; leadId: number }) {
    const [data, setData] = useState<Payload | null>(null);
    const [failed, setFailed] = useState(false);
    const [link, setLink] = useState<string | null>(null);
    const [days, setDays] = useState(7);
    const [busy, setBusy] = useState(false);
    const [confirm, setConfirm] = useState<{ doc: FinancingDocumentDto } | null>(null);

    const load = useCallback(() => {
        getLeadTradeIn(companyId, leadId).then((r: any) => setData(r?.data ?? null)).catch(() => setFailed(true));
    }, [companyId, leadId]);
    useEffect(() => { load(); }, [load]);

    if (failed || !data) return null;
    if (!data.trade_in && data.requests.length === 0) return null;

    const t = data.trade_in;
    const notes = data.requests.map((r) => r.notes).filter(Boolean) as string[];
    const holders = data.requests.flatMap((r) => r.holders);
    const docs = holders.flatMap((h) => h.documents);
    const photos = docs.filter((d) => !d.personal);
    const dua = docs.filter((d) => d.personal);
    const hidden = holders.reduce((n, h) => n + (h.hidden_documents ?? 0), 0);

    const open = async (d: FinancingDocumentDto) => {
        try {
            const r: any = d.personal ? await getFinancingDocumentDownload(companyId, d.id) : await getTradeInFileDownload(companyId, d.id);
            window.location.href = fileSrc(r?.data?.url) ?? "#";
        } catch (e) {
            toast.error(errorOf(e, "Não foi possível descarregar."));
        }
    };

    const requestLink = async () => {
        setBusy(true);
        try {
            const r: any = await createLeadTradeInLink(companyId, leadId, days);
            setLink(r?.data?.url ?? null);
            load();
        } catch (e) {
            toast.error(errorOf(e, "Não foi possível criar o link."));
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="mt-3" data-testid="trade-in-card">
            <div className="d-flex align-items-center justify-content-between gap-2 mb-2">
                <span className="form-label text-uppercase fs-12 mb-0">Retoma</span>
                <ReasonButton size="sm" color="outline-primary" onClick={requestLink} disabled={busy}
                    reason={!data.can.request ? "O seu perfil não permite pedir fotografias." : data.antivirus_blocks ? "O envio fica disponível quando o antivírus estiver ativo." : null}>
                    <i className="ri-camera-line me-1" />Pedir fotografias da retoma
                </ReasonButton>
            </div>
            {t && (
                <p className="mb-2 fs-13">
                    <strong>{[t.brand, t.model].filter(Boolean).join(" ") || "Viatura"}</strong>
                    {t.year ? ` · ${t.year}` : ""}{t.km != null ? ` · ${t.km.toLocaleString("pt-PT")} km` : ""}
                </p>
            )}
            {notes.map((n, i) => <p key={i} className="text-muted fs-13 mb-2" style={{ whiteSpace: "pre-wrap" }}>«{n}»</p>)}

            {link && (
                <div className="d-flex gap-2 mb-2" data-testid="trade-in-link">
                    <Input readOnly value={link} aria-label="Link da retoma" bsSize="sm" onFocus={(e) => e.currentTarget.select()} />
                    <Button size="sm" color="primary" onClick={() => copyText(link, "Link copiado. Envie-o ao cliente.")}>Copiar</Button>
                </div>
            )}
            {!link && data.can.request && (
                <div className="d-flex align-items-center gap-2 mb-2 fs-12 text-muted">
                    Validade do link
                    <Input type="number" bsSize="sm" min={1} max={data.defaults.max_days} value={days} style={{ maxWidth: 70 }}
                        onChange={(e) => setDays(Math.max(1, Math.min(data.defaults.max_days, Number(e.target.value) || 1)))} aria-label="Validade do link da retoma (dias)" /> dias
                </div>
            )}

            {photos.length > 0 ? (
                <div className="d-grid gap-2 mb-2" style={{ gridTemplateColumns: "repeat(auto-fill, minmax(110px, 1fr))" }} data-testid="trade-in-photos">
                    {photos.map((p) => (
                        <div key={p.id} className="border rounded overflow-hidden position-relative">
                            {p.thumb_url
                                ? <img src={fileSrc(p.thumb_url)} alt={p.name} style={{ width: "100%", height: 90, objectFit: "cover", display: "block" }} />
                                : <div className="d-flex align-items-center justify-content-center bg-light text-muted" style={{ height: 90 }}>
                                    {p.scan_status === "pendente" ? <><Spinner size="sm" className="me-1" />Em análise</> : p.scan_status === "infetado" ? "Recusado" : <i className="ri-image-line fs-3" />}
                                </div>}
                            <div className="d-flex align-items-center justify-content-between px-1 py-1">
                                <Badge color="light" className="text-body">{KIND[p.kind] ?? p.kind_label}</Badge>
                                <span>
                                    {p.downloadable && <button type="button" className="btn btn-link btn-sm p-0 me-1" onClick={() => open(p)} aria-label={`Descarregar ${p.name}`}><i className="ri-download-2-line" /></button>}
                                    {data.can.delete && <button type="button" className="btn btn-link btn-sm p-0 text-danger" onClick={() => setConfirm({ doc: p })} aria-label={`Apagar ${p.name}`}><i className="ri-delete-bin-line" /></button>}
                                </span>
                            </div>
                        </div>
                    ))}
                </div>
            ) : <p className="text-muted fs-13 mb-2">Ainda não chegaram fotografias.</p>}

            {data.can.see_dua
                ? (dua.length > 0 && <DocumentsTable docs={dua} onDownload={open} onDelete={data.can.delete_dua ? (d) => setConfirm({ doc: d }) : undefined} />)
                : hidden > 0 && <p className="text-muted fs-12 mb-2"><i className="ri-lock-line me-1" />O DUA chegou, mas só o veem as pessoas com acesso ao Financiamento (tem dados pessoais do proprietário).</p>}

            {data.requests.map((r) => r.holders.map((h) => (
                <div key={h.link_id} className="d-flex align-items-center justify-content-between fs-12 text-muted mt-1">
                    <span>Link de {fmtDay(r.created_at)} <Badge color={STATE[h.state].color} className={h.state === "apagado" ? "text-body" : ""}>{STATE[h.state].label}</Badge>{h.state === "ativo" ? ` · até ${fmtDay(h.expires_at)}` : ""}</span>
                    {h.state === "ativo" && data.can.request && (
                        <span className="d-flex gap-2">
                            <button type="button" className="btn btn-link btn-sm p-0" onClick={async () => { try { const x: any = await getTradeInLinkUrl(companyId, h.link_id); if (x?.data?.url) await copyText(x.data.url, "Link copiado. Envie-o ao cliente."); } catch (e) { toast.error(errorOf(e, "Não foi possível obter o link.")); } }}>Copiar link</button>
                            <SendLinkByEmail companyId={companyId} kind="trade_in" linkId={h.link_id} variant="link" />
                            <button type="button" className="btn btn-link btn-sm p-0" onClick={async () => { await revokeTradeInLink(companyId, h.link_id); load(); }}>Revogar</button>
                        </span>
                    )}
                </div>
            )))}

            <ConfirmModal isOpen={!!confirm} title="Apagar o ficheiro?" message={`Apaga "${confirm?.doc.name}". Não se pode desfazer.`} variant="danger" confirmText="Apagar"
                onCancel={() => setConfirm(null)} onConfirm={async () => {
                    if (!confirm) return;
                    try {
                        if (confirm.doc.personal) await deleteFinancingDocument(companyId, confirm.doc.id); else await deleteTradeInFile(companyId, confirm.doc.id);
                        setConfirm(null);
                        load();
                    } catch (e) { toast.error(errorOf(e, "Não foi possível apagar.")); }
                }} />
        </div>
    );
}
