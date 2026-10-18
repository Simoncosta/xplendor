import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import ContactosPage from "./ContactosPage";
import { getInboundContact, getInboundContacts, updateInboundContact } from "helpers/laravel_helper";

jest.mock("react-router-dom", () => ({ Link: ({ to, children, ...rest }: any) => <a href={to} {...rest}>{children}</a>, useSearchParams: () => [new URLSearchParams("")] }), { virtual: true });
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() }, ToastContainer: () => null }));
jest.mock("helpers/workingCompany", () => ({ getWorkingCompanyId: () => 7 }));
jest.mock("helpers/swal", () => ({ confirmAction: jest.fn(() => Promise.resolve(true)) }));
jest.mock("contexts/ModulesContext", () => ({ useCan: (p: string) => [["contactos.ver", "contactos.editar"].includes(p), null] }));
jest.mock("helpers/laravel_helper", () => ({ getInboundContacts: jest.fn(), getInboundContact: jest.fn(), updateInboundContact: jest.fn(), deleteInboundContact: jest.fn() }));

const row = { id: 4, channel: "site", channel_label: "Site", type: "estudo_zona", type_label: "Estudo da zona", name: "Pessoa Fictícia", email: "pessoa@exemplo.invalid",
    phone: "+351 900 000 001", status: "novo", created_at: "2026-10-16T10:00:00+01:00", property: null, development: null, consent_marketing: false, utm_source: "meta", utm_campaign: null };

describe("Contactos recebidos", () => {
    it("lista os contactos, abre o detalhe com os consentimentos e marca como tratado", async () => {
        (getInboundContacts as jest.Mock).mockResolvedValue({ data: { contacts: [row], options: { types: { estudo_zona: "Estudo da zona" }, statuses: { novo: "Novo", tratado: "Tratado", arquivado: "Arquivado" }, channels: { site: "Site" } } } });
        (getInboundContact as jest.Mock).mockResolvedValue({ data: { ...row, summary: "Estudo da zona: Paranhos", details: { parish_id: 1, property_kind: "apartamento" }, parishes: { 1: "Paranhos" },
            consent_privacy_at: "2026-10-16T10:00:00+01:00", policy_version: "2026-10", consent_marketing_at: null, origin: { utm_source: "meta", landing_path: "/lp/estudo-da-zona" } } });
        (updateInboundContact as jest.Mock).mockResolvedValue({ data: { ...row, status: "tratado" } });
        render(<ContactosPage />);
        expect(await screen.findByText("Pessoa Fictícia")).toBeInTheDocument();
        fireEvent.click(screen.getByLabelText("Abrir o contacto de Pessoa Fictícia"));
        expect(await screen.findByText("Estudo da zona: Paranhos")).toBeInTheDocument();
        expect(screen.getByText(/versão 2026-10/)).toBeInTheDocument();
        expect(screen.getByText(/entrada em \/lp\/estudo-da-zona/)).toBeInTheDocument();
        fireEvent.click(screen.getByTestId("contact-handled"));
        await waitFor(() => expect(updateInboundContact).toHaveBeenCalledWith(7, 4, "tratado"));
    });
});
