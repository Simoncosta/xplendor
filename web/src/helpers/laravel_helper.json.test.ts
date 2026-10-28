import axios from "axios";
import * as fs from "fs";
import * as path from "path";
import {
    linkAgencyMetaAsset, createAgencyCompanyRequest, createAgencyManagementRequest, anularPingwinUnit, createSupplier, createExpenseCategory,
} from "./laravel_helper";
import { apiErrorMessage, apiErrorStatus } from "./apiError";

jest.mock("axios", () => {
    const fn = () => jest.fn(() => Promise.resolve({ data: {} }));
    return {
        __esModule: true,
        default: { defaults: { baseURL: "", headers: { post: {}, common: {} } }, interceptors: { request: { use: jest.fn() }, response: { use: jest.fn() } },
            post: fn(), get: fn(), put: fn(), patch: fn(), delete: fn(), create: jest.fn() },
    };
});

const lastPost = () => {
    const calls = (axios.post as jest.Mock).mock.calls;
    const [url, body, config] = calls[calls.length - 1];
    return { url: String(url), body, contentType: config?.headers?.["Content-Type"] };
};

/**
 * Os POST sem ficheiros vão em JSON: em multipart (o que a app envia por omissão), true/false
 * chegavam como "true"/"false" e a regra boolean do Laravel recusava-os (422).
 */
describe("pedidos sem ficheiros em JSON, com os booleanos verdadeiros", () => {
    beforeEach(() => (axios.post as jest.Mock).mockClear());

    it("Agência › Portefólios: replace segue como true (era o que partia)", async () => {
        await linkAgencyMetaAsset(3, { company_id: 5, asset_type: "page", external_id: "2002", replace: true });
        expect(lastPost()).toEqual({ url: "/agencies/3/meta/links", body: { company_id: 5, asset_type: "page", external_id: "2002", replace: true }, contentType: "application/json" });
    });

    it("os outros cinco com booleanos", async () => {
        await createAgencyCompanyRequest(3, { name: "Casa Viva", authorization_declared: true });
        expect(lastPost()).toMatchObject({ body: { authorization_declared: true }, contentType: "application/json" });
        await createAgencyManagementRequest(3, { nipc: "500000000", authorization_declared: true });
        expect(lastPost()).toMatchObject({ body: { authorization_declared: true }, contentType: "application/json" });
        await anularPingwinUnit(4, 9);
        expect(lastPost()).toMatchObject({ body: { confirm: true }, contentType: "application/json" });
        await createSupplier(4, { name: "Fornecedor", archived: false });
        expect(lastPost()).toMatchObject({ body: { archived: false }, contentType: "application/json" });
        await createExpenseCategory(4, { name: "Combustível", archived: false });
        expect(lastPost()).toMatchObject({ body: { archived: false }, contentType: "application/json" });
    });
});

/**
 * Guarda: nenhum api.create do laravel_helper fica em multipart sem levar ficheiros. Cada chamada
 * leva um terceiro argumento (JSON_BODY, MULTIPART ou cabeçalhos próprios) ou envia um FormData.
 */
describe("guarda dos POST do laravel_helper", () => {
    it("todos os POST sem ficheiros levam JSON", () => {
        const src = fs.readFileSync(path.join(__dirname, "laravel_helper.ts"), "utf8");
        const missing: string[] = [];
        let idx = 0;
        for (;;) {
            const start = src.indexOf("api.create(", idx);
            if (start < 0) break;
            let i = start + "api.create(".length, depth = 1, quote: string | null = null, commas = 0;
            for (; depth > 0; i++) {
                const ch = src[i];
                if (quote) { if (ch === quote && src[i - 1] !== "\\") quote = null; continue; }
                if (ch === '"' || ch === "'" || ch === "`") quote = ch;
                else if ("([{".includes(ch)) depth++;
                else if (")]}".includes(ch)) depth--;
                else if (ch === "," && depth === 1) commas++;
            }
            const fnStart = src.lastIndexOf("export const ", start);
            const fnHead = src.slice(fnStart, start);
            const name = fnHead.match(/export const (\w+)/)?.[1] ?? "?";
            const sendsFormData = /FormData/.test(fnHead);
            if (commas < 2 && !sendsFormData) missing.push(name);
            idx = i;
        }
        expect(missing).toEqual([]);
    });
});

describe("apiErrorMessage", () => {
    it("mostra o primeiro erro de validação, depois a mensagem, e só depois o texto por omissão", () => {
        expect(apiErrorMessage({ message: "Dados inválidos.", errors: { replace: ["O campo replace tem de ser verdadeiro ou falso."] }, __status: 422 }, "Falhou."))
            .toBe("O campo replace tem de ser verdadeiro ou falso.");
        expect(apiErrorMessage({ message: "Já existe.", __status: 409 }, "Falhou.")).toBe("Já existe.");
        expect(apiErrorMessage({ response: { data: { message: "Pelo axios direto." } } }, "Falhou.")).toBe("Pelo axios direto.");
        expect(apiErrorMessage("Internal Server Error", "Falhou.")).toBe("Falhou.");
        expect(apiErrorMessage(null, "Falhou.")).toBe("Falhou.");
        expect(apiErrorStatus({ __status: 422 })).toBe(422);
        expect(apiErrorStatus({ response: { status: 429 } })).toBe(429);
    });
});
