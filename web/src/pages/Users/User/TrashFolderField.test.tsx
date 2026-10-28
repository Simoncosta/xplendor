import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import TrashFolderField from "./TrashFolderField";
import { getMailboxTrashFolder, setMailboxTrashFolder } from "helpers/laravel_helper";

jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() } }));
jest.mock("Components/Common/Select", () => ({ __esModule: true, default: ({ value, onChange, options, ariaLabel }: any) => (
    <select aria-label={ariaLabel} value={value} onChange={(e) => onChange(e.target.value)}>
        {options.map((o: any) => <option key={o.value} value={o.value}>{o.label}</option>)}
    </select>
) }));
jest.mock("helpers/laravel_helper", () => ({ getMailboxTrashFolder: jest.fn(), setMailboxTrashFolder: jest.fn() }));

describe("Pasta do Lixo nas definições da caixa (E-mail 10b)", () => {
    beforeEach(() => jest.clearAllMocks());

    it("com uma pasta marcada pelo servidor, só mostra qual é", async () => {
        (getMailboxTrashFolder as jest.Mock).mockResolvedValue({ data: { marked: "INBOX.Trash", chosen: null, folders: [] } });
        render(<TrashFolderField companyId={5} />);
        expect(await screen.findByTestId("mailbox-trash-marked")).toHaveTextContent("INBOX.Trash");
        expect(screen.queryByLabelText("Pasta do Lixo")).not.toBeInTheDocument();
    });

    it("sem marca, escolhe-se uma das pastas que existem e guarda-se", async () => {
        (getMailboxTrashFolder as jest.Mock).mockResolvedValue({ data: { marked: null, chosen: null, folders: [{ path: "INBOX.Lixo antigo", name: "Lixo antigo" }] } });
        (setMailboxTrashFolder as jest.Mock).mockResolvedValue({ data: { chosen: "INBOX.Lixo antigo" }, message: "Pasta do Lixo: INBOX.Lixo antigo." });
        render(<TrashFolderField companyId={5} />);
        fireEvent.change(await screen.findByLabelText("Pasta do Lixo"), { target: { value: "INBOX.Lixo antigo" } });
        fireEvent.click(screen.getByTestId("mailbox-trash-save"));
        await waitFor(() => expect(setMailboxTrashFolder).toHaveBeenCalledWith(5, "INBOX.Lixo antigo"));
    });
});
