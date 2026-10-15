import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import SendTransferModal from "./SendTransferModal";
import { TransferRequest, TransferRoute } from "common/models/transfer.model";

const mockPrepare = jest.fn();
const mockSend = jest.fn();
const mockConversion = jest.fn();
jest.mock("helpers/laravel_helper", () => ({
    prepareStockTransfer: (...a: unknown[]) => mockPrepare(...a), sendStockTransfer: (...a: unknown[]) => mockSend(...a),
    getRecipeConversionInfo: (...a: unknown[]) => mockConversion(...a), getTransferProducts: jest.fn().mockResolvedValue({ data: { products: [] } }),
}));
jest.mock("../fichas/ConfirmFactorModal", () => ({ __esModule: true, default: ({ onDone }: { onDone: () => void }) => <button onClick={onDone}>confirmar-fator</button> }));
jest.mock("Components/Common/Select", () => ({ __esModule: true, default: () => <div /> }));

const route: TransferRoute = {
    id: 1, name: "Economato → Yuko", origin_warehouse_id: "BO", origin_name: "Armazém Yuko BO", dest_warehouse_id: "YUKO", dest_name: "Armazém Yuko",
    origin_store_id: "S1", dest_store_id: "S2", exit_docconfig_id: "1102", exit_doc_label: "Guia de ativos próprios", auto_receive: true, active: true,
    load_address: null, unload_address: null, addresses_ok: true,
};
const request: TransferRequest = {
    id: 9, pingwin_doc_id: "D9", kind: "encomenda", document: "ENCF BOENCF/35", doctype: "Encomenda Produção", store_id: "S2", store_name: "Tabern Yuko Original",
    doc_date: "2026-10-15", employee_name: null, age_days: 0, route_id: 1, lines_status: "ok",
    lines: [{ id: 31, product_id: "P1", code: "101343", description: "TESTE XPLENDOR STOCK A", qnt: 2, unit_desc: "Caixa 6 Unidades", unit_value: 1, qnt_base: 2, qnt_delivered: 0 }],
};
const prepLine = (over: object = {}) => ({
    index: 0, product_id: "P1", code: "101343", description: "TESTE XPLENDOR STOCK A", request_line_id: 31, qty: 2, unit: "Caixa 6 Unidades", stock_unit: "Unidade",
    stock_unit_id: "11001", units: [{ unit: "Unidade", factor: 1, source: "stock" }, { unit: "Caixa 6 Unidades", factor: 6, source: "confirmado" }], factor: 6, pingwin_factor: 1,
    qty_base: 12, origin_pcm_eur: 0.5, price_per_unit_eur: 3, last_purchase_price_eur: 0.5, origin_stock: 60, value_eur: 6,
    blocks: [{ code: "fator_diferente", text: "O PingWin conta cada «Caixa 6 Unidades» como 1 unidade; a XPLENDOR tem 6 confirmado. Confirme o fator antes de enviar." }],
    confirm: null, price_confirmed: false, warning: null, ...over,
});

describe("SendTransferModal", () => {
    beforeEach(() => { jest.useFakeTimers(); mockPrepare.mockReset(); mockSend.mockReset(); mockConversion.mockReset(); });
    afterEach(() => jest.useRealTimers());

    it("bloqueia a linha com o fator diferente, abre a confirmação do fator e só depois deixa enviar", async () => {
        mockPrepare.mockResolvedValueOnce({ data: { prepare: { lines: [prepLine()], total_value_eur: 6, blocked: true, needs_confirmation: false, warnings: 0, send_block: null } } });
        mockConversion.mockResolvedValue({ data: { line: { component_id: "P1" } } });
        render(<SendTransferModal open onClose={jest.fn()} onSent={jest.fn()} companyId={5} routes={[route]} request={request} canSend hidden={false} />);
        await act(async () => { jest.advanceTimersByTime(400); });
        expect(mockPrepare).toHaveBeenCalledWith(5, { route_id: 1, request_id: 9, lines: [{ product_id: "P1", qty: 2, unit: "Caixa 6 Unidades", request_line_id: 31 }] });
        expect(await screen.findByTestId("line-block")).toHaveTextContent("como 1 unidade");
        expect(screen.getByTestId("transfer-send")).toBeDisabled();
        expect(screen.getByTestId("send-reason")).toHaveTextContent("Há linhas bloqueadas");

        fireEvent.click(screen.getByText("Confirmar o fator"));
        mockPrepare.mockResolvedValueOnce({ data: { prepare: { lines: [prepLine({ blocks: [], confirm: { code: "pcm_zero", text: "O PCM é 0." } })], total_value_eur: 6, blocked: false, needs_confirmation: true, warnings: 0, send_block: null } } });
        fireEvent.click(await screen.findByText("confirmar-fator"));
        await act(async () => { jest.advanceTimersByTime(400); });
        expect(mockPrepare).toHaveBeenLastCalledWith(5, expect.objectContaining({ lines: [expect.objectContaining({ factor_ack: true })] }));
        expect(await screen.findByTestId("send-reason")).toHaveTextContent("Confirme os preços");

        mockPrepare.mockResolvedValueOnce({ data: { prepare: { lines: [prepLine({ blocks: [], confirm: { code: "pcm_zero", text: "O PCM é 0." }, price_confirmed: true })], total_value_eur: 6, blocked: false, needs_confirmation: false, warnings: 0, send_block: null } } });
        fireEvent.click(screen.getByLabelText(/Confirmo este preço/));
        await act(async () => { jest.advanceTimersByTime(400); });
        expect(screen.getByTestId("transfer-send")).not.toBeDisabled();
        mockSend.mockResolvedValue({ data: { transfer: { id: 77 } } });
        fireEvent.click(screen.getByTestId("transfer-send"));
        await waitFor(() => expect(mockSend).toHaveBeenCalled());
        expect(mockSend).toHaveBeenCalledWith(5, expect.objectContaining({ route_id: 1, request_id: 9, lines: [expect.objectContaining({ factor_ack: true, price_confirmed: true })] }));
    });

    it("mostra a guarda do servidor (por exemplo, o envio ainda não provado) e não deixa enviar", async () => {
        mockPrepare.mockResolvedValue({ data: { prepare: { lines: [prepLine({ blocks: [] })], total_value_eur: 6, blocked: false, needs_confirmation: false, warnings: 0,
            send_block: "O envio de transferências para o PingWin ainda não foi provado neste ambiente (STOCK_TRANSFER_SEND_PROVEN)." } } });
        render(<SendTransferModal open onClose={jest.fn()} onSent={jest.fn()} companyId={5} routes={[route]} request={request} canSend hidden={false} />);
        await act(async () => { jest.advanceTimersByTime(400); });
        expect(await screen.findByTestId("send-reason")).toHaveTextContent("ainda não foi provado");
        expect(screen.getByTestId("transfer-send")).toBeDisabled();
    });
});
