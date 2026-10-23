import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import ArticleGroupingSwitch from "./ArticleGroupingSwitch";

/** Cadastros › Artigos: "Artigo de agrupamento" lê o estado, muda-o e diz quem mudou e quando. */
const mockApi = { get: jest.fn(), set: jest.fn() };
jest.mock("helpers/laravel_helper", () => ({
    getArticleGrouping: (...a: unknown[]) => mockApi.get(...a),
    setArticleGrouping: (...a: unknown[]) => mockApi.set(...a),
}));
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() } }));

beforeEach(() => Object.values(mockApi).forEach((f) => f.mockReset()));

test("marcar o artigo como de agrupamento guarda e mostra quem mudou", async () => {
    mockApi.get.mockResolvedValue({ data: { grouping: false, changed_at: null, changed_by_name: null } });
    mockApi.set.mockResolvedValue({ data: { grouping: true, changed_at: "2026-10-23T10:00:00+01:00", changed_by_name: "Ana Gestora" }, message: "ok" });
    render(<ArticleGroupingSwitch companyId={5} catalogItemId={77} />);
    const sw = await screen.findByRole("switch", { name: "Artigo de agrupamento" });
    expect(sw).not.toBeChecked();
    expect(screen.getByTestId("article-grouping")).toHaveTextContent("Ao lançar, as linhas deste artigo juntam-se numa só, com quantidade 1 e o total somado.");
    fireEvent.click(sw);
    await waitFor(() => expect(mockApi.set).toHaveBeenCalledWith(5, 77, true));
    expect(await screen.findByTestId("article-grouping-changed")).toHaveTextContent("Mudado por Ana Gestora a");
    expect(screen.getByRole("switch", { name: "Artigo de agrupamento" })).toBeChecked();
});

test("sem artigo guardado (criação), não aparece", () => {
    const { container } = render(<ArticleGroupingSwitch companyId={5} catalogItemId={null} />);
    expect(container).toBeEmptyDOMElement();
    expect(mockApi.get).not.toHaveBeenCalled();
});
