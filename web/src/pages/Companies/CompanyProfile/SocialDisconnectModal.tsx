import { useEffect, useState } from "react";
import { Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import ReasonButton from "Components/Common/ReasonButton";

/**
 * Desligar as redes sociais com a mesma escolha da Meta: manter o histórico de
 * seguidores (omissão) ou apagar o que foi lido automaticamente, escrevendo APAGAR.
 * Em modo "purge" (já desligadas) só apaga. Os registos manuais ficam sempre.
 */

export const SOCIAL_PURGE_CONFIRMATION = "APAGAR";

export type SocialDisconnectMode = "disconnect" | "purge";

type Props = {
    isOpen: boolean;
    mode: SocialDisconnectMode;
    loading?: boolean;
    onCancel: () => void;
    onConfirm: (options: { purge: boolean; confirmation?: string }) => void;
};

export default function SocialDisconnectModal({ isOpen, mode, loading = false, onCancel, onConfirm }: Props) {
    const [purge, setPurge] = useState(mode === "purge");
    const [confirmation, setConfirmation] = useState("");

    useEffect(() => {
        if (isOpen) {
            setPurge(mode === "purge");
            setConfirmation("");
        }
    }, [isOpen, mode]);

    const confirmed = !purge || confirmation.trim() === SOCIAL_PURGE_CONFIRMATION;
    const confirmText = mode === "purge" ? "Apagar o histórico" : purge ? "Desligar e apagar" : "Desligar";

    return (
        <Modal isOpen={isOpen} toggle={!loading ? onCancel : undefined} centered>
            <ModalHeader toggle={!loading ? onCancel : undefined}>
                {mode === "purge" ? "Apagar o histórico de seguidores" : "Desligar as redes sociais"}
            </ModalHeader>
            <ModalBody>
                {mode === "disconnect" ? (
                    <>
                        <p className="text-muted fs-14">
                            A XPLENDOR deixa de ler os seguidores e retira na Meta só as permissões das redes sociais (Páginas e Instagram).
                            A ligação dos anúncios não é afetada. Escolha o que acontece ao histórico de seguidores:
                        </p>
                        <div className="form-check mb-2">
                            <Input className="form-check-input" type="radio" name="social-disconnect" id="social-keep"
                                checked={!purge} onChange={() => setPurge(false)} disabled={loading} />
                            <Label className="form-check-label fw-medium" for="social-keep">Manter o histórico de seguidores</Label>
                            <p className="text-muted fs-13 mb-0">O crescimento passado continua visível no Perfil da Marca. Pode apagá-lo mais tarde.</p>
                        </div>
                        <div className="form-check mb-3">
                            <Input className="form-check-input" type="radio" name="social-disconnect" id="social-purge"
                                checked={purge} onChange={() => setPurge(true)} disabled={loading} />
                            <Label className="form-check-label fw-medium" for="social-purge">Apagar o histórico lido automaticamente</Label>
                            <p className="text-muted fs-13 mb-0">Apaga os números de seguidores lidos da Meta. Os valores registados à mão mantêm-se.</p>
                        </div>
                    </>
                ) : (
                    <p className="text-muted fs-14">
                        Apaga os números de seguidores lidos automaticamente da Meta. Os valores registados à mão mantêm-se.
                    </p>
                )}
                {purge && (
                    <div className="alert alert-danger mb-0" role="alert">
                        <p className="fs-13 mb-2">Esta ação não pode ser desfeita.</p>
                        <Label for="social-purge-confirmation" className="fs-13 mb-1">
                            Para confirmar, escreva <strong>{SOCIAL_PURGE_CONFIRMATION}</strong>
                        </Label>
                        <Input id="social-purge-confirmation" bsSize="sm" autoComplete="off" value={confirmation}
                            onChange={(e) => setConfirmation(e.target.value)} disabled={loading} />
                    </div>
                )}
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onCancel} disabled={loading}>Cancelar</Button>
                <ReasonButton color="danger" disabled={loading}
                    reason={!loading && !confirmed ? `Escreva ${SOCIAL_PURGE_CONFIRMATION} para confirmar.` : null}
                    onClick={() => onConfirm(purge ? { purge: true, confirmation: confirmation.trim() } : { purge: false })}>
                    {loading ? <><Spinner size="sm" className="me-2" />A processar</> : confirmText}
                </ReasonButton>
            </ModalFooter>
        </Modal>
    );
}
