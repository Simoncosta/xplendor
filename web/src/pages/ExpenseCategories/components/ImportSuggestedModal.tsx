// React
import { useEffect, useMemo, useState } from "react";
import { useDispatch } from "react-redux";
import { Modal, ModalHeader, ModalBody, ModalFooter, Spinner } from "reactstrap";
import { toast } from "react-toastify";
// Components
import XButton from "Components/Common/XButton";
// Redux / helpers
import { importSuggestedExpenseCategories } from "slices/expense-categories/thunk";
import { getSuggestedExpenseCategories } from "helpers/laravel_helper";

interface ImportSuggestedModalProps {
    isOpen: boolean;
    toggle: () => void;
    companyId: number;
    /** Nomes das categorias já existentes (para marcar as que não serão recriadas). */
    existingNames: string[];
    onImported?: () => void;
}

/**
 * Pré-visualiza as categorias sugeridas ANTES de importar. Mostra as 14, marca
 * as que já existem (não serão duplicadas) e importa só as que faltam.
 */
export default function ImportSuggestedModal({ isOpen, toggle, companyId, existingNames, onImported }: ImportSuggestedModalProps) {
    const dispatch: any = useDispatch();
    const [suggested, setSuggested] = useState<string[]>([]);
    const [loading, setLoading] = useState(false);
    const [importing, setImporting] = useState(false);

    const existingLower = useMemo(
        () => new Set(existingNames.map((n) => n.trim().toLowerCase())),
        [existingNames]
    );

    useEffect(() => {
        if (!isOpen || !companyId) return;

        let active = true;
        setLoading(true);
        getSuggestedExpenseCategories(companyId)
            .then((res: any) => {
                if (active) setSuggested((res?.data?.suggested as string[]) ?? []);
            })
            .catch(() => {
                if (active) setSuggested([]);
            })
            .finally(() => {
                if (active) setLoading(false);
            });

        return () => {
            active = false;
        };
    }, [isOpen, companyId]);

    const missingCount = suggested.filter((n) => !existingLower.has(n.trim().toLowerCase())).length;

    const handleConfirm = async () => {
        if (!companyId) return;
        setImporting(true);
        try {
            const res: any = await dispatch(importSuggestedExpenseCategories({ companyId })).unwrap();
            const count = res?.data?.created_count ?? 0;
            toast.success(count > 0 ? `${count} categorias importadas.` : "Já tens todas as categorias sugeridas.");
            onImported?.();
            toggle();
        } catch {
            toast.error("Não foi possível importar as categorias sugeridas.");
        } finally {
            setImporting(false);
        }
    };

    return (
        <Modal isOpen={isOpen} toggle={toggle} centered scrollable>
            <ModalHeader toggle={toggle}>Importar categorias sugeridas</ModalHeader>
            <ModalBody>
                {loading ? (
                    <div className="text-center py-4">
                        <Spinner size="sm" /> <span className="text-muted ms-2">A carregar…</span>
                    </div>
                ) : (
                    <>
                        <p className="text-muted mb-3">
                            Vamos criar as categorias que ainda não tens. As que já existem ficam como estão (não são duplicadas).
                        </p>
                        <div className="d-flex flex-wrap gap-2">
                            {suggested.map((name) => {
                                const exists = existingLower.has(name.trim().toLowerCase());
                                return (
                                    <span
                                        key={name}
                                        className={`badge ${exists ? "bg-light text-muted" : "bg-primary-subtle text-primary"} fs-13 fw-normal`}
                                        title={exists ? "Já existe — não será duplicada" : "Será criada"}
                                    >
                                        {!exists && <i className="ri-add-line align-middle me-1" />}
                                        {exists && <i className="ri-check-line align-middle me-1" />}
                                        {name}
                                    </span>
                                );
                            })}
                        </div>
                    </>
                )}
            </ModalBody>
            <ModalFooter>
                <XButton variant="light" type="button" onClick={toggle}>Cancelar</XButton>
                <XButton
                    variant="success"
                    type="button"
                    loading={importing}
                    disabled={loading || missingCount === 0}
                    icon={<i className="ri-download-2-line" />}
                    onClick={handleConfirm}
                >
                    {missingCount > 0 ? `Criar ${missingCount}` : "Nada a criar"}
                </XButton>
            </ModalFooter>
        </Modal>
    );
}
