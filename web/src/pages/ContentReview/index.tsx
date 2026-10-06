import { useCallback, useEffect, useRef, useState } from "react";
import ContentReviewView, { ActionResult } from "./ContentReviewView";
import type { ReviewPayload } from "common/models/contentReview.model";
import { readTeamMarker } from "helpers/teamMarker";
import "./content-review.css";

/**
 * /app/aprovar#<token> (sem conta): o link de aprovação de um lote de publicações. Mesmo
 * padrão do orçamento: o token vem no fragmento do URL (nunca chega ao servidor, aos
 * registos de acesso nem ao Referer) e vai à API no cabeçalho X-Review-Token; sinal de
 * abertura só depois de a página carregar e estar visível; sem indexação e sem Referer.
 * Segue o tema claro ou escuro do telemóvel, fixo no contentor da página.
 */

const PUBLIC_URL = process.env.REACT_APP_PUBLIC_URL ?? "";
const VISITOR_KEY = "xpl_review_visitor";
const NAME_KEY = "xpl_review_name";
const OPEN_DELAY_MS = 1500;

function visitorId(): string {
    const fresh = () => {
        const bytes = new Uint8Array(24);
        window.crypto.getRandomValues(bytes);
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

const tokenFromHash = () => window.location.hash.replace(/^#/, "").trim();

async function call(token: string, path: string, body?: unknown): Promise<{ status: number; json: any }> {
    const r = await fetch(`${PUBLIC_URL}/api/public/review${path}`, {
        method: body === undefined ? "GET" : "POST",
        referrerPolicy: "no-referrer",
        headers: { Accept: "application/json", "X-Review-Token": token, ...(body === undefined ? {} : { "Content-Type": "application/json" }) },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    let json: any = null;
    try { json = await r.json(); } catch { /* sem corpo */ }
    return { status: r.status, json };
}

function failure(status: number, json: any): ActionResult {
    if (status === 422 && json?.errors) {
        const first = Object.values(json.errors).flat()[0];
        return { ok: false, message: String(first ?? json?.message ?? "Verifique os dados.") };
    }
    if (status === 429) return { ok: false, message: "Demasiados pedidos seguidos. Tente de novo dentro de um minuto." };
    return { ok: false, message: json?.message ?? "Não foi possível registar. Tente de novo dentro de momentos." };
}

export default function ContentReviewPage() {
    const [token] = useState(tokenFromHash);
    const [data, setData] = useState<ReviewPayload | null>(null);
    const [status, setStatus] = useState<"loading" | "ready" | "notfound" | "error">("loading");
    const [name, setName] = useState(() => { try { return localStorage.getItem(NAME_KEY) ?? ""; } catch { return ""; } });
    const openSent = useRef(false);
    const [theme, setTheme] = useState<"light" | "dark">(() => (window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light"));

    // Sem indexação, sem Referer e com o tema do telemóvel (repostos ao sair).
    useEffect(() => {
        const metas = [["robots", "noindex, nofollow, noarchive"], ["referrer", "no-referrer"]].map(([n, content]) => {
            const meta = document.createElement("meta");
            meta.name = n;
            meta.content = content;
            document.head.appendChild(meta);
            return meta;
        });
        // O tema vai no contentor da página (data-bs-theme), não no <html> nem no <body>: o
        // layout das páginas públicas põe no <body> o tema da app, e os dois não podem chocar.
        const mq = window.matchMedia("(prefers-color-scheme: dark)");
        const apply = () => setTheme(mq.matches ? "dark" : "light");
        mq.addEventListener("change", apply);
        return () => {
            metas.forEach((m) => document.head.removeChild(m));
            mq.removeEventListener("change", apply);
        };
    }, []);

    const load = useCallback(async () => {
        if (!/^[A-Za-z0-9]{64}$/.test(token)) { setStatus("notfound"); return; }
        try {
            const r = await call(token, "");
            if (r.status === 200 && r.json?.data) {
                setData(r.json.data);
                setStatus("ready");
                document.title = `Aprovar: ${r.json.data.title} | ${r.json.data.company.name}`;
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
            fetch(`${PUBLIC_URL}/api/public/review/open`, {
                method: "POST",
                referrerPolicy: "no-referrer",
                headers: { Accept: "application/json", "Content-Type": "application/json", "X-Review-Token": token },
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

    const onName = (v: string) => {
        setName(v);
        try { localStorage.setItem(NAME_KEY, v); } catch { /* armazenamento indisponível */ }
    };

    const respond = async (path: string, body: Record<string, unknown>): Promise<ActionResult> => {
        try {
            const r = await call(token, path, { name: name.trim(), ...body });
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

    if (status !== "ready" || !data) {
        return (
            <div className="cr-page" data-bs-theme={theme}>
                <div className="cr-wrap text-center pt-5">
                    {status === "loading" ? <p className="text-muted">A carregar…</p> : (
                        <>
                            <h1 className="fs-4">{status === "notfound" ? "Este link não é válido" : "Não foi possível abrir o link"}</h1>
                            <p className="text-muted">{status === "notfound" ? "Confirme o link que recebeu ou peça um novo à equipa." : "Tente de novo dentro de momentos."}</p>
                        </>
                    )}
                </div>
            </div>
        );
    }

    return (
        <ContentReviewView data={data} name={name} onName={onName} theme={theme} actions={{
            approve: (id) => respond(`/items/${id}/approve`, {}),
            requestChanges: (id, message) => respond(`/items/${id}/request-changes`, { message }),
            comment: (id, body) => respond(`/items/${id}/comments`, { body }),
            approveAll: () => respond("/approve-all", {}),
        }} />
    );
}
