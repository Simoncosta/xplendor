import { useCallback, useEffect, useState } from "react";
import { Alert } from "reactstrap";
import { toast } from "react-toastify";
import { getMailboxFolders, getMailboxMessages, getMailboxThread, restoreMailboxMessages, setMailboxFlag, setMailboxSeenMany, trashMailboxMessages } from "helpers/laravel_helper";
import { adjustMailboxUnread, refreshMailboxUnread } from "Components/Common/mailbox/useMailboxUnread";
import ComposeModal, { ComposeValues, quoteHeaderFor, replySubject } from "Components/Common/mailbox/ComposeModal";
import EmailSidebar from "./EmailSidebar";
import MessageList from "./MessageList";
import ThreadView from "./ThreadView";
import { MailThread } from "./threads";
import { MailFolder, MailFull, MailSummary, STARRED_VIEW } from "./types";

/**
 * E-mail 4 · A caixa de entrada (modelo EmailInbox), lida ao vivo do servidor de e-mail. Como no
 * Gmail: a lista agrupada por conversa e, ao abrir, a conversa ocupa o lugar da lista ("Voltar" e o
 * "Retroceder" do navegador regressam à lista). Abrir marca as mensagens como lidas; abrir a Entrada
 * liga as respostas novas e atualiza os não lidos (o envelope e o menu acompanham).
 * E-mail 5: "Escrever" e "Responder" (no mesmo fio, com o original citado por baixo).
 * E-mail 10: seleção (marcar como lidas, por ler, apagar), a estrela, o filtro "Com estrela" e
 * apagar para o Lixo da caixa com "Desfazer". Tudo no servidor de e-mail; o ecrã muda logo e volta
 * atrás (com o motivo) se o servidor der erro.
 */
type Compose = { title: string; initial: Partial<ComposeValues>; replyTo?: { folder: string; uid: number }; quoteHeader?: string };

export default function Inbox({ companyId, email, signature = null, profileLink }: { companyId: number; email: string; signature?: string | null; profileLink: string }) {
    const [folders, setFolders] = useState<MailFolder[]>([]);
    const [folder, setFolder] = useState<string>("INBOX");
    const [page, setPage] = useState(1);
    const [list, setList] = useState<{ items: MailSummary[]; starred: MailSummary[]; starredTotal: number; total: number; perPage: number; unread: number | null }>(
        { items: [], starred: [], starredTotal: 0, total: 0, perPage: 25, unread: null });
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [loadingList, setLoadingList] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [thread, setThread] = useState<MailThread | null>(null);
    const [messages, setMessages] = useState<MailFull[]>([]);
    const [loadingThread, setLoadingThread] = useState(false);
    const [threadError, setThreadError] = useState<string | null>(null);
    const [compose, setCompose] = useState<Compose | null>(null);

    useEffect(() => {
        getMailboxFolders(companyId)
            .then((r: any) => {
                const fs: MailFolder[] = r?.data?.folders ?? [];
                setFolders(fs);
                setFolder(fs.find((f) => f.special === "inbox")?.path ?? fs[0]?.path ?? "INBOX");
            })
            .catch((e: any) => { setError(e?.message || "Não foi possível ler a caixa."); setLoadingList(false); });
    }, [companyId]);

    const inboxPath = folders.find((f) => f.special === "inbox")?.path ?? "INBOX";
    // "Com estrela" não é uma pasta: são as da Entrada com a estrela (os pedidos vão à Entrada).
    const starredView = folder === STARRED_VIEW;
    const serverFolder = starredView ? inboxPath : folder;

    const loadList = useCallback(() => {
        if (folders.length === 0) return;
        setLoadingList(true);
        setSelected(new Set());
        getMailboxMessages(companyId, serverFolder, page, starredView)
            .then((r: any) => {
                setList({ items: r?.data?.items ?? [], starred: r?.data?.starred ?? [], starredTotal: r?.data?.starred_total ?? 0,
                    total: r?.data?.total ?? 0, perPage: r?.data?.per_page ?? 25, unread: r?.data?.unread ?? null });
                setError(null);
                refreshMailboxUnread();
            })
            .catch((e: any) => setError(e?.message || "Não foi possível ler a pasta."))
            .finally(() => setLoadingList(false));
    }, [companyId, serverFolder, starredView, page, folders.length]);

    useEffect(() => { loadList(); }, [loadList]);

    // O "Retroceder" do navegador fecha a conversa (como no Gmail), sem sair da página.
    useEffect(() => {
        const onPop = () => { if (!(window.history.state && window.history.state.xpMailThread)) { setThread(null); setMessages([]); } };
        window.addEventListener("popstate", onPop);
        return () => window.removeEventListener("popstate", onPop);
    }, []);

    /** Muda as mensagens da lista (as da secção "Com estrela" e as outras). */
    const patchLocal = (uids: number[], patch: Partial<MailSummary>) => setList((l) => ({
        ...l,
        items: l.items.map((m) => (uids.includes(m.uid) ? { ...m, ...patch } : m)),
        starred: l.starred.map((m) => (uids.includes(m.uid) ? { ...m, ...patch } : m)),
    }));
    const markLocal = (uids: number[], seen: boolean) => patchLocal(uids, { seen });

    /**
     * Marca no servidor, mas o ecrã muda logo: a lista, o número da Entrada e o do envelope e do menu
     * (só a Entrada conta para o número); no fim, acerta com a resposta do servidor.
     */
    const markSeen = async (uids: number[], seen: boolean) => {
        if (uids.length === 0) return;
        markLocal(uids, seen);
        if (serverFolder === inboxPath) {
            const delta = seen ? -uids.length : uids.length;
            adjustMailboxUnread(delta);
            setList((l) => ({ ...l, unread: l.unread === null ? null : Math.max(0, l.unread + delta) }));
        }
        let unread: number | null = null;
        try {
            // Todas de uma vez: um pedido e uma ligação ao servidor de e-mail (com a contagem na mesma ligação).
            const s: any = await setMailboxSeenMany(companyId, serverFolder, uids, seen);
            unread = s?.data?.unread ?? null;
        } catch (e: any) {
            // Não ficou marcado no servidor: o ecrã volta atrás e diz porquê (nada de falhas em silêncio).
            markLocal(uids, !seen);
            if (serverFolder === inboxPath) {
                const undo = seen ? uids.length : -uids.length;
                adjustMailboxUnread(undo);
                setList((l) => ({ ...l, unread: l.unread === null ? null : Math.max(0, l.unread + undo) }));
            }
            toast.error(`${seen ? "Não foi possível marcar como lida" : "Não foi possível marcar como por ler"}: ${e?.message || "erro do servidor"}`);
            throw e;
        } finally {
            if (unread !== null) setList((l) => ({ ...l, unread }));
            refreshMailboxUnread();
        }
    };

    const open = (t: MailThread) => {
        setThread(t);
        setMessages([]);
        setThreadError(null);
        setLoadingThread(true);
        window.history.pushState({ ...(window.history.state || {}), xpMailThread: t.key }, "");
        // As por ler da conversa passam logo a lidas (o número desce no mesmo instante).
        const unseen = [...list.starred, ...list.items].filter((m) => t.uids.includes(m.uid) && !m.seen).map((m) => m.uid);
        markSeen(unseen, true).catch(() => { /* o aviso já apareceu e o ecrã voltou atrás */ });
        // A conversa toda num só pedido: uma ligação ao servidor de e-mail, seja qual for o número de mensagens.
        getMailboxThread(companyId, serverFolder, t.uids)
            .then((r: any) => setMessages(r?.data?.messages ?? []))
            .catch((e: any) => setThreadError(e?.message || "Não foi possível abrir a conversa."))
            .finally(() => setLoadingThread(false));
    };

    const back = () => {
        if (window.history.state && window.history.state.xpMailThread) window.history.back();
        setThread(null);
        setMessages([]);
    };

    const unread = async () => {
        if (!thread) return;
        try {
            await markSeen(thread.uids, false);
            back();
        } catch {
            // o aviso já apareceu (markSeen) e a conversa fica aberta
        }
    };

    // ── E-mail 10: seleção, estrela, apagar e desfazer ─────────────────────────

    const toggleSelect = (t: MailThread) => setSelected((s) => {
        const next = new Set(s);
        next.has(t.key) ? next.delete(t.key) : next.add(t.key);
        return next;
    });
    const toggleAll = (ts: MailThread[], select: boolean) => setSelected(select ? new Set(ts.map((t) => t.key)) : new Set());

    const markSelected = (ts: MailThread[], seen: boolean) => {
        // Só as que mudam (o número de não lidos acompanha sem contar a mais).
        const all = [...list.starred, ...list.items];
        const uids = ts.flatMap((t) => t.uids).filter((u) => all.some((m) => m.uid === u && m.seen !== seen));
        setSelected(new Set());
        markSeen(uids, seen).catch(() => { /* o aviso já apareceu e o ecrã voltou atrás */ });
    };

    /** A estrela da conversa (\\Flagged no servidor; no Gmail é a estrela). O ecrã muda logo. */
    const toggleStar = async (t: MailThread) => {
        const flagged = !t.flagged;
        patchLocal(t.uids, { flagged });
        try {
            await setMailboxFlag(companyId, serverFolder, t.uids, flagged);
        } catch (e: any) {
            patchLocal(t.uids, { flagged: !flagged });
            toast.error(`${flagged ? "Não foi possível pôr a estrela" : "Não foi possível tirar a estrela"}: ${e?.message || "erro do servidor"}`);
        }
    };

    /**
     * Apagar: para o Lixo da caixa (nunca de vez). As conversas saem logo da lista; se o servidor der
     * erro, voltam, com o motivo. Depois, "Desfazer" durante alguns segundos.
     */
    const remove = async (ts: MailThread[]) => {
        const uids = ts.flatMap((t) => t.uids);
        if (uids.length === 0) return;
        const before = list;
        const unreadGone = [...list.starred, ...list.items].filter((m) => uids.includes(m.uid) && !m.seen).length;
        setSelected(new Set());
        setList((l) => ({ ...l, items: l.items.filter((m) => !uids.includes(m.uid)), starred: l.starred.filter((m) => !uids.includes(m.uid)),
            total: Math.max(0, l.total - l.items.filter((m) => uids.includes(m.uid)).length) }));
        if (serverFolder === inboxPath && unreadGone > 0) adjustMailboxUnread(-unreadGone);
        const from = serverFolder;
        try {
            const r: any = await trashMailboxMessages(companyId, from, uids, ts.flatMap((t) => t.messageIds));
            if (typeof r?.data?.unread === "number") setList((l) => ({ ...l, unread: r.data.unread }));
            const undo = r?.data?.undo as { folder: string; message_ids: string[] } | null;
            toast(({ closeToast }: { closeToast?: () => void }) => (
                <div className="d-flex align-items-center gap-3" data-testid="mail-undo-toast">
                    <span>{r?.message || "Movida para o Lixo."}</span>
                    {undo && (
                        <button type="button" className="btn btn-sm btn-light ms-auto" data-testid="mail-undo" onClick={async () => {
                            closeToast?.();
                            try {
                                await restoreMailboxMessages(companyId, undo.folder, undo.message_ids);
                                toast.success("Desfeito: as mensagens voltaram.");
                            } catch (e: any) {
                                toast.error(`Não foi possível desfazer: ${e?.message || "erro do servidor"}`);
                            }
                            loadList();
                            refreshMailboxUnread();
                        }}>Desfazer</button>
                    )}
                </div>
            ), { autoClose: 8000 });
        } catch (e: any) {
            setList(before);
            if (serverFolder === inboxPath && unreadGone > 0) adjustMailboxUnread(unreadGone);
            toast.error(`Não foi possível apagar: ${e?.message || "erro do servidor"}`);
        } finally {
            refreshMailboxUnread();
        }
    };

    const deleteOpen = () => {
        if (!thread) return;
        const t = thread;
        back();
        remove([t]);
    };

    /** Responder: a quem enviou (ou, se fui eu, a quem a mandei), "Re: " uma vez e o original citado pelo servidor. */
    const reply = (m: MailFull) => {
        const mine = !!m.from.address && m.from.address.toLowerCase() === email.toLowerCase();
        const to = mine ? m.to.map((a) => a.address).filter(Boolean).join(", ") : (m.from.address ?? "");
        setCompose({ title: "Responder", initial: { to, subject: replySubject(m.subject) }, replyTo: { folder: m.folder, uid: m.uid }, quoteHeader: quoteHeaderFor(m.date, m.from) });
    };
    // Depois de enviar, a pasta de Enviados mostra a cópia (se for a que está aberta).
    const sent = () => { if (folders.find((f) => f.path === folder)?.special === "sent" && !thread) loadList(); };

    const pickFolder = (path: string) => { setFolder(path); setPage(1); setThread(null); setMessages([]); };
    const title = starredView ? "Com estrela" : folders.find((f) => f.path === folder)?.name ?? folder;

    if (error && folders.length === 0) {
        return <Alert color="danger" data-testid="mail-error">{error} <a href={`/app${profileLink}`} className="alert-link ms-1">Rever a caixa no Perfil</a></Alert>;
    }
    if (folders.length === 0) {
        return <InboxSkeleton email={email} profileLink={profileLink} />; // as pastas ainda a chegar
    }

    return (
        <div className="email-wrapper d-lg-flex gap-1 mx-n4 mt-n4 p-1" data-testid="mail-inbox">
            <EmailSidebar email={email} folders={folders} current={folder} unread={list.unread} onPick={pickFolder} profileLink={profileLink}
                onCompose={() => setCompose({ title: "Nova mensagem", initial: {} })} />
            {thread ? (
                <ThreadView companyId={companyId} myEmail={email} messages={messages} loading={loadingThread} error={threadError}
                    onBack={back} onUnread={unread} onReply={reply} onDelete={deleteOpen}
                    onLinked={(uid, c) => setMessages((ms) => ms.map((m) => (m.uid === uid ? { ...m, customer: c } : m)))} />
            ) : error ? <div className="email-content p-4"><Alert color="danger">{error}</Alert></div> : (
                <MessageList items={list.items} starred={list.starred} starredTotal={list.starredTotal} myEmail={email} loading={loadingList}
                    total={list.total} page={page} perPage={list.perPage} onOpen={open} onPage={setPage} onRefresh={loadList} title={title}
                    empty={starredView ? "Não há mensagens com estrela na Entrada." : undefined}
                    actions={{ selected, onToggleSelect: toggleSelect, onToggleAll: toggleAll, onStar: toggleStar, onMarkSelected: markSelected, onDeleteSelected: remove }} />
            )}
            {compose && <ComposeModal isOpen toggle={() => setCompose(null)} companyId={companyId} title={compose.title} initial={compose.initial}
                replyTo={compose.replyTo ?? null} quoteHeader={compose.quoteHeader} signature={signature} onSent={sent} />}
        </div>
    );
}

/** E-mail 4b · A caixa a carregar: o mesmo desenho, com o esqueleto das pastas e da lista (em vez de um spinner). */
export function InboxSkeleton({ email, profileLink }: { email: string; profileLink: string }) {
    return (
        <div className="email-wrapper d-lg-flex gap-1 mx-n4 mt-n4 p-1" data-testid="mail-inbox-loading">
            <EmailSidebar email={email} folders={[]} current="" unread={null} onPick={() => undefined} profileLink={profileLink} loading />
            <MessageList items={[]} myEmail={email} loading total={0} page={1} perPage={25} onOpen={() => undefined} onPage={() => undefined} onRefresh={() => undefined} title="Entrada" />
        </div>
    );
}
