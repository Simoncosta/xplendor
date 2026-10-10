import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { getBussola } from "helpers/laravel_helper";
import type { CompassData, CompassPlay } from "common/models/bussola.model";
import BussolaDashboardSummary from "./BussolaDashboardSummary";
import PlayCard from "./PlayCard";

// O react-router-dom 7 não se resolve no Jest 27 do CRA: um Link simples chega para estes testes.
jest.mock("react-router-dom", () => ({
    Link: ({ to, children, ...rest }: any) => <a href={to} {...rest}>{children}</a>,
}), { virtual: true });
const MemoryRouter = ({ children }: { children: any }) => <>{children}</>;

jest.mock("helpers/laravel_helper", () => ({
    getBussola: jest.fn(),
    excludeRestaurantItem: jest.fn(),
    ignoreRestaurantSignal: jest.fn(),
    createPostFromPlay: jest.fn(),
    requestPlayCaption: jest.fn(),
    getPlayCaption: jest.fn(),
}));

const play = (connected: boolean): CompassPlay => ({
    key: "item_up:1:prod:42", type: "item_up", type_label: "A ganhar força", icon: "ri-arrow-up-line", color: "success",
    locations: ["Yuko Baixa"], location_id: 2, title: "Gyoza", confidence: "alta",
    number: { value: "+30%", caption: "nas últimas 4 semanas" }, bars: [],
    what: { text: "Mostrar a gyoza.", source: "template" },
    where: {
        networks: [
            { network: "instagram", label: "Instagram", format_key: "ig_carousel", format_label: "Carrossel", format_source: "regras" },
            { network: "facebook", label: "Facebook", format_key: "fb_photos", format_label: "Fotografia", format_source: null },
        ],
        connected,
    },
    when: { date: "2026-10-10", is_today: false, label: "Sábado, 10/10", target: null },
    detail: { sentences: ["Vendeu mais."], sample: "120 vendas", confidence: [] },
    signal_keys: ["item_up:1:prod:42"], theme: "Gyoza", format: "Carrossel",
});

const data: CompassData = {
    enabled: true, company: "Yuko Tavern", locations: [{ id: 1, name: "Yuko Costa Cabral" }, { id: 2, name: "Yuko Baixa" }],
    location_id: 0, categories_pending: 0, computed_at: null, can_act: true, top: null, plays: [play(true)],
};

describe("dashboard do restaurante, separador Marketing e resultados", () => {
    beforeEach(() => (getBussola as jest.Mock).mockReset());

    it("mostra só as 3 jogadas, com o seletor de loja no cabeçalho, sem o topo das vendas", async () => {
        (getBussola as jest.Mock).mockResolvedValue({ data });
        render(<MemoryRouter><BussolaDashboardSummary companyId={5} /></MemoryRouter>);
        const box = await screen.findByTestId("dashboard-bussola");
        expect(getBussola).toHaveBeenCalledWith(5, null, false, true);
        expect(within(box).getByText("As 3 jogadas da semana")).toBeInTheDocument();
        expect(within(box).getByRole("radiogroup", { name: "Loja" })).toBeInTheDocument();
        expect(within(box).getByRole("radio", { name: "Todas as lojas" })).toBeInTheDocument();
        expect(screen.queryByText(/Esta semana em/)).not.toBeInTheDocument();
        expect(screen.queryByText(/Abrir a Bússola completa/)).not.toBeInTheDocument();
        expect(screen.queryByText(/as vendas das últimas 4 semanas/)).not.toBeInTheDocument();
    });

    it("sem Finanças, as barras em euros vêm desfocadas, com números fictícios, e a frase", async () => {
        const weak: CompassPlay = { ...play(true), key: "weak_period:wd3", type: "weak_period", title: "Encher o almoço de quarta",
            number: { value: "−50%", caption: "abaixo da média do turno" },
            bars: [{ label: "Almoço de quarta (Yuko Baixa)", value: null, pct: 50, color: "warning", hidden: true }] };
        (getBussola as jest.Mock).mockResolvedValue({ data: { ...data, plays: [weak], financial: { visible: false, note: "Sem acesso aos valores financeiros" } } });
        render(<MemoryRouter><BussolaDashboardSummary companyId={5} /></MemoryRouter>);
        const box = await screen.findByTestId("dashboard-bussola");
        expect(within(box).getByTestId("hidden-money")).toHaveAttribute("aria-label", "Sem acesso aos valores financeiros");
        expect(within(box).getByTestId("financial-note")).toHaveTextContent("Sem acesso aos valores financeiros");
        expect(within(box).getByText("−50%")).toBeInTheDocument();
    });

    it("não mostra nada quando a leitura por artigo está desligada", async () => {
        (getBussola as jest.Mock).mockResolvedValue({ data: { ...data, enabled: false } });
        const { container } = render(<MemoryRouter><BussolaDashboardSummary companyId={5} /></MemoryRouter>);
        await waitFor(() => expect(getBussola).toHaveBeenCalled());
        expect(container).toBeEmptyDOMElement();
    });
});

describe("cartão da jogada: Onde", () => {
    const renderCard = (connected: boolean) => render(
        <MemoryRouter>
            <PlayCard companyId={5} play={play(connected)} canAct canActReason={null}
                onCreate={jest.fn()} onSuggest={jest.fn()} onDetail={jest.fn()} onIgnore={jest.fn()} />
        </MemoryRouter>,
    );

    it("com as redes ligadas: Instagram e Facebook, cada um com o seu formato, sem nota", () => {
        renderCard(true);
        expect(screen.getByText("Instagram: Carrossel")).toBeInTheDocument();
        expect(screen.getByText("Facebook: Fotografia")).toBeInTheDocument();
        expect(screen.queryByText(/ainda não estão ligadas/)).not.toBeInTheDocument();
        expect(screen.queryByText(/Sem redes ligadas/)).not.toBeInTheDocument();
    });

    it("sem as redes ligadas: as duas redes e a nota com a ligação às Integrações", () => {
        renderCard(false);
        expect(screen.getByText("Instagram: Carrossel")).toBeInTheDocument();
        expect(screen.getByText("Facebook: Fotografia")).toBeInTheDocument();
        expect(screen.getByText(/As redes ainda não estão ligadas\. Ligue-as nas/)).toBeInTheDocument();
        expect(screen.getByRole("link", { name: "Integrações" })).toHaveAttribute("href", "/companies/5?tab=integrations");
    });

    it("o menu da jogada de um artigo tem as duas formas de ignorar; a permanente chega como permanent=true", () => {
        const onIgnore = jest.fn();
        const p = play(true);
        render(<MemoryRouter><PlayCard companyId={5} play={p} canAct canActReason={null} onCreate={jest.fn()} onSuggest={jest.fn()} onDetail={jest.fn()} onIgnore={onIgnore} /></MemoryRouter>);
        fireEvent.click(screen.getByRole("button", { name: /Mais ações/ }));
        expect(screen.getByText("Ignorar durante 4 semanas")).toBeInTheDocument();
        fireEvent.click(screen.getByText("Não voltar a sugerir este artigo"));
        expect(onIgnore).toHaveBeenCalledWith(p, true);
    });

    it("um período fraco não tem a exclusão permanente (não é um artigo)", () => {
        const p: CompassPlay = { ...play(true), key: "weak_period:1:sat-dinner", type: "weak_period", signal_keys: ["weak_period:1:sat-dinner"] };
        render(<MemoryRouter><PlayCard companyId={5} play={p} canAct canActReason={null} onCreate={jest.fn()} onSuggest={jest.fn()} onDetail={jest.fn()} onIgnore={jest.fn()} /></MemoryRouter>);
        fireEvent.click(screen.getByRole("button", { name: /Mais ações/ }));
        expect(screen.getByText("Ignorar durante 4 semanas")).toBeInTheDocument();
        expect(screen.queryByText("Não voltar a sugerir este artigo")).not.toBeInTheDocument();
    });
});
