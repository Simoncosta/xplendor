import { useEffect } from "react";

/**
 * XPLENDOR — Submenus da sidebar que nunca passam o fundo do ecrã.
 *
 * Com a sidebar recolhida (data-sidebar-size="sm"), o Velzon mostra o submenu de cada item num painel
 * flutuante (position: absolute) por baixo do item. A sidebar, neste modo, também é absolute (não fixa):
 * um painel que passa o fundo da janela estica o documento e a página ganha scroll. Acontecia com o root,
 * que tem a sidebar mais comprida (por exemplo, "Administração" em 1366×768).
 *
 * Um só hook, por delegação de eventos no #navbar-nav, para todos os itens com submenu (e os submenus
 * dentro de submenus):
 *  · ao abrir (rato ou teclado) mede o painel; se não cabe para baixo, abre para cima (o fundo do painel
 *    encosta ao item); se não cabe de nenhum lado, fica entre a barra de topo e o fundo da janela, com
 *    max-height e scroll dentro do painel;
 *  · recalcula no resize e no scroll (da página ou da sidebar);
 *  · teclado: Tab abre (o foco entra no item), Esc fecha e devolve o foco ao item; o foco que vem de um
 *    clique não abre (depois de carregar em "Tickets", o painel fechava com o rato e ficava aberto pelo foco);
 *  · sidebar expandida: ao abrir um grupo, rola só a sidebar (o mais perto possível) para o grupo ficar visível.
 */

export const FLYOUT_MARGIN = 8;

export type FlyoutPlacement = { mode: "down" | "up" | "clamp"; top: number | null; maxHeight: number | null };

/**
 * Onde pôr um painel flutuante (coordenadas da janela; o resultado "top" é relativo ao item, que é o
 * position: relative do painel).
 *  · naturalTop: onde o painel começa sem ajustes (por baixo do item);
 *  · height: a altura do painel inteiro;
 *  · upBottom: onde o fundo do painel encosta quando abre para cima;
 *  · itemTop: o topo do item;
 *  · ceiling: o limite de cima (o fundo da barra de topo, que fica por cima da sidebar);
 *  · viewportHeight: window.innerHeight.
 */
export function placeFlyout(p: { naturalTop: number; height: number; upBottom: number; itemTop: number; ceiling: number; viewportHeight: number; margin?: number }): FlyoutPlacement {
    const m = p.margin ?? FLYOUT_MARGIN;
    const floor = p.viewportHeight - m;
    const ceil = Math.max(0, p.ceiling) + m;
    if (p.naturalTop + p.height <= floor) return { mode: "down", top: null, maxHeight: null };
    // Para cima, arredonda para baixo do fundo (ceil): com alturas fracionárias, o round deixava o painel
    // acabar uns décimos de píxel acima do rótulo e via-se uma linha entre os dois.
    if (p.upBottom - p.height >= ceil) return { mode: "up", top: Math.ceil(p.upBottom - p.height - p.itemTop), maxHeight: null };
    // Não cabe de nenhum lado: ocupa a janela entre a barra de topo e o fundo, com scroll por dentro.
    return { mode: "clamp", top: Math.round(ceil - p.itemTop), maxHeight: Math.max(80, Math.floor(floor - ceil)) };
}

const NAV = "navbar-nav";
const OPEN = "xp-flyout-open";
const SUPPRESSED = "xp-flyout-suppressed";
const UP = "xp-flyout-up";
const SCROLL = "xp-flyout-scroll";

const isFlyoutMode = () => {
    const h = document.documentElement;
    return h.getAttribute("data-sidebar-size") === "sm" && ["vertical", "semibox"].includes(h.getAttribute("data-layout") ?? "") && window.innerWidth >= 768;
};

/** O submenu direto de um item (ou null). */
const dropdownOf = (li: Element): HTMLElement | null => {
    for (const c of Array.from(li.children)) if (c.classList.contains("menu-dropdown")) return c as HTMLElement;
    return null;
};

/** Os itens com submenu, do de fora para o de dentro, que contêm este elemento. */
function chainOf(el: Element | null, nav: HTMLElement): HTMLElement[] {
    const out: HTMLElement[] = [];
    let li = el?.closest("li.nav-item") ?? null;
    while (li && nav.contains(li)) {
        if (dropdownOf(li)) out.unshift(li as HTMLElement);
        li = li.parentElement?.closest("li.nav-item") ?? null;
    }
    return out;
}

function reset(li: HTMLElement) {
    const d = dropdownOf(li);
    if (!d) return;
    d.style.removeProperty("top");
    d.style.removeProperty("max-height");
    d.classList.remove(UP, SCROLL);
}

function place(li: HTMLElement) {
    const d = dropdownOf(li);
    if (!d) return;
    reset(li);
    const r = d.getBoundingClientRect();
    if (r.height === 0) return; // fechado (não está a ser mostrado)
    const itemRect = li.getBoundingClientRect();
    // No primeiro nível o rótulo do item fica na mesma linha, à direita do ícone: para cima, o painel
    // encosta por cima dessa linha; nos níveis de dentro, encosta ao fundo do item.
    const topLevel = !li.parentElement?.closest(".menu-dropdown");
    const link = li.querySelector(":scope > a") as HTMLElement | null;
    const upBottom = topLevel && link ? link.getBoundingClientRect().top : itemRect.bottom;
    const topbar = document.getElementById("page-topbar");
    const pl = placeFlyout({
        naturalTop: r.top, height: r.height, upBottom, itemTop: itemRect.top,
        ceiling: topbar ? topbar.getBoundingClientRect().bottom : 0, viewportHeight: window.innerHeight,
    });
    if (pl.top !== null) d.style.setProperty("top", `${pl.top}px`);
    if (pl.maxHeight !== null) {
        d.style.setProperty("max-height", `${pl.maxHeight}px`);
        d.classList.add(SCROLL);
    }
    if (pl.mode === "up") d.classList.add(UP);
}

/** Rola só o contentor da sidebar (SimpleBar) para o grupo aberto ficar visível ("nearest"). */
export function revealInSidebar(li: HTMLElement) {
    const scroller = li.closest(".simplebar-content-wrapper") as HTMLElement | null;
    if (!scroller) return;
    const s = scroller.getBoundingClientRect();
    const r = li.getBoundingClientRect();
    // O contentor da sidebar pode descer abaixo da janela: o que conta é a parte que se vê.
    const bottom = Math.min(s.bottom, window.innerHeight);
    if (r.bottom > bottom - FLYOUT_MARGIN) {
        // Desce o necessário para o fundo do grupo ficar visível, sem esconder o topo do grupo.
        scroller.scrollTop += Math.max(0, Math.min(r.bottom - bottom + FLYOUT_MARGIN, r.top - s.top - FLYOUT_MARGIN));
    } else if (r.top < s.top + FLYOUT_MARGIN) {
        scroller.scrollTop -= s.top + FLYOUT_MARGIN - r.top;
    }
}

export default function useSidebarFlyout() {
    useEffect(() => {
        const nav = document.getElementById(NAV);
        if (!nav) return;
        const hovered = new Set<HTMLElement>();
        let frame = 0;
        let pointerFocus = false; // o próximo foco no menu vem do rato (ou do toque), não do teclado

        const all = () => Array.from(nav.querySelectorAll<HTMLElement>("li.nav-item")).filter((li) => dropdownOf(li));
        const cleanupAll = () => all().forEach((li) => { reset(li); li.classList.remove(OPEN, SUPPRESSED); });
        const openNow = () => all().filter((li) => hovered.has(li) || li.classList.contains(OPEN));
        const placeAll = () => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => { if (isFlyoutMode()) openNow().forEach(place); });
        };

        const onOver = (e: MouseEvent) => {
            if (!isFlyoutMode()) return;
            const chain = chainOf(e.target as Element, nav);
            for (const li of Array.from(hovered)) {
                if (!chain.includes(li)) {
                    hovered.delete(li);
                    li.classList.remove(SUPPRESSED);
                    if (!li.classList.contains(OPEN)) reset(li);
                }
            }
            const fresh = chain.filter((li) => !hovered.has(li));
            fresh.forEach((li) => hovered.add(li));
            if (fresh.length) requestAnimationFrame(() => fresh.forEach((li) => place(li)));
        };
        const onLeave = () => {
            hovered.forEach((li) => { li.classList.remove(SUPPRESSED); if (!li.classList.contains(OPEN)) reset(li); });
            hovered.clear();
        };
        const onPointerDown = () => { pointerFocus = true; };
        const onAnyKey = () => { pointerFocus = false; };
        const onFocusIn = (e: FocusEvent) => {
            if (!isFlyoutMode() || pointerFocus) return;
            const chain = chainOf(e.target as Element, nav);
            nav.querySelectorAll<HTMLElement>(`li.${OPEN}`).forEach((li) => { if (!chain.includes(li)) { li.classList.remove(OPEN); if (!hovered.has(li)) reset(li); } });
            nav.querySelectorAll<HTMLElement>(`li.${SUPPRESSED}`).forEach((li) => { if (!chain.includes(li)) li.classList.remove(SUPPRESSED); });
            const fresh = chain.filter((li) => !li.classList.contains(OPEN) && !li.classList.contains(SUPPRESSED));
            fresh.forEach((li) => li.classList.add(OPEN));
            if (fresh.length) requestAnimationFrame(() => fresh.forEach((li) => place(li)));
        };
        const onFocusOut = (e: FocusEvent) => {
            const to = e.relatedTarget as Node | null;
            if (to && nav.contains(to)) return;
            nav.querySelectorAll<HTMLElement>(`li.${OPEN}, li.${SUPPRESSED}`).forEach((li) => { li.classList.remove(OPEN, SUPPRESSED); if (!hovered.has(li)) reset(li); });
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key !== "Escape" || !isFlyoutMode()) return;
            const chain = chainOf(document.activeElement, nav);
            const li = chain[chain.length - 1];
            if (!li) return;
            // Esc fecha o painel de dentro e devolve o foco ao item que o abriu (sem o voltar a abrir).
            li.classList.remove(OPEN);
            li.classList.add(SUPPRESSED);
            reset(li);
            (li.querySelector(":scope > a") as HTMLElement | null)?.focus();
            e.preventDefault();
        };
        const onClick = (e: MouseEvent) => {
            if (isFlyoutMode()) return;
            const a = (e.target as Element).closest('a[data-bs-toggle="collapse"]');
            const li = a?.closest("li.nav-item") as HTMLElement | null;
            if (!li || !nav.contains(li)) return;
            // Sidebar expandida: depois de o grupo abrir (a animação do Collapse), mostrá-lo dentro da sidebar.
            window.setTimeout(() => { if (dropdownOf(li)?.classList.contains("show")) revealInSidebar(li); }, 380);
        };
        const onMode = () => { if (!isFlyoutMode()) { hovered.clear(); cleanupAll(); } else placeAll(); };

        nav.addEventListener("mouseover", onOver);
        nav.addEventListener("mousedown", onPointerDown);
        document.addEventListener("keydown", onAnyKey, true);
        nav.addEventListener("mouseleave", onLeave);
        nav.addEventListener("focusin", onFocusIn);
        nav.addEventListener("focusout", onFocusOut);
        nav.addEventListener("keydown", onKey);
        nav.addEventListener("click", onClick);
        window.addEventListener("resize", placeAll);
        window.addEventListener("scroll", placeAll, true); // também o scroll da sidebar (SimpleBar)
        const mo = new MutationObserver(onMode);
        mo.observe(document.documentElement, { attributes: true, attributeFilter: ["data-sidebar-size", "data-layout"] });
        return () => {
            cancelAnimationFrame(frame);
            nav.removeEventListener("mouseover", onOver);
            nav.removeEventListener("mousedown", onPointerDown);
            document.removeEventListener("keydown", onAnyKey, true);
            nav.removeEventListener("mouseleave", onLeave);
            nav.removeEventListener("focusin", onFocusIn);
            nav.removeEventListener("focusout", onFocusOut);
            nav.removeEventListener("keydown", onKey);
            nav.removeEventListener("click", onClick);
            window.removeEventListener("resize", placeAll);
            window.removeEventListener("scroll", placeAll, true);
            mo.disconnect();
            cleanupAll();
        };
    }, []);
}
