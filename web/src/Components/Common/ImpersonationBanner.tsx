import { useState } from "react";
import { readAuthUser, isImpersonating, stopImpersonationFlow } from "helpers/impersonation";

/**
 * XPLENDOR — IMPERSONATION: barra de ALERTA persistente (todas as páginas) enquanto se está
 * "na pele" de um utilizador. Nunca deixar esquecer. Botão "Sair da impersonation". A verdade
 * é do backend (reconciliação no Layout); aqui só refletimos o authUser.
 */
export default function ImpersonationBanner() {
    const [leaving, setLeaving] = useState(false);
    if (!isImpersonating()) return null;

    const u = readAuthUser();
    const name = u?.name ?? "utilizador";
    const company = u?.impersonation_company?.name ?? u?.company_id;

    const leave = async () => { setLeaving(true); try { await stopImpersonationFlow(); } catch { setLeaving(false); } };

    return (
        <div
            role="alert"
            className="d-flex align-items-center justify-content-center gap-2 px-3 text-white"
            style={{ background: "var(--vz-danger)", position: "sticky", top: 0, zIndex: 1057, fontSize: "0.75rem", lineHeight: 1, minHeight: 24, padding: "3px 12px" }}
        >
            <i className="ri-eye-line" />
            <span>
                Estás a ver como <strong>{name}</strong>{company ? <> — <strong>{company}</strong></> : null}
            </span>
            <button
                type="button"
                className="btn btn-light btn-sm py-0 px-2 ms-2"
                style={{ fontSize: "0.72rem", lineHeight: 1.4 }}
                disabled={leaving}
                onClick={leave}
            >
                {leaving ? "A sair…" : "Sair"}
            </button>
        </div>
    );
}
