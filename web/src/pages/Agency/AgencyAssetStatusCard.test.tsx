import { render, screen, within } from "@testing-library/react";
import AgencyAssetStatusCard from "./AgencyAssetStatusCard";
import { getAgencyMetaLinks } from "helpers/laravel_helper";

jest.mock("helpers/laravel_helper", () => ({ getAgencyMetaLinks: jest.fn() }));

describe("Parceiro 5: o estado de cada ativo ligado a um cliente", () => {
    beforeEach(() => {
        (getAgencyMetaLinks as jest.Mock).mockResolvedValue({ data: {
            clients: [{ id: 3, name: "Restaurante Cliente Lda" }],
            links: [
                { id: 1, asset_type: "page", external_id: "2002", name: "Restaurante", company_id: 3, company: "Restaurante Cliente Lda", status: "ligado", message: null, checked_at: "2026-10-23T08:15:00Z" },
                { id: 2, asset_type: "ad_account", external_id: "555", name: "Anúncios do Restaurante", company_id: 3, company: "Restaurante Cliente Lda", status: "sem_acesso",
                    message: "Saiu dos portfólios da Meta que a agência vê.", checked_at: "2026-10-23T08:15:00Z" },
            ],
        } });
    });

    it("mostra o estado, o motivo de quem ficou sem acesso e a contagem dos problemas", async () => {
        render(<AgencyAssetStatusCard agencyId={9} />);
        const rows = await screen.findAllByTestId("agency-asset-row");
        expect(rows).toHaveLength(2);
        expect(within(rows[0]).getByText("Ligado")).toBeInTheDocument();
        expect(within(rows[1]).getByText("Sem acesso")).toBeInTheDocument();
        expect(within(rows[1]).getByText("Saiu dos portfólios da Meta que a agência vê.")).toBeInTheDocument();
        expect(within(rows[1]).getByText(/Restaurante Cliente Lda · verificado a/)).toBeInTheDocument();
        expect(screen.getByText("1 com problemas")).toBeInTheDocument();
    });

    it("sem ativos ligados, diz que não há", async () => {
        (getAgencyMetaLinks as jest.Mock).mockResolvedValue({ data: { clients: [], links: [] } });
        render(<AgencyAssetStatusCard agencyId={9} />);
        expect(await screen.findByText("Ainda nenhum ativo ligado a um cliente.")).toBeInTheDocument();
    });
});
