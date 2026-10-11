import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import StandBussola from "./StandBussola";
import StandBussolaDashboard from "./StandBussolaDashboard";
import { excludeStandVehicle, getBussolaStand, ignoreStandSignals } from "helpers/laravel_helper";
import type { StandCompassData } from "common/models/standCompass.model";
import { confirmAction } from "helpers/swal";

jest.mock("react-router-dom", () => ({ Link: ({ to, children, ...rest }: any) => <a href={to} {...rest}>{children}</a> }), { virtual: true });
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() }, ToastContainer: () => null }));
jest.mock("helpers/workingCompany", () => ({ getWorkingCompanyId: () => 7 }));
jest.mock("helpers/swal", () => ({ confirmAction: jest.fn(() => Promise.resolve(true)) }));
let mockPerms: string[] = ["automovel.ver", "automovel.editar"];
jest.mock("contexts/ModulesContext", () => ({ useAccess: () => ({ has: (m?: string) => m === "stock" || !m, can: (p?: string) => !p || mockPerms.includes(p) }) }));
jest.mock("helpers/laravel_helper", () => ({
    getBussolaStand: jest.fn(), createPostFromStandPlay: jest.fn(), requestStandPlayCaption: jest.fn(), getStandPlayCaption: jest.fn(),
    ignoreStandSignals: jest.fn(() => Promise.resolve({})), restoreStandSignal: jest.fn(), excludeStandVehicle: jest.fn(() => Promise.resolve({})),
    createPostFromPlay: jest.fn(), requestPlayCaption: jest.fn(), getPlayCaption: jest.fn(), excludeRestaurantItem: jest.fn(), ignoreRestaurantSignal: jest.fn(),
}));

const where = { networks: [{ network: "instagram", label: "Instagram", format_key: "ig_feed_image", format_label: "Imagem (feed)", format_source: null }], connected: true, ad: null };
const play = (over: any) => ({
    key: "review_price:all", type: "review_price", type_label: "Preço acima do mercado", icon: "ri-price-tag-3-line", color: "warning", locations: [], location_id: 0,
    title: "Rever o preço de Peugeot 208 (2019)", confidence: "alta", number: { value: "+12%", caption: "acima da mediana dos comparáveis" }, bars: [],
    items: [{ car_id: 11, label: "Peugeot 208 (2019)", detail: "50 dias, +12%, mediana de 20 000 € (12 comparáveis)" }],
    what: { text: "Reveja o preço face aos veículos comparáveis no mercado.", source: "template" }, where: { networks: [], connected: true, ad: "Na ficha do veículo" },
    when: { date: "2026-10-09", is_today: false, label: "Rever na sexta-feira, 09/10", target: null },
    detail: { sentences: ["Frase."], sample: "Amostra.", confidence: [] }, signal_keys: ["review_price:11"], theme: "Tema", format: "Carrossel",
    car_id: 11, actions: ["review_price", "view_car"], post_note: null, ...over,
});

const data = (over: Partial<StandCompassData> = {}): StandCompassData => ({
    enabled: true, provider: "stand", company: "Stand Exemplo", type: "all",
    types: [{ key: "all", label: "Todos" }, { key: "car", label: "Carros" }, { key: "motorhome", label: "Autocaravanas" }],
    computed_at: "2026-10-08T05:20:00Z", data_until: "2026-10-08", can_act: true, can_act_reason: null, formats: ["Carrossel"],
    financial: { visible: true, note: null },
    top: { title: "Esta semana em Stand Exemplo", context: "Stock de hoje; anúncios, vistas e contactos das últimas 4 semanas.", notes: ["Em 3 veículos, a data de entrada é estimada."], numbers: [
        { kind: "stock_count", label: "Veículos em stock", value: 25, format: "int", caption: "stock próprio", icon: "ri-car-line" },
        { kind: "stock_value", label: "Valor do stock", value: 50000000, format: "eur", caption: "a soma dos preços", icon: "ri-money-euro-circle-line" },
        { kind: "above_market", label: "Acima do mercado", value: 40, format: "pct", caption: "de 10 veículos com comparação exata", icon: "ri-scales-3-line" },
    ] },
    plays: [
        play({}) as any,
        play({ key: "give_stage:all", type: "give_stage", type_label: "Para dar palco", title: "Dar palco a Citroën C3 (2021)", car_id: 12, actions: ["create", "suggest", "view_car"],
            items: [{ car_id: 12, label: "Citroën C3 (2021)", detail: "alinhado com o mercado" }], where, signal_keys: ["stage:12"], post_note: "Leva até 4 fotografias do veículo." }) as any,
    ],
    blocks: {
        aging: { headline: "3 veículos à venda estão no limiar.", groups: [{ group: "car", label: "Carros", threshold: 45, total: 4, bands: [{ label: "Até 15 dias", count: 1 }, { label: "De 16 a 30 dias", count: 0 }, { label: "De 31 a 60 dias", count: 2 }, { label: "Mais de 60 dias", count: 1 }] }],
            oldest: [{ car_id: 11, label: "Peugeot 208 (2019)", days: 80, threshold: 45, stale: true }] },
        price: { headline: "4 de 10 veículos com comparação exata estão acima do mercado.", eligible: 10, counts: { below: 2, aligned: 4, above: 4 },
            top_above: [{ car_id: 11, label: "Peugeot 208 (2019)", diff_pct: 12, price: 22400, median: 20000, comparables: 12, days: 80 }], outside: { sem_tipologia: 2, sem_mercado: 1 } },
        ads: { headline: "Nas últimas 4 semanas, 1 veículo teve gasto em anúncios; 0 sem nenhum contacto.", rows: [{ car_id: 13, label: "Renault Clio (2020)", sold: true, contacts: 2, spend: 120, cost_per_contact: 60 }], without_contacts: [] },
        demand: { headline: "Os veículos mais vistos.", rows: [{ car_id: 12, label: "Citroën C3 (2021)", views: 340, contacts: 3 }] },
        forgotten: { headline: "1 veículo à venda há mais de 30 dias está sem anúncio nem publicação.", items: [{ car_id: 14, label: "Fiat 500 (2018)", days: 40 }], total: 1 },
        footer: ["A Bússola nunca mostra nomes, telefones nem e-mails dos contactos, só contagens."],
    },
    ...over,
});

describe("Bússola do stand", () => {
    beforeEach(() => {
        mockPerms = ["automovel.ver", "automovel.editar"];
        (getBussolaStand as jest.Mock).mockReset().mockResolvedValue({ data: data() });
        (confirmAction as jest.Mock).mockResolvedValue(true); // o CRA repõe as simulações antes de cada teste
        (excludeStandVehicle as jest.Mock).mockResolvedValue({});
        (ignoreStandSignals as jest.Mock).mockResolvedValue({});
    });

    it("mostra o topo, as jogadas com os botões de cada tipo e os blocos", async () => {
        render(<StandBussola />);
        expect(await screen.findByText("Rever o preço de Peugeot 208 (2019)")).toBeInTheDocument();
        expect(getBussolaStand).toHaveBeenCalledWith(7, "all");
        expect(screen.getByTestId("compass-number-stock_value")).toHaveTextContent("500 000 €");
        const price = screen.getByTestId("play-review_price");
        expect(within(price).getByTestId("play-review-price")).toHaveAttribute("href", "/cars/11");
        expect(within(price).getByTestId("play-view-car")).toHaveAttribute("href", "/cars/11/ficha");
        expect(within(price).queryByText("Criar publicação")).not.toBeInTheDocument();
        const stage = screen.getByTestId("play-give_stage");
        expect(within(stage).getByText("Criar publicação")).toBeInTheDocument();
        expect(within(stage).getByText("Sugerir texto")).toBeInTheDocument();
        expect(screen.getByTestId("stand-price-outside")).toHaveTextContent("2 sem tipologia, 1 sem mercado (caravanas)");
        expect(screen.getByTestId("stand-forgotten")).toHaveTextContent("Fiat 500 (2018)");
        expect(screen.getByTestId("stand-footer")).toHaveTextContent("só contagens");
    });

    it("o seletor de tipo pede as jogadas do tipo escolhido", async () => {
        render(<StandBussola />);
        fireEvent.click(await screen.findByRole("radio", { name: "Autocaravanas" }));
        await waitFor(() => expect(getBussolaStand).toHaveBeenLastCalledWith(7, "motorhome"));
    });

    it("sem Finanças, os valores financeiros aparecem desfocados e os preços continuam à vista", async () => {
        (getBussolaStand as jest.Mock).mockResolvedValue({ data: data({
            financial: { visible: false, note: "Sem acesso aos valores financeiros" },
            top: { ...data().top!, numbers: [{ kind: "stock_value", label: "Valor do stock", value: null, hidden: true, format: "eur", caption: "", icon: "ri-money-euro-circle-line" }] },
            blocks: { ...data().blocks!, ads: { headline: "x", rows: [{ car_id: 13, label: "Renault Clio (2020)", sold: false, contacts: 2, spend: null, cost_per_contact: null, hidden: true }], without_contacts: [] } },
        }) });
        render(<StandBussola />);
        expect(await screen.findByTestId("financial-note")).toHaveTextContent("Sem acesso aos valores financeiros");
        expect(screen.getAllByTestId("hidden-money").length).toBeGreaterThanOrEqual(3);
        expect(screen.getByTestId("stand-price")).toHaveTextContent("22 400 €");
    });

    it("os botões que abrem a área automóvel só aparecem a quem a tem", async () => {
        mockPerms = ["automovel.ver"];
        render(<StandBussola />);
        const price = await screen.findByTestId("play-review_price");
        expect(within(price).queryByTestId("play-review-price")).not.toBeInTheDocument();
        expect(within(price).getByTestId("play-view-car")).toBeInTheDocument();
        mockPerms = [];
    });

    it("não voltar a sugerir um veículo e ignorar durante 4 semanas", async () => {
        (getBussolaStand as jest.Mock).mockResolvedValue({ data: data({ plays: [play({}) as any] }) });
        render(<StandBussola />);
        await screen.findByTestId("play-review_price");
        fireEvent.click(screen.getByRole("button", { name: /Mais ações: Rever o preço/ }));
        fireEvent.click(await screen.findByText("Não voltar a sugerir este veículo"));
        await waitFor(() => expect(excludeStandVehicle).toHaveBeenCalledWith(7, "review_price:11"));
        fireEvent.click(screen.getByRole("button", { name: /Mais ações: Rever o preço/ }));
        fireEvent.click(await screen.findByText("Ignorar durante 4 semanas"));
        await waitFor(() => expect(ignoreStandSignals).toHaveBeenCalledWith(7, ["review_price:11"]));
    });

    it("o dashboard: só as jogadas, com o seletor de tipo, e nada se a Bússola estiver desligada", async () => {
        (getBussolaStand as jest.Mock).mockResolvedValue({ data: data({ top: null, blocks: undefined }) });
        const { unmount } = render(<StandBussolaDashboard companyId={7} />);
        expect(await screen.findByTestId("dashboard-bussola-stand")).toBeInTheDocument();
        expect(getBussolaStand).toHaveBeenCalledWith(7, "all", true);
        expect(within(screen.getByTestId("bussola-plays")).getByTestId("stand-type")).toBeInTheDocument();
        expect(screen.queryByTestId("bussola-top")).not.toBeInTheDocument();
        unmount();
        (getBussolaStand as jest.Mock).mockResolvedValue({ data: data({ enabled: false, plays: [] }) });
        render(<StandBussolaDashboard companyId={7} />);
        await waitFor(() => expect(getBussolaStand).toHaveBeenCalledTimes(2));
        expect(screen.queryByTestId("dashboard-bussola-stand")).not.toBeInTheDocument();
    });
});
