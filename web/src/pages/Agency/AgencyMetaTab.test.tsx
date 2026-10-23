import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import AgencyMetaTab, { AgencyMetaState } from "./AgencyMetaTab";
import { disconnectAgencyMeta, getAgencyMeta, getAgencyMetaAuthUrl } from "helpers/laravel_helper";
import { confirmAction } from "helpers/swal";

jest.mock("helpers/laravel_helper", () => ({ getAgencyMeta: jest.fn(), getAgencyMetaAuthUrl: jest.fn(), disconnectAgencyMeta: jest.fn() }));
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() } }));
jest.mock("helpers/swal", () => ({ confirmAction: jest.fn() }));

const off: AgencyMetaState = { connected: false, status: null, meta_user_id: null, scopes: [], missing_scopes: ["business_management"], connected_at: null,
    connected_by: null, expires_at: null, days_left: null, renew_soon: false };
const on: AgencyMetaState = { connected: true, status: "active", meta_user_id: "U9", scopes: ["business_management", "ads_read"], missing_scopes: [],
    connected_at: "2026-11-10T10:00:00Z", connected_by: "Ana Admin", expires_at: "2026-11-18T10:00:00Z", days_left: 8, renew_soon: true };

describe("AgencyMetaTab (Parceiro 2)", () => {
    beforeEach(() => {
        jest.clearAllMocks();
        (confirmAction as jest.Mock).mockResolvedValue(true);
    });

    it("sem ligação, o administrador liga com a Meta", async () => {
        (getAgencyMeta as jest.Mock).mockResolvedValue({ data: off });
        (getAgencyMetaAuthUrl as jest.Mock).mockResolvedValue({ data: { url: "https://meta.simulada/dialog" } });
        const assign = jest.fn();
        Object.defineProperty(window, "location", { value: { ...window.location, assign, search: "" }, writable: true });
        render(<AgencyMetaTab agencyId={3} canManage manageReason="" />);
        fireEvent.click(await screen.findByTestId("agency-meta-connect"));
        await waitFor(() => expect(assign).toHaveBeenCalledWith("https://meta.simulada/dialog"));
        expect(getAgencyMetaAuthUrl).toHaveBeenCalledWith(3);
        expect(screen.queryByTestId("agency-meta-disconnect")).not.toBeInTheDocument();
    });

    it("ligada: mostra quem ligou, as permissões e o aviso para renovar; desliga", async () => {
        (getAgencyMeta as jest.Mock).mockResolvedValue({ data: on });
        (disconnectAgencyMeta as jest.Mock).mockResolvedValue({ message: "Ligação da Meta desligada.", data: { meta: off } });
        render(<AgencyMetaTab agencyId={3} canManage manageReason="" />);
        expect(await screen.findByText(/Ana Admin/)).toBeInTheDocument();
        expect(screen.getByTestId("agency-meta-renew")).toHaveTextContent("A ligação acaba dentro de 8 dias");
        expect(screen.getByTestId("agency-meta-scopes")).toHaveTextContent("Portfólios da Meta (business_management)");
        fireEvent.click(screen.getByTestId("agency-meta-disconnect"));
        await waitFor(() => expect(disconnectAgencyMeta).toHaveBeenCalledWith(3));
        expect(await screen.findByTestId("agency-meta-connect")).toHaveTextContent("Ligar com a Meta");
    });

    it("quem não é administrador da agência vê o estado, sem poder ligar", async () => {
        (getAgencyMeta as jest.Mock).mockResolvedValue({ data: { ...on, renew_soon: false } });
        render(<AgencyMetaTab agencyId={3} canManage={false} manageReason="Só o administrador da agência liga e desliga a Meta." />);
        await screen.findByText(/Ana Admin/);
        fireEvent.click(screen.getByTestId("agency-meta-disconnect"));
        expect(disconnectAgencyMeta).not.toHaveBeenCalled();
    });
});
