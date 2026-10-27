import { useEffect, useState } from "react";
import { getMyMailboxUnread } from "helpers/laravel_helper";
import { isImpersonating } from "helpers/impersonation";
import { sessionUser } from "helpers/roles";

/**
 * E-mail 3 · Os não lidos da minha caixa de correio, para o envelope do cabeçalho e a entrada
 * "E-mail" do menu. Um só pedido partilhado por todos os que o usam (de 2 em 2 minutos; o servidor
 * conta de 5 em 5), sempre na empresa da própria pessoa (a caixa é dela, também quando trabalha
 * noutra empresa). Durante a impersonation não pede nada. Quem muda a caixa (o Perfil) avisa com
 * refreshMailboxUnread().
 */

export type MailboxUnread = { hasMailbox: boolean; unread: number | null };

const EMPTY: MailboxUnread = { hasMailbox: false, unread: null };
const POLL_MS = 120_000;

let current: MailboxUnread = EMPTY;
let timer: ReturnType<typeof setInterval> | null = null;
const listeners = new Set<(s: MailboxUnread) => void>();

/** O número como aparece: nada em 0 (ou sem caixa), "99+" acima de 99. */
export const unreadLabel = (n: number | null | undefined): string | null => (!n || n <= 0 ? null : n > 99 ? "99+" : String(n));

const publish = (next: MailboxUnread) => {
    current = next;
    listeners.forEach((l) => l(next));
};

/**
 * E-mail 4 · Muda o número no mesmo instante (abrir uma por ler: -1; "Marcar como por ler": +1), sem
 * esperar pela contagem seguinte; quem chama acerta depois com refreshMailboxUnread().
 */
export function adjustMailboxUnread(delta: number): void {
    if (!current.hasMailbox || current.unread === null || delta === 0) return;
    publish({ ...current, unread: Math.max(0, current.unread + delta) });
}

export async function refreshMailboxUnread(): Promise<void> {
    const me = sessionUser();
    if (!me?.company_id || isImpersonating()) {
        publish(EMPTY);
        return;
    }
    try {
        const r: any = await getMyMailboxUnread(Number(me.company_id));
        publish({ hasMailbox: !!r?.data?.has_mailbox, unread: r?.data?.unread_count ?? null });
    } catch {
        publish(EMPTY);
    }
}

export default function useMailboxUnread(): MailboxUnread {
    const [state, setState] = useState<MailboxUnread>(current);
    useEffect(() => {
        listeners.add(setState);
        if (listeners.size === 1) {
            refreshMailboxUnread();
            timer = setInterval(refreshMailboxUnread, POLL_MS);
        }
        return () => {
            listeners.delete(setState);
            if (listeners.size === 0 && timer) {
                clearInterval(timer);
                timer = null;
            }
        };
    }, []);
    return state;
}
