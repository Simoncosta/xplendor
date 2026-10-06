import { useState } from "react";
import PostPreview from "pages/Editorial/PostPreview";
import { Network, POST_CHANNEL_META, channelIcons } from "common/models/editorialPost.model";
import { mediaSrc } from "common/models/editorialWorkflow.model";
import { ITEM_STATE_META, ReviewAccount, ReviewItem, ReviewPayload } from "common/models/contentReview.model";

/**
 * Página do link de aprovação (pensada para telemóvel), partilhada pela página pública e
 * pelo "Ver como o cliente" da equipa: cada publicação com a pré-visualização como na rede
 * (componentes da F3b), a decisão (aprovar ou pedir alterações), comentários partilhados e
 * "Aprovar tudo". Estados honestos: item atualizado pela equipa (sem ações até reenviar),
 * link expirado ou revogado (só consulta).
 */

export type ActionResult = { ok: boolean; message?: string };
export type ReviewActions = {
    approve: (itemId: number) => Promise<ActionResult>;
    requestChanges: (itemId: number, message: string) => Promise<ActionResult>;
    comment: (itemId: number, body: string) => Promise<ActionResult>;
    approveAll: () => Promise<ActionResult>;
};

const dmy = (iso: string | null) => (iso ? iso.slice(0, 10).split("-").reverse().join("/") : "");
const dmyhm = (iso: string | null) => {
    if (!iso) return "";
    const d = new Date(iso);
    return d.toLocaleString("pt-PT", { day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit", timeZone: "Europe/Lisbon" });
};

/** A conta ligada da rede; sem ligação, o logótipo e o nome da empresa. */
function identity(data: ReviewPayload, network: Network): { name: string; username: string | null; avatar: string | null } {
    const acc: ReviewAccount | null = network === "facebook" ? data.accounts.facebook : data.accounts.instagram;
    return {
        name: acc?.name || data.company.name,
        username: network === "instagram" ? acc?.username ?? null : null,
        avatar: acc?.avatar_url || data.company.logo_url,
    };
}

/** As pré-visualizações de uma publicação, uma por rede (com duas redes, escolhe-se qual ver). */
function NetworkPreviews({ data, item }: { data: ReviewPayload; item: ReviewItem }) {
    const [shown, setShown] = useState<Network>(item.networks[0]?.network ?? "instagram");
    const n = item.networks.find((x) => x.network === shown) ?? item.networks[0];
    if (!n) return null;
    const who = identity(data, n.network);
    return (
        <div>
            {item.networks.length > 1 && (
                <div className="btn-group btn-group-sm w-100 mb-2" role="tablist" aria-label="Rede">
                    {item.networks.map((x) => (
                        <button key={x.network} type="button" role="tab" aria-selected={shown === x.network} className={`btn ${shown === x.network ? "btn-primary" : "btn-outline-primary"}`} onClick={() => setShown(x.network)}>
                            <i className={`${POST_CHANNEL_META[x.network].icon} me-1`} />{POST_CHANNEL_META[x.network].label}
                        </button>
                    ))}
                </div>
            )}
            <div className="text-muted fs-11 text-center mb-1">Pré-visualização aproximada{item.networks.length > 1 ? ` no ${POST_CHANNEL_META[n.network].label}` : ""}</div>
            <PostPreview channel={n.network} mediaFormat={n.media_format} caption={n.caption} hashtags={item.hashtags}
                items={item.media.items} cover={item.media.cover} accountName={who.name} avatarUrl={who.avatar} username={who.username} />
        </div>
    );
}

type Props = {
    data: ReviewPayload; name: string; onName: (v: string) => void; actions?: ReviewActions;
    /** Tema fixo no contentor (página pública: o do telemóvel). Sem ele, segue o da app ("Ver como o cliente"). */
    theme?: "light" | "dark";
};

export default function ContentReviewView({ data, name, onName, actions, theme }: Props) {
    const [tab, setTab] = useState<"posts" | "grid">("posts");
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<{ key: string; message: string } | null>(null);
    const [changesFor, setChangesFor] = useState<number | null>(null);
    const [changesMsg, setChangesMsg] = useState("");
    const [commentFor, setCommentFor] = useState<Record<number, string>>({});
    const [confirmAll, setConfirmAll] = useState(false);
    const nameOk = name.trim().length > 0;
    const igItems = data.items.filter((i) => i.networks.some((n) => n.network === "instagram"));
    const pending = data.counts.pending;

    const run = async (key: string, fn: () => Promise<ActionResult>, after?: () => void) => {
        if (!actions) return;
        if (!nameOk) { setError({ key, message: "Indique o seu nome no topo antes de decidir." }); return; }
        setBusy(key);
        setError(null);
        const r = await fn();
        setBusy(null);
        if (r.ok) after?.();
        else setError({ key, message: r.message ?? "Não foi possível registar. Tente de novo." });
    };
    const err = (key: string) => (error?.key === key ? <div className="text-danger fs-13 mt-2" role="alert">{error.message}</div> : null);

    return (
        <div className="cr-page" data-bs-theme={theme}>
            <div className="cr-wrap">
                <header className="d-flex align-items-center gap-2 mb-3">
                    {data.company.logo_url
                        ? <img src={mediaSrc(data.company.logo_url)} alt="" className="rounded-circle border" style={{ width: 40, height: 40, objectFit: "cover" }} />
                        : <span className="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center fw-semibold" style={{ width: 40, height: 40 }}>{data.company.name.slice(0, 1)}</span>}
                    <div className="min-w-0">
                        <div className="fw-semibold text-truncate">{data.company.name}</div>
                        <div className="text-muted fs-13 text-truncate">{data.title}</div>
                    </div>
                </header>

                {data.preview && (
                    <div className="alert alert-info fs-13 py-2"><i className="ri-eye-line me-1" />Assim vê o cliente. As ações estão desativadas e esta visita não conta como abertura.</div>
                )}
                {data.state_message && <div className="alert alert-warning fs-13 py-2"><i className="ri-lock-line me-1" />{data.state_message}</div>}

                <h1 className="fs-4 mb-1">Publicações para aprovar</h1>
                <p className="text-muted fs-13 mb-3">
                    {data.counts.pending > 0 ? `${data.counts.pending} à espera da sua decisão` : "Sem publicações à espera"}
                    {data.counts.approved > 0 && ` · ${data.counts.approved} ${data.counts.approved === 1 ? "aprovada" : "aprovadas"}`}
                    {data.counts.changes_requested > 0 && ` · ${data.counts.changes_requested} com alterações pedidas`}
                    {data.state === "open" && ` · válido até ${dmy(data.expires_at)}`}
                </p>

                {data.can_act && (
                    <div className="card card-body p-3 mb-3">
                        <label className="form-label fs-13 mb-1" htmlFor="cr-name">O seu nome</label>
                        <input id="cr-name" className="form-control" autoComplete="name" maxLength={120} value={name} onChange={(e) => onName(e.target.value)} placeholder="Para registar quem decide" />
                        {pending > 1 && (
                            <div className="mt-3">
                                {!confirmAll ? (
                                    <button type="button" className="btn btn-success w-100" disabled={!!busy} onClick={() => (nameOk ? setConfirmAll(true) : setError({ key: "all", message: "Indique o seu nome antes de decidir." }))}>
                                        <i className="ri-check-double-line me-1" />Aprovar tudo ({pending})
                                    </button>
                                ) : (
                                    <div className="border rounded p-2 fs-13">
                                        Aprovar as {pending} publicações pendentes em nome de <strong>{name.trim()}</strong>?
                                        <div className="d-flex gap-2 mt-2">
                                            <button type="button" className="btn btn-success btn-sm flex-grow-1" disabled={!!busy} onClick={() => run("all", actions!.approveAll, () => setConfirmAll(false))}>
                                                {busy === "all" ? "A aprovar…" : "Sim, aprovar tudo"}
                                            </button>
                                            <button type="button" className="btn btn-light btn-sm" onClick={() => setConfirmAll(false)}>Cancelar</button>
                                        </div>
                                    </div>
                                )}
                                {err("all")}
                            </div>
                        )}
                    </div>
                )}

                {igItems.length > 0 && (
                    <div className="btn-group w-100 mb-3" role="tablist" aria-label="Vista">
                        <button type="button" role="tab" aria-selected={tab === "posts"} className={`btn btn-sm ${tab === "posts" ? "btn-primary" : "btn-outline-primary"}`} onClick={() => setTab("posts")}>
                            <i className="ri-smartphone-line me-1" />Publicações
                        </button>
                        <button type="button" role="tab" aria-selected={tab === "grid"} className={`btn btn-sm ${tab === "grid" ? "btn-primary" : "btn-outline-primary"}`} onClick={() => setTab("grid")}>
                            <i className="ri-layout-grid-line me-1" />Grelha do perfil
                        </button>
                    </div>
                )}

                {tab === "grid" ? (
                    <ProfileGrid data={data} onOpen={(id) => { setTab("posts"); setTimeout(() => document.getElementById(`cr-item-${id}`)?.scrollIntoView({ behavior: "smooth" }), 50); }} />
                ) : data.items.map((item) => {
                    const meta = ITEM_STATE_META[item.state];
                    const k = (s: string) => `${s}-${item.id}`;
                    return (
                        <article key={item.id} id={`cr-item-${item.id}`} className="card mb-3">
                            <div className="card-body p-3">
                                <div className="d-flex align-items-start justify-content-between gap-2 mb-2">
                                    <div className="min-w-0">
                                        <div className="fw-semibold">{channelIcons(item).map((c) => <i key={c.icon} className={`${c.icon} me-1`} title={c.label} />)}{item.title}</div>
                                        <div className="text-muted fs-12">{dmy(item.publish_date)}{item.version_number ? ` · versão ${item.version_number}` : ""}</div>
                                    </div>
                                    <span className={`badge bg-${meta.color}-subtle text-${meta.color} text-wrap text-end`}><i className={`${meta.icon} me-1`} />{meta.label}</span>
                                </div>

                                {item.media_available ? (
                                    <NetworkPreviews data={data} item={item} />
                                ) : (
                                    <div className="border rounded p-3 fs-13">
                                        <div className="text-muted mb-2"><i className="ri-image-line me-1" />Os ficheiros deixaram de estar disponíveis neste link.</div>
                                        <div style={{ whiteSpace: "pre-line" }}>{[item.caption, item.hashtags.join(" ")].filter(Boolean).join("\n\n")}</div>
                                    </div>
                                )}
                                {(item.cta || item.first_comment) && (
                                    <div className="fs-13 mt-2">
                                        {item.cta && <div><strong>Chamada à ação:</strong> {item.cta}</div>}
                                        {item.first_comment && <div><strong>Primeiro comentário:</strong> {item.first_comment}</div>}
                                    </div>
                                )}

                                {item.decision && (
                                    <div className={`alert ${item.decision.decision === "approved" ? "alert-success" : "alert-warning"} fs-13 py-2 mt-3 mb-0`}>
                                        {item.decision.decision === "approved" ? "Aprovada" : "Alterações pedidas"} por <strong>{item.decision.reviewer_name}</strong>
                                        {item.decision.created_at && ` em ${dmyhm(item.decision.created_at)}`}
                                        {item.decision.message && <div className="mt-1" style={{ whiteSpace: "pre-line" }}>{item.decision.message}</div>}
                                    </div>
                                )}
                                {item.state === "outdated" && (
                                    <div className="alert alert-secondary fs-13 py-2 mt-3 mb-0">
                                        <i className="ri-refresh-line me-1" />A equipa atualizou esta publicação depois de a enviar. Aguarde que a volte a enviar neste link para decidir.
                                    </div>
                                )}

                                {item.can_act && actions && (
                                    <div className="mt-3">
                                        {changesFor === item.id ? (
                                            <div>
                                                <label className="form-label fs-13 mb-1" htmlFor={k("msg")}>O que deve ser alterado?</label>
                                                <textarea id={k("msg")} className="form-control mb-2" rows={3} maxLength={3000} value={changesMsg} onChange={(e) => setChangesMsg(e.target.value)} />
                                                <div className="d-flex gap-2">
                                                    <button type="button" className="btn btn-warning flex-grow-1" disabled={!!busy || changesMsg.trim().length < 3}
                                                        onClick={() => run(k("chg"), () => actions.requestChanges(item.id, changesMsg.trim()), () => { setChangesFor(null); setChangesMsg(""); })}>
                                                        {busy === k("chg") ? "A enviar…" : "Enviar pedido"}
                                                    </button>
                                                    <button type="button" className="btn btn-light" onClick={() => setChangesFor(null)}>Cancelar</button>
                                                </div>
                                                {err(k("chg"))}
                                            </div>
                                        ) : (
                                            <div className="d-flex gap-2">
                                                <button type="button" className="btn btn-success flex-grow-1" disabled={!!busy} onClick={() => run(k("ok"), () => actions.approve(item.id))}>
                                                    <i className="ri-check-line me-1" />{busy === k("ok") ? "A aprovar…" : "Aprovar"}
                                                </button>
                                                <button type="button" className="btn btn-outline-warning flex-grow-1" disabled={!!busy} onClick={() => { setChangesFor(item.id); setChangesMsg(""); }}>
                                                    <i className="ri-chat-1-line me-1" />Pedir alterações
                                                </button>
                                            </div>
                                        )}
                                        {err(k("ok"))}
                                    </div>
                                )}

                                {(item.comments.length > 0 || (data.can_act && actions)) && (
                                    <div className="border-top mt-3 pt-2">
                                        <div className="fw-semibold fs-13 mb-1">Comentários</div>
                                        {item.comments.map((c) => (
                                            <div key={c.id} className="fs-13 mb-2">
                                                <strong>{c.author}</strong> <span className="text-muted fs-11">{dmyhm(c.created_at)}{c.version_number ? ` · versão ${c.version_number}` : ""}</span>
                                                <div style={{ whiteSpace: "pre-line" }}>{c.body}</div>
                                            </div>
                                        ))}
                                        {data.can_act && actions && (
                                            <div className="d-flex gap-2">
                                                <input className="form-control form-control-sm" maxLength={3000} placeholder="Escrever um comentário" aria-label={`Comentário sobre ${item.title}`}
                                                    value={commentFor[item.id] ?? ""} onChange={(e) => setCommentFor((m) => ({ ...m, [item.id]: e.target.value }))} />
                                                <button type="button" className="btn btn-sm btn-primary" disabled={!!busy || !(commentFor[item.id] ?? "").trim()}
                                                    onClick={() => run(k("com"), () => actions.comment(item.id, (commentFor[item.id] ?? "").trim()), () => setCommentFor((m) => ({ ...m, [item.id]: "" })))}>
                                                    Enviar
                                                </button>
                                            </div>
                                        )}
                                        {err(k("com"))}
                                    </div>
                                )}
                            </div>
                        </article>
                    );
                })}

                <footer className="text-muted fs-12 text-center mt-4">
                    Ao decidir ou comentar, ficam registados o nome indicado, a decisão e a data. Não guardamos o endereço IP.{" "}
                    <a href="https://xplendor.tech/politica-de-privacidade/#aprovacao-conteudos" target="_blank" rel="noreferrer noopener">Política de privacidade</a>
                </footer>
            </div>
        </div>
    );
}

/** As publicações de Instagram do lote como ficam no perfil (da mais recente para a mais antiga). */
function ProfileGrid({ data, onOpen }: { data: ReviewPayload; onOpen: (itemId: number) => void }) {
    const items = data.items.filter((i) => i.networks.some((n) => n.network === "instagram")).sort((a, b) => (b.publish_date ?? "").localeCompare(a.publish_date ?? ""));
    const ig = data.accounts.instagram;
    return (
        <div>
            <div className="d-flex align-items-center gap-2 mb-2">
                {(ig?.avatar_url || data.company.logo_url)
                    ? <img src={mediaSrc(ig?.avatar_url || data.company.logo_url)} alt="" className="rounded-circle" style={{ width: 44, height: 44, objectFit: "cover" }} />
                    : null}
                <strong>{ig?.username || ig?.name || data.company.name}</strong>
            </div>
            <div className="d-grid" style={{ gridTemplateColumns: "repeat(3, 1fr)", gap: 3 }}>
                {items.map((i) => {
                    const first = i.media.cover ?? i.media.items[0] ?? null;
                    const meta = ITEM_STATE_META[i.state];
                    return (
                        <button key={i.id} type="button" onClick={() => onOpen(i.id)} title={`${i.title} · ${meta.label}`}
                            className="position-relative border-0 p-0 overflow-hidden bg-light" style={{ aspectRatio: "3 / 4" }}>
                            {first?.preview_url
                                ? <img src={mediaSrc(first.preview_url)} alt="" className="w-100 h-100" style={{ objectFit: "cover" }} />
                                : <span className="w-100 h-100 d-flex align-items-center justify-content-center text-muted fs-12 p-2 text-center">{i.title}</span>}
                            {(i.networks.find((n) => n.network === "instagram")?.media_format === "ig_reel" || i.media.items.length > 1) && (
                                <i className={`${i.networks.find((n) => n.network === "instagram")?.media_format === "ig_reel" ? "ri-film-line" : "ri-stack-line"} position-absolute top-0 end-0 m-1 text-white fs-16`} style={{ textShadow: "0 0 3px #000" }} />
                            )}
                            <span className={`position-absolute bottom-0 start-0 m-1 badge bg-${meta.color}`} style={{ fontSize: "0.6rem" }}>{dmy(i.publish_date).slice(0, 5)}</span>
                        </button>
                    );
                })}
            </div>
            <p className="text-muted fs-12 mt-2">Só as publicações deste lote. Toque numa para a ver e decidir.</p>
        </div>
    );
}
