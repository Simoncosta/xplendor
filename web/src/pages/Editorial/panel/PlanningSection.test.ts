import { autoPublishHint, emptyPlan, planPayload } from "./PlanningSection";

// O react-router-dom 7 não se resolve no Jest 27 do CRA: um Link simples chega.
jest.mock("react-router-dom", () => ({ Link: () => null }), { virtual: true });
jest.mock("helpers/laravel_helper", () => ({ getBlogs: jest.fn() }));

describe("planPayload (F2: Publicar automaticamente)", () => {
    it("começa desmarcada e só segue para as redes", () => {
        const plan = { ...emptyPlan("2026-11-20"), title: "Menu de Natal", publish_time: "12:00" };
        expect(plan.auto_publish).toBe(false);
        expect(planPayload(plan).auto_publish).toBe(false);
        expect(planPayload({ ...plan, auto_publish: true }).auto_publish).toBe(true);
        expect(planPayload({ ...plan, auto_publish: true, site: true }).auto_publish).toBe(false);
    });
});

describe("autoPublishHint (porque não sai sozinha)", () => {
    const now = "2026-11-10 10:00";
    const at = (publish_date: string, publish_time: string) => ({ publish_date, publish_time });

    it("interruptor desligado: diz onde se liga", () => {
        const h = autoPublishHint(at("2026-11-20", "12:00"), false, "scheduled", now);
        expect(h.switchOff).toBe(true);
        expect(h.text).toMatch(/desligada nesta empresa: esta publicação continua manual\. Liga-se em $/);
    });

    it("sem hora, hora passada e sem aprovação", () => {
        expect(autoPublishHint(at("2026-11-20", ""), true, "scheduled", now).text).toMatch(/Sem hora/);
        expect(autoPublishHint(at("2026-11-10", "10:00"), true, "scheduled", now).text).toMatch(/já passaram/);
        expect(autoPublishHint(at("2026-11-09", "23:00"), true, "scheduled", now).text).toMatch(/já passaram/);
        expect(autoPublishHint(at("2026-11-20", "12:00"), true, "client_review", now).text).toMatch(/só sai sozinha depois de aprovada \(etapa Programado\)/);
        expect(autoPublishHint(at("2026-11-20", "12:00"), undefined, undefined, now).text).toMatch(/depois de aprovada/);
    });

    it("tudo certo: sai sozinha", () => {
        const h = autoPublishHint(at("2026-11-10", "10:01"), true, "scheduled", now);
        expect([h.tone, h.text]).toEqual(["success", "Aprovada e programada: sai sozinha à hora marcada em cada rede."]);
    });
});
