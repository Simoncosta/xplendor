import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import AdminRadarPage from "./AdminRadarPage";
import { getAdminRadar, getAdminRadarItems } from "helpers/laravel_helper";
import type { RadarItem, RadarItemsPage, RadarSummary } from "common/models/radar.model";

// O react-router-dom 7 não se resolve no Jest 27 do CRA: um Link simples chega.
jest.mock("react-router-dom", () => ({ Link: ({ to, children, ...rest }: any) => <a href={to} {...rest}>{children}</a> }), { virtual: true });
jest.mock("helpers/laravel_helper", () => ({ getAdminRadar: jest.fn(), getAdminRadarItems: jest.fn() }));

const labels = {
    tragedia: "Tragédia", morte: "Morte", acidente: "Acidente", crime: "Crime", doenca: "Doença", politica: "Política", religiao: "Religião",
    conflito: "Conflito", vida_pessoal: "Nome de pessoa (vida pessoal)", nome_pessoa: "Nome de pessoa", marca_protegida: "Marca protegida", desporto: "Desporto",
};

const summary: RadarSummary = {
    radar_enabled: true,
    sources: [{
        key: "google_trends", label: "Google Trends", url: "https://trends.google.com/trending/rss?geo=PT", type: "trends", enabled: true, disabled_reason: null,
        interval_minutes: 15, attribution: "Fonte: Google Trends", terms_url: "https://policies.google.com/terms?hl=pt-PT", terms_checked_at: "2026-10-12", terms_note: null,
        requests_last_hour: 2, items_24h: 10, last_ok_at: "2026-10-12T18:20:00Z",
        last_run: { started_at: "2026-10-12T18:20:00Z", status: "ok", dry_run: false, http_status: 200, items_seen: 10, items_new: 10, items_blocked: 4, error: null },
    }, {
        key: "ipma_avisos", label: "IPMA: avisos meteorológicos", url: "https://api.ipma.pt/", type: "ipma", enabled: false,
        disabled_reason: "Os termos de 2017 restringem a fins não lucrativos; à espera da confirmação escrita do IPMA.", interval_minutes: 15,
        attribution: "Fonte: IPMA", terms_url: "https://api.ipma.pt/", terms_checked_at: "2026-10-12", terms_note: null, requests_last_hour: 0, items_24h: 0, last_ok_at: null, last_run: null,
    }],
    totals: { items_24h: 72, blocked_24h: 31, items_30d: 410, blocked_30d: 190, topics_open: 20, topics_open_passed: 12 },
    blocked_by_reason: [{ reason: "politica", label: "Política", count: 9 }],
    flags: [{ flag: "desporto", label: "Desporto", count: 3 }],
    topics: [{
        id: 1, kind: "trend", title: "navio", first_seen_at: "2026-10-12T18:20:00Z", last_seen_at: "2026-10-12T18:20:00Z", window_until: "2026-10-14T18:20:00Z",
        open: true, approx_volume: 500, approx_volume_label: "500+", districts: [], verdict: "passed", reasons: [], flags: [], items_count: 1, source_names: ["Google Trends", "SAPO"],
    }],
    labels,
    limits: { max_requests_per_hour: 4, retention_days: 30 },
};

const item = (over: Partial<RadarItem> = {}): RadarItem => ({
    id: 1, title: "Margem de refinação sobe no trimestre", url: "https://exemplo.pt/margem", source_key: "jornal_eco", source_type: "news", source_name: "ECO",
    published_at: "2026-10-12T16:09:19Z", fetched_at: "2026-10-12T18:20:00Z", approx_volume: null, approx_volume_label: null, verdict: "passed",
    reasons: [], flags: [], topic: null, ...over,
});

const page = (items: RadarItem[]): RadarItemsPage => ({
    items, labels, pagination: { current_page: 1, last_page: 1, per_page: 25, total: items.length, from: items.length ? 1 : 0, to: items.length },
});

describe("Administração › Radar", () => {
    beforeEach(() => {
        (getAdminRadar as jest.Mock).mockReset().mockResolvedValue({ data: summary });
        (getAdminRadarItems as jest.Mock).mockReset().mockResolvedValue({ data: page([
            item(),
            item({ id: 2, title: "Debate sobre as portagens", verdict: "blocked", reasons: ["politica"], source_name: "Observador" }),
            item({ id: 3, title: "navio", source_key: "google_trends", source_type: "trends", source_name: "Google Trends", approx_volume: 500, approx_volume_label: "500+",
                topic: { id: 1, kind: "trend", title: "navio", verdict: "passed" } }),
        ]) });
    });

    it("mostra os mosaicos, os itens com a ligação para a fonte, o veredicto e os motivos", async () => {
        render(<AdminRadarPage />);
        expect(await screen.findByText("Margem de refinação sobe no trimestre")).toBeInTheDocument();
        expect(getAdminRadarItems).toHaveBeenCalledWith({ verdict: undefined, source: undefined, days: 1, page: 1, per_page: 25 });
        await waitFor(() => expect(screen.getByTestId("radar-tile-entradas-24h")).toHaveTextContent("72"));
        expect(screen.getByTestId("radar-tile-retiradas-24h")).toHaveTextContent("31");

        const table = screen.getByTestId("radar-items");
        const link = within(table).getByLabelText("Abrir na fonte: Margem de refinação sobe no trimestre");
        expect(link).toHaveAttribute("href", "https://exemplo.pt/margem");
        expect(link).toHaveAttribute("target", "_blank");
        expect(link).toHaveAttribute("rel", "noopener noreferrer");
        expect(within(table).getAllByText("Retirado").length).toBeGreaterThan(0);
        expect(within(table).getAllByText("Política").length).toBeGreaterThan(0);
        expect(within(table).getAllByText("Sem tendência").length).toBeGreaterThan(0);
        expect(within(table).getAllByText("500+").length).toBeGreaterThan(0);

        // As fontes, com a atribuição, o motivo de estar desligada e os termos.
        expect(screen.getAllByText("Fonte: Google Trends").length).toBeGreaterThan(0);
        expect(screen.getAllByText(/confirmação escrita do IPMA/).length).toBeGreaterThan(0);
        expect(screen.getAllByText("2 de 4").length).toBeGreaterThan(0);
        expect(screen.queryByTestId("radar-disabled")).not.toBeInTheDocument();
    });

    it("com o Radar desligado (RADAR_ENABLED=false) diz que está desligado", async () => {
        (getAdminRadar as jest.Mock).mockResolvedValue({ data: { ...summary, radar_enabled: false } });
        render(<AdminRadarPage />);
        expect(await screen.findByTestId("radar-disabled")).toHaveTextContent("Radar desligado.");
    });

    it("a vista Retiradas pelo filtro pede só os itens bloqueados e mostra o vazio", async () => {
        render(<AdminRadarPage />);
        await screen.findByText("Margem de refinação sobe no trimestre");
        (getAdminRadarItems as jest.Mock).mockResolvedValue({ data: page([]) });

        fireEvent.click(screen.getByRole("tab", { name: "Retiradas pelo filtro" }));

        await waitFor(() => expect(getAdminRadarItems).toHaveBeenLastCalledWith({ verdict: "blocked", source: undefined, days: 1, page: 1, per_page: 25 }));
        expect(await screen.findByText("O filtro não retirou nada neste período.")).toBeInTheDocument();
        expect(screen.getByRole("tab", { name: "Retiradas pelo filtro" })).toHaveAttribute("aria-selected", "true");
    });
});
