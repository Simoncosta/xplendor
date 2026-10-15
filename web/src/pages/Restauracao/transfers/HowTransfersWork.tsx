import { Button, Modal, ModalBody, ModalFooter, ModalHeader } from "reactstrap";
import { TransferPath } from "./TransferCircuit";

/**
 * XPLENDOR — Fatia 7c: "Como funciona" das transferências. Abre na primeira visita (neste aparelho) e no
 * botão "?". Mostra o caminho de um envio e as regras que a XPLENDOR segue.
 */
export const HELP_KEY = "xp-transfers-help-seen";

export default function HowTransfersWork({ open, onClose }: { open: boolean; onClose: () => void }) {
    const close = () => {
        try { localStorage.setItem(HELP_KEY, "1"); } catch { /* sem armazenamento */ }
        onClose();
    };
    return (
        <Modal isOpen={open} toggle={close} size="lg" centered>
            <ModalHeader toggle={close}>Como funcionam as transferências</ModalHeader>
            <ModalBody>
                <TransferPath transfer={{
                    status: "enviado", request: { id: 0, document: "Encomenda do restaurante" }, exit_doc_number: "Guia", entry_doc_number: null,
                    auto_receive: true, origin_name: "Economato", dest_name: "restaurante",
                } as never} />
                <ol className="mt-3 mb-0 ps-3">
                    <li className="mb-2"><strong>Pedido.</strong> O restaurante faz a encomenda no PingWin (Encomenda Bebidas ou Encomenda Produção). A XPLENDOR lê as encomendas todas as noites e quando carrega em «Ler encomendas».</li>
                    <li className="mb-2"><strong>Envio.</strong> O gestor do Economato revê as linhas e envia. A XPLENDOR grava no PingWin uma Guia de ativos próprios que tira o stock do Economato, na unidade de stock e ao preço médio (PCM) do Economato.</li>
                    <li className="mb-2"><strong>Receção.</strong> Se o circuito recebe sozinho, a Entrada no armazém do restaurante segue logo, com as mesmas quantidades e o mesmo preço. Se não, o restaurante confirma o que recebeu e a Entrada grava-se com essas quantidades.</li>
                    <li className="mb-2"><strong>Embalagens.</strong> Uma caixa ou grade só se envia com o fator confirmado na XPLENDOR. Se o PingWin conta a embalagem de outra maneira, a linha fica bloqueada até se confirmar o fator.</li>
                    <li className="mb-0"><strong>Nada se repete sozinho.</strong> Cada envio é uma ação de uma pessoa. Se a Entrada falhar, repete-se só a entrada; um envio errado anula-se (a Entrada e depois a Guia).</li>
                </ol>
            </ModalBody>
            <ModalFooter><Button color="primary" onClick={close}>Percebi</Button></ModalFooter>
        </Modal>
    );
}
