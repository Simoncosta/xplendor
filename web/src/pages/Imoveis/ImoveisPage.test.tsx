import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import ImoveisPage from "./ImoveisPage";
import { createProperty, getDistricts, getMunicipalities, getParishes, getProperties } from "helpers/laravel_helper";

jest.mock("react-router-dom", () => ({ useSearchParams: () => [new URLSearchParams("")] }), { virtual: true });
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() }, ToastContainer: () => null }));
jest.mock("helpers/workingCompany", () => ({ getWorkingCompanyId: () => 7 }));
jest.mock("helpers/swal", () => ({ confirmAction: jest.fn(() => Promise.resolve(true)) }));
let mockPerms = ["imoveis.ver", "imoveis.criar", "imoveis.editar"];
jest.mock("contexts/ModulesContext", () => ({ useCan: (p: string) => [mockPerms.includes(p), mockPerms.includes(p) ? null : "Não tem permissão para esta ação."] }));
jest.mock("helpers/laravel_helper", () => ({
    getProperties: jest.fn(), createProperty: jest.fn(() => Promise.resolve({ message: "Imóvel criado." })), updateProperty: jest.fn(), deleteProperty: jest.fn(),
    getDistricts: jest.fn(() => Promise.resolve({ data: [] })), getMunicipalities: jest.fn(() => Promise.resolve({ data: [] })), getParishes: jest.fn(() => Promise.resolve({ data: [] })),
}));

const options = { kinds: { apartamento: "Apartamento", moradia: "Moradia" }, typologies: ["T1", "T2"], listings: { venda: "Venda", arrendamento: "Arrendamento" },
    statuses: { disponivel: "Disponível", reservado: "Reservado", fechado: "Fechado" }, energy_classes: ["A", "B", "C", "em_processo"] };

describe("Imóveis: a carteira", () => {
    beforeEach(() => {
        (createProperty as jest.Mock).mockResolvedValue({ message: "Imóvel criado." });
        for (const f of [getDistricts, getMunicipalities, getParishes]) (f as jest.Mock).mockResolvedValue({ data: [] });
        (getProperties as jest.Mock).mockResolvedValue({ data: { options, properties: [{
            id: 1, reference: "DEMO-001", title: "T2 renovado em Benfica", kind: "apartamento", kind_label: "Apartamento", typology: "T2", area_m2: 85, listing: "venda",
            price: 350000, energy_class: null, energy_class_missing: true, status: "disponivel", parish_id: 3, parish: "Benfica", municipality_id: 2, municipality: "Lisboa",
            district_id: 11, listed_at: "2026-10-11", closed_at: null, days: 3, contacts_30: 0, visits_30: 0, interest_updated_at: null,
            ine: { median_m2: 4000, period: "1.º Trimestre de 2026", attribution: "x" },
        }] } });
    });

    it("lista os imóveis com a classe energética em falta e a referência do INE, e cria um imóvel", async () => {
        render(<ImoveisPage />);
        expect(await screen.findByText("T2 renovado em Benfica")).toBeInTheDocument();
        expect(screen.getByText("Em falta")).toBeInTheDocument();
        expect(screen.getByText(/INE: 4\s?000 €\/m²/)).toBeInTheDocument();
        fireEvent.click(screen.getByTestId("property-new"));
        fireEvent.change(await screen.findByLabelText("Referência"), { target: { value: "DEMO-009" } });
        fireEvent.change(screen.getByLabelText("Título"), { target: { value: "Moradia em Carnide" } });
        fireEvent.change(screen.getByLabelText("Preço (€)"), { target: { value: "650000" } });
        fireEvent.click(screen.getByTestId("property-save"));
        await waitFor(() => expect(createProperty).toHaveBeenCalled());
        expect((createProperty as jest.Mock).mock.calls[0][1]).toMatchObject({ reference: "DEMO-009", title: "Moradia em Carnide", price: 650000, listing: "venda", status: "disponivel" });
    });

    it("sem imoveis.criar o botão fica indisponível com o motivo", async () => {
        mockPerms = ["imoveis.ver"];
        render(<ImoveisPage />);
        await screen.findByText("T2 renovado em Benfica");
        expect(screen.getByTestId("property-new")).toBeDisabled();
        mockPerms = ["imoveis.ver", "imoveis.criar", "imoveis.editar"];
    });
});
