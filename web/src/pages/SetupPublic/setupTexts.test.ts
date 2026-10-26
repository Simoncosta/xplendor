import { WHAT } from "./index";

describe("Configurar (link ao cliente): o que cada ligação da Meta lê", () => {
    it("só promete o que a XPLENDOR lê, como no App Review", () => {
        expect(WHAT.social).toContain("(seguidores e fotografia do perfil)");
        expect(WHAT.meta_ads).toContain("(gasto, impressões e cliques)");
        expect(`${WHAT.social} ${WHAT.meta_ads}`).not.toMatch(/publicações|alcance/);
    });
});
