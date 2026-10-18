import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import AuditFindingPanel from "./AuditFindingPanel";

const mockNavigate = jest.fn();
const mockFinding = jest.fn();
const mockSubstitute = jest.fn();
const mockRecipe = jest.fn();
jest.mock("react-router-dom", () => ({ useNavigate: () => mockNavigate, Link: ({ children, to }: any) => <a href={to}>{children}</a> }), { virtual: true });
jest.mock("helpers/laravel_helper", () => ({
    getAuditFinding: (...a: unknown[]) => mockFinding(...a), getRecipeSubstitute: (...a: unknown[]) => mockSubstitute(...a), getRecipe: (...a: unknown[]) => mockRecipe(...a),
    getRecipeConversionInfo: jest.fn(), setArticleGroupMain: jest.fn(), setAuditFindingState: jest.fn(), confirmRecipeYield: jest.fn(),
}));
jest.mock("pages/Restauracao/fichas/RecipeEditor", () => ({ __esModule: true, default: () => <div data-testid="recipe-editor" /> }));
jest.mock("pages/Restauracao/fichas/ConfirmFactorModal", () => ({ __esModule: true, default: () => null, parseNum: (v: string) => Number(v.replace(",", ".")) }));

const finding = (action: any) => ({ data: { financial: { visible: true }, finding: {
    id: 9, rule: "restauracao.unidade_base_grande", rule_title: "Unidade base grande", area: "restauracao", area_label: "Restauração", severity: "media",
    entity_type: "artigo", entity_id: "100", entity_label: "100 · Café", title: "Café: as fichas usam 0,007 da unidade", impact_cents: null, impact_pending_cents: 2000,
    weight_cents: null, pareto_pct: null, state: "aberto", state_by: null, state_at: null, state_reason: null, note: null, first_seen_at: null, last_seen_at: null,
    resolved_at: null, can_edit: true, seen: "Vimos.", why: "Importa.", suggestion: "Faça.", stake: "Em jogo.", evidence: {}, refs: [], events: [],
    fix: { url: "/x", label: "Abrir no Guião de unidades", action },
} } });

describe("AuditFindingPanel · achado → correção", () => {
    beforeEach(() => { [mockNavigate, mockFinding, mockSubstitute, mockRecipe].forEach((m) => m.mockReset()); });

    it("um achado de unidades abre o Guião nesse artigo", async () => {
        mockFinding.mockResolvedValue(finding({ type: "guiao", product_id: "100" }));
        render(<AuditFindingPanel companyId={5} findingId={9} onClose={jest.fn()} onChanged={jest.fn()} />);
        fireEvent.click(await screen.findByTestId("audit-fix"));
        expect(mockNavigate).toHaveBeenCalledWith("/restauracao/auditoria/guiao?artigo=100");
    });

    it("uma unidade global incoerente (U9) abre a correção artigo a artigo", async () => {
        mockFinding.mockResolvedValue(finding({ type: "unidade_global", unit_id: "CX24" }));
        render(<AuditFindingPanel companyId={5} findingId={9} onClose={jest.fn()} onChanged={jest.fn()} />);
        fireEvent.click(await screen.findByTestId("audit-fix"));
        expect(mockNavigate).toHaveBeenCalledWith("/restauracao/auditoria/guiao?unidade=CX24");
    });

    it("o M2 abre o substituto com o custo antes e depois", async () => {
        mockFinding.mockResolvedValue(finding({ type: "substituir_componente", product_id: "500", component_id: "366" }));
        mockSubstitute.mockResolvedValue({ data: { found: true, reason: null, financial: { visible: true },
            dish: { product_id: "500", label: "500 · Coca Cola 0", sold_qty_90d: 90, bom_id: "B5" },
            component: { product_id: "366", label: "366 · Coca Cola 0 garrafa", last_purchase: null, qnt: 1, unit: "Unidade", line_key: "00000000" },
            candidates: [{ product_id: "415", code: "415", description: "Coca Cola Zero 0,35", family: "BEBIDAS", why: "Descrição parecida e com compras.", last_purchase: "2026-10-01",
                price_per_base_eur: 0.56, same_unit_family: true, cost_before_eur: null, cost_after_eur: 0.56, monthly_before_eur: null, monthly_after_eur: 16.8, note: null,
                line: { key: null, replaces_key: "00000000", component_id: "415", description: "Coca Cola Zero 0,35", unit_id: "11001", qnt: 1, units: [] } }] } });
        render(<AuditFindingPanel companyId={5} findingId={9} onClose={jest.fn()} onChanged={jest.fn()} />);
        fireEvent.click(await screen.findByTestId("audit-fix"));
        await waitFor(() => expect(screen.getByTestId("substitute-modal")).toHaveTextContent("Coca Cola Zero 0,35"));
        expect(screen.getByTestId("substitute-modal")).toHaveTextContent(/16,80\s€/);
        expect(mockSubstitute).toHaveBeenCalledWith(5, "500", "366");
        fireEvent.click(screen.getByTestId("substitute-pick-415"));
        expect(await screen.findByTestId("recipe-editor")).toBeInTheDocument();
    });

    it("o rendimento em falta abre a confirmação já preenchida com a evidência", async () => {
        mockFinding.mockResolvedValue(finding({ type: "confirmar_rendimento", product_id: "162", bom_id: "B9", quantity: 30, reason: "A descrição diz «30LT»." }));
        mockRecipe.mockResolvedValue({ data: { versions: [{ bom_id: "B9", valid_from: "2026-01-01", in_force: true, cost_eur: 90,
            yield: { quantity: null, unit_label: "Litro", confirmed: false, reason: null, suggestions: [], sums: [] }, lines: [] }] } });
        render(<AuditFindingPanel companyId={5} findingId={9} onClose={jest.fn()} onChanged={jest.fn()} />);
        fireEvent.click(await screen.findByTestId("audit-fix"));
        await waitFor(() => expect(mockRecipe).toHaveBeenCalledWith(5, "162"));
        expect(await screen.findByDisplayValue("30")).toBeInTheDocument();
    });
});
