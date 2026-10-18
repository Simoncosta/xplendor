import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import PropertyModal from "./PropertyModal";
import { createProperty, getPropertySite, publishProperty, updateProperty, updatePropertySite } from "helpers/laravel_helper";
import { confirmAction } from "helpers/swal";

jest.mock("react-router-dom", () => ({ Link: ({ to, children, ...rest }: any) => <a href={to} {...rest}>{children}</a> }), { virtual: true });
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() }, ToastContainer: () => null }));
jest.mock("helpers/swal", () => ({ confirmAction: jest.fn() }));
jest.mock("helpers/laravel_helper", () => ({
    createProperty: jest.fn(), updateProperty: jest.fn(), getPropertySite: jest.fn(), updatePropertySite: jest.fn(), publishProperty: jest.fn(), unpublishProperty: jest.fn(),
    getDistricts: jest.fn(), getMunicipalities: jest.fn(), getParishes: jest.fn(),
    uploadListingImages: jest.fn(), orderListingImages: jest.fn(), updateListingImage: jest.fn(), deleteListingImage: jest.fn(),
}));

const options = { kinds: { apartamento: "Apartamento" }, typologies: ["T2"], listings: { venda: "Venda" }, statuses: { disponivel: "Disponível", fechado: "Fechado" }, energy_classes: ["A", "B", "em_processo"] };
const property: any = { id: 5, reference: "DEMO-005", title: "T2 exemplo", kind: "apartamento", kind_label: "Apartamento", typology: "T2", area_m2: 92.1, listing: "venda", price: 250000,
    energy_class: null, energy_class_missing: true, status: "disponivel", parish_id: null, parish: null, municipality_id: null, municipality: null, district_id: null,
    listed_at: "2026-10-01", closed_at: null, days: 3, contacts_30: 0, visits_30: 0, interest_updated_at: null, ine: null };
const site: any = { id: 5, reference: "DEMO-005", title: "T2 exemplo", slug: null, development_id: null, bedrooms: 2, bathrooms: 1, gross_area_m2: 110.5, construction_state: "usado",
    approx_lat: null, approx_lng: null, approx_radius_m: null, price_on_request: false, energy_precertificate: false, short_description: null, description: null,
    youtube_id: null, chave_nova_url: null, chave_nova_reference: null, site_status: "rascunho", published_at: null, show_as_sold: false, featured: false, images: [],
    publish_missing: ["Indique a classe energética.", "Carregue pelo menos uma fotografia."],
    publish_checklist: [{ field: "energy_class", label: "classe energética", message: "Indique a classe energética." }, { field: "photos", label: "fotografia", message: "Carregue pelo menos uma fotografia." }],
    options: { construction_states: { usado: "Usado" }, developments: [] } };
const ready = { ...site, publish_missing: [], publish_checklist: [] };

const renderModal = (props: Partial<React.ComponentProps<typeof PropertyModal>> = {}) => render(
    <PropertyModal companyId={7} editing={property} options={options as any} siteOn canEdit editReason={null} onClose={jest.fn()} onChanged={jest.fn()} {...props} />);

describe("Um só modal por imóvel", () => {
    beforeEach(() => {
        const { getDistricts, getMunicipalities, getParishes } = jest.requireMock("helpers/laravel_helper");
        for (const f of [getDistricts, getMunicipalities, getParishes]) (f as jest.Mock).mockResolvedValue({ data: [] });
        (getPropertySite as jest.Mock).mockResolvedValue({ data: site });
    });

    it("tem os separadores Dados, Fotografias e Site; o Site mostra o que falta e leva ao campo", async () => {
        renderModal({ initialTab: "site" });
        expect(await screen.findByTestId("site-missing-summary")).toHaveTextContent("Faltam: classe energética, fotografia");
        expect(screen.getByTestId("tab-data")).toBeInTheDocument();
        expect(screen.getByTestId("tab-photos")).toHaveTextContent("Fotografias (0)");
        fireEvent.click(screen.getByTestId("go-energy_class"));
        expect(await screen.findByLabelText("Classe energética")).toBeInTheDocument(); // o separador Dados, com o campo
        fireEvent.click(screen.getByTestId("tab-site"));
        fireEvent.click(await screen.findByTestId("go-photos"));
        expect(await screen.findByTestId("listing-photos")).toBeInTheDocument();
    });

    it("a área aceita vírgula e Guardar grava os dados e o anúncio sem fechar", async () => {
        (updateProperty as jest.Mock).mockResolvedValue({ data: { ...property, area_m2: 92.5 }, message: "Imóvel guardado." });
        (updatePropertySite as jest.Mock).mockResolvedValue({ data: site });
        const onClose = jest.fn();
        renderModal({ onClose });
        const area = await screen.findByLabelText("Área útil (m²)");
        expect(area).toHaveValue("92,1");
        await screen.findByTestId("tab-site");
        await waitFor(() => expect(getPropertySite).toHaveBeenCalled());
        fireEvent.change(area, { target: { value: "92,5" } });
        fireEvent.click(screen.getByTestId("property-save"));
        await waitFor(() => expect(updatePropertySite).toHaveBeenCalled());
        expect((updateProperty as jest.Mock).mock.calls[0][2]).toMatchObject({ area_m2: "92,5" });
        expect((updatePropertySite as jest.Mock).mock.calls[0][2]).toMatchObject({ gross_area_m2: "110,5", bedrooms: 2, construction_state: "usado" });
        expect(onClose).not.toHaveBeenCalled();
        expect(screen.getByTestId("property-modal")).toBeInTheDocument();
    });

    it("Gravar e publicar grava primeiro e publica no mesmo modal", async () => {
        (getPropertySite as jest.Mock).mockResolvedValue({ data: ready });
        (updateProperty as jest.Mock).mockResolvedValue({ data: { ...property, energy_class: "B", energy_class_missing: false } });
        (updatePropertySite as jest.Mock).mockResolvedValue({ data: ready });
        (publishProperty as jest.Mock).mockResolvedValue({ data: { ...ready, site_status: "publicado" }, message: "Imóvel publicado no site." });
        const changed = jest.fn();
        renderModal({ onChanged: changed, initialTab: "site" });
        fireEvent.change(await screen.findByLabelText("Vídeo do YouTube"), { target: { value: "https://youtube.com/shorts/AbCdEfGhIjK" } });
        fireEvent.click(screen.getByTestId("property-publish"));
        await waitFor(() => expect(publishProperty).toHaveBeenCalledWith(7, 5));
        expect((updatePropertySite as jest.Mock).mock.calls[0][2]).toMatchObject({ youtube: "https://youtube.com/shorts/AbCdEfGhIjK" });
        expect(changed).toHaveBeenCalled();
        expect((await screen.findAllByText("Publicado")).length).toBeGreaterThan(0);
    });

    it("um imóvel novo: Fotografias e Site depois de guardar os dados, sem sair do modal", async () => {
        (createProperty as jest.Mock).mockResolvedValue({ data: { ...property, id: 9, reference: "DEMO-009" }, message: "Imóvel criado." });
        renderModal({ editing: "new" });
        expect(await screen.findByText(/guarde primeiro os dados/)).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText("Referência"), { target: { value: "DEMO-009" } });
        fireEvent.change(screen.getByLabelText("Título"), { target: { value: "Moradia exemplo" } });
        fireEvent.change(screen.getByLabelText("Preço (€)"), { target: { value: "650000" } });
        fireEvent.click(screen.getByTestId("property-save"));
        await waitFor(() => expect(getPropertySite).toHaveBeenCalledWith(7, 9));
        expect(await screen.findByTestId("tab-site")).toBeInTheDocument();
    });

    it("sem o módulo do site, só os dados; sem permissão, os campos ficam bloqueados", async () => {
        renderModal({ siteOn: false, canEdit: false, editReason: "Não tem permissão para esta ação." });
        expect(await screen.findByLabelText("Referência")).toBeDisabled();
        expect(screen.queryByTestId("tab-site")).not.toBeInTheDocument();
        expect(getPropertySite).not.toHaveBeenCalled();
        expect(screen.getByTestId("property-save")).toBeDisabled();
    });

    it("retirar do site pelo menu dos três pontos, por cima do modal", async () => {
        (getPropertySite as jest.Mock).mockResolvedValue({ data: { ...ready, site_status: "publicado" } });
        (confirmAction as jest.Mock).mockResolvedValue(true);
        const { unpublishProperty } = jest.requireMock("helpers/laravel_helper");
        (unpublishProperty as jest.Mock).mockResolvedValue({ data: ready, message: "Imóvel retirado do site." });
        renderModal();
        fireEvent.click(await screen.findByLabelText("Mais ações: site do imóvel"));
        const item = await screen.findByText("Retirar do site");
        expect((item.closest(".dropdown-menu") as HTMLElement).style.zIndex).toBe("1070");
        fireEvent.click(item);
        await waitFor(() => expect(unpublishProperty).toHaveBeenCalledWith(7, 5));
    });
});
