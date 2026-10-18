"use client";

import Link from "next/link";
import { useId, useState } from "react";
import { FORMULARIO } from "@/content/textos";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/cn";
import { readOrigin } from "@/lib/origin";

/**
 * O formulário de contacto geral (tipo "contacto"). Vai para o servidor do site (/api/contacto),
 * que o envia à XPLENDOR com o token do contentor; o navegador nunca fala com a XPLENDOR. Leva os
 * consentimentos separados (privacidade obrigatório, marketing opcional), a origem da visita e o
 * event_id (partilhado com o Pixel no F6). O campo "website" é o honeypot.
 */
export function ContactForm({ tone = "light", defaultMessage = "", subject, className, kind = "contacto", propertySlug, developmentSlug, submitLabel }: {
    tone?: "light" | "dark"; defaultMessage?: string; subject?: string; className?: string;
    /** "visita": o pedido de visita de um imóvel ou de um empreendimento (com a disponibilidade). */
    kind?: "contacto" | "visita"; propertySlug?: string; developmentSlug?: string; submitLabel?: string;
}) {
    const id = useId();
    const [state, setState] = useState<"idle" | "sending" | "sent" | "preview">("idle");
    const [error, setError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const dark = tone === "dark";

    async function submit(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const form = new FormData(e.currentTarget);
        const name = String(form.get("name") ?? "").trim();
        const email = String(form.get("email") ?? "").trim();
        const phone = String(form.get("phone") ?? "").trim();
        const errs: Record<string, string> = {};
        if (name.length < 2) errs.name = FORMULARIO.nomeEmFalta;
        if (!email && !phone) errs.email = FORMULARIO.emailOuTelefone;
        if (!form.get("consent_privacy")) errs.consent_privacy = FORMULARIO.privacidadeEmFalta;
        setFieldErrors(errs);
        if (Object.keys(errs).length) return;
        setState("sending");
        setError(null);
        try {
            const res = await fetch("/api/contacto", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    type: kind, property_slug: propertySlug, development_slug: developmentSlug,
                    preferred_time: kind === "visita" ? String(form.get("preferred_time") ?? "").trim() || undefined : undefined,
                    name, email: email || null, phone: phone || null, message: String(form.get("message") ?? "").trim() || null,
                    consent_privacy: true, consent_marketing: form.get("consent_marketing") === "on", website: String(form.get("website") ?? ""),
                    subject: subject ?? null, event_id: crypto.randomUUID(), origin: { ...readOrigin(), page_path: window.location.pathname },
                }),
            });
            const data = await res.json().catch(() => ({}));
            if (res.status === 202 && data?.preview) { setState("preview"); return; }
            if (res.ok) { setState("sent"); return; }
            if (res.status === 422 && data?.errors) {
                setFieldErrors(Object.fromEntries(Object.entries(data.errors as Record<string, string[]>).map(([k, v]) => [k, v[0]])));
                setState("idle");
                return;
            }
            setError(res.status === 429 ? FORMULARIO.demasiados : FORMULARIO.erro);
            setState("idle");
        } catch {
            setError(FORMULARIO.erro);
            setState("idle");
        }
    }

    if (state === "sent" || state === "preview") {
        return (
            <div role="status" className={cn("border-l-2 border-amber py-2 pl-5", className)}>
                {state === "sent" ? <><p className="font-display text-h3">{FORMULARIO.sucessoTitulo}</p><p className="mt-2">{kind === "visita" ? FORMULARIO.sucessoVisita : FORMULARIO.sucesso}</p></> : <p>{FORMULARIO.preview}</p>}
            </div>
        );
    }

    const field = cn("mt-2 block w-full rounded-xs border px-4 py-3 text-base outline-none transition-colors",
        dark ? "border-line-dark bg-white/5 text-cream placeholder:text-mist focus:border-amber" : "border-navy/25 bg-white/60 text-navy focus:border-navy");
    const label = "text-sm font-semibold";
    const err = (k: string) => fieldErrors[k] && <p id={`${id}-${k}-erro`} className={cn("mt-1.5 text-sm", dark ? "text-amber" : "text-[#9a2b12]")}>{fieldErrors[k]}</p>;

    return (
        <form onSubmit={submit} noValidate className={cn("grid gap-5 sm:grid-cols-2", className)} data-contact-form>
            <div className="sm:col-span-2">
                <label htmlFor={`${id}-name`} className={label}>{FORMULARIO.nome}</label>
                <input id={`${id}-name`} name="name" autoComplete="name" maxLength={120} required className={field}
                    aria-invalid={!!fieldErrors.name} aria-describedby={fieldErrors.name ? `${id}-name-erro` : undefined} />
                {err("name")}
            </div>
            <div>
                <label htmlFor={`${id}-email`} className={label}>{FORMULARIO.email}</label>
                <input id={`${id}-email`} name="email" type="email" autoComplete="email" maxLength={160} className={field}
                    aria-invalid={!!fieldErrors.email} aria-describedby={fieldErrors.email ? `${id}-email-erro` : undefined} />
                {err("email")}
            </div>
            <div>
                <label htmlFor={`${id}-phone`} className={label}>{FORMULARIO.telefone}</label>
                <input id={`${id}-phone`} name="phone" type="tel" autoComplete="tel" maxLength={30} className={field}
                    aria-invalid={!!fieldErrors.phone} aria-describedby={fieldErrors.phone ? `${id}-phone-erro` : undefined} />
                {err("phone")}
            </div>
            {kind === "visita" && (
                <div className="sm:col-span-2">
                    <label htmlFor={`${id}-preferred`} className={label}>{FORMULARIO.disponibilidade}</label>
                    <input id={`${id}-preferred`} name="preferred_time" maxLength={120} placeholder={FORMULARIO.disponibilidadeExemplo} className={field} />
                </div>
            )}
            <div className="sm:col-span-2">
                <label htmlFor={`${id}-message`} className={label}>{FORMULARIO.mensagem}</label>
                <textarea id={`${id}-message`} name="message" rows={4} maxLength={2000} defaultValue={defaultMessage} className={cn(field, "resize-y")} />
            </div>
            <div className="hidden" aria-hidden="true">
                <label htmlFor={`${id}-website`}>Website</label>
                <input id={`${id}-website`} name="website" tabIndex={-1} autoComplete="off" />
            </div>
            <div className="space-y-3 text-[0.95rem] sm:col-span-2">
                <label className="flex items-start gap-3">
                    <input type="checkbox" name="consent_privacy" required className="mt-1 h-4 w-4 shrink-0 accent-amber"
                        aria-invalid={!!fieldErrors.consent_privacy} aria-describedby={fieldErrors.consent_privacy ? `${id}-consent_privacy-erro` : undefined} />
                    <span>{FORMULARIO.privacidadeAntes}<Link href="/privacidade" className="link-underline font-semibold" target="_blank">{FORMULARIO.privacidadeLigacao}</Link>{FORMULARIO.privacidadeDepois}</span>
                </label>
                {err("consent_privacy")}
                <label className="flex items-start gap-3">
                    <input type="checkbox" name="consent_marketing" className="mt-1 h-4 w-4 shrink-0 accent-amber" />
                    <span className={dark ? "text-mist" : "muted"}>{FORMULARIO.marketing}</span>
                </label>
            </div>
            {error && <p role="alert" className={cn("text-sm sm:col-span-2", dark ? "text-amber" : "text-[#9a2b12]")}>{error}</p>}
            <div className="sm:col-span-2">
                <Button type="submit" size="lg" disabled={state === "sending"} className="w-full sm:w-auto">
                    {state === "sending" ? FORMULARIO.aEnviar : (submitLabel ?? FORMULARIO.enviar)}
                </Button>
            </div>
        </form>
    );
}
