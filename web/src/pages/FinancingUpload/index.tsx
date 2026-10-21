import { useCallback, useEffect, useState } from "react";
import "./financing-upload.css";

/**
 * /app/enviar-documentos#<código> (sem conta): a página onde o cliente envia os documentos para
 * o financiamento. O código vem no fragmento (nunca chega ao servidor, aos registos nem ao
 * Referer) e vai à API no cabeçalho X-Financing-Token. Mostra a marca da empresa, a lista pedida
 * e o que já foi enviado neste link (o documento e a data); o cliente só envia: não vê nem
 * descarrega ficheiros. Antes do primeiro envio: o aviso de proteção de dados e, quando o CC é
 * pedido, o consentimento para a cópia (Lei n.º 7/2007, art. 5.º). Sem indexação.
 */

const PUBLIC_URL = process.env.REACT_APP_PUBLIC_URL ?? "";
const ACCEPT = ".pdf,.jpg,.jpeg,.png,.heic,.heif,application/pdf,image/jpeg,image/png,image/heic,image/heif";

interface Item { key: string; label: string; hint: string; sent: { name: string; sent_at: string | null; refused: boolean }[] }
export interface FinancingPublicPayload {
    company: { name: string; logo_url: string | null; fiscal_name: string | null; nipc: string | null };
    customer_first_name: string | null;
    expires_at: string;
    items: Item[];
    limits: { max_file_bytes: number; max_files: number; remaining_files: number; types: string[] };
    notice: { version: string; privacy: { title: string; paragraphs: string[] }; cc_copy: string | null; accepted: boolean };
}

const tokenFromHash = () => window.location.hash.replace(/^#/, "").trim();
const fmtDay = (s?: string | null) => (s ? new Date(s).toLocaleDateString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric" }) : "");

async function call(token: string, path: string, init: RequestInit = {}): Promise<{ status: number; json: any }> {
    const r = await fetch(`${PUBLIC_URL}/api/public/financing${path}`, {
        ...init,
        referrerPolicy: "no-referrer",
        headers: { Accept: "application/json", "X-Financing-Token": token, ...(init.headers ?? {}) },
    });
    let json: any = null;
    try { json = await r.json(); } catch { /* sem corpo */ }
    return { status: r.status, json };
}

function messageOf(status: number, json: any): string {
    if (status === 422 && json?.errors) return String(Object.values(json.errors).flat()[0] ?? json?.message ?? "Verifique o ficheiro.");
    if (status === 413) return "O ficheiro é demasiado grande.";
    if (status === 429) return "Demasiados envios seguidos. Tente de novo dentro de um minuto.";
    return json?.message ?? "Não foi possível enviar. Tente de novo dentro de momentos.";
}

export default function FinancingUploadPage() {
    const [token] = useState(tokenFromHash);
    const [data, setData] = useState<FinancingPublicPayload | null>(null);
    const [status, setStatus] = useState<"loading" | "ready" | "notfound" | "error">("loading");
    const [privacy, setPrivacy] = useState(false);
    const [ccCopy, setCcCopy] = useState(false);
    const [consentError, setConsentError] = useState<string | null>(null);
    const [sending, setSending] = useState<string | null>(null);
    const [itemMsg, setItemMsg] = useState<Record<string, { ok: boolean; text: string }>>({});

    useEffect(() => {
        const metas = [["robots", "noindex, nofollow, noarchive"], ["referrer", "no-referrer"]].map(([n, content]) => {
            const meta = document.createElement("meta");
            meta.name = n;
            meta.content = content;
            document.head.appendChild(meta);
            return meta;
        });
        return () => metas.forEach((m) => document.head.removeChild(m));
    }, []);

    const load = useCallback(async () => {
        if (!/^[A-Za-z0-9]{64}$/.test(token)) { setStatus("notfound"); return; }
        try {
            const r = await call(token, "");
            if (r.status === 200 && r.json?.data) {
                setData(r.json.data);
                setStatus("ready");
                document.title = `Enviar documentos | ${r.json.data.company.name}`;
            } else setStatus(r.status === 404 ? "notfound" : "error");
        } catch {
            setStatus("error");
        }
    }, [token]);

    useEffect(() => { void load(); }, [load]);

    const accept = async () => {
        setConsentError(null);
        const r = await call(token, "/consent", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ privacy, cc_copy: ccCopy }) });
        if (r.status === 200 && r.json?.data) setData(r.json.data);
        else if (r.status === 404) setStatus("notfound");
        else setConsentError(messageOf(r.status, r.json));
    };

    const send = async (item: Item, file: File) => {
        if (data && file.size > data.limits.max_file_bytes) {
            setItemMsg((m) => ({ ...m, [item.key]: { ok: false, text: `O ficheiro tem mais de ${Math.round(data.limits.max_file_bytes / 1048576)} MB.` } }));
            return;
        }
        setSending(item.key);
        setItemMsg((m) => ({ ...m, [item.key]: { ok: true, text: "" } }));
        const form = new FormData();
        form.append("kind", item.key);
        form.append("file", file);
        try {
            const r = await call(token, "/documents", { method: "POST", body: form });
            if (r.status === 201 && r.json?.data) {
                setData(r.json.data);
                setItemMsg((m) => ({ ...m, [item.key]: { ok: true, text: "Recebido. Obrigado." } }));
            } else if (r.status === 404) {
                setStatus("notfound");
            } else {
                setItemMsg((m) => ({ ...m, [item.key]: { ok: false, text: messageOf(r.status, r.json) } }));
            }
        } catch {
            setItemMsg((m) => ({ ...m, [item.key]: { ok: false, text: "Sem ligação. Verifique a internet e tente de novo." } }));
        } finally {
            setSending(null);
        }
    };

    if (status === "loading") return <div className="fu-page"><div className="fu-card fu-center" aria-busy="true">A carregar…</div></div>;
    if (status !== "ready" || !data) {
        return (
            <div className="fu-page">
                <div className="fu-card" data-testid="fu-gone">
                    <h1 className="fu-title">{status === "notfound" ? "Este link já não está disponível." : "Não foi possível abrir a página."}</h1>
                    <p className="fu-muted">{status === "notfound"
                        ? "Se ainda precisa de enviar documentos, peça um novo link a quem lho enviou."
                        : "Tente de novo dentro de momentos."}</p>
                </div>
            </div>
        );
    }

    const { company, notice } = data;
    return (
        <div className="fu-page">
            <div className="fu-card">
                <header className="fu-brand">
                    {company.logo_url ? <img src={company.logo_url} alt={company.name} className="fu-logo" /> : null}
                    <span className="fu-company">{company.name}</span>
                </header>
                <h1 className="fu-title">{data.customer_first_name ? `Olá, ${data.customer_first_name}.` : "Olá."}</h1>
                <p>
                    {company.name} pediu-lhe estes documentos para analisar o financiamento da viatura. Os ficheiros seguem diretamente para a
                    empresa, de forma segura. Este link é válido até {fmtDay(data.expires_at)}.
                </p>

                {!notice.accepted ? (
                    <section className="fu-notice" data-testid="fu-notice">
                        <h2>{notice.privacy.title}</h2>
                        {notice.privacy.paragraphs.map((p) => <p key={p}>{p}</p>)}
                        <label className="fu-check">
                            <input type="checkbox" checked={privacy} onChange={(e) => setPrivacy(e.target.checked)} />
                            <span>Li e aceito o aviso de proteção de dados.</span>
                        </label>
                        {notice.cc_copy && (
                            <label className="fu-check">
                                <input type="checkbox" checked={ccCopy} onChange={(e) => setCcCopy(e.target.checked)} />
                                <span>{notice.cc_copy}</span>
                            </label>
                        )}
                        {consentError && <p className="fu-error" role="alert">{consentError}</p>}
                        <button type="button" className="fu-button" disabled={!privacy || (!!notice.cc_copy && !ccCopy)} onClick={accept}>Continuar</button>
                    </section>
                ) : (
                    <section data-testid="fu-items">
                        {data.items.map((item) => {
                            const msg = itemMsg[item.key];
                            return (
                                <div className="fu-item" key={item.key} data-testid={`fu-item-${item.key}`}>
                                    <div className="fu-item-head">
                                        <div>
                                            <strong>{item.label}</strong>
                                            {item.hint && <div className="fu-muted">{item.hint}</div>}
                                        </div>
                                        <label className={`fu-button fu-button-small${sending ? " fu-disabled" : ""}`}>
                                            {sending === item.key ? "A enviar…" : item.sent.length ? "Enviar outro" : "Escolher ficheiro"}
                                            <input type="file" accept={ACCEPT} hidden disabled={!!sending || data.limits.remaining_files === 0}
                                                aria-label={`Enviar ${item.label}`}
                                                onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ""; if (f) void send(item, f); }} />
                                        </label>
                                    </div>
                                    {item.sent.length > 0 && (
                                        <ul className="fu-sent">
                                            {item.sent.map((s, i) => (
                                                <li key={`${s.name}-${i}`} className={s.refused ? "fu-refused" : ""}>
                                                    {s.refused ? "✕ Não foi possível aceitar este ficheiro. Envie-o de novo." : `✓ ${s.name} · enviado a ${fmtDay(s.sent_at)}`}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    {msg?.text && <p className={msg.ok ? "fu-ok" : "fu-error"} role={msg.ok ? "status" : "alert"}>{msg.text}</p>}
                                </div>
                            );
                        })}
                        <p className="fu-muted fu-small">
                            Aceitam-se {data.limits.types.join(", ")}, até {Math.round(data.limits.max_file_bytes / 1048576)} MB por ficheiro. Pode fechar esta página e voltar
                            ao link até {fmtDay(data.expires_at)}. Aqui só se envia: os ficheiros não se podem ver nem descarregar nesta página.
                        </p>
                    </section>
                )}
                <footer className="fu-footer fu-muted fu-small">
                    {company.fiscal_name}{company.nipc ? ` · NIPC ${company.nipc}` : ""}
                </footer>
            </div>
        </div>
    );
}
