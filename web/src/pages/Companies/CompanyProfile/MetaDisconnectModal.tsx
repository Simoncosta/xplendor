import { useEffect, useState } from "react";
import { Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";

/**
 * Desligar a Meta com escolha do que acontece aos dados já recebidos:
 * "Manter o histórico" (omissão) ou "Apagar todos os dados da Meta", que exige
 * escrever APAGAR. Em modo "purge" (integração já desligada) só apaga.
 */

export const META_PURGE_CONFIRMATION = "APAGAR";

export type MetaDisconnectMode = "disconnect" | "purge";

interface Props {
    isOpen: boolean;
    mode: MetaDisconnectMode;
    loading?: boolean;
    onCancel: () => void;
    onConfirm: (options: { purge: boolean; confirmation?: string }) => void;
}

export default function MetaDisconnectModal({ isOpen, mode, loading = false, onCancel, onConfirm }: Props) {
    const [purge, setPurge] = useState(mode === "purge");
    const [confirmation, setConfirmation] = useState("");

    useEffect(() => {
        if (isOpen) {
            setPurge(mode === "purge");
            setConfirmation("");
        }
    }, [isOpen, mode]);

    const confirmed = !purge || confirmation.trim() === META_PURGE_CONFIRMATION;
    const confirmText = mode === "purge" ? "Apagar dados" : (purge ? "Desligar e apagar" : "Desligar");

    return (
        <Modal isOpen={isOpen} toggle={!loading ? onCancel : undefined} centered>
            <ModalHeader toggle={!loading ? onCancel : undefined}>
                {mode === "purge" ? "Apagar os dados da Meta" : "Desligar a Meta"}
            </ModalHeader>
            <ModalBody>
                {mode === "disconnect" && (
                    <>
                        <p className="text-muted fs-14">
                            A XPLENDOR deixa de receber dados da Meta e a autorização da aplicação é retirada na sua conta Meta.
                            Escolha o que acontece aos dados já recebidos:
                        </p>
                        <div className="form-check mb-2">
                            <Input className="form-check-input" type="radio" name="meta-disconnect" id="meta-keep"
                                checked={!purge} onChange={() => setPurge(false)} disabled={loading} />
                            <Label className="form-check-label fw-medium" for="meta-keep">Manter o histórico</Label>
                            <p className="text-muted fs-13 mb-0">
                                Os resultados passados das campanhas continuam visíveis na XPLENDOR. Pode apagá-los mais tarde.
                            </p>
                        </div>
                        <div className="form-check mb-3">
                            <Input className="form-check-input" type="radio" name="meta-disconnect" id="meta-purge"
                                checked={purge} onChange={() => setPurge(true)} disabled={loading} />
                            <Label className="form-check-label fw-medium" for="meta-purge">Apagar todos os dados da Meta</Label>
                            <p className="text-muted fs-13 mb-0">
                                Apaga métricas, campanhas, anúncios, públicos e despesas calculadas a partir da Meta.
                                As vendas mantêm-se, sem ligação a campanhas.
                            </p>
                        </div>
                    </>
                )}

                {mode === "purge" && (
                    <p className="text-muted fs-14">
                        Apaga as métricas, campanhas, anúncios, públicos e despesas calculadas a partir da Meta que ficaram
                        guardados. As vendas mantêm-se, sem ligação a campanhas.
                    </p>
                )}

                {purge && (
                    <div className="alert alert-danger mb-0" role="alert">
                        <p className="fs-13 mb-2">Esta ação não pode ser desfeita.</p>
                        <Label for="meta-purge-confirmation" className="fs-13 mb-1">
                            Para confirmar, escreva <strong>{META_PURGE_CONFIRMATION}</strong>
                        </Label>
                        <Input id="meta-purge-confirmation" bsSize="sm" autoComplete="off" value={confirmation}
                            onChange={(e) => setConfirmation(e.target.value)} disabled={loading} />
                    </div>
                )}
            </ModalBody>
            <ModalFooter>
                <Button color="light" className="border" onClick={onCancel} disabled={loading}>
                    Cancelar
                </Button>
                <Button
                    color="danger"
                    disabled={loading || !confirmed}
                    onClick={() => onConfirm(purge ? { purge: true, confirmation: confirmation.trim() } : { purge: false })}
                >
                    {loading ? <><Spinner size="sm" className="me-2" />A processar...</> : confirmText}
                </Button>
            </ModalFooter>
        </Modal>
    );
}
