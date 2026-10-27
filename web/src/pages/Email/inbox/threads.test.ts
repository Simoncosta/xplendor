import { groupThreads } from "./threads";

const m = (uid: number, thread: string, from: [string | null, string], over: any = {}) => ({
    uid, thread, subject: "Assunto", from: { name: from[0], address: from[1] }, date: `2026-10-27T1${uid}:00:00Z`, seen: true, flagged: false, has_attachments: false, ...over,
});

describe("Agrupar a lista por conversa (E-mail 4)", () => {
    it("junta pelo thread, pela ordem da mais recente, com os remetentes e o número", () => {
        const items = [
            m(9, "a", ["Joana Cliente", "joana@cliente.invalid"], { seen: false }),
            m(8, "b", [null, "boletim@novidades.invalid"]),
            m(7, "a", ["Matilde", "matilde@exemplo.pt"], { has_attachments: true }),
            m(5, "a", ["Joana Cliente", "joana@cliente.invalid"]),
        ];
        const threads = groupThreads(items as any, "MATILDE@exemplo.pt");
        expect(threads.map((t) => t.key)).toEqual(["a", "b"]);
        expect(threads[0]).toMatchObject({ uids: [9, 7, 5], count: 3, unread: true, hasAttachments: true, senders: "Joana, eu" });
        expect(threads[0].latest.uid).toBe(9);
        expect(threads[1]).toMatchObject({ uids: [8], count: 1, unread: false, hasAttachments: false, senders: "boletim@novidades.invalid" });
    });

    it("sem thread, cada mensagem é uma conversa", () => {
        const threads = groupThreads([m(2, "", [null, "a@x.invalid"]), m(1, "", [null, "a@x.invalid"])] as any, "eu@x.invalid");
        expect(threads).toHaveLength(2);
    });
});
