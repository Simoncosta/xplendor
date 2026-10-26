import axios from "axios";
import { createEditorialPost } from "./laravel_helper";
import { emptyPlan, planPayload } from "pages/Editorial/panel/PlanningSection";

// O axios simulado: só o que o api_helper usa ao carregar e nos pedidos.
jest.mock("axios", () => {
    const ok = () => Promise.resolve({});
    return {
        __esModule: true,
        default: {
            defaults: { headers: { post: {}, common: {} } },
            interceptors: { request: { use: jest.fn() }, response: { use: jest.fn() } },
            get: jest.fn(ok), post: jest.fn(ok), put: jest.fn(ok), patch: jest.fn(ok), delete: jest.fn(ok),
        },
    };
});
jest.mock("react-router-dom", () => ({ Link: () => null }), { virtual: true });

describe("Criar publicação (Linha Editorial)", () => {
    it("envia JSON: em multipart o false de \"Publicar automaticamente\" chegava como texto e o servidor recusava", async () => {
        const body = planPayload({ ...emptyPlan("2026-11-20"), title: "Menu de São Martinho" });
        await createEditorialPost(7, body);

        const [url, sent, config] = (axios.post as jest.Mock).mock.calls[0];
        expect(url).toMatch(/\/7\/editorial\/posts$/);
        expect(config?.headers?.["Content-Type"]).toBe("application/json");
        expect(sent.auto_publish).toBe(false);
    });
});
