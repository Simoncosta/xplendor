import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import RadarCard from "./RadarCard";
import { getRadar, ignoreRadar, reactRadar } from "helpers/laravel_helper";
import type { RadarPageData } from "common/models/radar.model";

jest.mock("react-router-dom", () => ({ Link: ({ to, children, ...rest }: any) => <a href={to} {...rest}>{children}</a> }), { virtual: true });
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() }, ToastContainer: () => null }));
let mockPerms: string[] = ["bussola.ver", "bussola.criar", "bussola.editar"];
jest.mock("contexts/ModulesContext", () => ({
    useCan: (p: string) => [mockPerms.includes(p), mockPerms.includes(p) ? null : "Não tem permissão para esta ação."],
    useAccess: () => ({ modules: ["radar"], isRoot: false }),
}));
jest.mock("helpers/laravel_helper", () => ({
    getRadar: jest.fn(), reactRadar: jest.fn(() => Promise.resolve({ message: "Ideia criada na Linha Editorial, com o gancho e as fontes, sem texto." })),
    ignoreRadar: jest.fn(() => Promise.resolve({})), muteRadar: jest.fn(() => Promise.resolve({})), unmuteRadar: jest.fn(), updateRadarSettings: jest.fn(),
}));

const today = new Date().toLocaleDateString("sv-SE", { timeZone: "Europe/Lisbon" });
const page = (over: Partial<RadarPageData> = {}): RadarPageData => ({
    enabled: true, company: "Tasca Exemplo", sector: { slug: "restauracao", name: "Restauração" }, sector_missing: false,
    signals: [{
        id: 11, title: "feira do queijo", kind: "trend", since: "2026-10-14T08:00:00Z", since_label: "há 2 horas", volume_label: "mais de 500 pesquisas",
        why: "Uma feira gastronómica próxima do que a marca comunica.", why_source: "ai", valid_until: "2026-10-16T08:00:00Z",
        when: { date: today, is_today: true, label: "Publicar hoje", target: null },
        sources: [{ title: "feira do queijo", source_name: "Google Trends", url: "https://exemplo.pt/a", published_at: "2026-10-14T08:00:00Z" },
            { title: null, source_name: "Jornal Exemplo", url: "https://exemplo.pt/b", published_at: null }],
        flags: ["marca_protegida"], notices: ["Marca protegida: não use os nomes nem os logótipos oficiais."], status: "new", editorial_post_id: null, attribution: "Fonte: Google Trends",
    }],
    calendar: [], settings: { sports_enabled: false, sports_clubs: [], muted: [] }, can_act: true, can_act_reason: null, formats: ["Imagem única", "Carrossel"],
    refreshed_at: "2026-10-14T09:45:00Z", ...over,
});

describe("Radar: o bloco da Bússola", () => {
    beforeEach(() => {
        mockPerms = ["bussola.ver", "bussola.criar", "bussola.editar"];
        (getRadar as jest.Mock).mockReset().mockResolvedValue({ data: page() });
        (reactRadar as jest.Mock).mockClear();
    });

    it("mostra o cartão com o gancho, o volume, o aviso, as fontes com ligação e sem euros", async () => {
        const { container } = render(<RadarCard companyId={7} />);
        const card = await screen.findByTestId("radar-signal");
        expect(within(card).getByText("Está a subir: feira do queijo")).toBeInTheDocument();
        expect(within(card).getByText(/mais de 500 pesquisas/)).toBeInTheDocument();
        expect(within(card).getByText("Marca protegida: não use os nomes nem os logótipos oficiais.")).toBeInTheDocument();
        const link = within(card).getByLabelText("Abrir na fonte: Jornal Exemplo");
        expect(link).toHaveAttribute("target", "_blank");
        expect(link).toHaveAttribute("rel", "noopener noreferrer");
        expect(within(card).getByText("Fonte: Google Trends")).toBeInTheDocument();
        expect(container.textContent).not.toMatch(/€|EUR/);
        expect(screen.getByTestId("radar-upcoming-empty")).toBeInTheDocument();
    });

    it("\"Reagir\" cria a ideia sem texto (sem legenda, hashtags nem CTA)", async () => {
        render(<RadarCard companyId={7} />);
        fireEvent.click(await screen.findByTestId("radar-react"));
        fireEvent.click(await screen.findByTestId("radar-react-submit"));
        await waitFor(() => expect(reactRadar).toHaveBeenCalled());
        const body = (reactRadar as jest.Mock).mock.calls[0][1];
        expect(body).toMatchObject({ signal_id: 11, title: "Está a subir: feira do queijo", publish_date: today, networks: ["instagram"] });
        expect(body).not.toHaveProperty("caption");
        expect(body).not.toHaveProperty("hashtags");
        expect(body).not.toHaveProperty("cta");
    });

    it("sem bussola.criar o Reagir fica indisponível com o motivo; Ignorar chama a API", async () => {
        mockPerms = ["bussola.ver", "bussola.editar"];
        render(<RadarCard companyId={7} />);
        await screen.findByTestId("radar-signal");
        expect(screen.getByTestId("radar-react")).toBeDisabled();
        expect(screen.getByLabelText(/Reagir \(indisponível: Não tem permissão/)).toBeInTheDocument();
        fireEvent.click(screen.getByTestId("radar-ignore"));
        await waitFor(() => expect(ignoreRadar).toHaveBeenCalledWith(7, 11));
    });

    it("sem ramo pede para escolher o ramo", async () => {
        (getRadar as jest.Mock).mockResolvedValue({ data: page({ sector: null, sector_missing: true, signals: [] }) });
        render(<RadarCard companyId={7} />);
        expect(await screen.findByTestId("radar-sector-missing")).toHaveTextContent("Escolha o ramo da sua marca na Linha Editorial");
    });

    it("o \"Vem aí\" mostra a data com a fonte oficial e reage com a chave do calendário", async () => {
        (getRadar as jest.Mock).mockResolvedValue({ data: page({ signals: [], calendar: [{
            key: "ine.precos_habitacao_local", occurrence: "2026-10-23", title: "INE: Estatísticas de Preços da Habitação ao nível local", starts_on: "2026-10-23", ends_on: "2026-10-23",
            note: "2.º trimestre de 2026.", source: { name: "INE, calendário de difusão", url: "https://www.ine.pt/x" }, confirmed_on: "2026-10-14", confirmed_by: "machine",
            when: { date: "2026-10-23", is_today: false, label: "Publicar a 23/10", target: null }, reacted: false,
        }] }) });
        render(<RadarCard companyId={7} />);
        const list = await screen.findByTestId("radar-upcoming");
        expect(within(list).getByText("Fonte: INE, calendário de difusão")).toHaveAttribute("rel", "noopener noreferrer");
        fireEvent.click(within(list).getByText("Reagir"));
        fireEvent.change(await screen.findByLabelText("Data de publicação"), { target: { value: "2099-10-23" } });
        fireEvent.click(screen.getByTestId("radar-react-submit"));
        await waitFor(() => expect(reactRadar).toHaveBeenCalled());
        expect((reactRadar as jest.Mock).mock.calls[0][1]).toMatchObject({ calendar_key: "ine.precos_habitacao_local", occurrence: "2026-10-23" });
    });
});
