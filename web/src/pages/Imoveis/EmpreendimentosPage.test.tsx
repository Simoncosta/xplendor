import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import EmpreendimentosPage from "./EmpreendimentosPage";
import { createDevelopment, getDevelopments, getDistricts, getListingsSummary, getMunicipalities, getParishes } from "helpers/laravel_helper";

jest.mock("react-router-dom", () => ({ Link: ({ to, children, ...rest }: any) => <a href={to} {...rest}>{children}</a>, useSearchParams: () => [new URLSearchParams("")] }), { virtual: true });
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() }, ToastContainer: () => null }));
jest.mock("helpers/workingCompany", () => ({ getWorkingCompanyId: () => 7 }));
jest.mock("helpers/swal", () => ({ confirmAction: jest.fn(() => Promise.resolve(true)) }));
const mockPerms = ["imoveis.ver", "imoveis.criar", "imoveis.editar"];
jest.mock("contexts/ModulesContext", () => ({ useCan: (p: string) => [mockPerms.includes(p), mockPerms.includes(p) ? null : "Não tem permissão para esta ação."] }));
jest.mock("helpers/laravel_helper", () => ({
    getDevelopments: jest.fn(), getDevelopment: jest.fn(), createDevelopment: jest.fn(), updateDevelopment: jest.fn(), deleteDevelopment: jest.fn(),
    publishDevelopment: jest.fn(), unpublishDevelopment: jest.fn(), getListingsSummary: jest.fn(() => Promise.resolve({ data: { properties: {}, developments: { 3: { views_30: 12 } } } })),
    getDistricts: jest.fn(() => Promise.resolve({ data: [] })), getMunicipalities: jest.fn(() => Promise.resolve({ data: [] })), getParishes: jest.fn(() => Promise.resolve({ data: [] })),
    uploadListingImages: jest.fn(), orderListingImages: jest.fn(), updateListingImage: jest.fn(), deleteListingImage: jest.fn(),
}));

describe("Empreendimentos", () => {
    beforeEach(() => {
        for (const f of [getDistricts, getMunicipalities, getParishes]) (f as jest.Mock).mockResolvedValue({ data: [] });
        (getListingsSummary as jest.Mock).mockResolvedValue({ data: { properties: {}, developments: { 3: { views_30: 12 } } } });
    });
    it("lista os empreendimentos com a fase da obra e as visualizações, e cria um novo", async () => {
        (getDevelopments as jest.Mock).mockResolvedValue({ data: { options: { phases: { projeto: "Projeto", estrutura: "Estrutura" }, energy_classes: ["A", "B"] }, developments: [{
            id: 3, name: "Empreendimento Exemplo", slug: "empreendimento-exemplo", short_description: null, parish_id: 1, parish: "Paranhos", municipality_id: 1, municipality: "Porto",
            district_id: 1, lat: 41.17, lng: -8.6, construction_phase: "estrutura", construction_phase_label: "Estrutura", expected_completion: "2027-12", energy_class_expected: "A",
            site_status: "publicado", published_at: null, featured: false, units_count: 4, cover_url: null,
        }] } });
        (createDevelopment as jest.Mock).mockResolvedValue({ data: { id: 9, name: "Novo Exemplo", slug: "novo-exemplo", construction_phase: "projeto", construction_phase_label: "Projeto",
            site_status: "rascunho", images: [], units: [], publish_missing: ["Indique a freguesia."], publish_checklist: [{ field: "parish_id", label: "freguesia", message: "Indique a freguesia." }],
            featured: false, units_count: 0, cover_url: null } });
        render(<EmpreendimentosPage />);
        expect(await screen.findByText("Empreendimento Exemplo")).toBeInTheDocument();
        expect(screen.getByText(/conclusão prevista 12\/2027/)).toBeInTheDocument();
        expect(await screen.findByText("12 visualizações em 30 dias")).toBeInTheDocument();
        fireEvent.click(screen.getByTestId("development-new"));
        fireEvent.change(await screen.findByLabelText("Nome"), { target: { value: "Novo Exemplo" } });
        fireEvent.click(screen.getByTestId("development-save"));
        await waitFor(() => expect(createDevelopment).toHaveBeenCalled());
        expect((createDevelopment as jest.Mock).mock.calls[0][1]).toMatchObject({ name: "Novo Exemplo", construction_phase: "projeto" });
        // Depois de criar: os separadores Fotografias, Site e Frações; o Site mostra o que falta e leva ao campo.
        fireEvent.click(await screen.findByTestId("dev-tab-site"));
        expect(await screen.findByTestId("site-missing-summary")).toHaveTextContent("Faltam: freguesia");
        fireEvent.click(screen.getByTestId("go-parish_id"));
        expect(await screen.findByLabelText("Nome")).toBeInTheDocument();
        expect(screen.getByTestId("development-publish")).toHaveTextContent("Gravar e publicar");
    });
});
