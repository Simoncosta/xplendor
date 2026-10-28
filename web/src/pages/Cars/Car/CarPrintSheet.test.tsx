import { act, render, screen, within } from "@testing-library/react";
import CarPrintSheet, { descriptionParagraphs, groupPhone } from "./CarPrintSheet";
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

    it("com o contacto comercial da empresa: designação e telefone em grupos de 3, sem o nome do vendedor", async () => {
        mockGetCarPrintSheet.mockResolvedValue({ data: sheet({ print_contact: { label: "Dep. Comercial", phone: "917349847" } }) });
        render(<CarPrintSheet />);
        const contact = await screen.findByTestId("ps-print-contact");
        expect(contact.textContent).toBe("Dep. Comercial: 917 349 847");
        expect(screen.queryByText(/Vendedor Exemplo/)).not.toBeInTheDocument();
        expect(screen.queryByText(/910000000/)).not.toBeInTheDocument();
    });

    it("sem o contacto comercial: o vendedor como antes", async () => {
        mockGetCarPrintSheet.mockResolvedValue({ data: sheet({ print_contact: null }) });
        render(<CarPrintSheet />);
        await screen.findByText("Vendedor Exemplo");
        expect(screen.getByText(/910000000/)).toBeInTheDocument();
        expect(screen.queryByTestId("ps-print-contact")).not.toBeInTheDocument();
    });

    it("o telefone em grupos de 3, com o indicativo à parte", () => {
        const plain = (v: string) => groupPhone(v).replace(/ /g, " ");
        expect(plain("917349847")).toBe("917 349 847");
        expect(plain("917 349 847")).toBe("917 349 847");
        expect(plain("+351917349847")).toBe("+351 917 349 847");
        expect(plain("00351 917349847")).toBe("+351 917 349 847");
    });

    it("o aviso legal, igual para todas as empresas, por cima da identificação da empresa", async () => {
        mockGetCarPrintSheet.mockResolvedValue({ data: sheet() });
        render(<CarPrintSheet />);
        const legal = await screen.findByTestId("ps-legal");
        expect(legal.textContent).toBe("Este preçário serve apenas como uma prévia do veículo e não faz parte de um contrato de venda. As informações fornecidas aqui são descrições não vinculativas e não representam bens garantidos. O vendedor não se responsabiliza por erros, erros de introdução e erros de transmissão de dados.");
        expect(legal.nextElementSibling).toHaveClass("ps-company");
    });

    it("a nota da empresa sai no rodapé, por cima do aviso legal", async () => {
        mockGetCarPrintSheet.mockResolvedValue({ data: sheet({ print_note: "Oficina própria para a manutenção. Financiamento à medida." }) });
        render(<CarPrintSheet />);
        const note = await screen.findByTestId("ps-note");
        expect(note.textContent).toBe("Oficina própria para a manutenção. Financiamento à medida.");
        expect(note.nextElementSibling).toBe(screen.getByTestId("ps-legal"));
    });

    it("sem nota (ou só com espaços), não aparece nada", async () => {
        mockGetCarPrintSheet.mockResolvedValue({ data: sheet({ print_note: "   " }) });
        render(<CarPrintSheet />);
        await screen.findByTestId("ps-legal");
        expect(screen.queryByTestId("ps-note")).not.toBeInTheDocument();
    });

    it("a descrição em HTML do editor passa a parágrafos de texto simples", () => {
        expect(descriptionParagraphs("<p><span style=\"color: red\">Um &amp; dois</span></p><p><br></p><p>Linha<br>seguinte</p><script>alert(1)</script>"))
            .toEqual(["Um & dois", "Linha\nseguinte", "alert(1)"]);
        expect(descriptionParagraphs("Texto simples sem etiquetas.")).toEqual(["Texto simples sem etiquetas."]);
    });

    describe("caber numa folha A4 sem passar a largura", () => {
        const realFonts = (document as any).fonts;
        const A4_CONTENT_PX = (276 * 96) / 25.4;
        let height: (fit: number) => number;
        beforeEach(() => {
            height = () => 500;
            jest.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(function (this: HTMLElement) {
                if (!this.classList.contains("ps-fit")) return { height: 0, width: 0 } as DOMRect;
                return { height: height(Number(this.style.getPropertyValue("--ps-fit") || 1)), width: 700 } as DOMRect;
            });
        });
        afterEach(() => { jest.restoreAllMocks(); (document as any).fonts = realFonts; });

        it("sem zoom: a ficha reduz-se pela letra (--ps-fit no tamanho, nunca zoom nem largura compensada)", async () => {
            mockGetCarPrintSheet.mockResolvedValue({ data: sheet() });
            const { container } = render(<CarPrintSheet />);
            await screen.findByText("Sunlight A72");
            const css = container.querySelector("style")!.textContent!;
            expect(css).not.toMatch(/zoom\s*:/);
            expect(css).not.toMatch(/calc\(100% \/ var\(--ps-fit/);
            expect(css).toMatch(/font-size: calc\(10\.5pt \* var\(--ps-fit, 1\)\)/);
            expect(css).toMatch(/max-width: 210mm/);
            expect(css).toMatch(/font-family: 'XplInterSheet'/);
        });

        it("o fator só se calcula depois de o tipo de letra carregar, e outra vez antes de imprimir", async () => {
            let fontsLoaded: () => void = () => undefined;
            (document as any).fonts = { ready: new Promise<void>((r) => { fontsLoaded = r; }) };
            // Com a letra normal ocupa 1,5 folhas: nem com o fator mínimo (0,72) cabe.
            height = (f) => A4_CONTENT_PX * 1.5 * f;
            mockGetCarPrintSheet.mockResolvedValue({ data: sheet() });
            render(<CarPrintSheet />);
            await screen.findByText("Sunlight A72");
            const fit = () => screen.getByTestId("print-sheet").querySelector(".ps-fit") as HTMLElement;
            expect(fit().dataset.fit).toBe("1"); // ainda à espera do Inter
            await act(async () => { fontsLoaded(); await Promise.resolve(); });
            expect(fit().dataset.fit).toBe("0.72"); // fica no mínimo...
            expect(fit().dataset.pages).toBe("2"); // ...e passa a 2 páginas limpas, sem cortar texto
            // Antes de imprimir, volta a medir (agora 1,2 folhas: cabe com cerca de 0,82).
            height = (f) => A4_CONTENT_PX * 1.2 * f;
            await act(async () => { window.dispatchEvent(new Event("beforeprint")); });
            expect(Number(fit().dataset.fit)).toBeLessThan(0.83);
            expect(Number(fit().dataset.fit)).toBeGreaterThan(0.8);
            expect(fit().dataset.pages).toBe("1");
            expect(fit().className).not.toContain("ps-two-pages");
        });
    });
});
