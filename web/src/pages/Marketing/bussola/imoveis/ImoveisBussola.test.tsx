import { render, screen, within } from "@testing-library/react";
import ImoveisBussola from "./ImoveisBussola";
import { getBussolaImoveis } from "helpers/laravel_helper";
import type { ImoveisCompassData } from "common/models/imoveisCompass.model";

jest.mock("react-router-dom", () => ({ Link: ({ to, children, ...rest }: any) => <a href={to} {...rest}>{children}</a> }), { virtual: true });
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() }, ToastContainer: () => null }));
jest.mock("helpers/workingCompany", () => ({ getWorkingCompanyId: () => 7 }));
jest.mock("helpers/swal", () => ({ confirmAction: jest.fn(() => Promise.resolve(true)) }));
jest.mock("contexts/ModulesContext", () => ({ useAccess: () => ({ has: () => true, can: () => true }), useCan: () => [true, null] }));
jest.mock("helpers/laravel_helper", () => ({
    getBussolaImoveis: jest.fn(), createPostFromImoveisPlay: jest.fn(), requestImoveisPlayCaption: jest.fn(), getImoveisPlayCaption: jest.fn(),
    ignoreImoveisSignals: jest.fn(), excludeImoveisProperty: jest.fn(),
    createPostFromPlay: jest.fn(), requestPlayCaption: jest.fn(), getPlayCaption: jest.fn(), excludeRestaurantItem: jest.fn(), ignoreRestaurantSignal: jest.fn(),
    createPostFromStandPlay: jest.fn(), requestStandPlayCaption: jest.fn(), getStandPlayCaption: jest.fn(), ignoreStandSignals: jest.fn(), excludeStandVehicle: jest.fn(),
}));

const ATTR = "Fonte: Instituto Nacional de Estatística, IP – Portugal, Estatísticas de preços da habitação ao nível local, valor mediano das vendas de alojamentos familiares nos últimos 12 meses (euros por m²), 1.º Trimestre de 2026.";
const data: ImoveisCompassData = {
    enabled: true, provider: "imobiliario", company: "Consultor Exemplo", type: "all",
    types: [{ key: "all", label: "Todos" }, { key: "venda", label: "Venda" }, { key: "arrendamento", label: "Arrendamento" }],
    computed_at: "2026-10-14T10:00:00Z", data_until: "2026-10-14",
    top: { title: "A carteira de hoje", context: "Carteira de hoje.", notes: [], numbers: [
        { kind: "portfolio_count", label: "Imóveis em carteira", value: 3, format: "int", caption: "disponíveis e reservados", icon: "ri-home-4-line" },
        { kind: "portfolio_value", label: "Valor dos imóveis à venda", value: null, hidden: true, format: "eur", caption: "soma", icon: "ri-money-euro-circle-line" },
    ] as any },
    plays: [{
        key: "bce_day:all", type: "bce_day", type_label: "Decisão do BCE", icon: "ri-bank-line", color: "info", locations: [], location_id: 0,
        title: "Explicar a decisão do BCE no próprio dia", confidence: "alta", number: { value: "Hoje", caption: "decisão de política monetária" }, bars: [], items: [],
        what: { text: "Publique hoje uma reação à decisão do BCE, com a ligação ao comunicado oficial.", source: "template" },
        where: { networks: [], connected: false, ad: null }, when: { date: "2026-10-29", is_today: true, label: "Publicar hoje", target: null },
        detail: { sentences: [], sample: "", confidence: [] }, signal_keys: ["bce:20261029"], theme: "BCE", format: "Carrossel",
        property_id: null, link: "https://www.ecb.europa.eu/press/pr/date/2026/html/ecb.mp261029.pt.html", actions: ["create", "suggest"], post_note: null,
    }, {
        key: "publish_new:all", type: "publish_new", type_label: "Imóvel novo", icon: "ri-home-smile-line", color: "success", locations: [], location_id: 0,
        title: "Publicar o imóvel novo: T2 em Benfica (DEMO-001)", confidence: "alta", number: { value: "1", caption: "imóvel novo em carteira" }, bars: [],
        items: [{ property_id: 11, label: "T2 em Benfica (DEMO-001)", detail: "Falta a classe energética, obrigatória no anúncio." }],
        what: { text: "Publique o imóvel novo com as melhores fotografias e a classe energética.", source: "ai" },
        where: { networks: [], connected: false, ad: null }, when: { date: "2026-10-15", is_today: false, label: "Publicar amanhã", target: null },
        detail: { sentences: [], sample: "", confidence: [] }, signal_keys: ["new:11"], theme: "Imóvel novo", format: "Carrossel",
        property_id: 11, actions: ["create", "suggest", "view_property"], post_note: "Escolha as fotografias do imóvel na Linha Editorial, depois de criar a ideia.",
    }],
    blocks: {
        aging: { headline: "Há quanto tempo estão em carteira", groups: [{ group: "venda", label: "Venda", threshold: 90, total: 2, bands: [{ label: "Até 30 dias", count: 1 }] }], oldest: [] },
        price: { headline: "Preço por m² face à referência do INE", eligible: 1, counts: { below: 0, aligned: 0, above: 1 },
            top_above: [{ property_id: 12, label: "T3 em Alvalade (DEMO-002)", diff_pct: 61.8, price_m2: 8091, median: 5000, period: "1.º Trimestre de 2026", days: 120 }], outside: { sem_referencia: 1 }, attribution: ATTR },
        zones: { headline: "As zonas da carteira", rows: [] }, interest: { headline: "Contactos e visitas", note: "Manuais.", rows: [] },
        closed: { headline: "Fechados nos últimos 30 dias", rows: [] }, forgotten: { headline: "Há mais de 30 dias sem publicação", items: [], total: 0 },
        footer: ["A Bússola nunca sugere um preço: mostra os dias em carteira e a referência oficial, com a fonte e o período."],
    },
    financial: { visible: false, note: "Sem acesso aos valores financeiros" }, can_act: true, can_act_reason: null, formats: ["Carrossel"],
};

describe("Bússola do imobiliário", () => {
    it("mostra as jogadas, a ligação oficial do BCE, o Ver imóvel e a fonte do INE; o radar entra a seguir às jogadas", async () => {
        (getBussolaImoveis as jest.Mock).mockResolvedValue({ data });
        render(<ImoveisBussola radar={<div data-testid="radar-slot" />} />);
        await screen.findByTestId("imoveis-bussola");
        const bce = screen.getByTestId("play-official-link");
        expect(bce).toHaveAttribute("href", data.plays[0].link);
        expect(bce).toHaveAttribute("rel", "noopener noreferrer");
        expect(screen.getByTestId("play-view-property")).toHaveAttribute("href", "/imoveis?id=11");
        expect(screen.getByTestId("imoveis-ine-attribution")).toHaveTextContent("1.º Trimestre de 2026");
        expect(within(screen.getByTestId("imoveis-price")).getByText(/\+61,8%/)).toBeInTheDocument();
        expect(screen.getByTestId("radar-slot")).toBeInTheDocument();
        expect(screen.getByTestId("imoveis-type")).toBeInTheDocument();
    });
});
