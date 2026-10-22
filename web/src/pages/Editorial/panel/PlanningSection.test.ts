import { emptyPlan, planPayload } from "./PlanningSection";

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
