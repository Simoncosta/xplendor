import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import AgencyPortfoliosCard, { PortfoliosData } from "./AgencyPortfoliosCard";
import { getAgencyMetaLinks, getAgencyMetaPortfolios, linkAgencyMetaAsset } from "helpers/laravel_helper";
import { confirmAction } from "helpers/swal";

jest.mock("helpers/laravel_helper", () => ({ getAgencyMetaPortfolios: jest.fn(), getAgencyMetaLinks: jest.fn(), linkAgencyMetaAsset: jest.fn(), unlinkAgencyMetaAsset: jest.fn() }));
jest.mock("helpers/swal", () => ({ confirmAction: jest.fn() }));
jest.mock("react-toastify", () => ({ toast: { error: jest.fn(), success: jest.fn() } }));

const noLinks = { clients: [{ id: 41, name: "Tasca Gerida" }], links: [] };
beforeEach(() => {
    (getAgencyMetaLinks as jest.Mock).mockResolvedValue({ data: noLinks });
});

const data: PortfoliosData = {
    connected: true, ok: true, error: null, kind: null, read_at: "2026-11-10T10:00:00Z",
    businesses: [{
        id: "B2", name: "Restaurante Cliente", verification_status: "not_verified",
        pages: [{ id: "2002", name: "Restaurante", relation: "client", instagram: { id: "17842", username: "restaurante" } }],
        ad_accounts: [{ account_id: "555", name: "Anúncios do Restaurante", relation: "client", currency: "EUR", account_status: 1 }],
        instagram: [{ id: "17842", username: "restaurante", relation: "owned" }],
    }],
};

describe("AgencyPortfoliosCard (Parceiro 3)", () => {
    it("mostra cada portfólio com as Páginas, as contas de anúncios e o Instagram, da agência ou do cliente", async () => {
        (getAgencyMetaPortfolios as jest.Mock).mockResolvedValue({ data });
        render(<AgencyPortfoliosCard agencyId={3} />);
        const portfolio = await screen.findByTestId("agency-portfolio");
        expect(within(portfolio).getByText("Restaurante Cliente")).toBeInTheDocument();
        expect(within(portfolio).getByText("Não verificado")).toBeInTheDocument();
        expect(within(portfolio).getByText("555 · EUR · Ativa")).toBeInTheDocument();
        expect(within(portfolio).getAllByText("do cliente")).toHaveLength(2);
        expect(within(portfolio).getByText("da agência")).toBeInTheDocument();
        expect(within(portfolio).getAllByText(/@restaurante/)).toHaveLength(2);
    });

    it("Parceiro 4: liga uma Página a um cliente; com a ligação própria da empresa, pergunta se passa para a agência", async () => {
        (getAgencyMetaPortfolios as jest.Mock).mockResolvedValue({ data });
        (linkAgencyMetaAsset as jest.Mock).mockRejectedValueOnce({ __status: 409, message: "Esta empresa já tem a sua própria ligação às redes." }).mockResolvedValueOnce({ data: {} });
        (confirmAction as jest.Mock).mockResolvedValue(true);
        (getAgencyMetaLinks as jest.Mock).mockResolvedValueOnce({ data: noLinks })
            .mockResolvedValue({ data: { ...noLinks, links: [{ id: 5, asset_type: "page", external_id: "2002", company_id: 41, company: "Tasca Gerida", status: "ligado", message: null }] } });
        render(<AgencyPortfoliosCard agencyId={3} />);
        const control = await screen.findByTestId("asset-link-page-2002");
        fireEvent.change(within(control).getByLabelText("Ligar a um cliente"), { target: { value: "41" } });
        fireEvent.click(within(control).getByText("Ligar"));
        await waitFor(() => expect(linkAgencyMetaAsset).toHaveBeenLastCalledWith(3, { company_id: 41, asset_type: "page", external_id: "2002", replace: true }));
        expect(confirmAction).toHaveBeenCalled();
        expect(await screen.findByText("Ligado a Tasca Gerida")).toBeInTheDocument();
    });

    it("Parceiro 4: uma Página sem tarefa fica registada, com o que fazer", async () => {
        (getAgencyMetaPortfolios as jest.Mock).mockResolvedValue({ data });
        (getAgencyMetaLinks as jest.Mock).mockResolvedValue({ data: { ...noLinks, links: [{ id: 6, asset_type: "page", external_id: "2002", company_id: 41, company: "Tasca Gerida",
            status: "sem_tarefa", message: "A Meta não deu acesso a esta Página: atribua uma tarefa." }] } });
        render(<AgencyPortfoliosCard agencyId={3} />);
        expect(await screen.findByTestId("asset-link-page-2002")).toHaveTextContent("Registado para Tasca Gerida, sem acesso: A Meta não deu acesso a esta Página: atribua uma tarefa.");
    });

    it("\"Atualizar\" lê de novo; um erro da Meta aparece como aviso", async () => {
        (getAgencyMetaPortfolios as jest.Mock).mockResolvedValueOnce({ data })
            .mockResolvedValueOnce({ data: { ...data, ok: false, error: "O acesso da agência à Meta expirou.", businesses: [] } });
        render(<AgencyPortfoliosCard agencyId={3} />);
        await screen.findByTestId("agency-portfolio");
        fireEvent.click(screen.getByTestId("agency-portfolios-refresh"));
        await waitFor(() => expect(getAgencyMetaPortfolios).toHaveBeenLastCalledWith(3, true));
        expect(await screen.findByTestId("agency-portfolios-error")).toHaveTextContent("O acesso da agência à Meta expirou.");
    });
});
