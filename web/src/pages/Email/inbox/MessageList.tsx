import { MailSummary, shortDate } from "./types";
import { MailThread, groupThreads } from "./threads";
import { ListSkeleton } from "./skeletons";

/**
 * E-mail 4 · A lista da pasta, agrupada por conversa como no Gmail (os remetentes e o número de
 * mensagens), no formato do message-list do modelo: as conversas por ler a negrito e o clipe quando
 * alguma mensagem tem anexos.
 * E-mail 10 · Como no modelo EmailInbox: a caixa de seleção em cada conversa e "selecionar todas"
 * (só as da página visível), com a seleção as ações (marcar como lidas, por ler, apagar), e a
 * estrela em cada conversa. Na Entrada, as com estrela ficam no topo da primeira página.
 */
export type ListActions = {
    selected: Set<string>;
    onToggleSelect: (thread: MailThread) => void;
    onToggleAll: (threads: MailThread[], select: boolean) => void;
    onStar: (thread: MailThread) => void;
    onMarkSelected: (threads: MailThread[], seen: boolean) => void;
    onDeleteSelected: (threads: MailThread[]) => void;
};

export default function MessageList({ items, starred = [], starredTotal = 0, myEmail, loading, total, page, perPage, onOpen, onPage, onRefresh, title, actions, empty }: {
    items: MailSummary[]; starred?: MailSummary[]; starredTotal?: number; myEmail: string; loading: boolean; total: number; page: number; perPage: number;
    onOpen: (thread: MailThread) => void; onPage: (page: number) => void; onRefresh: () => void; title: string; actions?: ListActions; empty?: string;
}) {
    const first = total === 0 ? 0 : (page - 1) * perPage + 1;
    const last = Math.min(total, page * perPage);
    const starredThreads = groupThreads(starred, myEmail);
    const threads = groupThreads(items, myEmail);
    const visible = [...starredThreads, ...threads];
    const selectedThreads = actions ? visible.filter((t) => actions.selected.has(t.key)) : [];
    const allSelected = visible.length > 0 && selectedThreads.length === visible.length;

    const row = (t: MailThread) => (
        <li key={t.key} className={t.unread ? "unread" : ""} onClick={() => onOpen(t)} role="button" data-testid="mail-thread">
            <div className="col-mail col-mail-1">
                {actions && (
                    <div className="form-check checkbox-wrapper-mail fs-14" onClick={(e) => e.stopPropagation()}>
                        <input className="form-check-input" type="checkbox" id={`mail-${t.key}`} checked={actions.selected.has(t.key)}
                            onChange={() => actions.onToggleSelect(t)} aria-label={`Selecionar: ${t.latest.subject || "(sem assunto)"}`} data-testid="mail-select" />
                        <label className="form-check-label" htmlFor={`mail-${t.key}`} />
                    </div>
                )}
                {actions && (
                    <button type="button" className={`btn avatar-xs p-0 favourite-btn fs-15 ${t.flagged ? "active" : ""}`} data-testid="mail-star"
                        aria-label={t.flagged ? "Tirar a estrela" : "Pôr estrela"} aria-pressed={t.flagged} title={t.flagged ? "Tirar a estrela" : "Pôr estrela"}
                        onClick={(e) => { e.stopPropagation(); actions.onStar(t); }}>
                        <i className={t.flagged ? "ri-star-fill text-warning" : "ri-star-line text-muted"} />
                    </button>
                )}
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
    );

    return (
        <div className="email-content">
            <div className="p-4 pb-0">
                <div className="border-bottom border-bottom-dashed pb-3 d-flex align-items-center gap-2 flex-wrap">
                    {actions && (
                        <div className="form-check fs-14 m-0">
                            <input className="form-check-input" type="checkbox" id="mail-checkall" checked={allSelected} disabled={loading || visible.length === 0}
                                onChange={() => actions.onToggleAll(visible, !allSelected)} aria-label="Selecionar todas as desta página" data-testid="mail-select-all" />
                            <label className="form-check-label" htmlFor="mail-checkall" />
                        </div>
                    )}
                    {actions && selectedThreads.length > 0 ? (
                        <div className="d-flex align-items-center gap-1 flex-grow-1 email-topbar-link" data-testid="mail-selection-actions">
                            <span className="text-muted fs-13 me-2">{selectedThreads.length} selecionada{selectedThreads.length === 1 ? "" : "s"}</span>
                            <button type="button" className="btn btn-ghost-secondary btn-sm" onClick={() => actions.onMarkSelected(selectedThreads, true)} data-testid="mail-mark-read">
                                <i className="ri-mail-open-line me-1" />Marcar como lidas
                            </button>
                            <button type="button" className="btn btn-ghost-secondary btn-sm" onClick={() => actions.onMarkSelected(selectedThreads, false)} data-testid="mail-mark-unread">
                                <i className="ri-mail-unread-line me-1" />Marcar como por ler
                            </button>
                            <button type="button" className="btn btn-ghost-danger btn-sm" onClick={() => actions.onDeleteSelected(selectedThreads)} data-testid="mail-delete-selected">
                                <i className="ri-delete-bin-5-line me-1" />Apagar
                            </button>
                        </div>
                    ) : (
                        <h5 className="mb-0 flex-grow-1">{title}</h5>
                    )}
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
                        <ListSkeleton />
                    ) : (
                        <>
                            {starredThreads.length > 0 && (
                                <>
                                    <div className="text-muted fs-12 text-uppercase fw-semibold mt-3" data-testid="mail-starred-title">
                                        <i className="ri-star-fill text-warning me-1" />Com estrela{starredTotal > starred.length ? ` (as ${starred.length} mais recentes de ${starredTotal})` : ""}
                                    </div>
                                    <ul className="message-list" data-testid="mail-starred-list">{starredThreads.map(row)}</ul>
                                    <div className="text-muted fs-12 text-uppercase fw-semibold mt-2">Todas as outras</div>
                                </>
                            )}
                            <ul className="message-list" data-testid="mail-list">
                                {threads.length === 0
                                    ? <li className="text-center text-muted">{starredThreads.length > 0 ? "Não há outras mensagens." : (empty ?? "Não há mensagens nesta pasta.")}</li>
                                    : threads.map(row)}
                            </ul>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}
