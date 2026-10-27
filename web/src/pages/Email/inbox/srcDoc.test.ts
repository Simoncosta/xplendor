import { buildSrcDoc } from "./srcDoc";

describe("O documento do iframe da mensagem (E-mail 4)", () => {
    const html = '<p>Olá</p><img width="1" data-xp-src="https://rastreio.invalid/p.gif"><img src="data:image/png;base64,AAA">';

    it("por omissão, CSP rígida e as imagens externas bloqueadas", () => {
        const doc = buildSrcDoc(html, false);
        expect(doc).toContain(`content="default-src 'none'; style-src 'unsafe-inline'; img-src data:; font-src data:"`);
        expect(doc).not.toMatch(/(^|\s)src="https:/);
        expect(doc).toContain('data-xp-src="https://rastreio.invalid/p.gif"');
        expect(doc).toContain('<base target="_blank">');
        expect(doc).toContain('<meta name="referrer" content="no-referrer">');
        expect(doc).not.toContain("<script");
        expect(doc).toContain('<meta name="color-scheme" content="light">');
    });

    it("no tema escuro, a mensagem fica num cartão branco (o HTML dos e-mails pressupõe fundo branco)", () => {
        const doc = buildSrcDoc(html, false, true);
        expect(doc).toContain('<meta name="color-scheme" content="light">');
        expect(doc).toContain("padding:12px 16px");
        expect(doc).toContain("background:#ffffff");
    });

    it("\"Mostrar imagens\": as externas passam a src e a CSP deixa-as entrar", () => {
        const doc = buildSrcDoc(html, true);
        expect(doc).toContain(' src="https://rastreio.invalid/p.gif"');
        expect(doc).toContain("img-src data: https: http:");
    });
});
