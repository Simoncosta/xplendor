import Link from "next/link";
import { NAO_ENCONTRADO } from "@/content/textos";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/cn";

/** A página 404 (também para um host que não é de nenhum site). */
export default function NotFound() {
    return (
        <section className="container-site flex min-h-[70svh] flex-col justify-center py-24">
            <p className="font-display text-numeral text-slate" aria-hidden="true">404</p>
            <h1 className="text-h2 mt-6 max-w-[18ch]">{NAO_ENCONTRADO.titulo}</h1>
            <span className="accent-rule" aria-hidden="true" />
            <p className="text-lead muted mt-8 max-w-[44ch]">{NAO_ENCONTRADO.texto}</p>
            <div className="mt-10 flex flex-col gap-3 sm:flex-row">
                <Link href="/" className={cn(buttonVariants({ variant: "primary", size: "md" }))}>{NAO_ENCONTRADO.inicio}</Link>
                <Link href="/contacto" className={cn(buttonVariants({ variant: "outline", size: "md" }))}>{NAO_ENCONTRADO.contacto}</Link>
            </div>
        </section>
    );
}
