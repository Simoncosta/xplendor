import { useCallback, useEffect, useRef, useState } from "react";
import QuotePublicView, { AcceptInput, ActionResult } from "./QuotePublicView";
import logo from "./xplendor-x.png";
import "./quote-public.css";
import type { QuotePublicData } from "common/models/quotePublic.model";
import { readTeamMarker } from "helpers/teamMarker";

/**
 * /app/orcamento#<token> (sem login). O token vem no fragmento do URL (nunca chega ao
 * servidor, aos registos de acesso nem ao Referer) e é enviado à API no cabeçalho
 * X-Quote-Token. Carrega a versão do link, envia o sinal de abertura depois de a página
 * carregar e estar visível (os robôs de pré-visualização não correm isto), e regista as
 * respostas do cliente. Sem indexação e sem Referer (meta robots e referrer, e os
 * cabeçalhos da API).
 */

const PUBLIC_URL = process.env.REACT_APP_PUBLIC_URL ?? "";
const VISITOR_KEY = "xpl_quote_visitor";
const OPEN_DELAY_MS = 1500;

/** Identificador aleatório deste browser (para juntar recarregamentos na mesma visita). */
function visitorId(): string {
    const fresh = () => {
        const bytes = new Uint8Array(24);
        (window.crypto || (window as any).msCrypto).getRandomValues(bytes);
        return Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("");
    };
    try {
        const saved = localStorage.getItem(VISITOR_KEY);
        if (saved && /^[A-Za-z0-9_-]{16,64}$/.test(saved)) return saved;
        const id = fresh();
        localStorage.setItem(VISITOR_KEY, id);
        return id;
    } catch {
        return fresh();
    }
}

/** O token do fragmento (#), sem o "#". */
const tokenFromHash = () => window.location.hash.replace(/^#/, "").trim();

async function call(token: string, path: string, body?: unknown): Promise<{ status: number; json: any }> {
    const r = await fetch(`${PUBLIC_URL}/api/public/quote${path}`, {
        method: body === undefined ? "GET" : "POST",
        referrerPolicy: "no-referrer",
        headers: { Accept: "application/json", "X-Quote-Token": token, ...(body === undefined ? {} : { "Content-Type": "application/json" }) },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    let json: any = null;
    try { json = await r.json(); } catch { /* sem corpo */ }
    return { status: r.status, json };
}

function failure(status: number, json: any): ActionResult {
    if (status === 422 && json?.errors) {
        const fields = Object.fromEntries(Object.entries(json.errors).map(([k, v]) => [k.split(".")[0], Array.isArray(v) ? String(v[0]) : String(v)]));
        return { ok: false, message: json?.message ?? "Verifique os dados.", fields };
    }
    if (status === 429) return { ok: false, message: "Demasiados pedidos seguidos. Tente de novo dentro de um minuto." };
    return { ok: false, message: json?.message ?? "Não foi possível registar a resposta. Tente de novo dentro de momentos." };
}

export default function QuotePublicPage() {
    const [token] = useState(tokenFromHash);
    const [data, setData] = useState<QuotePublicData | null>(null);
    const [status, setStatus] = useState<"loading" | "ready" | "notfound" | "error">("loading");
    const openSent = useRef(false);

    // Sem indexação e sem Referer (o link nunca passa para outros sites).
    useEffect(() => {
        const metas = [["robots", "noindex, nofollow, noarchive"], ["referrer", "no-referrer"]].map(([name, content]) => {
            const meta = document.createElement("meta");
            meta.name = name;
            meta.content = content;
            document.head.appendChild(meta);
            return meta;
        });
        return () => { metas.forEach((m) => document.head.removeChild(m)); };
    }, []);

    const load = useCallback(async () => {
        if (!/^[A-Za-z0-9]{64}$/.test(token)) { setStatus("notfound"); return; }
        try {
            const r = await call(token, "");
            if (r.status === 200 && r.json?.data) {
                setData(r.json.data);
                setStatus("ready");
                document.title = `Orçamento ${r.json.data.number} | XPLENDOR`;
            } else {
                setStatus(r.status === 404 ? "notfound" : "error");
            }
        } catch {
            setStatus("error");
        }
    }, [token]);

    useEffect(() => { void load(); }, [load]);

    // Sinal de abertura: uma vez por carregamento, depois de a página estar visível.
    useEffect(() => {
        if (status !== "ready" || openSent.current) return;
        let timer: ReturnType<typeof setTimeout> | undefined;
        const send = () => {
            if (openSent.current) return;
            openSent.current = true;
            fetch(`${PUBLIC_URL}/api/public/quote/open`, {
                method: "POST",
                referrerPolicy: "no-referrer",
                headers: { Accept: "application/json", "Content-Type": "application/json", "X-Quote-Token": token },
                body: JSON.stringify({ visitor_id: visitorId(), team_marker: readTeamMarker() }),
                keepalive: true,
            }).catch(() => { /* a abertura não é crítica */ });
        };
        const schedule = () => {
            if (timer) clearTimeout(timer);
            if (document.visibilityState === "visible") timer = setTimeout(send, OPEN_DELAY_MS);
        };
        schedule();
        document.addEventListener("visibilitychange", schedule);
        return () => {
            if (timer) clearTimeout(timer);
            document.removeEventListener("visibilitychange", schedule);
        };
    }, [status, token]);

    /** O PDF também pede o token no cabeçalho: descarrega-se por fetch e abre-se localmente. */
    const downloadPdf = async () => {
        try {
            const r = await fetch(`${PUBLIC_URL}/api/public/quote/pdf`, { referrerPolicy: "no-referrer", headers: { "X-Quote-Token": token } });
            if (!r.ok) throw new Error();
            const url = URL.createObjectURL(await r.blob());
            const a = document.createElement("a");
            a.href = url;
            a.download = `orcamento-${data?.number ?? ""}.pdf`.replace(/-\.pdf$/, ".pdf");
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(url), 60000);
        } catch {
            alert("Não foi possível descarregar o PDF. Tente de novo dentro de momentos.");
        }
    };

    const respond = async (path: string, body: unknown): Promise<ActionResult> => {
        try {
            const r = await call(token, path, body);
            if (r.status === 200 && r.json?.data) {
                setData(r.json.data);
                return { ok: true };
            }
            if (r.status === 409) void load(); // o estado mudou entretanto: mostrar o atual
            return failure(r.status, r.json);
        } catch {
            return { ok: false, message: "Sem ligação. Verifique a internet e tente de novo." };
        }
    };

    if (status === "loading") {
        return <div className="qp" data-bs-theme="light"><div className="qp-paper"><div className="qp-loading">A carregar o orçamento…</div></div></div>;
    }
    if (status !== "ready" || !data) {
        const notFound = status === "notfound";
        return (
            <div className="qp" data-bs-theme="light">
                <div className="qp-paper">
                    <div className="qp-head"><div className="qp-brand"><img src={logo} alt="" /><span>XPLENDOR</span></div></div>
                    <div className="qp-result">
                        <div className="qp-result-icon is-grey" aria-hidden>?</div>
                        <h1>{notFound ? "Este link não é válido" : "Não foi possível abrir o orçamento"}</h1>
                        <p>{notFound
                            ? "Confirme o link que recebeu ou peça um novo à equipa XPLENDOR."
                            : "Tente de novo dentro de momentos."}</p>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <QuotePublicView
            data={data}
            onDownloadPdf={downloadPdf}
            onAccept={(input: AcceptInput) => respond("/accept", input)}
            onRefuse={(reason: string) => respond("/refuse", { reason: reason || null })}
            onRequestChanges={(message: string) => respond("/request-changes", { message })}
        />
    );
}
