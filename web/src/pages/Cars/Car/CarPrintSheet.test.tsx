import { render, screen, within } from "@testing-library/react";
import CarPrintSheet, { descriptionParagraphs } from "./CarPrintSheet";
import type { CarPrintSheet as Sheet } from "types/api";

// O Jest do CRA não resolve o react-router-dom 7 (exports): substitui-se só o que a ficha usa.
jest.mock("react-router-dom", () => ({
    useParams: () => ({ companyId: "1", id: "7" }),
    useNavigate: () => jest.fn(),
    Link: ({ to, children }: any) => <a href={to}>{children}</a>,
}), { virtual: true });

const mockGetCarPrintSheet = jest.fn();
jest.mock("helpers/laravel_helper", () => ({
    getCarPrintSheet: (...args: unknown[]) => mockGetCarPrintSheet(...args),
}));

const sheet = (over: Partial<Sheet> = {}): Sheet => ({
    presentation: {
        version: "presentation-v1",
        display_name: "Sunlight A72",
        eyebrow: ["Integral", "Usado", "Ref. 7"],
        meta: ["Integral", "2021", "Importado", "Branco metalizado"],
        price: { hidden: false, promo: true, regular: 89900, current: 84900, label: null },
        key_figures: [
            { key: "km", value: "45 210 km", caption: "Quilómetros" },
            { key: "seats", value: "4 lugares", caption: "Para viajar" },
        ],
        sections: [
            { id: "camas", title: "Camas", details: [], items: [], beds: [{ label: "Cama central", description: "Cama de casal com acesso pelos dois lados" }] },
            { id: "sala", title: "Sala e cozinha", details: [{ label: "Fogão", value: "3 bocas · Gás" }], items: ["Forno", "TV e suporte TV"] },
            { id: "cabine", title: "Cabine de condução e segurança", details: [], items: ["ABS", "Airbags do condutor e passageiro"] },
        ],
        description_title: "Sobre esta autocaravana",
        description: "<p><span style=\"background-color: rgb(255, 255, 255);\">Autocaravana integral muito bem conservada.</span></p><p>Garantia de 18 meses.</p>",
    },
    qr: { available: true, short_url: "https://xplendor.tech/q/abcd2345", svg: "<svg viewBox=\"0 0 10 10\"></svg>", site_url: "https://quebom.pt/vehicles/7", site_label: "quebom.pt/vehicles/7" },
    seller: { name: "Vendedor Exemplo", mobile: "910000000" },
    company: { id: 1, nipc: "500000000", trade_name: "Stand Exemplo", fiscal_name: "Stand Exemplo Lda", logo_path: null, address: "Rua A, 1", postal_code: "1000-001", phone: null, mobile: null, email: "geral@exemplo.pt", website: "exemplo.pt" },
    vehicle_type: "motorhome", brand: null, model: null, category: null, version: null, engine_brand: null, license_plate: null, vin: null,
    registration: { year: 2021, month: null }, price: { gross: 89900, promo_gross: 84900, hide_price_online: false },
    headline_stats: { seats: 4, sleeps: 4, length_m: null, gross_weight_kg: null, mileage_km: 45210 } as any,
    specs: {} as any, state: {} as any, warranty_months: null, vehicle_attributes: {}, extras: [],
    ...over,
});

describe("Ficha A4 da viatura", () => {
    beforeEach(() => mockGetCarPrintSheet.mockReset());

    it("desenha a apresentação: cabeçalho, números-chave, blocos com ✓, camas com explicação, descrição e rodapé com o QR", async () => {
        mockGetCarPrintSheet.mockResolvedValue({ data: sheet() });
        render(<CarPrintSheet />);
        await screen.findByText("Sunlight A72");
        expect(screen.getByText("Integral · Usado · Ref. 7")).toBeInTheDocument();
        expect(screen.getByText("Integral · 2021 · Importado · Branco metalizado")).toBeInTheDocument();
        expect(screen.getByText(/84\s900\s€/)).toBeInTheDocument();
        expect(screen.getByText(/89\s900\s€/)).toBeInTheDocument();
        expect(within(screen.getByTestId("ps-figures")).getByText("45 210 km")).toBeInTheDocument();
        const camas = screen.getByTestId("ps-section-camas");
        expect(camas).toHaveTextContent("Cama central");
        expect(camas).toHaveTextContent("Cama de casal com acesso pelos dois lados");
        expect(screen.getByTestId("ps-section-sala")).toHaveTextContent("✓TV e suporte TV");
        expect(screen.getByTestId("ps-about")).toHaveTextContent("Sobre esta autocaravana");
        expect(screen.getByText("Autocaravana integral muito bem conservada.")).toBeInTheDocument();
        expect(screen.getByTestId("ps-about")).not.toHaveTextContent("<span");
        expect(within(screen.getByTestId("ps-qr")).getByText("quebom.pt/vehicles/7")).toBeInTheDocument();
        expect(screen.getByText("Vendedor Exemplo")).toBeInTheDocument();
        expect(screen.queryByTestId("ps-qr-missing")).not.toBeInTheDocument();
    });

    it("preço escondido no site: Sob consulta", async () => {
        const s = sheet();
        s.presentation.price = { hidden: true, promo: false, regular: null, current: null, label: "Sob consulta" };
        mockGetCarPrintSheet.mockResolvedValue({ data: s });
        render(<CarPrintSheet />);
        await screen.findByText("Sob consulta");
        expect(screen.queryByText(/€/)).not.toBeInTheDocument();
    });

    it("preço com espaço também nos milhares de 4 algarismos, como no site", async () => {
        const s = sheet();
        s.presentation.price = { hidden: false, promo: false, regular: 7500, current: 7500, label: null };
        mockGetCarPrintSheet.mockResolvedValue({ data: s });
        render(<CarPrintSheet />);
        // O matcher junta os espaços: confirma-se o espaço fixo no texto do elemento.
        expect((await screen.findByText(/^7\s500\s€$/)).textContent).toBe("7\u00a0500\u00a0€");
    });

    it("sem o endereço da viatura no site: sem QR e o ecrã diz onde preencher", async () => {
        mockGetCarPrintSheet.mockResolvedValue({ data: sheet({ qr: { available: false, reason: "missing_template" } }) });
        render(<CarPrintSheet />);
        await screen.findByTestId("ps-qr-missing");
        expect(screen.queryByTestId("ps-qr")).not.toBeInTheDocument();
        expect(within(screen.getByTestId("ps-qr-missing")).getByText("Empresa › Dados gerais")).toHaveAttribute("href", "/companies/1");
    });

    it("a descrição em HTML do editor passa a parágrafos de texto simples", () => {
        expect(descriptionParagraphs("<p><span style=\"color: red\">Um &amp; dois</span></p><p><br></p><p>Linha<br>seguinte</p><script>alert(1)</script>"))
            .toEqual(["Um & dois", "Linha\nseguinte", "alert(1)"]);
        expect(descriptionParagraphs("Texto simples sem etiquetas.")).toEqual(["Texto simples sem etiquetas."]);
    });
});
