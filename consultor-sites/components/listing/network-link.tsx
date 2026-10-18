"use client";

import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/cn";
import { track } from "@/lib/track";

/** "Ver na {rede}": a página do anúncio no site da rede, numa nova aba, contada (sem cookies). */
export function NetworkLink({ href, label, subject, slug, className }: { href: string; label: string; subject: "property" | "development"; slug: string; className?: string }) {
    return (
        <a href={href} target="_blank" rel="noopener noreferrer" onClick={() => track("chave_nova_click", subject, slug)}
            className={cn(buttonVariants({ variant: "outline", size: "md" }), className)}>
            {label}
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 17L17 7M9 7h8v8" stroke="currentColor" strokeWidth="1.8" /></svg>
        </a>
    );
}
