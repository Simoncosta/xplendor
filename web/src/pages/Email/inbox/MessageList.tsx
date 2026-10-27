import { Spinner } from "reactstrap";
import { MailSummary, shortDate } from "./types";
import { MailThread, groupThreads } from "./threads";

/**
 * E-mail 4 · A lista da pasta, agrupada por conversa como no Gmail (os remetentes e o número de
 * mensagens), no formato do message-list do modelo: as conversas por ler a negrito e o clipe quando
 * alguma mensagem tem anexos.
 */
export default function MessageList({ items, myEmail, loading, total, page, perPage, onOpen, onPage, onRefresh, title }: {
    items: MailSummary[]; myEmail: string; loading: boolean; total: number; page: number; perPage: number;
    onOpen: (thread: MailThread) => void; onPage: (page: number) => void; onRefresh: () => void; title: string;
}) {
    const first = total === 0 ? 0 : (page - 1) * perPage + 1;
    const last = Math.min(total, page * perPage);
    const threads = groupThreads(items, myEmail);
    return (
        <div className="email-content">
            <div className="p-4 pb-0">
                <div className="border-bottom border-bottom-dashed pb-3 d-flex align-items-center gap-2">
                    <h5 className="mb-0 flex-grow-1">{title}</h5>
                    <span className="text-muted fs-13" data-testid="mail-range">{total > 0 ? `${first} a ${last} de ${total}` : ""}</span>
                    <div className="d-flex gap-1 email-topbar-link">
                        <button type="button" className="btn btn-ghost-secondary btn-icon btn-sm fs-16" title="Anteriores" aria-label="Anteriores"
                            disabled={page <= 1 || loading} onClick={() => onPage(page - 1)}><i className="ri-arrow-left-s-line" /></button>
                        <button type="button" className="btn btn-ghost-secondary btn-icon btn-sm fs-16" title="Seguintes" aria-label="Seguintes"
                            disabled={last >= total || loading} onClick={() => onPage(page + 1)}><i className="ri-arrow-right-s-line" /></button>
                        <button type="button" className="btn btn-ghost-secondary btn-icon btn-sm fs-16" title="Atualizar" aria-label="Atualizar"
                            disabled={loading} onClick={onRefresh}><i className="ri-refresh-line" /></button>
                    </div>
                </div>
                <div className="message-list-content mx-n4 px-4 message-list-scroll overflow-auto position-relative">
                    {loading ? (
                        <div className="text-center py-5 text-muted"><Spinner size="sm" className="me-2" />A ler a caixa…</div>
                    ) : (
                        <ul className="message-list" data-testid="mail-list">
                            {threads.length === 0 ? <li className="text-center text-muted">Não há mensagens nesta pasta.</li> : threads.map((t) => (
                                <li key={t.key} className={t.unread ? "unread" : ""} onClick={() => onOpen(t)} role="button" data-testid="mail-thread">
                                    <div className="col-mail col-mail-1">
                                        <span className="dot" style={{ borderColor: t.unread ? "var(--vz-primary)" : "transparent" }} />
                                        <a href="#!" className={`title ${t.unread ? "fw-semibold" : ""}`} onClick={(e) => e.preventDefault()}>
                                            {t.senders}{t.count > 1 && <span className="text-muted fw-normal ms-1" data-testid="mail-thread-count">{t.count}</span>}
                                        </a>
                                    </div>
                                    <div className="col-mail col-mail-2">
                                        <a href="#!" className={`subject ${t.unread ? "fw-semibold" : ""}`} style={{ right: 140 }} onClick={(e) => e.preventDefault()}>{t.latest.subject || "(sem assunto)"}</a>
                                        <div className="date d-flex align-items-center justify-content-end gap-2" style={{ width: 130 }}>
                                            {t.hasAttachments && <i className="ri-attachment-2 text-muted fs-15" title="Tem anexos" aria-label="Tem anexos" data-testid="mail-clip" />}
                                            <span className={t.unread ? "fw-semibold" : ""}>{shortDate(t.latest.date)}</span>
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </div>
    );
}
