import React from "react";
import useDelayedFlag from "hooks/useDelayedFlag";

/**
 * E-mail 4b · Os esqueletos da caixa de correio: enquanto as pastas, a lista ou uma conversa
 * carregam, a página mantém o desenho e mostra linhas cinzentas (os placeholders do Bootstrap e do
 * Velzon, como o Components/Common/Skeleton), em vez de ficar vazia com um spinner. Só aparecem
 * depois de ~200 ms (antes ocupam o espaço, invisíveis), para não piscar em respostas rápidas.
 */

const bar = (style: React.CSSProperties, className = "") => <span className={`placeholder xp-skeleton-bar ${className}`} style={style} />;

function Wrap({ testId, children, className = "", as = "div" }: { testId: string; children: React.ReactNode; className?: string; as?: "div" | "ul" }) {
    const visible = useDelayedFlag(true, 200);
    const Tag = as as any;
    return (
        <Tag className={`xp-skeleton placeholder-glow ${visible ? "" : "xp-skeleton-pending"} ${className}`} aria-busy="true" data-testid={testId}>
            {as === "div" && <span className="visually-hidden">A carregar…</span>}
            {children}
        </Tag>
    );
}

/** As pastas, na barra da caixa (o mesmo lugar e o mesmo número de linhas que a lista final costuma ter). */
export function FoldersSkeleton() {
    return (
        <Wrap testId="mail-skeleton-folders" className="mail-list mt-3">
            {[70, 60, 66, 48, 40, 58].map((w, i) => (
                <div key={i} className="d-flex align-items-center gap-3 py-2">
                    {bar({ width: 14, height: 14 }, "rounded")}
                    {bar({ width: `${w}%`, height: 10 })}
                </div>
            ))}
        </Wrap>
    );
}

/** As linhas da lista, com as colunas do message-list (remetente, assunto, data). */
export function ListSkeleton({ rows = 8 }: { rows?: number }) {
    return (
        <Wrap testId="mail-skeleton-list" as="ul" className="message-list">
            {Array.from({ length: rows }).map((_, i) => (
                <li key={i} aria-hidden="true">
                    <div className="col-mail col-mail-1" style={{ height: "100%" }}>
                        <span className="title d-flex align-items-center h-100">{bar({ width: `${55 + (i % 3) * 12}%`, height: 10 })}</span>
                    </div>
                    <div className="col-mail col-mail-2">
                        <span className="subject d-flex align-items-center h-100" style={{ right: 140 }}>{bar({ width: `${45 + ((i * 7) % 40)}%`, height: 10 })}</span>
                        <div className="date d-flex align-items-center justify-content-end h-100" style={{ width: 130 }}>{bar({ width: 44, height: 10 })}</div>
                    </div>
                </li>
            ))}
        </Wrap>
    );
}

/** Uma conversa a abrir: o assunto, o cabeçalho de duas mensagens e as linhas do texto. */
export function ThreadSkeleton() {
    return (
        <Wrap testId="mail-skeleton-thread" className="mt-3">
            <div className="mb-4">{bar({ width: "55%", height: 18 })}</div>
            {[0, 1].map((m) => (
                <div key={m} className="border-bottom py-3">
                    <div className="d-flex align-items-start gap-3">
                        {bar({ width: 32, height: 32 }, "rounded-circle flex-shrink-0")}
                        <div className="flex-grow-1 d-flex flex-column gap-2">
                            {bar({ width: "28%", height: 10 })}
                            {bar({ width: "40%", height: 8 })}
                        </div>
                        {bar({ width: 90, height: 8 })}
                    </div>
                    {m === 1 && (
                        <div className="d-flex flex-column gap-2 mt-3" style={{ paddingLeft: 44 }}>
                            {["96%", "88%", "92%", "70%", "84%", "45%"].map((w, i) => <React.Fragment key={i}>{bar({ width: w, height: 10 })}</React.Fragment>)}
                        </div>
                    )}
                </div>
            ))}
        </Wrap>
    );
}

/** A secção "Conversa" da ficha do cliente e da venda, enquanto carrega. */
export function ConversationSkeleton() {
    return (
        <Wrap testId="mail-skeleton-conversation" className="vstack gap-2">
            {[0, 1, 2].map((i) => (
                <div key={i} className="border rounded p-2 d-flex flex-column gap-2" aria-hidden="true">
                    <div className="d-flex align-items-center gap-2">
                        {bar({ width: 16, height: 16 }, "rounded-circle")}
                        {bar({ width: `${50 + i * 10}%`, height: 10 })}
                        <span className="ms-auto">{bar({ width: 70, height: 8 })}</span>
                    </div>
                    {bar({ width: "35%", height: 8 })}
                </div>
            ))}
        </Wrap>
    );
}
