import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import AgencyPortfoliosCard, { PortfoliosData } from "./AgencyPortfoliosCard";
import { getAgencyMetaPortfolios } from "helpers/laravel_helper";

jest.mock("helpers/laravel_helper", () => ({ getAgencyMetaPortfolios: jest.fn() }));

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
