import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import ProfileEditorModal, { describeArea } from "./ProfileEditorModal";
import { createPermissionProfile, previewPermissionProfile } from "helpers/laravel_helper";
import type { ProfilesPayload } from "common/models/permissionProfile.model";

jest.mock("helpers/laravel_helper", () => ({ createPermissionProfile: jest.fn(), previewPermissionProfile: jest.fn(), updatePermissionProfile: jest.fn() }));
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() } }));

const editorial = { area: "editorial", label: "Linha Editorial", module: "linha_editorial", actions: ["ver", "criar", "editar", "aprovar", "apagar", "configurar"] };
const financas = { area: "financas", label: "Finanças", module: "finance", actions: ["ver", "criar", "editar", "apagar"] };
const data: ProfilesPayload = {
    catalog: [editorial, financas],
    allowed: { cliente: ["editorial.ver", "editorial.criar", "editorial.editar", "editorial.aprovar", "editorial.apagar", "editorial.configurar", "financas.ver", "financas.criar", "financas.editar", "financas.apagar"], agencia: [], teto: [] },
    profiles: [{
        id: 9, name: "Só leitura", description: "Consulta, sem alterar nada.", side: "cliente", is_system: true, is_suggestion: true, is_admin: false,
        editable: false, assignable: false, only_assigned_clients: false, users: 0, permissions: ["editorial.ver"],
        summary: [{ area: "editorial", label: "Linha Editorial", text: "Pode ver.", actions: ["ver"] }],
    }],
    users: [], is_agency: false, management: null, can_manage: true, can_set_ceiling: false,
};

describe("Novo perfil (D13)", () => {
    it("explica cada área em linguagem simples", () => {
        expect(describeArea(editorial, new Set(["editorial.ver", "editorial.criar"]))).toBe("Pode ver e criar; não pode editar, aprovar, apagar e configurar.");
        expect(describeArea(financas, new Set())).toBe("Não vê.");
        expect(describeArea(financas, new Set(["financas.ver", "financas.criar", "financas.editar", "financas.apagar"]))).toBe("Pode ver, criar, editar e apagar.");
    });

    it("parte de uma sugestão, deixa editar tudo e mostra as permissões efetivas antes de gravar", async () => {
        (previewPermissionProfile as jest.Mock).mockResolvedValue({ data: {
            summary: [{ area: "editorial", label: "Linha Editorial", text: "Pode ver e criar; não pode editar, aprovar, apagar e configurar.", actions: ["ver", "criar"] }],
            effective: ["editorial.ver", "editorial.criar"], ignored: [], inactive_modules: ["Finanças"], note: null,
        } });
        (createPermissionProfile as jest.Mock).mockResolvedValue({ data: {} });
        const onSaved = jest.fn();
        render(<ProfileEditorModal companyId={5} data={data} target="new" onClose={jest.fn()} onSaved={onSaved} />);

        fireEvent.click(screen.getByText("Só leitura"));
        expect(screen.getByLabelText("Nome do perfil")).toHaveValue("Só leitura");
        fireEvent.change(screen.getByLabelText("Nome do perfil"), { target: { value: "Leitura com ideias" } });
        fireEvent.click(screen.getAllByLabelText("Criar")[0]);
        expect(screen.getByText("Pode ver e criar; não pode editar, aprovar, apagar e configurar.")).toBeInTheDocument();

        fireEvent.click(screen.getByText("Rever permissões"));
        expect(await screen.findByTestId("profile-preview")).toBeInTheDocument();
        expect(previewPermissionProfile).toHaveBeenCalledWith(5, { side: "cliente", permissions: ["editorial.ver", "editorial.criar"] });
        expect(screen.getByText(/Sem efeito por agora/)).toHaveTextContent("Finanças");

        fireEvent.click(screen.getByText("Criar perfil"));
        await waitFor(() => expect(onSaved).toHaveBeenCalled());
        expect(createPermissionProfile).toHaveBeenCalledWith(5, expect.objectContaining({ name: "Leitura com ideias", side: "cliente", from_profile_id: 9, permissions: ["editorial.ver", "editorial.criar"] }));
    });
});
