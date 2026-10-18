import type { ReactNode } from "react";

/** O início de uma página interior: o título grande à esquerda e o texto de abertura. */
export function PageIntro({ title, lead, children }: { title: string; lead?: string; children?: ReactNode }) {
    return (
        <section className="container-site pb-12 pt-14 md:pb-20 md:pt-24">
            <h1 className="text-display max-w-[16ch]">{title}</h1>
            <span className="accent-rule" aria-hidden="true" />
            {lead && <p className="text-lead muted mt-8 max-w-[52ch]">{lead}</p>}
            {children}
        </section>
    );
}

/** Um marcador visível de um dado em falta (só quando falta; um site publicado tem-nos todos). */
export function Missing({ what }: { what: string }) {
    return <mark className="marker">{`[por indicar: ${what}]`}</mark>;
}
