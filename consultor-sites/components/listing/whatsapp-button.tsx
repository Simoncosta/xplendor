"use client";

import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/cn";
import { track } from "@/lib/track";
import { whatsappHref } from "@/lib/whatsapp";

/**
 * "Falar pelo WhatsApp" na ficha de um imóvel ou de um empreendimento: a mensagem do perfil já com a
 * referência (ou o nome do empreendimento), contada como evento (whatsapp_click).
 */
export function WhatsAppButton({ number, message, label, subject, slug, className }: {
    number: string; message: string; label: string; subject: "property" | "development"; slug: string; className?: string;
}) {
    return (
        <a href={whatsappHref(number, message)} target="_blank" rel="noopener noreferrer" onClick={() => track("whatsapp_click", subject, slug)}
            className={cn(buttonVariants({ variant: "outline", size: "lg" }), "gap-3", className)}>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.86 9.86 0 0 0 4.74 1.21h.01c5.46 0 9.9-4.45 9.9-9.91A9.85 9.85 0 0 0 12.04 2Zm5.8 14.06c-.24.68-1.41 1.3-1.95 1.38-.5.07-1.13.1-1.82-.12-.42-.13-.96-.31-1.65-.61-2.9-1.25-4.8-4.17-4.94-4.36-.14-.2-1.18-1.57-1.18-3s.75-2.13 1.02-2.42c.26-.29.58-.36.77-.36h.55c.18.01.42-.07.66.5.24.58.82 2.01.89 2.16.07.14.12.31.02.5-.1.2-.14.31-.29.48-.14.17-.3.38-.43.51-.14.14-.29.3-.13.59.17.29.74 1.22 1.59 1.98 1.09.97 2.01 1.27 2.3 1.42.29.14.46.12.63-.07.17-.2.72-.84.91-1.13.19-.29.38-.24.65-.14.26.1 1.68.79 1.97.93.29.15.48.22.55.34.07.12.07.7-.17 1.38Z" /></svg>
            {label}
        </a>
    );
}
