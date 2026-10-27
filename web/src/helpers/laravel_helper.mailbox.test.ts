import axios from "axios";
import { getMailboxThread, linkMailboxMessage, setMailboxSeen, setMailboxSeenMany, testMyMailbox, testMyMailboxImap } from "./laravel_helper";

jest.mock("axios", () => {
    const fn = () => jest.fn(() => Promise.resolve({ data: {} }));
    return {
        __esModule: true,
        default: { defaults: { baseURL: "", headers: { post: {}, common: {} } }, interceptors: { request: { use: jest.fn() }, response: { use: jest.fn() } },
            post: fn(), get: fn(), put: fn(), patch: fn(), delete: fn(), create: jest.fn() },
    };
});

/** E-mail 4 · Os POST da caixa vão em JSON (por omissão a app envia multipart e o true chegava como "true": 422 no "marcar como lida"). */
describe("Pedidos da caixa de correio", () => {
    it("marcar como lida, ligar a um cliente e os testes enviam JSON, com booleanos verdadeiros", async () => {
        await setMailboxSeen(5, 7, "INBOX", true);
        await linkMailboxMessage(5, 7, "INBOX", 12);
        await testMyMailbox(5);
        await testMyMailboxImap(5);
        await setMailboxSeenMany(5, "INBOX", [7, 8], true);
        const calls = (axios.post as jest.Mock).mock.calls;
        expect(calls[0][1]).toEqual({ folder: "INBOX", seen: true });
        calls.forEach((c) => expect(c[2]).toEqual({ headers: { "Content-Type": "application/json" } }));
        expect(calls[4][1]).toEqual({ folder: "INBOX", uids: [7, 8], seen: true });
    });

    it("a conversa pede-se toda de uma vez (os UID numa só lista)", async () => {
        await getMailboxThread(5, "INBOX", [8, 7]);
        expect((axios.get as jest.Mock).mock.calls[0][0]).toMatch(/\/companies\/5\/my-mailbox\/thread\?folder=INBOX&uids=8,7$/);
    });
});
