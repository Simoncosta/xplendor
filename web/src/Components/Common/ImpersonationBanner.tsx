import { useEffect, useRef, useState } from "react";
import { readAuthUser, isImpersonating, stopImpersonationFlow } from "helpers/impersonation";

/**
 * XPLENDOR — IMPERSONATION: lembrete persistente (todas as páginas) enquanto se
 * está "na pele" de um utilizador. Nunca deixar esquecer.
 *
 * (Polish Fase C) — APRESENTAÇÃO migrada de barra no topo → bolinha flutuante no
 * canto ESQUERDO (espelho da bolinha de suporte à direita). A LÓGICA é a mesma:
 * a condição (isImpersonating), o "Sair" (stopImpersonationFlow) e a verdade do
 * backend (reconciliação no Layout) ficam INTACTAS — só muda a forma.
 *
 * Posição: alinha-se à esquerda do conteúdo (offset pela largura do menu lateral
 * via CSS em _xplendor-overrides.scss) para não sobrepor o menu; no mobile, onde
 * o menu está escondido, encosta a left:24. Cor de ALERTA (var(--vz-danger))
 * para continuar bem visível.
 */
export default function ImpersonationBanner() {
    const [leaving, setLeaving] = useState(false);
    const [open, setOpen] = useState(false);
    const wrapRef = useRef<HTMLDivElement>(null);

    // Fechar o popover: clique fora ou Esc. (Hooks antes do early-return para
    // respeitar as regras dos hooks.)
    useEffect(() => {
        if (!open) return;
        const onClick = (e: MouseEvent) => {
            if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setOpen(false);
        };
        const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") setOpen(false); };
        document.addEventListener("mousedown", onClick);
        document.addEventListener("keydown", onKey);
        return () => {
            document.removeEventListener("mousedown", onClick);
            document.removeEventListener("keydown", onKey);
        };
    }, [open]);

    if (!isImpersonating()) return null;

    const u = readAuthUser();
    const name = u?.name ?? "utilizador";
    const company = u?.impersonation_company?.name ?? u?.company_id;

    const leave = async () => {
        setLeaving(true);
        try { await stopImpersonationFlow(); } catch { setLeaving(false); }
    };

    return (
        <div ref={wrapRef} className="impersonation-fab-wrap">
            {open && (
                <div className="card impersonation-pop mb-0" role="dialog" aria-label="Impersonation">
                    <div className="card-body p-3">
                        <div className="d-flex align-items-start justify-content-between gap-2 mb-2">
                            <span className="badge bg-danger-subtle text-danger">
                                <i className="ri-eye-line align-bottom me-1" />
                                A ver como outra pessoa
                            </span>
                            <button
                                type="button"
                                className="btn-close fs-11"
                                aria-label="Fechar"
                                onClick={() => setOpen(false)}
                            />
                        </div>
                        <p className="mb-3 fs-13 text-body">
                            Estás a ver como <strong>{name}</strong>
                            {company ? <> — <strong>{company}</strong></> : null}
                        </p>
                        <button
                            type="button"
                            className="btn btn-danger btn-sm w-100"
                            disabled={leaving}
                            onClick={leave}
                        >
                            {leaving ? "A sair…" : <><i className="ri-logout-box-r-line align-bottom me-1" />Sair da impersonation</>}
                        </button>
                    </div>
                </div>
            )}

            <button
                type="button"
                className="impersonation-fab"
                aria-label="Estás em impersonation — ver detalhes"
                aria-expanded={open}
                title="Estás a ver como outra pessoa"
                onClick={() => setOpen((v) => !v)}
            >
                <i className="ri-eye-line" />
            </button>
        </div>
    );
}
