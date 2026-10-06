import { useCallback, useEffect, useMemo, useState } from "react";
import { Badge, Button, Input, Label, Modal, ModalBody, ModalHeader, Spinner } from "reactstrap";
import { useNavigate } from "react-router-dom";
import { toast } from "react-toastify";
import { createReviewLink, extendReviewLink, getReviewCandidates, getReviewLinks, resendReviewLink, revokeReviewLink } from "helpers/laravel_helper";
import { Network, POST_CHANNEL_META } from "common/models/editorialPost.model";
import { mediaSrc } from "common/models/editorialWorkflow.model";
import { ITEM_STATE_META, LINK_STATE_META, ReviewCandidate, ReviewLinkSummary, whatsappUrl } from "common/models/contentReview.model";

/**
 * Links de aprovação por lote (F3c): escolher publicações em Aprovação, indicar o
 * destinatário (opcional), enviar (email ao cliente, se indicado) e partilhar por
 * WhatsApp. Reenviar atualiza os itens no MESMO link; prolongar dá mais 14 dias; revogar
 * deixa o link só para consulta. "Ver como o cliente" não conta como abertura.
 */

const COUNT_LABEL: Record<keyof typeof ITEM_STATE_META, (n: number) => string> = {
    pending: (n) => (n === 1 ? "pendente" : "pendentes"),
    approved: (n) => (n === 1 ? "aprovada" : "aprovadas"),
    changes_requested: () => "com alterações pedidas",
    outdated: (n) => (n === 1 ? "atualizada pela equipa" : "atualizadas pela equipa"),
};
const MONTHS = ["Janeiro", "Fevereiro", "Março", "Abril", "Maio", "Junho", "Julho", "Agosto", "Setembro", "Outubro", "Novembro", "Dezembro"];
const dmy = (iso: string | null) => (iso ? iso.slice(0, 10).split("-").reverse().join("/") : "");
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString("pt-PT", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" }) : "");
/** "Novembro, semana 1" a partir da data da primeira publicação. */
const batchTitle = (iso: string | undefined) => {
    if (!iso) return "";
    const [, m, d] = iso.split("-").map(Number);
    return `${MONTHS[m - 1]}, semana ${Math.ceil(d / 7)}`;
};
const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};

type Form = { id: number | null; title: string; postIds: number[]; name: string; email: string };
type Props = { isOpen: boolean; toggle: () => void; companyId: number; canProduce: boolean; focusLinkId?: number | null; onChanged?: () => void };

export default function ReviewLinksModal({ isOpen, toggle, companyId, canProduce, focusLinkId, onChanged }: Props) {
    const navigate = useNavigate();
    const [links, setLinks] = useState<ReviewLinkSummary[] | null>(null);
    const [candidates, setCandidates] = useState<ReviewCandidate[]>([]);
    const [form, setForm] = useState<Form | null>(null);
    const [sent, setSent] = useState<ReviewLinkSummary | null>(null);
    const [busy, setBusy] = useState(false);
    const [confirmRevoke, setConfirmRevoke] = useState<number | null>(null);

    const load = useCallback(async () => {
        try {
            const r: any = await getReviewLinks(companyId);
            setLinks(r.data.links);
        } catch (e: any) {
            setLinks([]);
            toast.error(errorMessage(e, "Não foi possível carregar os links."));
        }
    }, [companyId]);

    useEffect(() => { if (isOpen) { setForm(null); setSent(null); setLinks(null); void load(); } }, [isOpen, load]);
    useEffect(() => {
        if (isOpen && focusLinkId && links) setTimeout(() => document.getElementById(`rl-${focusLinkId}`)?.scrollIntoView({ block: "center" }), 100);
    }, [isOpen, focusLinkId, links]);

    const openForm = async (link?: ReviewLinkSummary) => {
        setBusy(true);
        try {
            const r: any = await getReviewCandidates(companyId);
            const list: ReviewCandidate[] = r.data;
            setCandidates(list);
            const ids = new Set(list.map((c) => c.id));
            setForm(link
                ? { id: link.id, title: link.title, postIds: link.items.map((i) => i.post_id).filter((id) => ids.has(id)), name: link.recipient_name ?? "", email: link.recipient_email ?? "" }
                : { id: null, title: batchTitle(list[0]?.publish_date), postIds: list.filter((c) => c.in_links.length === 0).map((c) => c.id), name: "", email: "" });
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível carregar as publicações em Aprovação."));
        } finally {
            setBusy(false);
        }
    };

    const submit = async () => {
        if (!form) return;
        setBusy(true);
        try {
            const body = { title: form.title.trim(), post_ids: form.postIds, recipient_name: form.name.trim() || undefined, recipient_email: form.email.trim() || undefined };
            const r: any = form.id ? await resendReviewLink(companyId, form.id, body) : await createReviewLink(companyId, body);
            toast.success(r.message ?? "Link enviado.");
            setSent(r.data);
            setForm(null);
            setLinks((ls) => [r.data, ...(ls ?? []).filter((l) => l.id !== r.data.id)]);
            void load();
            onChanged?.();
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível enviar o link."));
        } finally {
            setBusy(false);
        }
    };

    const act = async (fn: () => Promise<any>) => {
        setBusy(true);
        try {
            const r: any = await fn();
            toast.success(r.message ?? "Feito.");
            await load();
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível concluir."));
        } finally {
            setBusy(false);
            setConfirmRevoke(null);
        }
    };

    const copy = async (url: string) => {
        try { await navigator.clipboard.writeText(url); toast.success("Link copiado."); } catch { toast.error("Não foi possível copiar. Selecione o link e copie à mão."); }
    };

    const togglePost = (id: number) => setForm((f) => f && ({ ...f, postIds: f.postIds.includes(id) ? f.postIds.filter((x) => x !== id) : [...f.postIds, id] }));
    const allSelected = useMemo(() => form && candidates.length > 0 && candidates.every((c) => form.postIds.includes(c.id)), [form, candidates]);

    const shareButtons = (l: ReviewLinkSummary) => l.url && (
        <>
            <Button size="sm" color="soft-primary" onClick={() => void copy(l.url!)}><i className="ri-file-copy-line me-1" />Copiar link</Button>
            <a className="btn btn-sm btn-soft-success" href={whatsappUrl(l.share_message ?? l.url)} target="_blank" rel="noreferrer noopener"><i className="ri-whatsapp-line me-1" />Partilhar por WhatsApp</a>
        </>
    );

    return (
        <Modal isOpen={isOpen} toggle={toggle} size="lg" centered scrollable>
            <ModalHeader toggle={toggle}>{form ? (form.id ? "Reenviar no mesmo link" : "Novo lote para aprovação") : "Aprovação por link"}</ModalHeader>
            <ModalBody>
                {form ? (
                    <div>
                        <div className="row g-2 mb-3">
                            <div className="col-12">
                                <Label className="mb-1" for="rl-title">Nome do lote</Label>
                                <Input id="rl-title" maxLength={120} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} placeholder='Por exemplo, "Novembro, semana 1"' />
                            </div>
                            <div className="col-md-6">
                                <Label className="mb-1" for="rl-name">Destinatário <span className="text-muted fw-normal">(opcional)</span></Label>
                                <Input id="rl-name" maxLength={120} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                            </div>
                            <div className="col-md-6">
                                <Label className="mb-1" for="rl-email">Email <span className="text-muted fw-normal">(opcional; recebe o link)</span></Label>
                                <Input id="rl-email" type="email" maxLength={190} value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
                            </div>
                        </div>
                        <div className="d-flex align-items-center justify-content-between mb-2">
                            <strong className="fs-13">Publicações em Aprovação ({form.postIds.length} escolhidas)</strong>
                            {candidates.length > 0 && (
                                <Button color="link" size="sm" className="p-0" onClick={() => setForm({ ...form, postIds: allSelected ? [] : candidates.map((c) => c.id) })}>
                                    {allSelected ? "Desmarcar todas" : "Escolher todas"}
                                </Button>
                            )}
                        </div>
                        {candidates.length === 0 && <p className="text-muted fs-13">Não há publicações em Aprovação. Envie primeiro as publicações para Aprovação no Kanban ou na janela de produção.</p>}
                        <div className="vstack gap-1 mb-3">
                            {candidates.map((c) => (
                                <label key={c.id} className="d-flex align-items-center gap-2 border rounded p-2 mb-0" style={{ cursor: "pointer" }}>
                                    <Input type="checkbox" className="m-0 flex-shrink-0" checked={form.postIds.includes(c.id)} onChange={() => togglePost(c.id)} />
                                    {c.thumb_url
                                        ? <img src={mediaSrc(c.thumb_url)} alt="" className="rounded flex-shrink-0" style={{ width: 40, height: 40, objectFit: "cover" }} />
                                        : <span className="rounded bg-light flex-shrink-0" style={{ width: 40, height: 40 }} />}
                                    <span className="flex-grow-1 min-w-0">
                                        <span className="d-block text-truncate fs-13">{Object.keys(c.networks).map((n) => <i key={n} className={`${POST_CHANNEL_META[n as Network].icon} me-1`} />)}{c.title}</span>
                                        <span className="text-muted fs-12">{dmy(c.publish_date)} · versão {c.version_number}{c.in_links.length > 0 ? ` · já em "${c.in_links.map((l) => l.title).join('", "')}"` : ""}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                        {form.id && <p className="text-muted fs-12">As publicações já decididas ficam no link para consulta. As escolhidas passam para a versão atual e o cliente volta a poder decidir.</p>}
                        <div className="d-flex gap-2 justify-content-end">
                            <Button color="light" onClick={() => setForm(null)}>Cancelar</Button>
                            <Button color="primary" disabled={busy || form.postIds.length === 0 || !form.title.trim()} onClick={() => void submit()}>
                                {busy ? <Spinner size="sm" /> : <><i className="ri-send-plane-line me-1" />{form.id ? "Reenviar" : form.email.trim() ? "Criar e enviar por email" : "Criar link"}</>}
                            </Button>
                        </div>
                    </div>
                ) : (
                    <>
                        {sent && (
                            <div className="alert alert-success">
                                <div className="fw-semibold mb-1"><i className="ri-checkbox-circle-line me-1" />Link pronto: {sent.title}</div>
                                <div className="fs-12 text-break mb-2">{sent.url}</div>
                                <div className="d-flex flex-wrap gap-2">
                                    {shareButtons(sent)}
                                    <Button size="sm" color="soft-secondary" onClick={() => navigate(`/editorial/aprovacao/${sent.id}/ver`)}><i className="ri-eye-line me-1" />Ver como o cliente</Button>
                                </div>
                            </div>
                        )}
                        <div className="d-flex align-items-center justify-content-between mb-3">
                            <p className="text-muted fs-13 mb-0">O cliente aprova sem conta, no telemóvel, com a pré-visualização como na rede. O link é válido 14 dias.</p>
                            {canProduce && <Button color="primary" size="sm" className="flex-shrink-0 ms-2" disabled={busy} onClick={() => void openForm()}><i className="ri-add-line me-1" />Novo lote</Button>}
                        </div>
                        {!links ? <div className="text-center py-4"><Spinner /></div> : links.length === 0 ? (
                            <p className="text-muted fs-13 text-center py-3 border rounded mb-0">Ainda não há links de aprovação.</p>
                        ) : (
                            <div className="vstack gap-2">
                                {links.map((l) => (
                                    <div key={l.id} id={`rl-${l.id}`} className={`border rounded p-2 ${focusLinkId === l.id ? "border-primary" : ""}`}>
                                        <div className="d-flex flex-wrap align-items-center gap-2 justify-content-between">
                                            <div className="min-w-0">
                                                <strong>{l.title}</strong> <Badge color={`${LINK_STATE_META[l.state].color}-subtle`} className={`text-${LINK_STATE_META[l.state].color}`}>{LINK_STATE_META[l.state].label}</Badge>
                                                <div className="text-muted fs-12">
                                                    {l.recipient_name || l.recipient_email ? `Para ${[l.recipient_name, l.recipient_email].filter(Boolean).join(", ")} · ` : ""}
                                                    enviado {when(l.last_sent_at)} · {l.state === "open" ? `válido até ${dmy(l.expires_at)}` : l.state === "expired" ? `expirou a ${dmy(l.expires_at)}` : "revogado"}
                                                </div>
                                                <div className="text-muted fs-12">
                                                    {l.opens.count > 0 ? `Aberto ${l.opens.count} ${l.opens.count === 1 ? "vez" : "vezes"} (última ${when(l.opens.last_at)})` : "Ainda não foi aberto"}
                                                </div>
                                            </div>
                                            <div className="d-flex flex-wrap gap-1 fs-12">
                                                {(Object.keys(ITEM_STATE_META) as (keyof typeof ITEM_STATE_META)[]).filter((s) => l.counts[s] > 0).map((s) => (
                                                    <Badge key={s} color={`${ITEM_STATE_META[s].color}-subtle`} className={`text-${ITEM_STATE_META[s].color}`} title={ITEM_STATE_META[s].label}>
                                                        <i className={`${ITEM_STATE_META[s].icon} me-1`} />{l.counts[s]} {COUNT_LABEL[s](l.counts[s])}
                                                    </Badge>
                                                ))}
                                            </div>
                                        </div>
                                        <ul className="list-unstyled fs-12 my-2">
                                            {l.items.map((i) => (
                                                <li key={i.id} className="d-flex gap-1 align-items-center">
                                                    <i className={`${ITEM_STATE_META[i.state].icon} text-${ITEM_STATE_META[i.state].color}`} title={ITEM_STATE_META[i.state].label} />
                                                    <span className="text-truncate">{i.title}</span><span className="text-muted">· {dmy(i.publish_date)}</span>
                                                </li>
                                            ))}
                                        </ul>
                                        <div className="d-flex flex-wrap gap-1">
                                            {l.state !== "revoked" && shareButtons(l)}
                                            <Button size="sm" color="soft-secondary" onClick={() => navigate(`/editorial/aprovacao/${l.id}/ver`)}><i className="ri-eye-line me-1" />Ver como o cliente</Button>
                                            {canProduce && l.state !== "revoked" && (
                                                <>
                                                    <Button size="sm" color="soft-info" disabled={busy} onClick={() => void openForm(l)}><i className="ri-refresh-line me-1" />Reenviar</Button>
                                                    <Button size="sm" color="soft-secondary" disabled={busy} onClick={() => void act(() => extendReviewLink(companyId, l.id))}><i className="ri-time-line me-1" />Prolongar 14 dias</Button>
                                                    {confirmRevoke === l.id ? (
                                                        <span className="d-inline-flex gap-1 align-items-center fs-12">
                                                            Revogar? O cliente fica só a consultar.
                                                            <Button size="sm" color="danger" disabled={busy} onClick={() => void act(() => revokeReviewLink(companyId, l.id))}>Revogar</Button>
                                                            <Button size="sm" color="light" onClick={() => setConfirmRevoke(null)}>Não</Button>
                                                        </span>
                                                    ) : (
                                                        <Button size="sm" color="soft-danger" disabled={busy} onClick={() => setConfirmRevoke(l.id)}><i className="ri-forbid-line me-1" />Revogar</Button>
                                                    )}
                                                </>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </>
                )}
            </ModalBody>
        </Modal>
    );
}
