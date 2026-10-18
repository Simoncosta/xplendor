import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import SiteConsultorPage from "./SiteConsultorPage";
import { confirmAction } from "helpers/swal";
import { deleteConsultantSiteZonePhoto, getConsultantSite, getDistricts, getMunicipalities, getParishes, previewConsultantSite, updateConsultantSite, uploadConsultantSiteZonePhoto } from "helpers/laravel_helper";

jest.mock("react-router-dom", () => ({ Link: ({ to, children, ...rest }: any) => <a href={to} {...rest}>{children}</a>, useSearchParams: () => [new URLSearchParams("")] }), { virtual: true });
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() }, ToastContainer: () => null }));
jest.mock("helpers/workingCompany", () => ({ getWorkingCompanyId: () => 7 }));
jest.mock("helpers/swal", () => ({ confirmAction: jest.fn(() => Promise.resolve(true)) }));
jest.mock("helpers/roles", () => ({ sessionIsPlatformRoot: () => false }));
let mockPerms = ["site_consultor.ver", "site_consultor.editar"];
jest.mock("contexts/ModulesContext", () => ({ useCan: (p: string) => [mockPerms.includes(p), mockPerms.includes(p) ? null : "Só o Administrador pode publicar o site."] }));
jest.mock("helpers/laravel_helper", () => ({
    getConsultantSite: jest.fn(), updateConsultantSite: jest.fn(), uploadConsultantSiteAsset: jest.fn(), deleteConsultantSiteAsset: jest.fn(), uploadConsultantSiteZonePhoto: jest.fn(), deleteConsultantSiteZonePhoto: jest.fn(),
    publishConsultantSite: jest.fn(), previewConsultantSite: jest.fn(), unpublishConsultantSite: jest.fn(), getConsultantSiteHosts: jest.fn(), updateConsultantSiteHosts: jest.fn(),
    getDistricts: jest.fn(() => Promise.resolve({ data: [] })), getMunicipalities: jest.fn(() => Promise.resolve({ data: [] })), getParishes: jest.fn(() => Promise.resolve({ data: [] })),
}));

const profile = {
    company: { public_name: "Consultor Exemplo", mobile: "+351 900 000 000", whatsapp_number: "+351900000000", socials: {}, facebook_pixel_id: null, fiscal_name: "Consultor Exemplo (fictício)",
        nipc: "999999990", address: "Rua Exemplo, 1", email: "geral@exemplo.invalid" },
    hero_promise: null, bio_short: null, intro_youtube_id: null, intro_video_enabled: false, featured_videos: ["AbCdEfGhIjK"], zone_photos: [] as any[], brokerage_legal_name: null, brokerage_ami: null,
    network_label: "Consultor Chave Nova", network_text: null, legal_links: { complaints_book_url: null, ral: [], legal_info_text: null },
    controller_is_company: true, controller_legal_name: null, controller_nif: null, controller_address: null, controller_email: null,
    data_controller: { legal_name: "Consultor Exemplo (fictício)", nif: "999999990", address: "Rua Exemplo, 1", email: "geral@exemplo.invalid", is_company: true },
    privacy_policy_version: null, credit_partner: { legal_name: null, bp_registration: null, text: null }, credit_partner_enabled: false,
    whatsapp_messages: { general: { popup: "Está a pensar vender?", prefill: "Olá {nome}" }, property: { popup: "a", prefill: "b" }, development: { popup: "c", prefill: "d" }, buy: { popup: "e", prefill: "f" } },
    parishes: [], ga4_id: null, colors: { navy: "#0E1E37", blue: "#1D3856", amber: "#FFA000", cream: "#FAF6EF", slate: "#5B6B7F" },
    sections: { marketing: true, credit: false }, show_made_with: true, assets: { signature: null, photo: null }, hosts: [{ host: "consultor.exemplo.test", is_primary: true }],
    site_status: "rascunho", published_at: null, publish_missing: ["Indique a licença AMI.", "Indique a ligação do livro de reclamações."],
    options: { sections: { marketing: "O que faço pela sua casa", credit: "Crédito com o parceiro" }, default_colors: { navy: "#0E1E37", blue: "#1D3856", amber: "#FFA000", cream: "#FAF6EF", slate: "#5B6B7F" },
        default_whatsapp_messages: {} },
};

describe("Site do consultor", () => {
    beforeEach(() => {
        (getConsultantSite as jest.Mock).mockResolvedValue({ data: profile });
        for (const f of [getDistricts, getMunicipalities, getParishes]) (f as jest.Mock).mockResolvedValue({ data: [] });
    });

    it("mostra o que falta para publicar e os dados que vêm da empresa", async () => {
        render(<SiteConsultorPage />);
        expect(await screen.findByTestId("site-publish-missing")).toHaveTextContent("Indique a licença AMI.");
        expect(screen.getByTestId("site-publish")).toBeDisabled();
        expect(screen.getByText("Consultor Exemplo")).toBeInTheDocument();
        expect(screen.getByText("+351900000000")).toBeInTheDocument();
    });

    it("guarda a mediação e a promessa do topo", async () => {
        (updateConsultantSite as jest.Mock).mockResolvedValue({ data: { ...profile, brokerage_ami: "12345" }, message: "Perfil do site guardado." });
        render(<SiteConsultorPage />);
        fireEvent.change(await screen.findByLabelText("Licença AMI"), { target: { value: "12345" } });
        fireEvent.change(screen.getByLabelText("A promessa do topo"), { target: { value: "Vender com um plano claro." } });
        fireEvent.click(screen.getByTestId("site-save"));
        await waitFor(() => expect(updateConsultantSite).toHaveBeenCalled());
        expect((updateConsultantSite as jest.Mock).mock.calls[0][1]).toMatchObject({ brokerage_ami: "12345", hero_promise: "Vender com um plano claro.", controller_is_company: true });
    });

    it("sem permissão para publicar, o botão diz porquê mesmo com tudo preenchido", async () => {
        mockPerms = ["site_consultor.ver", "site_consultor.editar"];
        (getConsultantSite as jest.Mock).mockResolvedValue({ data: { ...profile, publish_missing: [] } });
        render(<SiteConsultorPage />);
        expect(await screen.findByTestId("site-publish")).toBeDisabled();
        mockPerms = ["site_consultor.ver", "site_consultor.editar", "site_consultor.aprovar"];
    });

    it("o responsável pelo tratamento fica por escolher até se escolher", async () => {
        (getConsultantSite as jest.Mock).mockResolvedValue({ data: { ...profile, controller_is_company: null,
            data_controller: { legal_name: null, nif: null, address: null, email: null, is_company: null } } });
        (updateConsultantSite as jest.Mock).mockResolvedValue({ data: profile, message: "Perfil do site guardado." });
        render(<SiteConsultorPage />);
        expect(await screen.findByText(/Por escolher: o site não pode ser publicado/)).toBeInTheDocument();
        expect(screen.getByLabelText("A própria empresa")).not.toBeChecked();
        expect(screen.getByLabelText("Outra entidade (por exemplo, a agência)")).not.toBeChecked();
        fireEvent.click(screen.getByLabelText("Outra entidade (por exemplo, a agência)"));
        expect(screen.getByLabelText("Nome legal do responsável")).toBeInTheDocument();
        fireEvent.click(screen.getByTestId("site-save"));
        await waitFor(() => expect(updateConsultantSite).toHaveBeenCalled());
        expect((updateConsultantSite as jest.Mock).mock.calls[0][1]).toMatchObject({ controller_is_company: false });
    });

    it("pré-visualizar abre o link assinado noutro separador", async () => {
        const tab: any = { opener: {}, location: { href: "" }, close: jest.fn() };
        const open = jest.spyOn(window, "open").mockReturnValue(tab);
        (previewConsultantSite as jest.Mock).mockResolvedValue({ data: { url: "https://consultor.exemplo.test/pre-visualizar?token=abc", expires_at: "x" } });
        render(<SiteConsultorPage />);
        fireEvent.click(await screen.findByTestId("site-preview"));
        await waitFor(() => expect(tab.location.href).toBe("https://consultor.exemplo.test/pre-visualizar?token=abc"));
        expect(tab.opener).toBeNull();
        open.mockRestore();
    });

    it("os vídeos em destaque são um link por linha, pela ordem", async () => {
        (updateConsultantSite as jest.Mock).mockResolvedValue({ data: profile, message: "Perfil do site guardado." });
        render(<SiteConsultorPage />);
        const field = await screen.findByLabelText("Vídeos em destaque (YouTube)");
        expect(field).toHaveValue("https://www.youtube.com/shorts/AbCdEfGhIjK");
        fireEvent.change(field, { target: { value: "https://www.youtube.com/shorts/AbCdEfGhIjK\n\n  https://youtu.be/ZyXwVuTsRqP  " } });
        fireEvent.click(screen.getByTestId("site-save"));
        await waitFor(() => expect(updateConsultantSite).toHaveBeenCalled());
        expect((updateConsultantSite as jest.Mock).mock.calls.at(-1)[1].featured_videos).toEqual(["https://www.youtube.com/shorts/AbCdEfGhIjK", "https://youtu.be/ZyXwVuTsRqP"]);
    });

    it("carrega e retira fotografias das zonas", async () => {
        const photo = { id: "abcdefghij", path: "/storage/company_7/consultor-site/zonas/zona-abcdefghij.webp", path_960: "/storage/company_7/consultor-site/zonas/zona-abcdefghij-960.webp",
            width: 2400, height: 1600, alt: "Rua de exemplo", parish_id: null };
        (uploadConsultantSiteZonePhoto as jest.Mock).mockResolvedValue({ data: { ...profile, zone_photos: [photo] }, message: "Fotografia guardada." });
        (deleteConsultantSiteZonePhoto as jest.Mock).mockResolvedValue({ data: profile, message: "Fotografia retirada." });
        (confirmAction as jest.Mock).mockResolvedValue(true);
        render(<SiteConsultorPage />);
        expect(await screen.findByText(/Ainda sem fotografias das zonas/)).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText("Descrição (o que se vê)"), { target: { value: "Rua de exemplo" } });
        const file = new File(["x"], "rua.jpg", { type: "image/jpeg" });
        fireEvent.change(screen.getByTestId("zone-photo-input"), { target: { files: [file] } });
        await waitFor(() => expect(uploadConsultantSiteZonePhoto).toHaveBeenCalledWith(7, file, "Rua de exemplo", null));
        expect(await screen.findByAltText("Rua de exemplo")).toBeInTheDocument();
        fireEvent.click(screen.getByLabelText("Mais ações: fotografia da zona"));
        fireEvent.click(await screen.findByText("Retirar"));
        await waitFor(() => expect(deleteConsultantSiteZonePhoto).toHaveBeenCalledWith(7, "abcdefghij"));
    });
});
