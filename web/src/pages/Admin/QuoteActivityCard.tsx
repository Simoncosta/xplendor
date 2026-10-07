import { useState } from "react";
import { Link } from "react-router-dom";
import { Badge, Card, CardBody, CardHeader, Input } from "reactstrap";
import { toast } from "react-toastify";
import { IQuote, IQuoteActivity, formatQuoteEuro, longDateTime, whatsappNumber } from "common/models/quote.model";

/**
 * Link público e atividade de um orçamento (só a equipa): o link da última versão
 * enviada (copiar, enviar por WhatsApp, ver como o cliente), as aberturas (quantas,
 * primeira, última e a cronologia) e as respostas do cliente (aceitação com as linhas
 * aceites, recusa com o motivo, pedidos de alterações com a mensagem).
 */

const DEVICE: Record<string, { label: string; icon: string }> = {
    mobile: { label: "Telemóvel", icon: "ri-smartphone-line" },
    desktop: { label: "Computador", icon: "ri-computer-line" },
};

const RESPONSE_META = {
    accepted: { label: "Aceite", color: "success", icon: "ri-checkbox-circle-line" },
    refused: { label: "Recusado", color: "danger", icon: "ri-close-circle-line" },
    changes_requested: { label: "Pedido de alterações", color: "warning", icon: "ri-edit-2-line" },
} as const;

const TIMELINE_PREVIEW = 8;

type Props = { quote: IQuote; activity: IQuoteActivity | null; loading: boolean };

export default function QuoteActivityCard({ quote, activity, loading }: Props) {
    const [showAll, setShowAll] = useState(false);
    if (!quote.versions?.length) return null;

    const latest = activity?.links.find((l) => l.is_latest) ?? null;
    const unsentVersion = quote.status === "draft" && latest && quote.version > latest.version;
    const waText = latest
        ? `Olá, segue o orçamento ${quote.display_number} da XPLENDOR: ${latest.url}\n\nNa página pode ver a proposta${quote.lines?.some((l) => l.is_optional) ? ", escolher os serviços opcionais" : ""} e responder diretamente. Fico ao dispor para qualquer dúvida.`
        : "";
    const waNumber = whatsappNumber(quote.client_phone);
    const waHref = latest ? `https://wa.me/${waNumber}?text=${encodeURIComponent(waText)}` : "#";

    const copy = async () => {
        if (!latest) return;
        try {
            await navigator.clipboard.writeText(latest.url);
            toast.success("Link copiado.");
        } catch {
            toast.error("Não foi possível copiar. Selecione o link e copie à mão.");
        }
    };

    const opens = activity?.opens;
    const timeline = opens?.timeline ?? [];
    const shown = showAll ? timeline : timeline.slice(0, TIMELINE_PREVIEW);

    return (
        <Card className="mb-3">
            <CardHeader className="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <h6 className="mb-0">Link para o cliente e atividade</h6>
                {opens && (opens.count > 0
                    ? <Badge color="success-subtle" className="text-success"><i className="ri-eye-line me-1" />Aberto {opens.count} {opens.count === 1 ? "vez" : "vezes"}</Badge>
                    : <Badge color="light" className="text-muted">Ainda não foi aberto</Badge>)}
            </CardHeader>
            <CardBody>
                {loading && !activity ? (
                    <p className="text-muted mb-0">A carregar…</p>
                ) : !latest ? (
                    <p className="text-muted mb-0">Sem link: o orçamento ainda não foi enviado.</p>
                ) : (
                    <>
                        {/* Link da última versão enviada */}
                        <div className="mb-3">
                            <div className="text-muted fs-12 mb-1">Link da versão {latest.version}</div>
                            <Input readOnly value={latest.url} onFocus={(e) => e.currentTarget.select()} className="fs-13 mb-2" aria-label="Link público do orçamento" />
                            <div className="d-flex flex-wrap gap-2">
                                <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => void copy()}><i className="ri-file-copy-line me-1" />Copiar link</button>
                                <a className="btn btn-outline-primary btn-sm" href={waHref} target="_blank" rel="noopener noreferrer">
                                    <i className="ri-whatsapp-line me-1" />Enviar por WhatsApp
                                </a>
                                <Link className="btn btn-outline-primary btn-sm" to={`/admin/quotes/${quote.id}/preview/${latest.version}`}>
                                    <i className="ri-eye-line me-1" />Ver como o cliente
                                </Link>
                            </div>
                            {!waNumber && <small className="text-muted d-block mt-1">Sem telemóvel do cliente: o WhatsApp abre para escolher o contacto.</small>}
                            {unsentVersion && (
                                <small className="text-warning d-block mt-2">
                                    A versão {quote.version} ainda não foi enviada. Quem abrir este link vê que o orçamento está a ser revisto.
                                </small>
                            )}
                        </div>

                        {/* Aberturas */}
                        <div className="border-top pt-3 mb-3">
                            <div className="fw-semibold fs-13 mb-2">Aberturas</div>
                            {opens && opens.count > 0 ? (
                                <>
                                    <div className="d-flex flex-wrap gap-3 fs-13 mb-2">
                                        <span><span className="text-muted">Primeira:</span> {longDateTime(opens.first_at)}</span>
                                        <span><span className="text-muted">Última:</span> {longDateTime(opens.last_at)}</span>
                                    </div>
                                    <ul className="list-unstyled vstack gap-1 mb-0 fs-13">
                                        {shown.map((o, i) => (
                                            <li key={`${o.opened_at}-${i}`} className="d-flex align-items-start gap-2">
                                                <i className={`${DEVICE[o.device]?.icon ?? "ri-device-line"} text-muted mt-1`} title={DEVICE[o.device]?.label} />
                                                <div>
                                                    <div>{longDateTime(o.opened_at)}</div>
                                                    <small className="text-muted">{DEVICE[o.device]?.label ?? o.device}{activity && activity.links.length > 1 ? ` · versão ${o.version}` : ""}</small>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                    {timeline.length > TIMELINE_PREVIEW && (
                                        <button type="button" className="btn btn-link btn-sm p-0 mt-1" onClick={() => setShowAll(!showAll)}>
                                            {showAll ? "Mostrar menos" : `Ver as ${timeline.length} aberturas`}
                                        </button>
                                    )}
                                </>
                            ) : (
                                <p className="text-muted fs-13 mb-0">Ainda não foi aberto. As pré-visualizações automáticas (WhatsApp, email) e as aberturas da equipa não contam.</p>
                            )}
                        </div>

                        {/* Respostas do cliente */}
                        <div className="border-top pt-3">
                            <div className="fw-semibold fs-13 mb-2">Respostas do cliente</div>
                            {activity && activity.responses.length > 0 ? (
                                <ul className="list-unstyled vstack gap-3 mb-0">
                                    {activity.responses.map((r, i) => {
                                        const m = RESPONSE_META[r.type];
                                        const b = r.selection?.buckets;
                                        return (
                                            <li key={i} className="border rounded p-2 fs-13">
                                                <div className="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                    <Badge color={`${m.color}-subtle`} className={`text-${m.color}`}><i className={`${m.icon} me-1`} />{m.label}</Badge>
                                                    <span className="text-muted">{longDateTime(r.created_at)} · versão {r.version}</span>
                                                    {r.after_changes_request && <Badge color="warning">Depois de um pedido de alterações</Badge>}
                                                </div>
                                                {r.type === "accepted" && (
                                                    <>
                                                        <div><span className="text-muted">Por</span> {r.name} <span className="text-muted">({r.email})</span>, com "Li e aceito as condições".</div>
                                                        {r.selection?.lines && <div><span className="text-muted">Serviços aceites:</span> {r.selection.lines.map((l) => l.name).join(", ")}</div>}
                                                        {b && (
                                                            <div>
                                                                {b.monthly.count > 0 && <>Mensal <strong>{formatQuoteEuro(b.monthly.total)}/mês</strong></>}
                                                                {b.monthly.count > 0 && b.one_off.count > 0 && " · "}
                                                                {b.one_off.count > 0 && <>Valor único <strong>{formatQuoteEuro(b.one_off.total)}</strong></>}
                                                            </div>
                                                        )}
                                                        {r.selection?.discount && !r.selection.discount.applies && r.selection.discount.reason && (
                                                            <div className="text-warning">{r.selection.discount.reason}</div>
                                                        )}
                                                    </>
                                                )}
                                                {r.type === "refused" && <div>{r.message ? <>Motivo: {r.message}</> : <span className="text-muted">Sem motivo indicado.</span>}</div>}
                                                {r.type === "changes_requested" && <div style={{ whiteSpace: "pre-line" }}>{r.message}</div>}
                                            </li>
                                        );
                                    })}
                                </ul>
                            ) : (
                                <p className="text-muted fs-13 mb-0">Sem respostas pelo link.</p>
                            )}
                            {activity?.onboarding_ticket_id && (
                                <Link to={`/admin/tickets/${activity.onboarding_ticket_id}`} className="btn btn-outline-primary btn-sm mt-3">
                                    <i className="ri-rocket-2-line me-1" />Ver o ticket de arranque
                                </Link>
                            )}
                        </div>
                    </>
                )}
            </CardBody>
        </Card>
    );
}
