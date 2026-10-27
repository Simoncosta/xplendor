import axios from "axios";
import { attachMailboxFile, getMailboxComposeContext, importMailboxAttachment, uploadMailboxAttachment, getMailboxThread, linkMailboxMessage, sendMailboxMessage, setMailboxSeen, setMailboxSeenMany, testMyMailbox, testMyMailboxImap } from "./laravel_helper";

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

    it("E-mail 5: enviar vai em JSON, com a resposta (pasta e UID) e as ligações como números", async () => {
        (axios.post as jest.Mock).mockClear();
        await sendMailboxMessage(5, { to: "a@exemplo.invalid", cc: "", subject: "Re: Olá", body: "<p>Olá</p>", reply_to: { folder: "INBOX", uid: 7 }, customer_id: 12 });
        const [path, body, config] = (axios.post as jest.Mock).mock.calls[0];
        expect(path).toMatch(/\/companies\/5\/my-mailbox\/send$/);
        expect(body).toEqual({ to: "a@exemplo.invalid", cc: "", subject: "Re: Olá", body: "<p>Olá</p>", reply_to: { folder: "INBOX", uid: 7 }, customer_id: 12 });
        expect(config).toEqual({ headers: { "Content-Type": "application/json" } });
    });

    it("E-mail 5: o contexto de escrever ao cliente pede só o que existe", async () => {
        (axios.get as jest.Mock).mockClear();
        await getMailboxComposeContext(5, { carId: 9 });
        expect((axios.get as jest.Mock).mock.calls[0][0]).toMatch(/\/companies\/5\/my-mailbox\/compose-context\?car_id=9$/);
    });

    it("E-mail 7: importar um anexo para as Faturas vai em JSON, com a pasta", async () => {
        (axios.post as jest.Mock).mockClear();
        await importMailboxAttachment(5, 7, "INBOX", 1);
        const [path, body, config] = (axios.post as jest.Mock).mock.calls[0];
        expect(path).toMatch(/\/companies\/5\/my-mailbox\/messages\/7\/attachments\/1\/import-invoice$/);
        expect(body).toEqual({ folder: "INBOX" });
        expect(config).toEqual({ headers: { "Content-Type": "application/json" } });
    });

    it("E-mail 5b: o anexo do computador vai em multipart (com o progresso); o dos Ficheiros em JSON", async () => {
        (axios.post as jest.Mock).mockClear();
        const progress = jest.fn();
        await uploadMailboxAttachment(5, new File(["x"], "a.pdf"), progress);
        const [path, body, config] = (axios.post as jest.Mock).mock.calls[0];
        expect(path).toMatch(/\/companies\/5\/my-mailbox\/attachments$/);
        expect(body).toBeInstanceOf(FormData);
        expect((body as FormData).get("file")).toBeInstanceOf(File);
        expect(config.headers).toEqual({ "Content-Type": "multipart/form-data" });
        config.onUploadProgress({ loaded: 50, total: 200 });
        expect(progress).toHaveBeenCalledWith(25);
        await attachMailboxFile(5, 77);
        const [path2, body2, config2] = (axios.post as jest.Mock).mock.calls[1];
        expect(path2).toMatch(/\/my-mailbox\/attachments\/from-files$/);
        expect(body2).toEqual({ file_id: 77 });
        expect(config2).toEqual({ headers: { "Content-Type": "application/json" } });
    });
});
