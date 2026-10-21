import { render, screen } from "@testing-library/react";
import QrScansCard from "./QrScansCard";

jest.mock("react-apexcharts", () => () => null);

describe("Leituras do QR no stand", () => {
    it("mostra as leituras e, à parte, as visitas do site vindas do QR, com a nota", () => {
        render(<QrScansCard data={{ total: 5, last_7d: 3, last_24h: 1, last_at: "2026-10-19T10:00:00Z", by_day: [{ date: "2026-10-19", total: 1 }], site_visits_from_qr: 2 }} />);
        expect(screen.getByTestId("qr-total")).toHaveTextContent("5");
        expect(screen.getByTestId("qr-site-visits")).toHaveTextContent("2");
        expect(screen.getByText(/não pessoas: não se sabe quem leu/)).toBeInTheDocument();
        expect(screen.getByText(/Última leitura:/)).toBeInTheDocument();
    });

    it("sem leituras", () => {
        render(<QrScansCard data={null} />);
        expect(screen.getByText("Ainda sem leituras")).toBeInTheDocument();
    });
});
