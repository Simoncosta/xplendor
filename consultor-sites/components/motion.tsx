"use client";

import { LazyMotion, MotionConfig, domAnimation } from "motion/react";
import { useEffect, type ReactNode } from "react";

/**
 * As animações do site, discretas. O Motion anima a linha do tempo dos 8 passos e o popup do
 * WhatsApp (só as funcionalidades do DOM, para o JavaScript ficar leve; com prefers-reduced-motion
 * não anima transformações). As entradas ao aparecer e a assinatura são CSS: o conteúdo está
 * sempre no HTML e visível; só se esconde para entrar quando o navegador aceita movimento.
 */
export function MotionProvider({ children }: { children: ReactNode }) {
    return (
        <LazyMotion features={domAnimation} strict>
            <MotionConfig reducedMotion="user">{children}</MotionConfig>
        </LazyMotion>
    );
}

/** Antes do primeiro desenho: liga as entradas só com movimento permitido (sem JavaScript, tudo visível). */
export const REVEAL_BOOTSTRAP = `try{if(!matchMedia('(prefers-reduced-motion: reduce)').matches)document.documentElement.classList.add('motion-ok')}catch(e){}`;

/** Um só observador para todas as entradas ([data-reveal]). */
export function RevealObserver() {
    useEffect(() => {
        const root = document.documentElement;
        if (!root.classList.contains("motion-ok") || !("IntersectionObserver" in window)) {
            root.classList.remove("motion-ok");
            return;
        }
        const io = new IntersectionObserver((entries) => {
            for (const e of entries) if (e.isIntersecting) { e.target.setAttribute("data-shown", ""); io.unobserve(e.target); }
        }, { rootMargin: "0px 0px -10% 0px" });
        // O movimento contínuo (zoom lento, barra do vídeo) só corre no ecrã.
        const live = new IntersectionObserver((entries) => {
            for (const e of entries) e.target.toggleAttribute("data-inview", e.isIntersecting);
        });
        const seen = new WeakSet<Element>();
        const watch = () => {
            document.querySelectorAll("[data-reveal]:not([data-shown])").forEach((el) => io.observe(el));
            document.querySelectorAll(".slow-zoom, [data-live]").forEach((el) => { if (!seen.has(el)) { seen.add(el); live.observe(el); } });
        };
        watch();
        const mo = new MutationObserver(watch);
        mo.observe(document.body, { childList: true, subtree: true });
        return () => { io.disconnect(); live.disconnect(); mo.disconnect(); };
    }, []);
    return null;
}

/** Entrada suave ao aparecer no ecrã (uma vez). */
export function Reveal({ children, delay = 0, className, as = "div" }: { children: ReactNode; delay?: number; className?: string; as?: "div" | "li" }) {
    const style = delay ? { transitionDelay: `${Math.round(delay * 1000)}ms` } : undefined;
    return as === "li" ? <li data-reveal className={className} style={style}>{children}</li> : <div data-reveal className={className} style={style}>{children}</div>;
}
