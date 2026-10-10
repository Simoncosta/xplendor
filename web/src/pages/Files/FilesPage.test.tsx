import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import FilesPage from "./FilesPage";
import { getFileDetail, getFiles, getFilesTrash } from "helpers/laravel_helper";
import type { FilesPayload } from "common/models/files.model";

// O react-router-dom 7 não se resolve no Jest 27 do CRA: um Link simples chega.
jest.mock("react-router-dom", () => ({ Link: ({ to, children, ...rest }: any) => <a href={to} {...rest}>{children}</a> }), { virtual: true });
jest.mock("simplebar-react", () => ({ __esModule: true, default: ({ children }: any) => <div>{children}</div> }));
jest.mock("react-toastify", () => ({ toast: { success: jest.fn(), error: jest.fn() }, ToastContainer: () => null }));
jest.mock("contexts/WorkingCompanyContext", () => ({ useWorkingCompanyId: () => 7 }));
jest.mock("helpers/laravel_helper", () => ({
    getFiles: jest.fn(), getFilesTrash: jest.fn(), getFileDetail: jest.fn(), createFileFolder: jest.fn(), updateFileFolder: jest.fn(), deleteFileFolder: jest.fn(),
    restoreFileFolder: jest.fn(), uploadFileDocument: jest.fn(), startFileMediaUpload: jest.fn(), sendFileMediaChunk: jest.fn(), updateStoredFile: jest.fn(),
    deleteStoredFile: jest.fn(), restoreStoredFile: jest.fn(), saveFileToFolder: jest.fn(), deleteFileShortcut: jest.fn(), restoreFileShortcut: jest.fn(),
}));

const file = (over: any = {}) => ({
    id: 1, name: "Contrato.pdf", extension: "pdf", mime: "application/pdf", kind: "pdf", size_bytes: 2048, folder_id: null, system: false, source: "upload",
    created_at: "2026-10-10T10:00:00Z", deleted_at: null, purge_after: null, media_asset_id: null, media_status: null, in_use: 0,
    thumb_url: null, preview_url: null, url: "/api/files/stored/1?signature=x", download_url: "/api/files/stored/1?download=1&signature=x", ...over,
});

const payload: FilesPayload = {
    tree: [
        { id: 10, parent_id: null, name: "Contratos", system: false, system_key: null, system_root: null, deleted_at: null, purge_after: null },
        { id: 20, parent_id: null, name: "Linha Editorial", system: true, system_key: "editorial", system_root: "editorial", deleted_at: null, purge_after: null },
    ],
    folder: null,
    folders: [{ id: 10, parent_id: null, name: "Contratos", system: false, system_key: null, system_root: null, deleted_at: null, purge_after: null }],
    files: [file(), file({ id: 2, name: "Montra.jpg", kind: "imagem", mime: "image/jpeg", media_asset_id: 5, in_use: 2, system: true, source: "media" }) as any],
    shortcuts: [],
    usage: { bytes: {}, total: 5 * 1024 * 1024, quota: 3 * 1024 ** 3, kinds: {} },
    can: { create: true, edit: true, delete: true, editorial: true },
    limits: { document_bytes: 52428800, image_bytes: 31457280, video_bytes: 314572800, chunk_bytes: 8388608 },
};

describe("Ficheiros", () => {
    beforeEach(() => {
        (getFiles as jest.Mock).mockResolvedValue({ data: payload });
        (getFilesTrash as jest.Mock).mockResolvedValue({ data: { folders: [], files: [], shortcuts: [], days: 30 } });
        (getFileDetail as jest.Mock).mockResolvedValue({ data: { ...file({ id: 2, name: "Montra.jpg", kind: "imagem", in_use: 2, system: true }), shortcuts: [],
            usage: [{ id: 99, title: "Promoção de outono", publish_date: "2026-10-20", stage: "production" }] } });
    });

    it("mostra as pastas das pessoas e da plataforma, os ficheiros e o espaço usado", async () => {
        render(<FilesPage />);
        expect(await screen.findByText("Montra.jpg")).toBeInTheDocument();
        expect(getFiles).toHaveBeenCalledWith(7, { folder_id: null, q: "" });
        expect(screen.getByText("Da plataforma")).toBeInTheDocument();
        expect(screen.getAllByText("Linha Editorial").length).toBeGreaterThan(0);
        expect(screen.getByText("Em uso em 2 publicações")).toBeInTheDocument();
        expect(screen.getByText("Do sistema")).toBeInTheDocument();
        expect(screen.getByTestId("files-usage")).toHaveTextContent("5 MB");
        expect(screen.getByText("Enviar")).toBeInTheDocument();
    });

    it("abre a pré-visualização com onde o ficheiro está em uso, e o do sistema não se apaga", async () => {
        (getFiles as jest.Mock).mockResolvedValue({ data: { ...payload, folders: [], files: [payload.files[1]] } }); // só o do sistema
        render(<FilesPage />);
        fireEvent.click(await screen.findByLabelText("Ver: Montra.jpg"));
        const panel = screen.getByTestId("files-detail");
        expect(await within(panel).findByText("Promoção de outono")).toHaveAttribute("href", "/editorial?publicacao=99");
        expect(within(panel).getByText(/Da plataforma \(trata-se no seu módulo\)/)).toBeInTheDocument();
        fireEvent.click(within(panel).getByLabelText("Mais ações: Montra.jpg"));
        // Os menus vivem no document.body (o da linha e o da pré-visualização).
        expect((await screen.findAllByText("Guardar numa pasta")).length).toBeGreaterThan(0);
        await waitFor(() => expect(screen.queryByText("Apagar")).not.toBeInTheDocument());
        expect(screen.queryByText("Mover")).not.toBeInTheDocument();
    });

    it("abre a reciclagem", async () => {
        render(<FilesPage />);
        fireEvent.click(await screen.findByText("Reciclagem"));
        expect(await screen.findByText("A reciclagem está vazia.")).toBeInTheDocument();
        expect(getFilesTrash).toHaveBeenCalledWith(7);
    });
});
