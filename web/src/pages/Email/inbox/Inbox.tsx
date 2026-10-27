import { useCallback, useEffect, useState } from "react";
import { Alert } from "reactstrap";
import { toast } from "react-toastify";
import { getMailboxFolders, getMailboxMessages, getMailboxThread, setMailboxSeenMany } from "helpers/laravel_helper";
import { adjustMailboxUnread, refreshMailboxUnread } from "Components/Common/mailbox/useMailboxUnread";
import ComposeModal, { ComposeValues, quoteHeaderFor, replySubject } from "Components/Common/mailbox/ComposeModal";
import EmailSidebar from "./EmailSidebar";
import MessageList from "./MessageList";
import ThreadView from "./ThreadView";
import { MailThread } from "./threads";
import { MailFolder, MailFull, MailSummary } from "./types";

/**
 * E-mail 4 · A caixa de entrada (modelo EmailInbox), lida ao vivo do servidor de e-mail. Como no
 * Gmail: a lista agrupada por conversa e, ao abrir, a conversa ocupa o lugar da lista ("Voltar" e o
 * "Retroceder" do navegador regressam à lista). Abrir marca as mensagens como lidas; abrir a Entrada
 * liga as respostas novas e atualiza os não lidos (o envelope e o menu acompanham).
 * E-mail 5: "Escrever" e "Responder" (no mesmo fio, com o original citado por baixo).
 */
type Compose = { title: string; initial: Partial<ComposeValues>; replyTo?: { folder: string; uid: number }; quoteHeader?: string };

export default function Inbox({ companyId, email, signature = null, profileLink }: { companyId: number; email: string; signature?: string | null; profileLink: string }) {
    const [folders, setFolders] = useState<MailFolder[]>([]);
    const [folder, setFolder] = useState<string>("INBOX");
    const [page, setPage] = useState(1);
    const [list, setList] = useState<{ items: MailSummary[]; total: number; perPage: number; unread: number | null }>({ items: [], total: 0, perPage: 25, unread: null });
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

    const loadList = useCallback(() => {
        if (folders.length === 0) return;
        setLoadingList(true);
        getMailboxMessages(companyId, folder, page)
            .then((r: any) => {
                setList({ items: r?.data?.items ?? [], total: r?.data?.total ?? 0, perPage: r?.data?.per_page ?? 25, unread: r?.data?.unread ?? null });
                setError(null);
                refreshMailboxUnread();
            })
            .catch((e: any) => setError(e?.message || "Não foi possível ler a pasta."))
            .finally(() => setLoadingList(false));
    }, [companyId, folder, page, folders.length]);

    useEffect(() => { loadList(); }, [loadList]);

    // O "Retroceder" do navegador fecha a conversa (como no Gmail), sem sair da página.
    useEffect(() => {
        const onPop = () => { if (!(window.history.state && window.history.state.xpMailThread)) { setThread(null); setMessages([]); } };
        window.addEventListener("popstate", onPop);
        return () => window.removeEventListener("popstate", onPop);
    }, []);

    const markLocal = (uids: number[], seen: boolean) => setList((l) => ({ ...l, items: l.items.map((m) => (uids.includes(m.uid) ? { ...m, seen } : m)) }));

    /**
     * Marca no servidor, mas o ecrã muda logo: a lista, o número da Entrada e o do envelope e do menu
     * (só a Entrada conta para o número); no fim, acerta com a resposta do servidor.
     */
    const markSeen = async (uids: number[], seen: boolean) => {
        if (uids.length === 0) return;
        markLocal(uids, seen);
        if (folder === inboxPath) {
            const delta = seen ? -uids.length : uids.length;
            adjustMailboxUnread(delta);
            setList((l) => ({ ...l, unread: l.unread === null ? null : Math.max(0, l.unread + delta) }));
        }
        let unread: number | null = null;
        try {
            // Todas de uma vez: um pedido e uma ligação ao servidor de e-mail (com a contagem na mesma ligação).
            const s: any = await setMailboxSeenMany(companyId, folder, uids, seen);
            unread = s?.data?.unread ?? null;
        } catch (e: any) {
            // Não ficou marcado no servidor: o ecrã volta atrás e diz porquê (nada de falhas em silêncio).
            markLocal(uids, !seen);
            if (folder === inboxPath) {
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
        const unseen = list.items.filter((m) => t.uids.includes(m.uid) && !m.seen).map((m) => m.uid);
        markSeen(unseen, true).catch(() => { /* o aviso já apareceu e o ecrã voltou atrás */ });
        // A conversa toda num só pedido: uma ligação ao servidor de e-mail, seja qual for o número de mensagens.
        getMailboxThread(companyId, folder, t.uids)
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

    /** Responder: a quem enviou (ou, se fui eu, a quem a mandei), "Re: " uma vez e o original citado pelo servidor. */
    const reply = (m: MailFull) => {
        const mine = !!m.from.address && m.from.address.toLowerCase() === email.toLowerCase();
        const to = mine ? m.to.map((a) => a.address).filter(Boolean).join(", ") : (m.from.address ?? "");
        setCompose({ title: "Responder", initial: { to, subject: replySubject(m.subject) }, replyTo: { folder: m.folder, uid: m.uid }, quoteHeader: quoteHeaderFor(m.date, m.from) });
    };
    // Depois de enviar, a pasta de Enviados mostra a cópia (se for a que está aberta).
    const sent = () => { if (folders.find((f) => f.path === folder)?.special === "sent" && !thread) loadList(); };

    const inboxPath = folders.find((f) => f.special === "inbox")?.path ?? "INBOX";
    const pickFolder = (path: string) => { setFolder(path); setPage(1); setThread(null); setMessages([]); };
    const title = folders.find((f) => f.path === folder)?.name ?? folder;

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
                    onBack={back} onUnread={unread} onReply={reply}
                    onLinked={(uid, c) => setMessages((ms) => ms.map((m) => (m.uid === uid ? { ...m, customer: c } : m)))} />
            ) : error ? <div className="email-content p-4"><Alert color="danger">{error}</Alert></div> : (
                <MessageList items={list.items} myEmail={email} loading={loadingList} total={list.total} page={page} perPage={list.perPage}
                    onOpen={open} onPage={setPage} onRefresh={loadList} title={title} />
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
