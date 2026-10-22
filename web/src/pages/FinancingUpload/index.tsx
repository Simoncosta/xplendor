import { useCallback, useEffect, useRef, useState } from "react";
import "./financing-upload.css";

/**
 * /app/enviar-documentos#<código> (sem conta): a página onde o cliente envia os documentos para o
 * financiamento (cada titular com o seu link) ou as fotografias e o DUA da retoma. O código vem no
 * fragmento (nunca chega ao servidor, aos registos nem ao Referer) e vai à API no cabeçalho
 * X-Financing-Token. Mostra a marca da empresa, o aviso de proteção de dados (aceite com o nome
 * completo, no financiamento), os dados do titular, a lista pedida e o que já foi enviado neste
 * link; o primeiro titular pode incluir um segundo titular. Sem indexação.
 */

const PUBLIC_URL = process.env.REACT_APP_PUBLIC_URL ?? "";
const POLL_MS = 3000;
const POLL_MAX = 40;

interface Item { kind: string; name: string; hint: string; required: boolean; max_files: number; accepted_types: string[]; max_file_bytes: number; sent_count: number }
interface SentFile { id: number; kind: string; name: string; size_bytes: number; state: "em_analise" | "recebido" | "recusado"; reason: string | null; reason_text: string | null; sent_at: string | null }
export interface FinancingPublicPayload {
    purpose: "financiamento" | "retoma";
    holder: number;
    holder_notice: string | null;
    company: { name: string; logo_url: string | null; fiscal_name: string | null; nipc: string | null };
    customer_first_name: string | null;
    expires_at: string;
    items: Item[];
    files: SentFile[];
    notice: { title: string; paragraphs: string[]; text: string; version: string; accepted: boolean; id_copy: string | null; name_required: boolean };
    notes: string | null;
    limits: { max_files: number; remaining_files: number; max_file_bytes: number };
    details: null | {
        phone: string | null; email: string | null; housing: string | null; housing_other: string | null; housing_monthly_eur: number | null; completed: boolean;
        housing_options: { value: string; label: string; needs_amount: boolean }[];
    };
    second_holder: null | {
        question: string; help: string; relationship_options: { value: string; label: string }[]; included: boolean;
        relationship: string | null; relationship_other: string | null; url: string | null;
    };
}

const tokenFromHash = () => window.location.hash.replace(/^#/, "").trim();
const fmtDay = (s?: string | null) => (s ? new Date(s).toLocaleDateString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric" }) : "");
const accept = (types: string[]) => types.flatMap((t) => (t === "pdf" ? [".pdf", "application/pdf"] : t === "jpg" ? [".jpg", ".jpeg", "image/jpeg"] : t === "png" ? [".png", "image/png"] : [".heic", ".heif", "image/heic", "image/heif"])).join(",");

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

const jsonBody = (method: string, body: unknown): RequestInit => ({ method, headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });

function messageOf(status: number, json: any): string {
    if (status === 413) return "O ficheiro é demasiado grande.";
    if (status === 429) return "Demasiados envios seguidos. Tente de novo dentro de um minuto.";
    return json?.message ?? "Não foi possível enviar. Tente de novo dentro de momentos.";
}

export default function FinancingUploadPage() {
    const [token] = useState(tokenFromHash);
    const [data, setData] = useState<FinancingPublicPayload | null>(null);
    const [status, setStatus] = useState<"loading" | "ready" | "notfound" | "error">("loading");
    const [privacy, setPrivacy] = useState(false);
    const [idCopy, setIdCopy] = useState(false);
    const [fullName, setFullName] = useState("");
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
                document.title = `${r.json.data.purpose === "retoma" ? "Fotografias da retoma" : "Enviar documentos"} | ${r.json.data.company.name}`;
            } else setStatus(r.status === 404 ? "notfound" : "error");
        } catch {
            setStatus("error");
        }
    }, [token]);

    useEffect(() => { void load(); }, [load]);

    // Enquanto um ficheiro está a ser verificado pelo antivírus, a página pergunta de 3 em 3 segundos (até 2 minutos).
    const checking = !!data?.files.some((f) => f.state === "em_analise");
    const polls = useRef(0);
    useEffect(() => {
        if (!checking || status !== "ready") { polls.current = 0; return; }
        if (polls.current >= POLL_MAX) return;
        const t = setTimeout(async () => {
            polls.current += 1;
            try {
                const r = await call(token, "");
                if (r.status === 200 && r.json?.data) setData(r.json.data);
                else if (r.status === 404) setStatus("notfound");
            } catch { /* tenta na próxima */ }
        }, POLL_MS);
        return () => clearTimeout(t);
    }, [checking, status, data, token]);

    const acceptNotice = async () => {
        setConsentError(null);
        const r = await call(token, "/consent", jsonBody("POST", { privacy, id_copy: idCopy, name: fullName.trim() }));
        if (r.status === 200 && r.json?.data) setData(r.json.data);
        else if (r.status === 404) setStatus("notfound");
        else setConsentError(messageOf(r.status, r.json));
    };

    const send = async (item: Item, file: File) => {
        if (file.size > item.max_file_bytes) {
            setItemMsg((m) => ({ ...m, [item.kind]: { ok: false, text: `O ficheiro tem mais de ${Math.round(item.max_file_bytes / 1048576)} MB.` } }));
            return;
        }
        setSending(item.kind);
        setItemMsg((m) => ({ ...m, [item.kind]: { ok: true, text: "" } }));
        const form = new FormData();
        form.append("kind", item.kind);
        form.append("file", file);
        try {
            const r = await call(token, "/documents", { method: "POST", body: form });
            if (r.status === 201) await load();
            else if (r.status === 404) setStatus("notfound");
            else setItemMsg((m) => ({ ...m, [item.kind]: { ok: false, text: messageOf(r.status, r.json) } }));
        } catch {
            setItemMsg((m) => ({ ...m, [item.kind]: { ok: false, text: "Sem ligação. Verifique a internet e tente de novo." } }));
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
                    <p className="fu-muted">{status === "notfound" ? "Se ainda precisa de enviar, peça um novo link a quem lho enviou." : "Tente de novo dentro de momentos."}</p>
                </div>
            </div>
        );
    }

    const { company, notice } = data;
    const tradeIn = data.purpose === "retoma";
    const canContinue = privacy && (!notice.id_copy || idCopy) && (!notice.name_required || fullName.trim().length >= 3);

    return (
        <div className="fu-page">
            <div className="fu-card">
                {data.holder_notice && <div className="fu-banner" role="alert" data-testid="fu-holder-notice">{data.holder_notice}</div>}
                <header className="fu-brand">
                    {company.logo_url ? <img src={company.logo_url} alt={company.name} className="fu-logo" /> : null}
                    <span className="fu-company">{company.name}</span>
                </header>
                <h1 className="fu-title">{data.customer_first_name ? `Olá, ${data.customer_first_name}.` : "Olá."}</h1>
                <p>
                    {tradeIn
                        ? <>{company.name} pediu-lhe as fotografias e o DUA da viatura que propõe para retoma, para preparar a proposta. </>
                        : <>{company.name} pediu-lhe estes documentos para analisar o financiamento da viatura{data.holder === 2 ? ", como segundo titular" : ""}. </>}
                    Os ficheiros seguem diretamente para a empresa, de forma segura. Este link é válido até {fmtDay(data.expires_at)}.
                </p>

                {!notice.accepted ? (
                    <section className="fu-notice" data-testid="fu-notice">
                        <h2>{notice.title}</h2>
                        {notice.paragraphs.map((p) => <p key={p}>{p}</p>)}
                        <label className="fu-check">
                            <input type="checkbox" checked={privacy} onChange={(e) => setPrivacy(e.target.checked)} />
                            <span>Li e aceito o aviso de proteção de dados.</span>
                        </label>
                        {notice.id_copy && (
                            <label className="fu-check">
                                <input type="checkbox" checked={idCopy} onChange={(e) => setIdCopy(e.target.checked)} />
                                <span>{notice.id_copy}</span>
                            </label>
                        )}
                        {notice.name_required && (
                            <label className="fu-field">
                                <span>O seu nome completo</span>
                                <input type="text" value={fullName} maxLength={160} autoComplete="name" onChange={(e) => setFullName(e.target.value)} />
                                <small className="fu-muted">Fica guardado com o seu consentimento. Cada titular aceita o seu.</small>
                            </label>
                        )}
                        {consentError && <p className="fu-error" role="alert">{consentError}</p>}
                        <button type="button" className="fu-button" disabled={!canContinue} onClick={acceptNotice}>Continuar</button>
                    </section>
                ) : (
                    <>
                        {data.details && <DetailsForm token={token} details={data.details} onSaved={setData} />}
                        <section data-testid="fu-items">
                            {data.items.map((item) => {
                                const files = data.files.filter((f) => f.kind === item.kind);
                                const msg = itemMsg[item.kind];
                                const full = item.sent_count >= item.max_files || data.limits.remaining_files === 0;
                                return (
                                    <div className="fu-item" key={item.kind} data-testid={`fu-item-${item.kind}`}>
                                        <div className="fu-item-head">
                                            <div>
                                                <strong>{item.name}</strong>{!item.required && <span className="fu-muted"> (opcional)</span>}
                                                {item.hint && <div className="fu-muted">{item.hint}</div>}
                                            </div>
                                            <label className={`fu-button fu-button-small${sending || full ? " fu-disabled" : ""}`}>
                                                {sending === item.kind ? "A enviar…" : files.length ? "Enviar outro" : "Escolher ficheiro"}
                                                <input type="file" accept={accept(item.accepted_types)} hidden disabled={!!sending || full}
                                                    aria-label={`Enviar ${item.name}`}
                                                    onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ""; if (f) void send(item, f); }} />
                                            </label>
                                        </div>
                                        {files.length > 0 && (
                                            <ul className="fu-sent">
                                                {files.map((s) => (
                                                    <li key={s.id} className={s.state === "recusado" ? "fu-refused" : s.state === "em_analise" ? "fu-checking" : ""} role={s.state === "recusado" ? "alert" : undefined}>
                                                        {s.state === "recusado"
                                                            ? `✕ ${s.name}: ${s.reason_text ?? "não foi possível aceitar este ficheiro."}`
                                                            : s.state === "em_analise" ? `A verificar ${s.name}…` : `✓ ${s.name} · enviado a ${fmtDay(s.sent_at)}`}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                        {msg?.text && <p className={msg.ok ? "fu-ok" : "fu-error"} role={msg.ok ? "status" : "alert"}>{msg.text}</p>}
                                    </div>
                                );
                            })}
                        </section>
                        {tradeIn && <NotesForm token={token} notes={data.notes} onSaved={setData} />}
                        {data.second_holder && <SecondHolder token={token} info={data.second_holder} onSaved={setData} />}
                        <p className="fu-muted fu-small">
                            Até {Math.round(data.limits.max_file_bytes / 1048576)} MB por ficheiro. Pode fechar esta página e voltar ao link até {fmtDay(data.expires_at)}.
                            Aqui só se envia: os ficheiros não se podem ver nem descarregar nesta página.
                        </p>
                    </>
                )}
                <footer className="fu-footer fu-muted fu-small">{company.fiscal_name}{company.nipc ? ` · NIPC ${company.nipc}` : ""}</footer>
            </div>
        </div>
    );
}

function DetailsForm({ token, details, onSaved }: { token: string; details: NonNullable<FinancingPublicPayload["details"]>; onSaved: (d: FinancingPublicPayload) => void }) {
    const [phone, setPhone] = useState(details.phone ?? "");
    const [email, setEmail] = useState(details.email ?? "");
    const [housing, setHousing] = useState(details.housing ?? "");
    const [other, setOther] = useState(details.housing_other ?? "");
    const [amount, setAmount] = useState(details.housing_monthly_eur != null ? String(details.housing_monthly_eur).replace(".", ",") : "");
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    const [saving, setSaving] = useState(false);
    const option = details.housing_options.find((o) => o.value === housing);

    const save = async () => {
        setSaving(true);
        setMsg(null);
        const r = await call(token, "/details", jsonBody("PUT", { phone, email, housing, housing_other: other, housing_monthly_eur: amount }));
        setSaving(false);
        if (r.status === 200 && r.json?.data) { onSaved(r.json.data); setMsg({ ok: true, text: "Dados guardados." }); }
        else setMsg({ ok: false, text: messageOf(r.status, r.json) });
    };

    return (
        <section className="fu-notice" data-testid="fu-details">
            <h2>Os seus dados</h2>
            <label className="fu-field"><span>Telemóvel</span><input type="tel" value={phone} autoComplete="tel" onChange={(e) => setPhone(e.target.value)} /></label>
            <label className="fu-field"><span>Email</span><input type="email" value={email} autoComplete="email" onChange={(e) => setEmail(e.target.value)} /></label>
            <label className="fu-field"><span>Habitação</span>
                <select value={housing} onChange={(e) => setHousing(e.target.value)}>
                    <option value="">Escolha…</option>
                    {details.housing_options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                </select>
            </label>
            {housing === "outra" && <label className="fu-field"><span>Qual?</span><input type="text" value={other} maxLength={120} onChange={(e) => setOther(e.target.value)} /></label>}
            {option?.needs_amount && (
                <label className="fu-field"><span>{housing === "arrendada" ? "Valor mensal da renda (€)" : "Valor mensal da prestação (€)"}</span>
                    <input type="text" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} />
                </label>
            )}
            {msg && <p className={msg.ok ? "fu-ok" : "fu-error"} role={msg.ok ? "status" : "alert"}>{msg.text}</p>}
            <button type="button" className="fu-button" disabled={saving || !phone || !email || !housing} onClick={save}>{details.completed ? "Atualizar os dados" : "Guardar os dados"}</button>
        </section>
    );
}

function NotesForm({ token, notes, onSaved }: { token: string; notes: string | null; onSaved: (d: FinancingPublicPayload) => void }) {
    const [value, setValue] = useState(notes ?? "");
    const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
    return (
        <section className="fu-notice" data-testid="fu-notes">
            <h2>Observações</h2>
            <p className="fu-muted fu-small">Por exemplo: o estado da viatura, riscos ou toques, revisões e extras.</p>
            <textarea className="fu-textarea" rows={4} maxLength={2000} value={value} onChange={(e) => setValue(e.target.value)} aria-label="Observações sobre a viatura" />
            {msg && <p className={msg.ok ? "fu-ok" : "fu-error"}>{msg.text}</p>}
            <button type="button" className="fu-button" onClick={async () => {
                const r = await call(token, "/notes", jsonBody("PUT", { notes: value }));
                if (r.status === 200 && r.json?.data) { onSaved(r.json.data); setMsg({ ok: true, text: "Observações guardadas." }); }
                else setMsg({ ok: false, text: messageOf(r.status, r.json) });
            }}>Guardar as observações</button>
        </section>
    );
}

function SecondHolder({ token, info, onSaved }: { token: string; info: NonNullable<FinancingPublicPayload["second_holder"]>; onSaved: (d: FinancingPublicPayload) => void }) {
    const [open, setOpen] = useState(false);
    const [rel, setRel] = useState(info.relationship ?? "");
    const [other, setOther] = useState(info.relationship_other ?? "");
    const [msg, setMsg] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);

    return (
        <section className="fu-notice" data-testid="fu-second-holder">
            <h2>{info.question}</h2>
            <p className="fu-muted">{info.help}</p>
            {info.included && info.url ? (
                <>
                    <p>O segundo titular tem o seu próprio link: envie-lho. Ele aceita o seu aviso, preenche os seus dados e envia os seus documentos.</p>
                    <div className="fu-copy"><input readOnly value={info.url} aria-label="Link do segundo titular" onFocus={(e) => e.currentTarget.select()} />
                        <button type="button" className="fu-button fu-button-small" onClick={async () => {
                            try { await navigator.clipboard.writeText(info.url!); setCopied(true); } catch { setCopied(false); }
                        }}>{copied ? "Copiado" : "Copiar o link"}</button>
                    </div>
                </>
            ) : !open ? (
                <button type="button" className="fu-button" onClick={() => setOpen(true)}>Sim, incluir um segundo titular</button>
            ) : (
                <>
                    <label className="fu-field"><span>Relação ou grau de parentesco</span>
                        <select value={rel} onChange={(e) => setRel(e.target.value)}>
                            <option value="">Escolha…</option>
                            {info.relationship_options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                        </select>
                    </label>
                    {rel === "outro" && <label className="fu-field"><span>Qual?</span><input type="text" value={other} maxLength={60} onChange={(e) => setOther(e.target.value)} /></label>}
                    {msg && <p className="fu-error" role="alert">{msg}</p>}
                    <button type="button" className="fu-button" disabled={!rel || (rel === "outro" && !other.trim())} onClick={async () => {
                        const r = await call(token, "/second-holder", jsonBody("POST", { relationship: rel, relationship_other: other }));
                        if (r.status === 200 && r.json?.data) onSaved(r.json.data); else setMsg(messageOf(r.status, r.json));
                    }}>Criar o link do segundo titular</button>
                </>
            )}
        </section>
    );
}
