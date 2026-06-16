import { useState } from "react";
import { toast } from "react-toastify";
import { UncontrolledTooltip } from "reactstrap";
import { markPromotion, unmarkPromotion } from "../../../helpers/stockPromotion_helper";
import { showApiErrorToast } from "../../../helpers/error_helper";
import type { PromotionCandidatePriority } from "../../../types/api";

interface PromotionToggleProps {
    companyId: number;
    carId: number;
    /** Estado actual da prioridade (vindo do parent). `null` = não marcada.
     *  **Componente totalmente controlado** — sem state interno de priority. */
    priority: PromotionCandidatePriority | null;
    /** Notificação ao parent: optimistic update (placeholder), valor final
     *  (do backend) ou revert em erro. O parent é dono da source of truth. */
    onChange?: (next: PromotionCandidatePriority | null) => void;
    /** "lg" para mobile cards (28px), "md" para tabela desktop (22px). */
    size?: "md" | "lg";
}

/**
 * Estrela ☆/★ pura — sem label, sem botão amarelo.
 *
 * **Controlado pelo parent.** `priority` vem do prop; o componente não tem
 * state local desta informação. O optimistic update vive no parent (que
 * já faz `setPage(prev.data.map(c => c.id === carId ? {...c, promotion} : c))`).
 *
 * Razão para ser controlado: quando a lista re-ordena/filtra, o XTanStackTable
 * reaproveita os `<tr>` (key={row.id} é índice TanStack, não car.id), e o
 * React reaproveita os StarToggle pela posição na árvore. Um `useState(initial)`
 * interno ignoraria o novo `initial` em re-render → state local stale do car
 * anterior → UI mente sobre o estado de marcação. Sendo controlado, o
 * componente reflecte sempre o car correcto, em qualquer ordem.
 */
const StarToggle = ({ companyId, carId, priority, onChange, size = "md" }: PromotionToggleProps) => {
    const [pending, setPending] = useState(false);
    const [hover, setHover] = useState(false);
    const isMarked = priority !== null;
    const tipId = `promote-${carId}`;

    const toggle = async (e: React.MouseEvent) => {
        e.stopPropagation();
        if (pending) return;
        const previous = priority; // snapshot para revert
        setPending(true);

        try {
            if (isMarked) {
                onChange?.(null); // optimistic
                await unmarkPromotion(companyId, carId);
                toast.success("Removida da promoção.", { autoClose: 2000 });
            } else {
                const placeholder: PromotionCandidatePriority = {
                    id: -1,
                    marked_at: new Date().toISOString(),
                    note: null,
                    marked_by: null,
                };
                onChange?.(placeholder); // optimistic
                const result = await markPromotion(companyId, carId, null);
                onChange?.(result); // valor final do backend
                toast.success("Marcada para promoção.", { autoClose: 2000 });
            }
        } catch (err: any) {
            onChange?.(previous); // revert
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
