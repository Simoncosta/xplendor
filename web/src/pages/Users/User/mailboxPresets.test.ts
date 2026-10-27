import { ACCOUNT_TYPE_OPTIONS, AdvancedField, EMPTY_FORM, applyPreset, changeEmail, changeType, touchedFromSaved } from "./mailboxPresets";

const none = new Set<AdvancedField>();

describe("Caixa de correio: os modelos de preenchimento", () => {
    it("Gmail: imap.gmail.com 993 e smtp.gmail.com 465 com SSL/TLS, utilizador = endereço, sem cópia em Enviados", () => {
        const f = changeType(changeEmail(EMPTY_FORM, "matilde@exemplo.pt", none), "gmail", none);
        expect(f).toMatchObject({ account_type: "gmail", imap_host: "imap.gmail.com", imap_port: 993, imap_encryption: "ssl",
            smtp_host: "smtp.gmail.com", smtp_port: 465, smtp_encryption: "ssl", username: "matilde@exemplo.pt", save_to_sent: false });
    });

    it("Domínio próprio (cPanel): mail.<domínio>, IMAP 993 e SMTP 465 com SSL/TLS, utilizador = endereço, com cópia em Enviados", () => {
        const f = changeEmail({ ...EMPTY_FORM, account_type: "cpanel" }, "matilde@quebom.pt", none);
        expect(f).toMatchObject({ account_type: "cpanel", imap_host: "mail.quebom.pt", imap_port: 993, imap_encryption: "ssl",
            smtp_host: "mail.quebom.pt", smtp_port: 465, smtp_encryption: "ssl", username: "matilde@quebom.pt", save_to_sent: true });
    });

    it("Outro (manual): não preenche nada", () => {
        const start = { ...EMPTY_FORM, account_type: "manual" as const, smtp_host: "smtp.servidor.pt", imap_host: "imap.servidor.pt", username: "matilde" };
        expect(changeEmail(start, "matilde@servidor.pt", none)).toMatchObject({ smtp_host: "smtp.servidor.pt", imap_host: "imap.servidor.pt", username: "matilde" });
    });

    it("um endereço @gmail.com ou @googlemail.com escolhe sozinho o Gmail", () => {
        expect(changeEmail(EMPTY_FORM, "matilde.exemplo@gmail.com", none).account_type).toBe("gmail");
        expect(changeEmail({ ...EMPTY_FORM, account_type: "manual" }, "matilde.exemplo@GoogleMail.com", none).account_type).toBe("gmail");
        expect(changeEmail(EMPTY_FORM, "matilde@exemplo.pt", none).account_type).toBe("cpanel");
    });

    it("os tipos de conta são Gmail, Domínio próprio (cPanel) e Outro (manual)", () => {
        expect(ACCOUNT_TYPE_OPTIONS.map((o) => o.label)).toEqual(["Gmail", "Domínio próprio (cPanel)", "Outro (manual)"]);
    });

    it("o modelo não sobrepõe o que a pessoa mudou nas definições avançadas", () => {
        const touched = new Set<AdvancedField>(["smtp_port", "smtp_encryption", "username"]);
        const f = changeEmail({ ...EMPTY_FORM, smtp_port: 587, smtp_encryption: "starttls", username: "matilde" }, "matilde@quebom.pt", touched);
        expect(f).toMatchObject({ smtp_host: "mail.quebom.pt", smtp_port: 587, smtp_encryption: "starttls", username: "matilde" });
        expect(changeType(f, "gmail", touched)).toMatchObject({ smtp_host: "smtp.gmail.com", smtp_port: 587, username: "matilde" });
        expect(applyPreset(f, none)).toMatchObject({ smtp_port: 465, smtp_encryption: "ssl", username: "matilde@quebom.pt" });
    });

    it("numa caixa gravada, conta como mudado à mão o que é diferente do modelo", () => {
        const saved = { ...changeEmail(EMPTY_FORM, "matilde@quebom.pt", none), smtp_port: 587 as const };
        expect(Array.from(touchedFromSaved(saved))).toEqual(["smtp_port"]);
    });
});
