import { useEffect, useState } from "react";
import { Modal, ModalBody, ModalFooter, ModalHeader } from "reactstrap";
import { ICarmineApi } from "common/models/carmine-api.model";

/**
 * XPLENDOR — Cadastro da API Carmine (stock automóvel) como cartão de integração.
 * Reutiliza o fluxo Redux existente (create/update) via onSubmit; não duplica a
 * camada de dados. Só é usável com o módulo 'stock' ativo (o backend recusa na
 * mesma — defesa em profundidade).
 */

type Props = {
    isOpen: boolean;
    data: ICarmineApi;
    onClose: () => void;
    onSubmit: (data: ICarmineApi) => void;
};

export default function CarmineConnectModal({ isOpen, data, onClose, onSubmit }: Props) {
    const [dealerId, setDealerId] = useState("");
    const [token, setToken] = useState("");
    const [showToken, setShowToken] = useState(false);

    useEffect(() => {
        if (!isOpen) return;
        setDealerId(data?.dealer_id ?? "");
        setToken(data?.token ?? "");
        setShowToken(false);
    }, [isOpen, data]);

    const submit = () => {
        onSubmit({ id: data?.id, dealer_id: dealerId.trim(), token: token.trim() });
        onClose();
    };

    return (
        <Modal isOpen={isOpen} toggle={onClose} centered>
            <ModalHeader toggle={onClose}>{data?.id ? "Reconfigurar Carmine" : "Ligar Carmine"}</ModalHeader>
            <ModalBody>
                <p className="text-muted fs-13">Liga a conta Carmine para sincronizar o stock de veículos.</p>
                <div className="mb-3">
                    <label className="form-label">ID do Dealer</label>
                    <input
                        type="text"
                        className="form-control"
                        placeholder="ID do Dealer"
                        value={dealerId}
                        onChange={(e) => setDealerId(e.target.value)}
                    />
                </div>
                <div className="mb-2">
                    <label className="form-label">Token</label>
                    <div className="position-relative auth-pass-inputgroup">
                        <input
                            type={showToken ? "text" : "password"}
                            className="form-control pe-5"
                            placeholder="Token"
                            value={token}
                            onChange={(e) => setToken(e.target.value)}
                            autoComplete="new-password"
                        />
                        <button
                            type="button"
                            className="btn btn-link position-absolute end-0 top-0 text-decoration-none text-muted"
                            onClick={() => setShowToken((s) => !s)}
                            tabIndex={-1}
                        >
                            <i className={showToken ? "ri-eye-off-fill" : "ri-eye-fill"} />
                        </button>
                    </div>
                </div>
            </ModalBody>
            <ModalFooter>
                <button className="btn btn-light" onClick={onClose}>Cancelar</button>
                <button className="btn btn-primary" onClick={submit} disabled={!dealerId.trim() || !token.trim()}>
                    <i className="ri-check-double-line me-1" /> Guardar
                </button>
            </ModalFooter>
        </Modal>
    );
}
