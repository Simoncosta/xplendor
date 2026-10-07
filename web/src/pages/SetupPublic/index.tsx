import { useCallback, useEffect, useRef, useState } from "react";
import { Alert, Badge, Button, Input, Label, Progress, Spinner } from "reactstrap";
import { readTeamMarker } from "helpers/teamMarker";
import { STEP_ICON, STEP_STATUS, SetupStep, SetupStepKey, stepDetailText } from "common/models/setupLink.model";
import "./setup-public.css";

/**
 * /app/configurar#<token> (sem conta): o cliente autoriza ele próprio as ligações de marketing
 * da empresa (Facebook e Instagram, anúncios da Meta, Google Analytics). Mesmo padrão dos
 * outros links: o token vem no fragmento (nunca chega ao servidor nem ao Referer) e vai à
 * API no cabeçalho X-Setup-Token; sinal de abertura depois de a página estar visível; sem
 * indexação. Pensada para o telemóvel e com o tema claro ou escuro do telemóvel.
 */

const PUBLIC_URL = process.env.REACT_APP_PUBLIC_URL ?? "";
const VISITOR_KEY = "xpl_setup_visitor";
const OPEN_DELAY_MS = 1500;

type Identity = { name: string; logo_url: string | null };
type Payload = {
    state: "open" | "expired" | "revoked";
    message?: string;
    company: Identity;
    agency: Identity | null;
    privacy_url: string;
    expires_at?: string;
    completed?: boolean;
    steps?: SetupStep[];
    ga4?: { sa_email: string | null } | null;
};
type Page = { id: string; name: string; selected: boolean; instagram: { id: string; username: string | null; name: string | null; selected: boolean } | null };
type AdAccount = { id: string; name: string; currency: string | null; business: string | null; active: boolean };

const WHAT: Record<SetupStepKey, string> = {
    social: "Ler as Páginas de Facebook e as contas de Instagram que escolher (seguidores e publicações). A XPLENDOR não publica nem altera nada.",
    meta_ads: "Ler os resultados dos anúncios da conta que escolher (gasto, alcance e cliques). A XPLENDOR não cria nem altera anúncios.",
    ga4: "Ler as visitas do site no Google Analytics, com um acesso de Visualizador (só leitura).",
};

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

async function call(token: string, path: string, method: "GET" | "POST" | "PUT" = "GET", body?: unknown): Promise<{ status: number; json: any }> {
    const r = await fetch(`${PUBLIC_URL}/api/public/setup${path}`, {
        method,
        referrerPolicy: "no-referrer",
        headers: { Accept: "application/json", "X-Setup-Token": token, ...(body === undefined ? {} : { "Content-Type": "application/json" }) },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    let json: any = null;
    try { json = await r.json(); } catch { /* sem corpo */ }
    return { status: r.status, json };
}

function errorOf(r: { status: number; json: any }, fallback: string): string {
    if (r.status === 422 && r.json?.errors) return String(Object.values(r.json.errors).flat()[0] ?? r.json?.message ?? fallback);
    if (r.status === 429) return "Demasiados pedidos seguidos. Tente de novo dentro de um minuto.";
    return r.json?.message ?? fallback;
}

function Logo({ who }: { who: Identity }) {
    return who.logo_url ? <img src={who.logo_url} alt="" className="sp-logo" /> : <span className="sp-logo-fallback" aria-hidden>{who.name.slice(0, 1).toUpperCase()}</span>;
}

export default function SetupPublicPage() {
    const [token] = useState(tokenFromHash);
    const [data, setData] = useState<Payload | null>(null);
    const [status, setStatus] = useState<"loading" | "ready" | "notfound" | "error">("loading");
    const [theme, setTheme] = useState<"light" | "dark">(() => (window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light"));
    const [returned] = useState(() => {
        const q = new URLSearchParams(window.location.search);
        return { step: q.get("passo") as SetupStepKey | null, result: q.get("resultado") };
    });
    const openSent = useRef(false);

    // Sem indexação, sem Referer e com o tema do telemóvel; limpa a query do regresso do Facebook.
    useEffect(() => {
        const metas = [["robots", "noindex, nofollow, noarchive"], ["referrer", "no-referrer"]].map(([n, content]) => {
            const meta = document.createElement("meta");
            meta.name = n;
            meta.content = content;
            document.head.appendChild(meta);
            return meta;
        });
        const mq = window.matchMedia("(prefers-color-scheme: dark)");
        const apply = () => setTheme(mq.matches ? "dark" : "light");
        mq.addEventListener("change", apply);
        if (window.location.search) window.history.replaceState(null, "", window.location.pathname + window.location.hash);
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
                document.title = `Configuração: ${r.json.data.company.name}`;
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
            fetch(`${PUBLIC_URL}/api/public/setup/open`, {
                method: "POST", referrerPolicy: "no-referrer", keepalive: true,
                headers: { Accept: "application/json", "Content-Type": "application/json", "X-Setup-Token": token },
                body: JSON.stringify({ visitor_id: visitorId(), team_marker: readTeamMarker() }),
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

    const steps = data?.steps ?? [];
    const done = steps.filter((s) => s.status === "done").length;
    const nextKey = steps.find((s) => s.status !== "done")?.key ?? null;

    return (
        <div className="sp-page" data-bs-theme={theme}>
            <div className="sp-wrap">
                {status === "loading" && <div className="text-center py-5"><Spinner color="primary" /></div>}
                {status === "notfound" && (
                    <div className="text-center py-5" data-testid="setup-notfound">
                        <i className="ri-link-unlink fs-1 text-muted" />
                        <h1 className="h5 mt-2">Link inválido</h1>
                        <p className="text-muted">Confirme que abriu o link completo, tal como o recebeu. Se o problema continuar, peça um link novo a quem lho enviou.</p>
                    </div>
                )}
                {status === "error" && (
                    <div className="text-center py-5">
                        <h1 className="h5">Não foi possível abrir a página</h1>
                        <p className="text-muted">Verifique a ligação à internet e tente de novo.</p>
                        <Button color="primary" onClick={() => { setStatus("loading"); void load(); }}>Tentar de novo</Button>
                    </div>
                )}
                {status === "ready" && data && (
                    <>
                        <header className="d-flex align-items-center gap-2 mb-3">
                            <Logo who={data.company} />
                            {data.agency && <><i className="ri-arrow-left-right-line text-muted" aria-hidden /><Logo who={data.agency} /></>}
                            <div className="min-w-0 ms-1">
                                <div className="fw-semibold text-truncate">{data.company.name}</div>
                                {data.agency && <div className="text-muted fs-12 text-truncate">Pedido pela agência {data.agency.name}</div>}
                            </div>
                        </header>

                        {data.state !== "open" ? (
                            <Alert color={data.state === "expired" ? "warning" : "danger"} className="mb-0" data-testid="setup-closed">
                                <h1 className="h6 alert-heading">{data.state === "expired" ? "Este link expirou" : "Este link já não está ativo"}</h1>
                                <p className="mb-0">{data.message}</p>
                            </Alert>
                        ) : (
                            <>
                                <h1 className="h5 mb-1">Ligar as plataformas de marketing</h1>
                                <p className="text-muted fs-14">
                                    {data.agency ? `A agência ${data.agency.name} pediu-lhe` : "Foi-lhe pedido"} que autorize a XPLENDOR a ler os dados de marketing de {data.company.name}. Não precisa de conta na XPLENDOR.
                                </p>

                                <div className="sp-step mb-3" data-testid="setup-what">
                                    <div className="fw-semibold mb-2"><i className="ri-shield-check-line text-success me-1" />O que vai autorizar (só leitura)</div>
                                    <ul className="ps-3 mb-2 fs-13">
                                        {steps.map((s) => <li key={s.key} className="mb-1"><strong>{s.label}:</strong> {WHAT[s.key]}</li>)}
                                    </ul>
                                    <p className="fs-12 text-muted mb-0">
                                        Pode retirar o acesso a qualquer momento, no Facebook ou no Google Analytics. Saiba mais na <a href={data.privacy_url} target="_blank" rel="noreferrer noopener">Política de Privacidade</a>.
                                    </p>
                                </div>

                                <div className="d-flex justify-content-between fs-13 mb-1"><span className="fw-medium">Progresso</span><span className="text-muted">{done} de {steps.length} {steps.length === 1 ? "passo" : "passos"}</span></div>
                                <Progress value={steps.length ? (done / steps.length) * 100 : 0} color="success" className="progress-sm mb-3" aria-label={`${done} de ${steps.length} passos feitos`} />

                                {data.completed && (
                                    <Alert color="success" data-testid="setup-completed">
                                        <i className="ri-checkbox-circle-line me-1" /><strong>Tudo pronto. Obrigado!</strong> {data.agency ? `A agência ${data.agency.name} já foi avisada.` : "A empresa já foi avisada."} Pode fechar esta página.
                                    </Alert>
                                )}

                                <div className="vstack gap-3">
                                    {steps.map((s, i) => (
                                        <StepCard key={s.key} index={i + 1} step={s} token={token} primary={s.key === nextKey} data={data}
                                            returned={returned.step === s.key ? returned.result : null} onChanged={load} />
                                    ))}
                                </div>
                                {data.expires_at && <p className="text-muted fs-12 text-center mt-4 mb-0">Este link é válido até {new Date(data.expires_at).toLocaleDateString("pt-PT")}.</p>}
                            </>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}

function StepCard({ index, step, token, primary, data, returned, onChanged }: {
    index: number; step: SetupStep; token: string; primary: boolean; data: Payload; returned: string | null; onChanged: () => Promise<void>;
}) {
    const st = STEP_STATUS[step.status];
    const detail = stepDetailText(step);
    const [redo, setRedo] = useState(false);
    const open = step.status !== "done" || redo;

    return (
        <section className={`sp-step${step.status === "done" ? " sp-done" : ""}`} data-testid={`setup-step-${step.key}`}>
            <div className="d-flex align-items-center gap-2 mb-2">
                <span className="badge rounded-pill bg-primary-subtle text-primary">{index}</span>
                <i className={`${STEP_ICON[step.key]} fs-18`} aria-hidden />
                <h2 className="h6 mb-0 me-auto">{step.label}</h2>
                <Badge color={`${st.color}-subtle`} className={`fw-normal ${st.color === "light" ? "text-body" : `text-${st.color}`}`}>{st.label}</Badge>
            </div>
            {step.status === "done" && !redo && (
                <div className="d-flex flex-wrap align-items-center gap-2">
                    <span className="fs-13 text-muted me-auto">{detail ?? "Ligado."}</span>
                    <Button size="sm" color="outline-primary" onClick={() => setRedo(true)}>Alterar</Button>
                </div>
            )}
            {step.status !== "done" && step.error && <Alert color={step.status === "not_approved" ? "warning" : "danger"} className="fs-13 py-2">{step.error}</Alert>}
            {open && step.key === "social" && <SocialStep token={token} primary={primary} returned={returned} onDone={async () => { setRedo(false); await onChanged(); }} />}
            {open && step.key === "meta_ads" && <AdsStep token={token} primary={primary} returned={returned} onDone={async () => { setRedo(false); await onChanged(); }} />}
            {open && step.key === "ga4" && <Ga4Step token={token} primary={primary} saEmail={data.ga4?.sa_email ?? null} onDone={async () => { setRedo(false); await onChanged(); }} />}
        </section>
    );
}

/** Vai ao Facebook (o diálogo da Meta) e volta a esta página. */
function useFacebook(token: string, path: string) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const go = async () => {
        setBusy(true);
        setError(null);
        const r = await call(token, path).catch(() => null);
        if (r?.status === 200 && r.json?.data?.url) {
            window.location.assign(r.json.data.url);
            return;
        }
        setBusy(false);
        setError(r ? errorOf(r, "Não foi possível abrir o Facebook. Tente de novo.") : "Sem ligação à internet. Tente de novo.");
    };

    return { busy, error, go };
}

function SocialStep({ token, primary, returned, onDone }: { token: string; primary: boolean; returned: string | null; onDone: () => Promise<void> }) {
    const fb = useFacebook(token, "/social/auth-url");
    const [pages, setPages] = useState<Page[] | null>(null);
    const [fbIds, setFbIds] = useState<string[]>([]);
    const [igIds, setIgIds] = useState<string[]>([]);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (returned !== "escolher") return;
        call(token, "/social/candidates").then((r) => {
            if (r.status !== 200) { setError(errorOf(r, "Não foi possível ler as suas Páginas.")); return; }
            const list: Page[] = r.json.data.pages ?? [];
            setPages(list);
            setFbIds(list.filter((p) => p.selected).map((p) => p.id));
            setIgIds(list.filter((p) => p.instagram?.selected).map((p) => p.instagram!.id));
        }).catch(() => setError("Sem ligação à internet. Tente de novo."));
    }, [returned, token]);

    const toggle = (list: string[], set: (v: string[]) => void, id: string, on: boolean) => set(on ? [...list, id] : list.filter((x) => x !== id));
    const save = async () => {
        setSaving(true);
        setError(null);
        const r = await call(token, "/social/accounts", "PUT", { facebook: fbIds, instagram: igIds }).catch(() => null);
        setSaving(false);
        if (r?.status === 200) { setPages(null); await onDone(); } else setError(r ? errorOf(r, "Não foi possível guardar.") : "Sem ligação à internet. Tente de novo.");
    };

    if (pages) {
        const igPages = pages.filter((p) => p.instagram);
        return (
            <div>
                <p className="fs-13">Escolha as Páginas e as contas de Instagram a ligar:</p>
                {pages.length === 0 && <Alert color="warning" className="fs-13">Esta conta de Facebook não gere nenhuma Página. Entre com a conta que gere a Página da empresa.</Alert>}
                {pages.length > 0 && <div className="fw-medium fs-13 mb-1">Páginas de Facebook</div>}
                <div className="vstack gap-2 mb-3">
                    {pages.map((p) => (
                        <label key={p.id} className="sp-choice d-flex align-items-center gap-2 mb-0" htmlFor={`fb-${p.id}`}>
                            <Input type="checkbox" className="form-check-input mt-0" id={`fb-${p.id}`} checked={fbIds.includes(p.id)} onChange={(e) => toggle(fbIds, setFbIds, p.id, e.target.checked)} />
                            <i className="ri-facebook-circle-fill text-primary" aria-hidden /><span className="text-truncate">{p.name}</span>
                        </label>
                    ))}
                </div>
                {igPages.length > 0 && <div className="fw-medium fs-13 mb-1">Contas de Instagram</div>}
                <div className="vstack gap-2 mb-3">
                    {igPages.map((p) => (
                        <label key={p.instagram!.id} className="sp-choice d-flex align-items-center gap-2 mb-0" htmlFor={`ig-${p.instagram!.id}`}>
                            <Input type="checkbox" className="form-check-input mt-0" id={`ig-${p.instagram!.id}`} checked={igIds.includes(p.instagram!.id)}
                                onChange={(e) => toggle(igIds, setIgIds, p.instagram!.id, e.target.checked)} />
                            <i className="ri-instagram-line text-danger" aria-hidden /><span className="text-truncate">{p.instagram!.username ? `@${p.instagram!.username}` : p.instagram!.name}</span>
                        </label>
                    ))}
                </div>
                {error && <Alert color="danger" className="fs-13 py-2">{error}</Alert>}
                <Button color="primary" className="w-100" disabled={saving || (fbIds.length === 0 && igIds.length === 0)} onClick={save}>
                    {saving ? <Spinner size="sm" /> : "Guardar a escolha"}
                </Button>
                {fbIds.length === 0 && igIds.length === 0 && <p className="text-muted fs-12 mt-1 mb-0 text-center">Escolha pelo menos uma Página ou uma conta de Instagram.</p>}
            </div>
        );
    }

    return (
        <div>
            <p className="fs-13 text-muted">Entre com a conta de Facebook que gere a Página da empresa. No fim, escolhe as Páginas e as contas de Instagram.</p>
            {(fb.error || error) && <Alert color="danger" className="fs-13 py-2">{fb.error ?? error}</Alert>}
            <Button color={primary ? "primary" : "outline-primary"} className="w-100" disabled={fb.busy} onClick={fb.go}>
                {fb.busy ? <Spinner size="sm" /> : <><i className="ri-facebook-fill me-1" />Ligar com o Facebook</>}
            </Button>
        </div>
    );
}

function AdsStep({ token, primary, returned, onDone }: { token: string; primary: boolean; returned: string | null; onDone: () => Promise<void> }) {
    const fb = useFacebook(token, "/ads/auth-url");
    const [accounts, setAccounts] = useState<AdAccount[] | null>(null);
    const [choice, setChoice] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (returned !== "escolher") return;
        call(token, "/ads/accounts").then((r) => {
            if (r.status !== 200) { setError(errorOf(r, "Não foi possível ler as contas de anúncios.")); return; }
            setAccounts(r.json.data.accounts ?? []);
            setChoice(r.json.data.selected ?? null);
        }).catch(() => setError("Sem ligação à internet. Tente de novo."));
    }, [returned, token]);

    const save = async () => {
        if (!choice) return;
        setSaving(true);
        setError(null);
        const r = await call(token, "/ads/account", "PUT", { account_id: choice }).catch(() => null);
        setSaving(false);
        if (r?.status === 200) { setAccounts(null); await onDone(); } else setError(r ? errorOf(r, "Não foi possível guardar.") : "Sem ligação à internet. Tente de novo.");
    };

    if (accounts) {
        return (
            <div>
                {accounts.length === 0 ? (
                    <Alert color="warning" className="fs-13">Esta conta de Facebook não tem acesso a nenhuma conta de anúncios. Entre com a conta que gere os anúncios da empresa.</Alert>
                ) : (
                    <>
                        <p className="fs-13">Escolha a conta de anúncios da empresa:</p>
                        <div className="vstack gap-2 mb-3" role="radiogroup" aria-label="Conta de anúncios">
                            {accounts.map((a) => (
                                <label key={a.id} className="sp-choice d-flex align-items-start gap-2 mb-0" htmlFor={`ad-${a.id}`}>
                                    <Input type="radio" name="ad-account" className="form-check-input mt-1" id={`ad-${a.id}`} checked={choice === a.id} onChange={() => setChoice(a.id)} />
                                    <span className="min-w-0">
                                        <span className="d-block text-truncate">{a.name}</span>
                                        <span className="d-block text-muted fs-12">{[a.business, a.currency, `ID ${a.id}`].filter(Boolean).join(", ")}{a.active ? "" : ", inativa"}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                    </>
                )}
                {error && <Alert color="danger" className="fs-13 py-2">{error}</Alert>}
                {accounts.length > 0 && (
                    <Button color="primary" className="w-100" disabled={saving || !choice} onClick={save}>{saving ? <Spinner size="sm" /> : "Guardar a conta"}</Button>
                )}
                {accounts.length === 0 && <Button color="outline-primary" className="w-100" disabled={fb.busy} onClick={fb.go}>Entrar com outra conta</Button>}
            </div>
        );
    }

    return (
        <div>
            <p className="fs-13 text-muted">Entre com a conta de Facebook que gere os anúncios da empresa. No fim, escolhe a conta de anúncios numa lista.</p>
            {(fb.error || error) && <Alert color="danger" className="fs-13 py-2">{fb.error ?? error}</Alert>}
            <Button color={primary ? "primary" : "outline-primary"} className="w-100" disabled={fb.busy} onClick={fb.go}>
                {fb.busy ? <Spinner size="sm" /> : <><i className="ri-facebook-fill me-1" />Autorizar os anúncios</>}
            </Button>
        </div>
    );
}

function Ga4Step({ token, primary, saEmail, onDone }: { token: string; primary: boolean; saEmail: string | null; onDone: () => Promise<void> }) {
    const [property, setProperty] = useState("");
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);
    const copy = async () => {
        if (!saEmail) return;
        try { await navigator.clipboard.writeText(saEmail); setCopied(true); setTimeout(() => setCopied(false), 2500); } catch { /* o email continua visível para copiar à mão */ }
    };
    const verify = async () => {
        setBusy(true);
        setError(null);
        const r = await call(token, "/ga4/verify", "POST", { property_id: property.trim() }).catch(() => null);
        setBusy(false);
        if (r?.status === 200) await onDone(); else setError(r ? errorOf(r, "Não foi possível verificar o acesso.") : "Sem ligação à internet. Tente de novo.");
    };
    const valid = /^\d{6,15}$/.test(property.trim());

    return (
        <div>
            <ol className="ps-3 fs-13 mb-3">
                <li className="mb-2">Abra o <a href="https://analytics.google.com/" target="_blank" rel="noreferrer noopener">Google Analytics</a> e vá a <strong>Administração</strong>, <strong>Gestão de acesso à propriedade</strong>.</li>
                <li className="mb-2">
                    Carregue em <strong>+</strong>, <strong>Adicionar utilizadores</strong>, e cole este email com a função <strong>Visualizador</strong>:
                    <div className="sp-choice d-flex align-items-center gap-2 mt-2">
                        <span className="sp-email flex-grow-1 min-w-0" data-testid="ga4-sa-email">{saEmail ?? "Email por configurar: peça-o a quem lhe enviou o link."}</span>
                        {saEmail && <Button size="sm" color="outline-primary" onClick={copy} aria-label="Copiar o email">{copied ? <><i className="ri-check-line me-1" />Copiado</> : <><i className="ri-file-copy-line me-1" />Copiar</>}</Button>}
                    </div>
                </li>
                <li>Em <strong>Administração</strong>, <strong>Detalhes da propriedade</strong>, copie o <strong>ID da propriedade</strong> (só números) e cole-o aqui:</li>
            </ol>
            <Label for="ga4-property" className="visually-hidden">ID da propriedade</Label>
            <Input id="ga4-property" inputMode="numeric" autoComplete="off" placeholder="Por exemplo, 398765432" value={property} maxLength={15}
                onChange={(e) => setProperty(e.target.value.replace(/\s/g, ""))} className="mb-2" />
            {error && <Alert color="danger" className="fs-13 py-2">{error}</Alert>}
            <Button color={primary ? "primary" : "outline-primary"} className="w-100" disabled={busy || !valid} onClick={verify}>
                {busy ? <Spinner size="sm" /> : <><i className="ri-shield-check-line me-1" />Verificar acesso</>}
            </Button>
            {!valid && property.trim() !== "" && <p className="text-muted fs-12 mt-1 mb-0 text-center">O ID da propriedade tem só números (não é o ID de medição "G-").</p>}
        </div>
    );
}
