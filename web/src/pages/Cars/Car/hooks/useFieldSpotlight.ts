import { useCallback, type RefObject } from "react";
import type { FormSearchEntry } from "../data/formSearchIndex";
import type { CarVehicleDetailsHandle } from "../components/CarVehicleDetailsDataFields";
import type { CarEquipmentHandle } from "../components/CarEquipmentDataFields";

/**
 * Hook que orquestra "levar ao campo" depois da busca universal:
 *   1. abre o accordion certo (via `useImperativeHandle` na Etapa 3)
 *   2. scrollIntoView no <div.accordion-item> ou na secção solta
 *   3. anima um destaque de 1.5s no header / no wrapper da secção
 *
 * v1 vai ao TOPO do accordion / da secção, NÃO ao campo exacto. Razões:
 *   - XInput não passa `name` para `id` (auditoria registada).
 *   - Confirmar no browser que abrir/scrollar funciona antes de investir
 *     em scroll fino do campo.
 *   - Mesmo no scroll ao topo, a Matilde vê o accordion aberto e o header
 *     destacado, percebe onde está e olha pelo accordion.
 *
 * `parent_off` resolvido upstream: o `selectEntry` do FormSearchBar já
 * substitui a entrada do filho pela entrada do PAI, logo este hook recebe
 * a entrada-pai e abre o accordion onde o pai vive. A Matilde marca o
 * checkbox-pai à mão e refaz a busca.
 */
export interface UseFieldSpotlightArgs {
    habitationRef: RefObject<CarVehicleDetailsHandle | null>;
    equipmentRef: RefObject<CarEquipmentHandle | null>;
}

/** Espera o accordion abrir (animação Reactstrap ~350ms) e depois scroll+destaque. */
const SCROLL_DELAY_AFTER_OPEN_MS = 80;
const HIGHLIGHT_DURATION_MS = 1500;
const HIGHLIGHT_COLOR = "rgba(247, 184, 75, 0.45)";        // amarelo Velzon
const HIGHLIGHT_BG    = "rgba(247, 184, 75, 0.12)";
/** Compensação extra acima da barra sticky no scroll-margin (respirar). */
const STICKY_BAR_EXTRA_OFFSET_PX = 8;
/** Fallback se a barra ainda não está montada quando se mede. */
const STICKY_BAR_FALLBACK_HEIGHT = 80;

/** Cancela animações pendentes no element (evita pisca-pisca em clicks duplos). */
const cancelPendingHighlights = (el: HTMLElement) => {
    if (typeof el.getAnimations !== "function") return;
    for (const anim of el.getAnimations()) {
        // Só cancelamos as que nós próprios marcámos.
        if (anim.id === "field-spotlight-pulse") anim.cancel();
    }
};

/** Anima um pulso amarelo soft de 1.5s no element. Web Animations API nativa. */
const pulseHighlight = (el: HTMLElement) => {
    if (typeof el.animate !== "function") return; // gracioso em browsers antigos
    cancelPendingHighlights(el);
    const anim = el.animate(
        [
            { boxShadow: "0 0 0 0 rgba(247, 184, 75, 0)", backgroundColor: "transparent" },
            { boxShadow: `0 0 0 4px ${HIGHLIGHT_COLOR}`, backgroundColor: HIGHLIGHT_BG, offset: 0.15 },
            { boxShadow: `0 0 0 4px ${HIGHLIGHT_COLOR}`, backgroundColor: HIGHLIGHT_BG, offset: 0.55 },
            { boxShadow: "0 0 0 0 rgba(247, 184, 75, 0)", backgroundColor: "transparent" },
        ],
        { duration: HIGHLIGHT_DURATION_MS, easing: "ease-out" },
    );
    anim.id = "field-spotlight-pulse";
};

/**
 * Localiza o `<div.accordion-item>` por id ÚNICO no DOM.
 *
 * **DESCOBERTA (Bug 1 da v1):** o `accordionId` da Reactstrap é uma chave
 * LÓGICA do `AccordionContext` (decide se cada `<Collapse>` está aberto),
 * NÃO vai para o DOM como `id`. O `AccordionBody.js` separa o `accordionId`
 * de `{...attributes}` antes de passar ao `<Collapse>`. Logo
 * `getElementById(accordionId)` devolvia null e o scroll nunca corria —
 * o `openAccordion` (que corre antes) já tinha aberto, daí "abre mas não
 * navega". Convenção: damos `id="hab-acc-{N}"` / `id="extras-acc-{N}"`
 * ao próprio `<AccordionItem>` (que aceita `{...attributes}` directos
 * para o `<div.accordion-item>` raiz — confirmado no source da Reactstrap).
 */
const findAccordionItem = (kind: "habitation" | "extras", accordionId: string): HTMLElement | null => {
    const domId = kind === "habitation" ? `hab-acc-${accordionId}` : `extras-acc-${accordionId}`;
    return document.getElementById(domId);
};

/**
 * Mede a altura da barra de busca sticky em runtime. Usa-se como
 * `scrollMarginTop` no element-alvo → `scrollIntoView({block:"start"})`
 * passa a respeitar esse offset automaticamente, sem precisar de
 * scrollBy ou window.scrollTo manuais. (Bug 2 da v1: target ficava
 * tapado pela barra.)
 */
const measureStickyBarHeight = (): number => {
    const bar = document.querySelector<HTMLElement>("[data-form-search-bar]");
    return (bar?.offsetHeight ?? STICKY_BAR_FALLBACK_HEIGHT) + STICKY_BAR_EXTRA_OFFSET_PX;
};

const scrollAndPulse = (target: HTMLElement, headerToHighlight: HTMLElement) => {
    // Aplica scroll-margin-top em runtime = altura da barra sticky + folga,
    // para o target NÃO ficar tapado. `scrollIntoView` respeita scrollMargin.
    target.style.scrollMarginTop = `${measureStickyBarHeight()}px`;
    target.scrollIntoView({ behavior: "smooth", block: "start" });
    pulseHighlight(headerToHighlight);
};

export const useFieldSpotlight = ({ habitationRef, equipmentRef }: UseFieldSpotlightArgs) => {
    return useCallback((entry: FormSearchEntry) => {
        const loc = entry.location;

        if (loc.kind === "section") {
            // Secção solta — scroll directo pelo `domId`.
            const el = document.getElementById(loc.domId);
            if (!el) return;
            scrollAndPulse(el, el);
            return;
        }

        // habitation | extras — pedimos ao container para abrir o accordion;
        // aguardamos 1 tick (animação Reactstrap começar) e scroll+destaque.
        const ref = loc.kind === "habitation" ? habitationRef : equipmentRef;
        ref.current?.openAccordion(loc.accordionId);

        // Pequeno delay para o Reactstrap começar a animação de open antes
        // do scroll — sem isto, o scroll calcula posição com o accordion
        // ainda fechado e fica curto.
        window.setTimeout(() => {
            const item = findAccordionItem(loc.kind, loc.accordionId);
            if (!item) return;
            const header = (item.querySelector(".accordion-header") as HTMLElement | null) ?? item;
            scrollAndPulse(item, header);
        }, SCROLL_DELAY_AFTER_OPEN_MS);
    }, [habitationRef, equipmentRef]);
};
