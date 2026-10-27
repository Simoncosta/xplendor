import { useCallback, useEffect, useState } from "react";
import { Alert, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { getMailboxFolders, getMailboxMessage, getMailboxMessages, setMailboxSeen } from "helpers/laravel_helper";
import { adjustMailboxUnread, refreshMailboxUnread } from "Components/Common/mailbox/useMailboxUnread";
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
 */
export default function Inbox({ companyId, email, profileLink }: { companyId: number; email: string; profileLink: string }) {
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
            for (const uid of uids) {
                const s: any = await setMailboxSeen(companyId, uid, folder, seen);
                unread = s?.data?.unread ?? unread;
            }
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
        markSeen(unseen, true).catch(() => { /* fica por marcar no servidor; a lista volta a ler */ });
        Promise.all(t.uids.map((uid) => getMailboxMessage(companyId, uid, folder)))
            .then(async (rs: any[]) => {
                setMessages(rs.map((r) => r?.data?.message).filter(Boolean));
            })
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
        } catch (e: any) {
            toast.error(e?.message || "Não foi possível marcar como por ler.");
        }
    };

    const inboxPath = folders.find((f) => f.special === "inbox")?.path ?? "INBOX";
    const pickFolder = (path: string) => { setFolder(path); setPage(1); setThread(null); setMessages([]); };
    const title = folders.find((f) => f.path === folder)?.name ?? folder;

    if (error && folders.length === 0) {
        return <Alert color="danger" data-testid="mail-error">{error} <a href={`/app${profileLink}`} className="alert-link ms-1">Rever a caixa no Perfil</a></Alert>;
    }
    if (folders.length === 0) {
        return <div className="text-center py-5 text-muted"><Spinner size="sm" className="me-2" />A ligar à caixa…</div>;
    }

    return (
        <div className="email-wrapper d-lg-flex gap-1 mx-n4 mt-n4 p-1" data-testid="mail-inbox">
            <EmailSidebar email={email} folders={folders} current={folder} unread={list.unread} onPick={pickFolder} profileLink={profileLink} />
            {thread ? (
                <ThreadView companyId={companyId} myEmail={email} messages={messages} loading={loadingThread} error={threadError}
                    onBack={back} onUnread={unread}
                    onLinked={(uid, c) => setMessages((ms) => ms.map((m) => (m.uid === uid ? { ...m, customer: c } : m)))} />
            ) : error ? <div className="email-content p-4"><Alert color="danger">{error}</Alert></div> : (
                <MessageList items={list.items} myEmail={email} loading={loadingList} total={list.total} page={page} perPage={list.perPage}
                    onOpen={open} onPage={setPage} onRefresh={loadList} title={title} />
            )}
        </div>
    );
}
