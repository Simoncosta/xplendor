import { Button, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";

export interface ConfirmModalProps {
    isOpen: boolean;
    title: string;
    message: string;
    confirmText?: string;
    cancelText?: string;
    variant?: "danger" | "warning" | "default";
    onConfirm: () => void;
    onCancel: () => void;
    loading?: boolean;
}

export default function ConfirmModal({
    isOpen,
    title,
    message,
    confirmText = "Confirmar",
    cancelText = "Cancelar",
    variant = "default",
    onConfirm,
    onCancel,
    loading = false,
}: ConfirmModalProps) {
    const confirmColor = variant === "danger"
        ? "danger"
        : (variant === "warning" ? "warning" : "primary");

    return (
        <Modal isOpen={isOpen} toggle={!loading ? onCancel : undefined} centered>
            <ModalHeader toggle={!loading ? onCancel : undefined}>
                {title}
            </ModalHeader>
            <ModalBody>
                <p className="mb-0 text-muted fs-14">{message}</p>
            </ModalBody>
            <ModalFooter>
                <Button color="light" className="border" onClick={onCancel} disabled={loading}>
                    {cancelText}
                </Button>
                <Button color={confirmColor} onClick={onConfirm} disabled={loading}>
                    {loading ? <><Spinner size="sm" className="me-2" />A confirmar...</> : confirmText}
                </Button>
            </ModalFooter>
        </Modal>
    );
}
