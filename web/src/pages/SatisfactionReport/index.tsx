import { useEffect, useMemo, useRef, useState, type ReactElement } from "react";
import { useParams } from "react-router-dom";
import "./satisfaction-report.css";

/**
 * DMS Pós-venda — Relatório de Satisfação (página PÚBLICA, Incremento 1).
 *
 * Conceito: NÃO é uma página-relatório — é o PAINEL DIGITAL DO CARRO (cockpit).
 * O cliente entra num espaço premium do SEU veículo: hero cinematográfico com
 * o carro + widgets em cartões, preparados para CRESCER (avaliação, fotos,
 * garantia entram como widgets nos próximos incrementos).
 *
 * A montra da XPLENDOR: logo do stand no topo, marca XPLENDOR no rodapé.
 * Mobile-first. Estilo 100% isolado (satisfaction-report.css sob `.xsr`, com
 * ícones SVG inline — sem depender de fontes/CSS globais do painel).
 *
 * SÓ estética: fetch, token, estados e persistência mantêm-se intocados.
 */

const PUBLIC_URL = process.env.REACT_APP_PUBLIC_URL ?? "";
const XPLENDOR_SITE = "https://xplendor.tech";

interface ReportImage { url: string; }
interface ReportData {
    status: string;
    rating: number | null;
    review_message: string | null;
    company: {
        name: string | null;
        logo_path: string | null;
        google_review_url: string | null;
        website: string | null;
        instagram: string | null;
        facebook: string | null;
        youtube: string | null;
    };
    car: {
        brand: string | null;
        model: string | null;
        version: string | null;
        registration_year: number | null;
        mileage_km: number | null;
        images: ReportImage[];
    };
    warranty: { total_months: number | null; start_date: string | null };
    photos: { id: number; url: string }[];
}

interface ClientPhoto { id: number; url: string; }
const MAX_PHOTOS = 3;
const ALLOWED_TYPES = ["image/jpeg", "image/png", "image/webp"];
const MAX_BYTES = 8 * 1024 * 1024;

type LoadState = "loading" | "error" | "ready";

const absUrl = (path: string | null): string | null => {
    if (!path) return null;
    return path.startsWith("http") ? path : PUBLIC_URL + path;
};

/* Ícones SVG inline (currentColor) — sem dependência de fontes globais. */
type IconName = "car" | "spec" | "message" | "star" | "camera" | "shield" | "spark";
function Icon({ name }: { name: IconName }) {
    const p: Record<IconName, ReactElement> = {
        car: <path d="M5 11l1.5-4.5A2 2 0 018.4 5h7.2a2 2 0 011.9 1.5L19 11m-14 0h14m-14 0a2 2 0 00-2 2v3h2m14-5a2 2 0 012 2v3h-2m-2 0H7m10 0v2m-10-2v2M7 14h.01M17 14h.01" />,
        spec: <path d="M4 6h16M4 12h16M4 18h10" />,
        message: <path d="M21 12a8 8 0 01-11.5 7.2L4 20l.8-5.5A8 8 0 1121 12z" />,
        star: <path d="M12 4l2.4 4.9 5.4.8-3.9 3.8.9 5.4-4.8-2.5-4.8 2.5.9-5.4L4.2 9.7l5.4-.8z" />,
        camera: <path d="M4 8h3l1.5-2h7L17 8h3a1 1 0 011 1v9a1 1 0 01-1 1H4a1 1 0 01-1-1V9a1 1 0 011-1zm8 3a3.5 3.5 0 100 7 3.5 3.5 0 000-7z" />,
        shield: <path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3zM9.5 12l1.8 1.8L15 10" />,
        spark: <path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z" />,
    };
    return (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
            {p[name]}
        </svg>
    );
}

/* Mostrador de GARANTIA — arco circular "quanto falta" (decorrido vs total). */
function WarrantyGauge({ totalMonths, startDate }: { totalMonths: number; startDate: string | null }) {
    const start = startDate ? new Date(startDate) : null;
    const elapsedMonths = start && !isNaN(start.getTime())
        ? Math.max(0, (Date.now() - start.getTime()) / (1000 * 60 * 60 * 24 * 30.44))
        : 0;
    const remaining = Math.max(0, totalMonths - elapsedMonths);
    const monthsLeft = Math.ceil(remaining);
    const ended = monthsLeft <= 0;
    const fraction = Math.min(1, Math.max(0, remaining / totalMonths)); // verde = o que falta

    const R = 54;
    const C = 2 * Math.PI * R;
    const dash = C * fraction;

    return (
        <div className="xsr-gauge">
            <div className="xsr-dial">
                <svg viewBox="0 0 128 128">
                    <circle className="xsr-dial-track" cx="64" cy="64" r={R} />
                    {!ended && (
                        <circle
                            className="xsr-dial-fill"
                            cx="64" cy="64" r={R}
                            strokeDasharray={`${dash} ${C - dash}`}
                            transform="rotate(-90 64 64)"
                        />
                    )}
                </svg>
                <div className="xsr-dial-center">
                    {ended ? (
                        <span className="xsr-dial-ended">Garantia<br />terminada</span>
                    ) : (
                        <>
                            <span className="xsr-dial-num">{monthsLeft}</span>
                            <span className="xsr-dial-unit">{monthsLeft === 1 ? "mês" : "meses"}</span>
                        </>
                    )}
                </div>
            </div>
            <div className="xsr-gauge-label">
                <span className="xsr-w-ico xsr-ico-sm"><Icon name="shield" /></span>
                <span>Garantia{ended ? "" : " · restante"}</span>
            </div>
        </div>
    );
}

/* Mostrador de QUILÓMETROS — valor em destaque (não é progresso). */
function KmDial({ km }: { km: number }) {
    const R = 54;
    const C = 2 * Math.PI * R;
    const arc = C * 0.62; // sweep decorativo constante (instrumento, não progresso)
    const value = new Intl.NumberFormat("pt-PT").format(km);

    return (
        <div className="xsr-gauge">
            <div className="xsr-dial">
                <svg viewBox="0 0 128 128">
                    <circle className="xsr-dial-track" cx="64" cy="64" r={R}
                        strokeDasharray={`${C * 0.75} ${C * 0.25}`} transform="rotate(135 64 64)" />
                    <circle className="xsr-dial-fill xsr-dial-km" cx="64" cy="64" r={R}
                        strokeDasharray={`${arc} ${C - arc}`} transform="rotate(135 64 64)" />
                </svg>
                <div className="xsr-dial-center">
                    <span className="xsr-dial-num xsr-dial-num-km">{value}</span>
                    <span className="xsr-dial-unit">km</span>
                </div>
            </div>
            <div className="xsr-gauge-label">
                <span className="xsr-w-ico xsr-ico-sm"><Icon name="spec" /></span>
                <span>Quilómetros</span>
            </div>
        </div>
    );
}

/* Redes sociais do stand — só as preenchidas; se nenhuma, não renderiza. */
type SocialKind = "website" | "instagram" | "facebook" | "youtube";
const SOCIAL_ICON: Record<SocialKind, ReactElement> = {
    website: (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
            <circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3c2.6 2.7 2.6 15.3 0 18M12 3c-2.6 2.7-2.6 15.3 0 18" />
        </svg>
    ),
    instagram: (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
            <rect x="3" y="3" width="18" height="18" rx="5" /><circle cx="12" cy="12" r="4" />
            <circle cx="17.2" cy="6.8" r="1.1" fill="currentColor" stroke="none" />
        </svg>
    ),
    facebook: (
        <svg viewBox="0 0 24 24" fill="currentColor">
            <path d="M14 8.5V7c0-.7.3-1 1-1h1.5V3H14c-2 0-3.5 1.5-3.5 3.7V8.5H8.5v3h2V21H14v-9.5h2.3l.4-3H14z" />
        </svg>
    ),
    youtube: (
        <svg viewBox="0 0 24 24" fill="currentColor">
            <path d="M22 8.2a3 3 0 0 0-2.1-2.1C18 5.6 12 5.6 12 5.6s-6 0-7.9.5A3 3 0 0 0 2 8.2 31 31 0 0 0 1.7 12 31 31 0 0 0 2 15.8a3 3 0 0 0 2.1 2.1c1.9.5 7.9.5 7.9.5s6 0 7.9-.5a3 3 0 0 0 2.1-2.1c.3-1.9.3-3.8.3-3.8s0-1.9-.3-3.8zM10 15V9l5.2 3z" />
        </svg>
    ),
};
const SOCIAL_LABEL: Record<SocialKind, string> = { website: "Website", instagram: "Instagram", facebook: "Facebook", youtube: "YouTube" };

function SocialLinks({ socials }: { socials: Record<SocialKind, string | null> }) {
    const items = (Object.keys(SOCIAL_ICON) as SocialKind[])
        .map((k) => ({ k, url: socials[k] }))
        .filter((i): i is { k: SocialKind; url: string } => !!i.url && i.url.trim() !== "");

    if (items.length === 0) return null;

    return (
        <div className="xsr-socials-inline">
            <span className="xsr-socials-label">Siga-nos:</span>
            <div className="xsr-socials">
                {items.map(({ k, url }) => (
                    <a key={k} className="xsr-social" href={url} target="_blank" rel="noopener noreferrer" aria-label={SOCIAL_LABEL[k]}>
                        {SOCIAL_ICON[k]}
                    </a>
                ))}
            </div>
        </div>
    );
}

/* Estrela clicável (preenchida ou contorno). */
function Star({ filled }: { filled: boolean }) {
    return (
        <svg viewBox="0 0 24 24" fill={filled ? "currentColor" : "none"} stroke="currentColor"
            strokeWidth="1.6" strokeLinejoin="round" aria-hidden>
            <path d="M12 3.6l2.5 5.1 5.6.8-4.05 3.95.96 5.6L12 16.9l-5.02 2.65.96-5.6L3.9 9.5l5.6-.8z" />
        </svg>
    );
}

/* Widget "A sua avaliação" — estrelas + comentário + ramo ≥4/<4 (Incremento 3). */
function RatingWidget({ token, companyName, googleReviewUrl, initial }: {
    token?: string;
    companyName: string;
    googleReviewUrl: string | null;
    initial: { status: string; rating: number | null; review_message: string | null };
}) {
    const alreadyDone = initial.status === "submitted" || !!initial.rating;
    const [done, setDone] = useState(alreadyDone);
    const [finalRating, setFinalRating] = useState(initial.rating ?? 0);
    const [finalMessage, setFinalMessage] = useState(initial.review_message ?? "");

    const [rating, setRating] = useState(0);
    const [hover, setHover] = useState(0);
    const [comment, setComment] = useState("");
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);

    const standName = companyName || "o stand";

    const submit = async () => {
        if (!token || rating < 1) return;
        setError(null);
        setBusy(true);
        try {
            const res = await fetch(`${PUBLIC_URL}/api/public/report/${token}/rating`, {
                method: "POST",
                headers: { "Content-Type": "application/json", Accept: "application/json" },
                body: JSON.stringify({ rating, comment: comment.trim() || null }),
            });
            const body = await res.json().catch(() => null);
            if (!res.ok) {
                setError(res.status === 422 ? (body?.message ?? "Não foi possível submeter.")
                    : res.status === 429 ? "Demasiadas tentativas. Aguarde um momento."
                    : "Não foi possível submeter a avaliação.");
                return;
            }
            setFinalRating(rating);
            setFinalMessage(comment.trim());
            setDone(true);
        } catch {
            setError("Falha de ligação. Tente novamente.");
        } finally {
            setBusy(false);
        }
    };

    const copy = () => {
        if (!finalMessage) return;
        navigator.clipboard?.writeText(finalMessage).then(
            () => { setCopied(true); setTimeout(() => setCopied(false), 2000); },
            () => {}
        );
    };

    // ---- Estado submetido ----
    if (done) {
        const high = finalRating >= 4;
        return (
            <section className="xsr-widget">
                <div className="xsr-w-head">
                    <span className="xsr-w-ico"><Icon name="star" /></span>
                    <h2>A sua avaliação</h2>
                </div>
                <div className="xsr-stars xsr-stars-static" aria-label={`${finalRating} de 5`}>
                    {[1, 2, 3, 4, 5].map((n) => (
                        <span key={n} className={"xsr-star" + (n <= finalRating ? " is-on" : "")}><Star filled={n <= finalRating} /></span>
                    ))}
                </div>

                {high ? (
                    <>
                        <p className="xsr-rate-thanks">Que bom que gostou! 💚 Ajudava-nos imenso se deixasse esta avaliação no Google.</p>
                        {finalMessage && (
                            <div className="xsr-review-quote">
                                <p>“{finalMessage}”</p>
                                <button type="button" className="xsr-btn-ghost" onClick={copy}>
                                    <span className="xsr-drop-ico xsr-ico-inline"><Icon name="spec" /></span>
                                    {copied ? "Copiado!" : "Copiar mensagem"}
                                </button>
                            </div>
                        )}
                        {googleReviewUrl && (
                            <a className="xsr-btn-primary" href={googleReviewUrl} target="_blank" rel="noopener noreferrer">
                                Ir para o Google
                            </a>
                        )}
                    </>
                ) : (
                    <p className="xsr-rate-thanks">Obrigado pelo seu feedback. Vamos usá-lo para melhorar{companyName ? ` na ${companyName}` : ""}. 🙏</p>
                )}
            </section>
        );
    }

    // ---- Estado por avaliar ----
    const shown = hover || rating;
    return (
        <section className="xsr-widget">
            <div className="xsr-w-head">
                <span className="xsr-w-ico"><Icon name="star" /></span>
                <h2>A sua avaliação</h2>
            </div>
            <p className="xsr-rate-intro">Como foi a sua experiência de compra?</p>

            <div className="xsr-stars xsr-stars-pick">
                {[1, 2, 3, 4, 5].map((n) => (
                    <button
                        type="button"
                        key={n}
                        className={"xsr-star-btn" + (n <= shown ? " is-on" : "")}
                        onClick={() => setRating(n)}
                        onMouseEnter={() => setHover(n)}
                        onMouseLeave={() => setHover(0)}
                        aria-label={`${n} estrela${n > 1 ? "s" : ""}`}
                    >
                        <Star filled={n <= shown} />
                    </button>
                ))}
            </div>

            <textarea
                className="xsr-textarea"
                rows={3}
                value={comment}
                onChange={(e) => setComment(e.target.value)}
                placeholder="Deixe o seu comentário (opcional)"
                maxLength={2000}
            />

            <button type="button" className="xsr-btn-primary xsr-btn-block" onClick={submit} disabled={busy || rating < 1}>
                {busy ? "A enviar…" : "Enviar avaliação"}
            </button>
            <p className="xsr-consent">Só pode avaliar uma vez, por isso confirme antes de enviar. Autoriza {standName} a usar a sua avaliação.</p>
            {error && <p className="xsr-error-text">{error}</p>}
        </section>
    );
}

/* Widget "As suas fotos" — upload público do cliente (Incremento 2). */
function ClientPhotos({ token, companyName, initial }: { token?: string; companyName: string; initial: ClientPhoto[] }) {
    const [photos, setPhotos] = useState<ClientPhoto[]>(initial);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const fileRef = useRef<HTMLInputElement | null>(null);

    const full = photos.length >= MAX_PHOTOS;
    const standName = companyName || "o stand";

    const onPick = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        e.target.value = ""; // permite re-escolher o mesmo ficheiro
        if (!file || !token) return;
        setError(null);

        if (!ALLOWED_TYPES.includes(file.type)) { setError("Formato inválido. Use JPG, PNG ou WebP."); return; }
        if (file.size > MAX_BYTES) { setError("A foto é demasiado grande (máx. 8 MB)."); return; }

        const fd = new FormData();
        fd.append("photo", file);
        setBusy(true);
        try {
            const res = await fetch(`${PUBLIC_URL}/api/public/report/${token}/photos`, {
                method: "POST", headers: { Accept: "application/json" }, body: fd,
            });
            const body = await res.json().catch(() => null);
            if (!res.ok) {
                setError(res.status === 422 ? (body?.message ?? "Não foi possível carregar a foto.")
                    : res.status === 429 ? "Demasiadas tentativas. Aguarde um momento."
                    : "Não foi possível carregar a foto.");
                return;
            }
            if (body?.data?.id) setPhotos((prev) => [...prev, { id: body.data.id, url: body.data.url }]);
        } catch {
            setError("Falha de ligação. Tente novamente.");
        } finally {
            setBusy(false);
        }
    };

    const remove = async (id: number) => {
        if (!token) return;
        setError(null);
        const prev = photos;
        setPhotos((p) => p.filter((x) => x.id !== id)); // otimista
        try {
            const res = await fetch(`${PUBLIC_URL}/api/public/report/${token}/photos/${id}`, {
                method: "DELETE", headers: { Accept: "application/json" },
            });
            if (!res.ok) { setPhotos(prev); setError("Não foi possível remover a foto."); }
        } catch {
            setPhotos(prev); setError("Falha de ligação ao remover.");
        }
    };

    return (
        <section className="xsr-widget">
            <div className="xsr-w-head">
                <span className="xsr-w-ico"><Icon name="camera" /></span>
                <h2>As suas fotos</h2>
                <span className="xsr-count-pill">{photos.length}/{MAX_PHOTOS}</span>
            </div>

            {photos.length > 0 && (
                <div className="xsr-photos-grid">
                    {photos.map((p) => (
                        <div className="xsr-photo" key={p.id}>
                            <img src={absUrl(p.url) ?? ""} alt="A sua foto" />
                            <button type="button" className="xsr-photo-del" onClick={() => remove(p.id)} aria-label="Remover foto">×</button>
                        </div>
                    ))}
                </div>
            )}

            {!full ? (
                <>
                    <button type="button" className="xsr-upload" onClick={() => fileRef.current?.click()} disabled={busy}>
                        {busy ? (
                            <span className="xsr-upload-busy"><span className="xsr-spinner xsr-spinner-sm" /> A carregar…</span>
                        ) : (
                            <>
                                <span className="xsr-drop-ico"><Icon name="camera" /></span>
                                <span>Adicionar foto</span>
                            </>
                        )}
                    </button>
                    <input
                        ref={fileRef}
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        hidden
                        onChange={onPick}
                    />
                    <p className="xsr-consent">
                        Ao carregar, autoriza {standName} a usar estas fotos nas redes sociais (RGPD). Pode removê-las a qualquer momento.
                    </p>
                </>
            ) : (
                <p className="xsr-soon-text">Máximo de {MAX_PHOTOS} fotos atingido. Remova uma para adicionar outra.</p>
            )}

            {error && <p className="xsr-error-text">{error}</p>}
        </section>
    );
}

export default function SatisfactionReport() {
    const { token } = useParams();
    const [state, setState] = useState<LoadState>("loading");
    const [data, setData] = useState<ReportData | null>(null);
    const [active, setActive] = useState(0);
    const [logoBroken, setLogoBroken] = useState(false);
    const touchStartX = useRef<number | null>(null);

    useEffect(() => {
        let alive = true;
        setState("loading");

        fetch(`${PUBLIC_URL}/api/public/report/${token}`, {
            headers: { Accept: "application/json" },
        })
            .then(async (res) => {
                if (!res.ok) throw new Error(String(res.status));
                const body = await res.json();
                if (!alive) return;
                setData((body?.data as ReportData) ?? null);
                setState("ready");
            })
            .catch(() => {
                if (alive) setState("error");
            });

        return () => { alive = false; };
    }, [token]);

    const images = useMemo(
        () => (data?.car.images ?? []).map((i) => absUrl(i.url)).filter((u): u is string => !!u),
        [data]
    );

    const logoUrl = absUrl(data?.company.logo_path ?? null);
    const companyName = data?.company.name ?? "";

    const vehicleTitle = useMemo(() => {
        if (!data) return "";
        const parts = [data.car.brand, data.car.model].filter(Boolean);
        return parts.join(" ");
    }, [data]);

    if (state === "loading") {
        return (
            <div className="xsr">
                <div className="xsr-center">
                    <div className="xsr-spinner" aria-label="A carregar" />
                </div>
            </div>
        );
    }

    if (state === "error" || !data) {
        return (
            <div className="xsr">
                <div className="xsr-center">
                    <div className="xsr-error-card">
                        <div className="xsr-error-icon" aria-hidden>🔗</div>
                        <h2>Este link já não está disponível</h2>
                        <p>O relatório pode ter expirado ou o endereço não está correto. Se recebeu este link do stand, contacte-os para receber um novo.</p>
                    </div>
                    <div className="xsr-foot">
                        <a href={XPLENDOR_SITE} target="_blank" rel="noopener noreferrer">
                            <span className="xsr-foot-spark"><Icon name="spark" /></span> feito com <strong>XPLENDOR</strong>
                        </a>
                    </div>
                </div>
            </div>
        );
    }

    const go = (dir: number) => {
        if (images.length < 2) return;
        setActive((prev) => (prev + dir + images.length) % images.length);
    };

    const brandModel = [data.car.brand, data.car.model].filter(Boolean).join(" ");

    return (
        <div className="xsr">
            <div className="xsr-shell">
                {/* Barra de topo: logo do stand (fallback nome). */}
                <header className="xsr-topbar">
                    <div className="xsr-brand">
                        {logoUrl && !logoBroken ? (
                            <img src={logoUrl} alt={companyName} onError={() => setLogoBroken(true)} />
                        ) : (
                            <div className="xsr-brand-fallback">{companyName || "O seu stand"}</div>
                        )}
                    </div>
                </header>

                {/* Saudação premium — como o título de um painel de bordo. */}
                <div className="xsr-greeting">
                    <div className="xsr-eyebrow">A sua nova viatura</div>
                    <h1 className="xsr-title">{vehicleTitle || "O seu carro"}</h1>
                    <div className="xsr-subline">
                        {data.car.version && <span className="xsr-chip">{data.car.version}</span>}
                        {data.car.registration_year && <span className="xsr-chip">{data.car.registration_year}</span>}
                    </div>
                </div>

                {/* Dashboard modular. */}
                <div className="xsr-grid">
                    {/* HERO — o veículo em destaque cinematográfico. */}
                    <section className="xsr-widget xsr-hero-widget">
                        {images.length > 0 ? (
                            <>
                                <div
                                    className="xsr-stage"
                                    onTouchStart={(e) => { touchStartX.current = e.touches[0].clientX; }}
                                    onTouchEnd={(e) => {
                                        if (touchStartX.current === null) return;
                                        const dx = e.changedTouches[0].clientX - touchStartX.current;
                                        if (Math.abs(dx) > 40) go(dx < 0 ? 1 : -1);
                                        touchStartX.current = null;
                                    }}
                                >
                                    <img src={images[active]} alt={vehicleTitle || "Viatura"} key={active} />
                                    <div className="xsr-stage-scrim" />
                                    {images.length > 1 && (
                                        <>
                                            <button type="button" className="xsr-nav xsr-nav-prev" onClick={() => go(-1)} aria-label="Foto anterior">‹</button>
                                            <button type="button" className="xsr-nav xsr-nav-next" onClick={() => go(1)} aria-label="Foto seguinte">›</button>
                                            <div className="xsr-counter">{active + 1} / {images.length}</div>
                                        </>
                                    )}
                                </div>

                                {images.length > 1 && (
                                    <div className="xsr-thumbs">
                                        {images.map((src, i) => (
                                            <button
                                                type="button"
                                                key={src + i}
                                                className={"xsr-thumb" + (i === active ? " is-active" : "")}
                                                onClick={() => setActive(i)}
                                                aria-label={`Ver foto ${i + 1}`}
                                            >
                                                <img src={src} alt="" />
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </>
                        ) : (
                            <div className="xsr-no-photos">
                                <span className="xsr-w-ico"><Icon name="car" /></span>
                                As fotos da viatura ainda não estão disponíveis.
                            </div>
                        )}
                    </section>

                    {/* CLUSTER de instrumentos — o "tablier": mostradores + ficha
                        agrupados num só painel coeso. */}
                    <section className="xsr-widget xsr-cluster">
                        {(data.warranty.total_months || data.car.mileage_km) && (
                            <div className="xsr-instruments">
                                {data.warranty.total_months ? (
                                    <WarrantyGauge totalMonths={data.warranty.total_months} startDate={data.warranty.start_date} />
                                ) : null}
                                {data.car.mileage_km ? <KmDial km={data.car.mileage_km} /> : null}
                            </div>
                        )}
                        <div className="xsr-cluster-specs">
                            <div className="xsr-w-head">
                                <span className="xsr-w-ico"><Icon name="spec" /></span>
                                <h2>Ficha do veículo</h2>
                            </div>
                            <dl className="xsr-specs">
                                {brandModel && (<div><dt>Marca e modelo</dt><dd>{brandModel}</dd></div>)}
                                {data.car.version && (<div><dt>Versão</dt><dd>{data.car.version}</dd></div>)}
                                {data.car.registration_year && (<div><dt>Ano</dt><dd>{data.car.registration_year}</dd></div>)}
                            </dl>
                        </div>
                    </section>

                    {/* Mensagem do stand (parabéns, como widget). */}
                    <section className="xsr-widget xsr-welcome">
                        <div className="xsr-w-head">
                            <span className="xsr-w-ico"><Icon name="message" /></span>
                            <h2>Uma palavra do stand</h2>
                        </div>
                        <p className="xsr-welcome-text">
                            Parabéns pela sua nova viatura! 🎉 Obrigado pela confiança{companyName ? ` na ${companyName}` : ""} — desejamos-lhe muitos quilómetros felizes.
                        </p>
                        {/* Redes sociais do stand — dentro do próprio card, só as preenchidas. */}
                        <SocialLinks socials={{
                            website: data.company.website,
                            instagram: data.company.instagram,
                            facebook: data.company.facebook,
                            youtube: data.company.youtube,
                        }} />
                    </section>

                    {/* Avaliação — estrelas + ramo ≥4/<4 (Incremento 3). */}
                    <RatingWidget
                        token={token}
                        companyName={companyName}
                        googleReviewUrl={data.company.google_review_url}
                        initial={{ status: data.status, rating: data.rating, review_message: data.review_message }}
                    />

                    {/* Fotos do cliente — upload público funcional (Incremento 2). */}
                    <ClientPhotos token={token} companyName={companyName} initial={data.photos ?? []} />

                    {/* Widget "Revisão & garantia" escondido por enquanto (futuro). */}
                </div>

                <div className="xsr-foot">
                    <a href={XPLENDOR_SITE} target="_blank" rel="noopener noreferrer">
                        <span className="xsr-foot-spark"><Icon name="spark" /></span> feito com <strong>XPLENDOR</strong>
                    </a>
                </div>
            </div>
        </div>
    );
}
