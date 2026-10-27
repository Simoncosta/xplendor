import { fireEvent, render } from "@testing-library/react";
import useSidebarFlyout, { placeFlyout, revealInSidebar } from "./useSidebarFlyout";

// Os casos medidos no root em 1366×768 (barra de topo até 70 px).
describe("placeFlyout", () => {
    const base = { itemTop: 603, upBottom: 603, ceiling: 70, viewportHeight: 768 };

    it("abre para baixo quando cabe", () => {
        expect(placeFlyout({ ...base, itemTop: 550, upBottom: 550, naturalTop: 603, height: 93 })).toEqual({ mode: "down", top: null, maxHeight: null });
    });

    it("abre para cima, com o fundo encostado ao item, quando não cabe para baixo (Administração: 656→980 numa janela de 768)", () => {
        const p = placeFlyout({ ...base, naturalTop: 656, height: 324 });
        expect(p.mode).toBe("up");
        expect(p.top).toBe(603 - 324 - 603);       // relativo ao item
        expect(603 + (p.top as number) + 324).toBe(603);   // o fundo do painel fica no item
    });

    it("para cima, com uma altura fracionária, o painel não fica acima do rótulo (Configurações: 152,78 px)", () => {
        const pl = placeFlyout({ itemTop: 533.59, upBottom: 533.59, ceiling: 70, viewportHeight: 640, naturalTop: 586.59, height: 152.78 });
        expect(pl.mode).toBe("up");
        expect(533.59 + (pl.top as number) + 152.78).toBeGreaterThanOrEqual(533.59);
    });

    it("não cabe de nenhum lado: fica entre a barra de topo e o fundo, com scroll por dentro", () => {
        const p = placeFlyout({ ...base, itemTop: 400, upBottom: 400, naturalTop: 453, height: 900 });
        expect(p.mode).toBe("clamp");
        expect(400 + (p.top as number)).toBe(78);              // 70 + 8
        expect(p.maxHeight).toBe(768 - 8 - 78);                 // ≤ 100vh − 16 px
        expect(78 + (p.maxHeight as number)).toBeLessThanOrEqual(768 - 8);
    });

    it("o limite de baixo deixa 8 px de margem", () => {
        expect(placeFlyout({ ...base, naturalTop: 600, height: 160 }).mode).toBe("down");   // 760 = 768 − 8
        expect(placeFlyout({ ...base, naturalTop: 600, height: 161 }).mode).toBe("up");
    });
});

describe("revealInSidebar", () => {
    const rect = (top: number, bottom: number) => ({ top, bottom, left: 0, right: 0, width: 0, height: bottom - top, x: 0, y: top, toJSON: () => null }) as DOMRect;
    const build = (liTop: number, liBottom: number) => {
        const scroller = document.createElement("div");
        scroller.className = "simplebar-content-wrapper";
        const li = document.createElement("li");
        scroller.appendChild(li);
        document.body.appendChild(scroller);
        scroller.getBoundingClientRect = () => rect(100, 700);
        li.getBoundingClientRect = () => rect(liTop, liBottom);
        scroller.scrollTop = 0;
        return { scroller, li };
    };

    it("rola só a sidebar o necessário para o grupo aberto no fundo ficar visível", () => {
        const { scroller, li } = build(600, 900);
        revealInSidebar(li);
        expect(scroller.scrollTop).toBe(900 - 700 + 8);
    });

    it("num grupo maior do que a sidebar, mostra o topo do grupo", () => {
        const { scroller, li } = build(600, 1500);
        revealInSidebar(li);
        expect(scroller.scrollTop).toBe(600 - 100 - 8);
    });

    it("conta só a parte da sidebar que se vê na janela", () => {
        const { scroller, li } = build(700, 790);
        scroller.getBoundingClientRect = () => rect(100, 900);   // o contentor desce abaixo da janela (768)
        revealInSidebar(li);
        expect(scroller.scrollTop).toBe(790 - 768 + 8);
    });

    it("não mexe quando o grupo já está visível", () => {
        const { scroller, li } = build(200, 500);
        revealInSidebar(li);
        expect(scroller.scrollTop).toBe(0);
    });
});

// Barra recolhida, grupo "Administração" com "Tickets" (o painel abre pelo rato ou pelo teclado).
describe("useSidebarFlyout: abrir pelo foco", () => {
    const Host = () => { useSidebarFlyout(); return null; };
    let li: HTMLElement, group: HTMLAnchorElement, tickets: HTMLAnchorElement;
    beforeEach(() => {
        document.documentElement.setAttribute("data-layout", "vertical");
        document.documentElement.setAttribute("data-sidebar-size", "sm");
        document.body.innerHTML = `<ul id="navbar-nav"><li class="nav-item"><a href="#" class="nav-link menu-link" id="g">Administração</a>
            <div class="menu-dropdown"><ul class="nav nav-sm"><li class="nav-item"><a href="/admin" class="nav-link" id="t">Tickets</a></li></ul></div></li></ul>`;
        li = document.querySelector("li.nav-item") as HTMLElement;
        group = document.getElementById("g") as HTMLAnchorElement;
        tickets = document.getElementById("t") as HTMLAnchorElement;
    });
    afterEach(() => { document.body.innerHTML = ""; document.documentElement.removeAttribute("data-sidebar-size"); });

    it("com o teclado (Tab), o foco no item abre o painel", () => {
        render(<Host />);
        fireEvent.keyDown(document.body, { key: "Tab" });
        group.focus();
        expect(li).toHaveClass("xp-flyout-open");
    });

    it("depois de carregar em Tickets, o foco do clique não deixa o painel aberto", () => {
        render(<Host />);
        fireEvent.mouseDown(tickets);
        tickets.focus();
        expect(li).not.toHaveClass("xp-flyout-open");
        // e o teclado volta a abrir
        fireEvent.keyDown(tickets, { key: "Tab" });
        group.focus();
        expect(li).toHaveClass("xp-flyout-open");
    });
});
