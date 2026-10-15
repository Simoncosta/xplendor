import { render, screen } from "@testing-library/react";
import { CircuitMap, TransferPath } from "./TransferCircuit";
import { TransferRoute } from "common/models/transfer.model";

// O React Flow só entra no ecrã largo; no telemóvel é uma lista (o que se testa aqui).
jest.mock("@xyflow/react", () => ({ ReactFlow: () => <div data-testid="rf" />, Background: () => null, Handle: () => null, MarkerType: { ArrowClosed: "arrowclosed" }, Position: {} }));
jest.mock("@xyflow/react/dist/style.css", () => ({}), { virtual: true });

const mockMatch = (matches: boolean) => {
    window.matchMedia = jest.fn().mockImplementation(() => ({ matches, addEventListener: jest.fn(), removeEventListener: jest.fn() }));
};
const route = (over: Partial<TransferRoute> = {}): TransferRoute => ({
    id: 1, name: "Economato → Baixa", origin_warehouse_id: "BO", origin_name: "Armazém Yuko BO", dest_warehouse_id: "BAIXA", dest_name: "Armazém Yuko Baixa",
    origin_store_id: "S1", dest_store_id: "S2", exit_docconfig_id: "1102", exit_doc_label: "Guia de ativos próprios", auto_receive: true, active: true,
    load_address: null, unload_address: null, addresses_ok: true, ...over,
});

describe("TransferCircuit", () => {
    it("no telemóvel mostra o circuito como lista vertical, com a receção de cada restaurante", () => {
        mockMatch(true);
        render(<CircuitMap routes={[route(), route({ id: 2, dest_name: "Armazém Yuko", auto_receive: false, addresses_ok: false })]} />);
        const list = screen.getByTestId("circuit-list");
        expect(list).toHaveTextContent("Armazém Yuko Baixa");
        expect(list).toHaveTextContent("Receção automática");
        expect(list).toHaveTextContent("O restaurante confirma a receção · falta a morada");
        expect(screen.queryByTestId("rf")).toBeNull();
    });

    it("no ecrã largo usa o React Flow; o caminho de um envio no telemóvel é uma lista de passos", () => {
        mockMatch(false);
        const { unmount } = render(<CircuitMap routes={[route()]} />);
        expect(screen.getByTestId("rf")).toBeInTheDocument();
        unmount();
        mockMatch(true);
        render(<TransferPath transfer={{ status: "enviado", auto_receive: false, request: null, exit_doc_number: "GA BOGA/6", entry_doc_number: null,
            origin_name: "Armazém Yuko BO", dest_name: "Armazém Yuko" } as never} />);
        const steps = screen.getByTestId("transfer-steps");
        expect(steps).toHaveTextContent("Pedido · Não se aplica");
        expect(steps).toHaveTextContent("Envio · Feito");
        expect(steps).toHaveTextContent("Receção · Em curso");
        expect(steps).toHaveTextContent("O Armazém Yuko confirma");
    });
});
