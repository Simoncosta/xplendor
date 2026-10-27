import { MailSummary } from "./types";

/** E-mail 4 · Uma conversa na lista (as mensagens da página com o mesmo "thread"), como no Gmail. */
export type MailThread = {
    key: string;
    uids: number[]; // da mais recente para a mais antiga
    latest: MailSummary;
    count: number;
    unread: boolean;
    hasAttachments: boolean;
    senders: string; // "Joana, eu (3)" sem o número; o número vem em count
};

const firstName = (s: string) => s.split(/\s+/)[0];

/** Agrupa a lista (já das mais recentes para as mais antigas) por conversa; a ordem é a da mensagem mais recente. */
export function groupThreads(items: MailSummary[], myEmail: string): MailThread[] {
    const groups = new Map<string, MailSummary[]>();
    for (const m of items) {
        const key = m.thread || `uid:${m.uid}`;
        groups.set(key, [...(groups.get(key) ?? []), m]);
    }
    return Array.from(groups.entries()).map(([key, ms]) => {
        const names: string[] = [];
        // Os remetentes pela ordem da conversa (da mais antiga para a mais recente), sem repetir.
        [...ms].reverse().forEach((m) => {
            const mine = !!m.from.address && m.from.address.toLowerCase() === myEmail.toLowerCase();
            const n = mine ? "eu" : ms.length > 1 ? firstName(m.from.name || m.from.address || "?") : (m.from.name || m.from.address || "Sem remetente");
            if (!names.includes(n)) names.push(n);
        });
        return {
            key, uids: ms.map((m) => m.uid), latest: ms[0], count: ms.length,
            unread: ms.some((m) => !m.seen), hasAttachments: ms.some((m) => m.has_attachments), senders: names.join(", "),
        };
    });
}
