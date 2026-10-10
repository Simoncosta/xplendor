import { useEffect, useMemo, useState } from "react";
import { Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import XSelect from "Components/Common/Select";
import ReasonButton from "Components/Common/ReasonButton";
import type { FileFolderDto } from "common/models/files.model";

/** Ficheiros: os modais (nome, escolher a pasta, confirmar), segundo o padrão visual (§4 e §7). */

export function NameModal({ isOpen, title, label, initial, hint, busy, confirmLabel, onClose, onConfirm }: {
    isOpen: boolean; title: string; label: string; initial: string; hint?: string; busy: boolean; confirmLabel: string;
    onClose: () => void; onConfirm: (name: string) => void;
}) {
    const [name, setName] = useState(initial);
    useEffect(() => { if (isOpen) setName(initial); }, [isOpen, initial]);

    return (
        <Modal isOpen={isOpen} toggle={() => !busy && onClose()} centered>
            <ModalHeader toggle={() => !busy && onClose()}>{title}</ModalHeader>
            <ModalBody>
                <Label for="files-name" className="form-label">{label}</Label>
                <Input id="files-name" value={name} maxLength={200} autoFocus onChange={(e) => setName(e.target.value)}
                    onKeyDown={(e) => { if (e.key === "Enter" && name.trim() && !busy) onConfirm(name.trim()); }} />
                {hint && <p className="text-muted fs-12 mt-2 mb-0">{hint}</p>}
            </ModalBody>
            <ModalFooter>
                <Button color="light" disabled={busy} onClick={onClose}>Cancelar</Button>
                <ReasonButton color="primary" disabled={busy} reason={!name.trim() ? "Escreva o nome." : null} onClick={() => onConfirm(name.trim())}>
                    {busy ? <Spinner size="sm" /> : confirmLabel}
                </ReasonButton>
            </ModalFooter>
        </Modal>
    );
}

/** Caminho legível de uma pasta das pessoas ("Contratos › 2026"). */
const pathOf = (f: FileFolderDto, all: FileFolderDto[]): string => {
    const parts: string[] = [];
    for (let cur: FileFolderDto | undefined = f; cur; cur = all.find((x) => x.id === cur!.parent_id)) parts.unshift(cur.name);
    return parts.join(" › ");
};

export function FolderPickerModal({ isOpen, title, hint, confirmLabel, folders, allowRoot, busy, onClose, onConfirm }: {
    isOpen: boolean; title: string; hint: string; confirmLabel: string; folders: FileFolderDto[]; allowRoot: boolean; busy: boolean;
    onClose: () => void; onConfirm: (folderId: number | null) => void;
}) {
    const [target, setTarget] = useState<number | null>(null);
    useEffect(() => { if (isOpen) setTarget(null); }, [isOpen]);
    const options = useMemo(() => [
        ...(allowRoot ? [{ value: 0, label: "Os meus ficheiros (sem pasta)" }] : []),
        ...folders.map((f) => ({ value: f.id, label: pathOf(f, folders) })).sort((a, b) => a.label.localeCompare(b.label, "pt")),
    ], [folders, allowRoot]);

    return (
        <Modal isOpen={isOpen} toggle={() => !busy && onClose()} centered>
            <ModalHeader toggle={() => !busy && onClose()}>{title}</ModalHeader>
            <ModalBody>
                <Label for="files-target" className="form-label">Pasta</Label>
                <XSelect<number> id="files-target" searchable options={options} value={target} onChange={(v) => setTarget(v)} />
                <p className="text-muted fs-12 mt-2 mb-0">{hint}</p>
            </ModalBody>
            <ModalFooter>
                <Button color="light" disabled={busy} onClick={onClose}>Cancelar</Button>
                <ReasonButton color="primary" disabled={busy} reason={target === null ? "Escolha a pasta." : null} onClick={() => onConfirm(target === 0 ? null : target)}>
                    {busy ? <Spinner size="sm" /> : confirmLabel}
                </ReasonButton>
            </ModalFooter>
        </Modal>
    );
}

export function ConfirmModal({ isOpen, title, message, confirmLabel, busy, onClose, onConfirm }: {
    isOpen: boolean; title: string; message: string; confirmLabel: string; busy: boolean; onClose: () => void; onConfirm: () => void;
}) {
    return (
        <Modal isOpen={isOpen} toggle={() => !busy && onClose()} centered>
            <ModalHeader toggle={() => !busy && onClose()}>{title}</ModalHeader>
            <ModalBody><p className="mb-0">{message}</p></ModalBody>
            <ModalFooter>
                <Button color="light" disabled={busy} onClick={onClose}>Cancelar</Button>
                <Button color="danger" disabled={busy} onClick={onConfirm}>{busy ? <Spinner size="sm" /> : confirmLabel}</Button>
            </ModalFooter>
        </Modal>
    );
}
