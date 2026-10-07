import { useCallback, useEffect, useRef, useState } from "react";
import logo from "pages/QuotePublic/xplendor-x.png";
import "pages/QuotePublic/quote-public.css";
import { readTeamMarker } from "helpers/teamMarker";
import { CHARGE_STATUS_META, ClientCharge, PROOF_ACCEPT, PROOF_MAX_MB, dmy, euro } from "common/models/charge.model";

/**
 * /app/cobranca#<token> (sem conta e sem o módulo de Finanças): a fatura da XPLENDOR,
 * o PDF e "Já paguei" com comprovativo opcional. O token vem no fragmento do URL (nunca
 * chega ao servidor, aos registos de acesso nem ao Referer) e vai no cabeçalho
 * X-Charge-Token. Sem indexação e sem Referer. O sinal de abertura só é enviado com a
 * página visível (os verificadores de links dos emails não contam).
 */

const PUBLIC_URL = process.env.REACT_APP_PUBLIC_URL ?? "";
const VISITOR_KEY = "xpl_charge_visitor";
const OPEN_DELAY_MS = 1500;
const API = `${PUBLIC_URL}/api/public/charge`;

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

export default function ChargePublicPage() {
    const [token] = useState(tokenFromHash);
    const [data, setData] = useState<ClientCharge | null>(null);
    const [status, setStatus] = useState<"loading" | "ready" | "notfound" | "error">("loading");
    const [paying, setPaying] = useState(false);
    const [note, setNote] = useState("");
    const [proof, setProof] = useState<File | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [done, setDone] = useState(false);
    const openSent = useRef(false);

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
            const r = await fetch(API, { referrerPolicy: "no-referrer", headers: { Accept: "application/json", "X-Charge-Token": token } });
            const json = await r.json().catch(() => null);
            if (r.ok && json?.data) {
                setData(json.data);
                setStatus("ready");
                document.title = "Fatura da XPLENDOR";
            } else {
                setStatus(r.status === 404 ? "notfound" : "error");
            }
        } catch {
            setStatus("error");
        }
    }, [token]);
    useEffect(() => { void load(); }, [load]);

    useEffect(() => {
        if (status !== "ready" || openSent.current) return;
        let timer: ReturnType<typeof setTimeout> | undefined;
        const send = () => {
            if (openSent.current) return;
            openSent.current = true;
            fetch(`${API}/open`, {
                method: "POST", referrerPolicy: "no-referrer", keepalive: true,
                headers: { Accept: "application/json", "Content-Type": "application/json", "X-Charge-Token": token },
                body: JSON.stringify({ visitor_id: visitorId(), team_marker: readTeamMarker() }),
            }).catch(() => { /* a abertura não é crítica */ });
        };
        const schedule = () => {
            if (timer) clearTimeout(timer);
            if (document.visibilityState === "visible") timer = setTimeout(send, OPEN_DELAY_MS);
        };
        schedule();
        document.addEventListener("visibilitychange", schedule);
        return () => { if (timer) clearTimeout(timer); document.removeEventListener("visibilitychange", schedule); };
    }, [status, token]);

    const downloadPdf = async () => {
        try {
            const r = await fetch(`${API}/pdf`, { referrerPolicy: "no-referrer", headers: { "X-Charge-Token": token } });
            if (!r.ok) throw new Error();
            const url = URL.createObjectURL(await r.blob());
            const a = document.createElement("a");
            a.href = url;
            a.download = "fatura-xplendor.pdf";
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(url), 60000);
        } catch {
            alert("Não foi possível descarregar a fatura. Tente de novo dentro de momentos.");
        }
    };

    const pick = (f: File | null) => {
        setError(null);
        if (f && f.size > PROOF_MAX_MB * 1024 * 1024) { setError(`O comprovativo não pode ter mais de ${PROOF_MAX_MB} MB.`); setProof(null); return; }
        setProof(f);
    };
    const confirmPaid = async () => {
        setBusy(true);
        setError(null);
        const fd = new FormData();
        if (note.trim()) fd.append("note", note.trim());
        if (proof) fd.append("proof", proof);
        try {
            const r = await fetch(`${API}/paid`, { method: "POST", referrerPolicy: "no-referrer", headers: { Accept: "application/json", "X-Charge-Token": token }, body: fd });
            const json = await r.json().catch(() => null);
            if (r.ok && json?.data) { setData(json.data); setDone(true); setPaying(false); return; }
            if (r.status === 429) setError("Demasiados pedidos seguidos. Tente de novo dentro de um minuto.");
            else if (json?.errors) setError(String(Object.values(json.errors).flat()[0]));
            else setError(json?.message ?? "Não foi possível registar. Tente de novo dentro de momentos.");
            if (r.status === 422 && !json?.errors?.proof) void load();
        } catch {
            setError("Sem ligação. Verifique a internet e tente de novo.");
        } finally {
            setBusy(false);
        }
    };

    if (status === "loading") {
        return <div className="qp" data-bs-theme="light"><div className="qp-paper"><div className="qp-loading">A carregar a fatura…</div></div></div>;
    }
    if (status !== "ready" || !data) {
        return (
            <div className="qp" data-bs-theme="light">
                <div className="qp-paper">
                    <div className="qp-head"><div className="qp-brand"><img src={logo} alt="" /><span>XPLENDOR</span></div></div>
                    <div className="qp-result">
                        <div className="qp-result-icon is-grey" aria-hidden>?</div>
                        <h1>{status === "notfound" ? "Este link não é válido" : "Não foi possível abrir a fatura"}</h1>
                        <p>{status === "notfound" ? "O link pode ter expirado ou a fatura ter sido anulada. Peça um novo à XPLENDOR." : "Tente de novo dentro de momentos."}</p>
                    </div>
                </div>
            </div>
        );
    }

    const meta = CHARGE_STATUS_META[data.status];
    return (
        <div className="qp" data-bs-theme="light" data-testid="charge-public">
            <div className="qp-paper">
                <div className="qp-head">
                    <div className="qp-brand"><img src={logo} alt="" /><span>XPLENDOR</span></div>
                    <div className="qp-meta"><div className="qp-kind">FATURA</div><div>{data.company}</div></div>
                </div>

                {data.status === "paid" && <div className="qp-notice is-green"><strong>Paga</strong>Confirmámos o pagamento{data.paid_at ? ` a ${dmy(data.paid_at)}` : ""}. Obrigado.</div>}
                {data.status === "payment_indicated" && <div className="qp-notice is-grey"><strong>{done ? "Obrigado" : "Pagamento indicado"}</strong>A XPLENDOR vai confirmar o pagamento. Os lembretes pararam.</div>}
                {data.status === "open" && data.refuse_note && <div className="qp-notice is-red"><strong>Pagamento ainda não confirmado</strong>{data.refuse_note}</div>}
                {data.status === "open" && !data.refuse_note && data.overdue && <div className="qp-notice is-red"><strong>Fatura vencida</strong>Venceu a {dmy(data.due_date)}.</div>}

                <div className="qp-section">
                    <div className="qp-label">Descrição</div>
                    <p className="mb-0" style={{ margin: 0 }}>{data.description}</p>
                </div>
                <div className="qp-section" style={{ display: "flex", gap: 12, flexWrap: "wrap" }}>
                    <div className="qp-total-box" style={{ flex: "1 1 200px" }}>
                        <div className="qp-label">Valor</div>
                        <div className="qp-value">{euro(data.amount)}</div>
                    </div>
                    <div className="qp-total-box" style={{ flex: "1 1 200px", borderColor: data.overdue ? "var(--qp-red)" : undefined }}>
                        <div className="qp-label">Vencimento</div>
                        <div className="qp-value" style={{ color: data.overdue ? "var(--qp-red)" : undefined }}>{dmy(data.due_date)}</div>
                        <div className="qp-muted" style={{ fontSize: 13 }}>{meta.label}</div>
                    </div>
                </div>

                <div className="qp-pdf" style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                    <button type="button" className="qp-btn" onClick={downloadPdf}>Descarregar a fatura (PDF)</button>
                    {data.can_indicate_payment && !paying && <button type="button" className="qp-btn is-primary" onClick={() => setPaying(true)}>Já paguei</button>}
                </div>

                {paying && data.can_indicate_payment && (
                    <div className="qp-section" data-testid="charge-paid-form">
                        <h2 className="qp-section-title">Já paguei</h2>
                        <p className="qp-muted" style={{ marginTop: 0 }}>Os lembretes param de imediato. A XPLENDOR confirma o pagamento.</p>
                        <label className="qp-field">
                            <span>Comprovativo (opcional, PDF ou imagem até {PROOF_MAX_MB} MB)</span>
                            <input type="file" accept={PROOF_ACCEPT} onChange={(e) => pick(e.target.files?.[0] ?? null)} />
                        </label>
                        <label className="qp-field">
                            <span>Nota (opcional)</span>
                            <textarea maxLength={1000} value={note} onChange={(e) => setNote(e.target.value)} placeholder="Por exemplo, transferência feita a 12/10." style={{ minHeight: 80 }} />
                        </label>
                        {error && <div className="qp-error" role="alert">{error}</div>}
                        <div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginTop: 8 }}>
                            <button type="button" className="qp-btn is-primary" disabled={busy} onClick={confirmPaid}>{busy ? "A enviar…" : "Confirmar que paguei"}</button>
                            <button type="button" className="qp-link-btn" disabled={busy} onClick={() => { setPaying(false); setError(null); }}>Cancelar</button>
                        </div>
                    </div>
                )}

                <div className="qp-foot" style={{ marginTop: 28 }}>
                    <p className="qp-muted" style={{ fontSize: 12 }}>
                        Fatura emitida pela XPLENDOR num programa de faturação certificado. Este link é pessoal: não o partilhe.
                    </p>
                </div>
            </div>
        </div>
    );
}
