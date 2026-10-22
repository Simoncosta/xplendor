import { HIDDEN_ACTIONS, isActionHidden } from "./hiddenActions";

describe("ações escondidas", () => {
    it("esconde o Pausar campanha e deixa as outras", () => {
        expect(HIDDEN_ACTIONS).toContain("pause_campaign");
        expect(isActionHidden("pause_campaign")).toBe(true);
        expect(isActionHidden("notify_client_whatsapp")).toBe(false);
        expect(isActionHidden(null)).toBe(false);
        expect(isActionHidden(undefined)).toBe(false);
    });
});
