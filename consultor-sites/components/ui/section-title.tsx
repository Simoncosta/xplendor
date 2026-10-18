import type { ReactNode } from "react";
import { cn } from "@/lib/cn";

/** O título de uma secção: alinhado à esquerda, em Fraunces, com a régua âmbar por baixo (nunca uma etiqueta por cima). */
export function SectionTitle({ children, id, className, as: Tag = "h2" }: { children: ReactNode; id?: string; className?: string; as?: "h1" | "h2" }) {
    return (
        <div className={cn("max-w-[22ch]", className)}>
            <Tag id={id} className={Tag === "h1" ? "text-display" : "text-h2"}>{children}</Tag>
            <span className="accent-rule" aria-hidden="true" />
        </div>
    );
}
