"use client";

import * as Dialog from "@radix-ui/react-dialog";
import * as Switch from "@radix-ui/react-switch";
import Link from "next/link";
import { useEffect, useState } from "react";
import { COOKIES } from "@/content/textos";
import { cn } from "@/lib/cn";
import { OPEN_PREFERENCES_EVENT, publishConsent, readConsent, writeConsent, type Consent } from "@/lib/consent";
import { buttonVariants } from "@/components/ui/button";

/**
 * O cartão dos cookies (canto inferior esquerdo). Na primeira visita: "Aceitar", "Recusar" e
 * "Personalizar" com o mesmo destaque. Depois da escolha, fica só o botão para mudar as
 * preferências. Só mostra as categorias que o perfil tem (GA4, Pixel); sem nenhuma, não há cartão
 * (em dev aparece, com o aviso). Nada de GA4 nem Pixel antes do consentimento.
 */
export function CookieConsent({ policyVersion, hasStatistics, hasMarketing, devNoIds, initial }: {
    policyVersion: string; hasStatistics: boolean; hasMarketing: boolean; devNoIds: boolean;
    /** A escolha já guardada (o servidor lê o cookie), válida para esta versão da política; null se não houver. */
    initial: Consent | null;
}) {
    const showStats = hasStatistics || devNoIds;
    const showMkt = hasMarketing || devNoIds;
    const relevant = showStats || showMkt;
    const [state, setState] = useState<"ask" | "done">(initial || !relevant ? "done" : "ask");
    const [open, setOpen] = useState(false);
    const [stats, setStats] = useState(initial?.s ?? false);
    const [mkt, setMkt] = useState(initial?.m ?? false);

    useEffect(() => {
        const c = readConsent();
        publishConsent(c && c.v === policyVersion ? c : null, false); // a camada de dados (sistema externo)
        const onOpen = () => setOpen(true);
        window.addEventListener(OPEN_PREFERENCES_EVENT, onOpen);
        return () => window.removeEventListener(OPEN_PREFERENCES_EVENT, onOpen);
    }, [policyVersion]);

    if (!relevant) return null;
    const choose = (s: boolean, m: boolean) => {
        writeConsent(policyVersion, s && showStats, m && showMkt);
        setStats(s && showStats);
        setMkt(m && showMkt);
        setState("done");
        setOpen(false);
    };
    const same = cn(buttonVariants({ variant: "outlineDark", size: "sm" }), "flex-1 px-3");

    return (
        <>
            {state === "ask" && !open && (
                <div role="region" aria-label={COOKIES.preferencias}
                    className="on-dark fixed bottom-4 left-4 right-4 z-50 max-w-[24rem] rounded-sm border border-line-dark p-5 shadow-paper sm:right-auto">
                    <p className="text-[0.95rem] leading-relaxed">{COOKIES.texto} <Link href="/cookies" className="link-underline text-mist">{COOKIES.saberMais}</Link></p>
                    {process.env.NODE_ENV !== "production" && devNoIds && (
                        <p className="mt-2 text-xs text-mist">Dev: sem IDs do GA4 nem do Pixel no perfil; em produção, este cartão só aparece com algum deles.</p>
                    )}
                    <div className="mt-4 flex gap-2">
                        <button type="button" className={same} onClick={() => choose(true, true)}>{COOKIES.aceitar}</button>
                        <button type="button" className={same} onClick={() => choose(false, false)}>{COOKIES.recusar}</button>
                        <button type="button" className={same} onClick={() => setOpen(true)}>{COOKIES.personalizar}</button>
                    </div>
                </div>
            )}
            {state === "done" && (
                <button type="button" onClick={() => setOpen(true)} aria-label={COOKIES.preferencias} title={COOKIES.preferencias}
                    className="fixed bottom-4 left-4 z-40 inline-flex h-11 w-11 items-center justify-center rounded-full border border-line bg-cream text-navy shadow-paper">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 3a9 9 0 1 0 9 9 3 3 0 0 1-3-3 3 3 0 0 1-3-3 3 3 0 0 1-3-3Z" stroke="currentColor" strokeWidth="1.5" strokeLinejoin="round" />
                        <circle cx="8.5" cy="11" r="1" fill="currentColor" /><circle cx="12" cy="16" r="1" fill="currentColor" /><circle cx="15.5" cy="13" r="1" fill="currentColor" />
                    </svg>
                </button>
            )}
            <Dialog.Root open={open} onOpenChange={setOpen}>
                <Dialog.Portal>
                    <Dialog.Overlay className="fixed inset-0 z-50 bg-navy/50" />
                    <Dialog.Content className="fixed inset-x-4 bottom-4 z-50 mx-auto max-w-lg rounded-sm bg-cream p-6 text-navy shadow-paper outline-none sm:inset-x-auto sm:left-1/2 sm:top-1/2 sm:bottom-auto sm:w-full sm:-translate-x-1/2 sm:-translate-y-1/2">
                        <Dialog.Title className="font-display text-h3">{COOKIES.dialogoTitulo}</Dialog.Title>
                        <Dialog.Description className="muted mt-2 text-[0.95rem]">{COOKIES.dialogoTexto}</Dialog.Description>
                        <div className="mt-5 divide-y divide-line border-y border-line">
                            <Category title={COOKIES.necessariosTitulo} text={COOKIES.necessariosTexto} locked />
                            {showStats && <Category title={COOKIES.estatisticaTitulo} text={COOKIES.estatisticaTexto} checked={stats} onChange={setStats} />}
                            {showMkt && <Category title={COOKIES.marketingTitulo} text={COOKIES.marketingTexto} checked={mkt} onChange={setMkt} />}
                        </div>
                        <div className="mt-6 flex flex-col gap-2 sm:flex-row">
                            <button type="button" className={cn(buttonVariants({ variant: "outline", size: "sm" }), "w-full sm:w-auto sm:flex-1")} onClick={() => choose(false, false)}>{COOKIES.recusarTodos}</button>
                            <button type="button" className={cn(buttonVariants({ variant: "outline", size: "sm" }), "w-full sm:w-auto sm:flex-1")} onClick={() => choose(true, true)}>{COOKIES.aceitarTodos}</button>
                            <button type="button" className={cn(buttonVariants({ variant: "outline", size: "sm" }), "w-full sm:w-auto sm:flex-1")} onClick={() => choose(stats, mkt)}>{COOKIES.guardar}</button>
                        </div>
                    </Dialog.Content>
                </Dialog.Portal>
            </Dialog.Root>
        </>
    );
}

function Category({ title, text, checked, onChange, locked }: { title: string; text: string; checked?: boolean; onChange?: (v: boolean) => void; locked?: boolean }) {
    const id = `cookie-${title}`;
    return (
        <div className="flex items-start justify-between gap-4 py-4">
            <div>
                <label htmlFor={id} className="font-semibold">{title}</label>
                <p className="muted mt-1 text-sm leading-relaxed">{text}</p>
            </div>
            {locked ? <span className="muted shrink-0 pt-0.5 text-sm">{COOKIES.sempreAtivos}</span> : (
                <Switch.Root id={id} checked={checked} onCheckedChange={onChange}
                    className="relative mt-0.5 h-6 w-11 shrink-0 rounded-full border border-navy/40 bg-cream-2 transition-colors data-[state=checked]:border-navy data-[state=checked]:bg-navy">
                    <Switch.Thumb className="block h-4 w-4 translate-x-1 rounded-full bg-navy transition-transform data-[state=checked]:translate-x-[1.35rem] data-[state=checked]:bg-amber" />
                </Switch.Root>
            )}
        </div>
    );
}

/** A ligação "Preferências de cookies" (rodapé, política de cookies). */
export function CookiePreferencesLink({ className, label = COOKIES.preferencias }: { className?: string; label?: string }) {
    return <button type="button" className={className} onClick={() => window.dispatchEvent(new CustomEvent(OPEN_PREFERENCES_EVENT))}>{label}</button>;
}
