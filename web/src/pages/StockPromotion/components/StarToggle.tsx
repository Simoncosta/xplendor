import { useState } from "react";
import { toast } from "react-toastify";
import { UncontrolledTooltip } from "reactstrap";
import { markPromotion, unmarkPromotion } from "../../../helpers/stockPromotion_helper";
import { showApiErrorToast } from "../../../helpers/error_helper";
import type { PromotionCandidatePriority } from "../../../types/api";

interface PromotionToggleProps {
    companyId: number;
    carId: number;
    initial: PromotionCandidatePriority | null;
    onChange?: (next: PromotionCandidatePriority | null) => void;
    /** "lg" para mobile cards (32px), "md" para tabela desktop (24px). */
    size?: "md" | "lg";
}

/**
 * Estrela ☆/★ pura — sem label, sem botão amarelo.
 *
 * Affordance feita por:
 *   - cor (cinza outline vs amarelo Velzon `#f7b84b` quando preenchida)
 *   - mudança de ícone (ri-star-line vs ri-star-fill)
 *   - cursor pointer + hover shift
 *   - tooltip touch-friendly explicando a acção
 *
 * Mantém POST/DELETE com optimistic update + revert (Etapas 4-5).
 */
const StarToggle = ({ companyId, carId, initial, onChange, size = "md" }: PromotionToggleProps) => {
    const [priority, setPriority] = useState<PromotionCandidatePriority | null>(initial);
    const [pending, setPending] = useState(false);
    const [hover, setHover] = useState(false);
    const isMarked = priority !== null;
    const tipId = `promote-${carId}`;

    const toggle = async (e: React.MouseEvent) => {
        e.stopPropagation();
        if (pending) return;
        const previous = priority;
        setPending(true);

        try {
            if (isMarked) {
                setPriority(null);
                onChange?.(null);
                await unmarkPromotion(companyId, carId);
                toast.success("Removida da promoção.", { autoClose: 2000 });
            } else {
                const placeholder: PromotionCandidatePriority = {
                    id: -1,
                    marked_at: new Date().toISOString(),
                    note: null,
                    marked_by: null,
                };
                setPriority(placeholder);
                onChange?.(placeholder);
                const result = await markPromotion(companyId, carId, null);
                setPriority(result);
                onChange?.(result);
                toast.success("Marcada para promoção.", { autoClose: 2000 });
            }
        } catch (err: any) {
            setPriority(previous);
            onChange?.(previous);
            showApiErrorToast(err, "Não foi possível guardar a prioridade.");
        } finally {
            setPending(false);
        }
    };

    const iconClass = isMarked ? "ri-star-fill" : "ri-star-line";
    const fontSize  = size === "lg" ? 28 : 22;
    // Amarelo Velzon (mesmo tom do warning subtle). Em hover sobre não-marcada,
    // pré-visualiza a cor activa para sinalizar a acção.
    const color = isMarked
        ? "#f7b84b"
        : (hover ? "#f7b84b" : "#ced4da");

    const tip = isMarked
        ? "Remover da lista de promoção"
        : "Marcar para tráfego pago";

    return (
        <>
            <button
                type="button"
                id={tipId}
                onClick={toggle}
                onMouseEnter={() => setHover(true)}
                onMouseLeave={() => setHover(false)}
                disabled={pending}
                className="btn p-0 border-0 bg-transparent d-inline-flex align-items-center justify-content-center"
                style={{
                    width: fontSize + 8,
                    height: fontSize + 8,
                    lineHeight: 1,
                    color,
                    transition: "color 120ms ease, transform 120ms ease",
                    transform: pending ? "scale(0.92)" : undefined,
                }}
                aria-pressed={isMarked}
                aria-label={tip}
            >
                <i className={iconClass} style={{ fontSize }} aria-hidden="true" />
            </button>
            <UncontrolledTooltip target={tipId} placement="top" trigger="hover focus">
                {tip}
            </UncontrolledTooltip>
        </>
    );
};

export default StarToggle;
