import { useEffect, useState } from "react";
import { Badge, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { getEditorialGrid } from "helpers/laravel_helper";
import { FeedData, FeedStory, GridTile, STAGE_META, mediaSrc } from "common/models/editorialWorkflow.model";
import PostPreview from "./PostPreview";

/**
 * Feed: como o perfil vai ficar em cada rede.
 *  · Instagram: a grelha do perfil, as próximas (da mais distante para a mais próxima) por
 *    cima das já publicadas; três colunas, mosaicos 3:4.
 *  · Facebook: a cronologia da Página, uma publicação por baixo da outra.
 * Um clique abre o painel da publicação.
 */

const dm = (iso: string) => { const [, m, d] = iso.split("-"); return `${d}/${m}`; };

function Tile({ t, onOpen }: { t: GridTile; onOpen: (id: number) => void }) {
    const sm = STAGE_META[t.stage];
    const reel = t.media_format === "ig_reel";
    const carousel = t.media_format === "ig_carousel" || t.items_count > 1;
    return (
        <button type="button" onClick={() => onOpen(t.id)} title={`${sm.label}: ${t.title}`}
            className="position-relative border-0 p-0 overflow-hidden bg-light" style={{ aspectRatio: "3 / 4", cursor: "pointer" }}>
            {t.image_url
                ? <img src={mediaSrc(t.image_url)} alt="" className="w-100 h-100" style={{ objectFit: "cover" }} loading="lazy" />
                : <span className="w-100 h-100 d-flex align-items-center justify-content-center text-muted fs-12 p-2 text-center">{t.title}</span>}
            {(reel || carousel) && <i className={`${reel ? "ri-film-line" : "ri-stack-line"} position-absolute top-0 end-0 m-1 text-white fs-16`} style={{ textShadow: "0 0 3px #000" }} />}
            <span className="position-absolute bottom-0 start-0 end-0 d-flex align-items-center gap-1 px-1 fs-11 text-white" style={{ background: "linear-gradient(transparent, rgba(0,0,0,0.65))", paddingTop: 12 }}>
                <span className="rounded-circle flex-shrink-0" style={{ width: 8, height: 8, background: sm.hex }} />{dm(t.publish_date)}
            </span>
        </button>
    );
}

function Story({ s, data, onOpen }: { s: FeedStory; data: FeedData; onOpen: (id: number) => void }) {
    const sm = STAGE_META[s.stage];
    return (
        <div className="mb-3">
            <div className="d-flex align-items-center gap-2 mb-1 fs-12">
                <span className="rounded-circle flex-shrink-0" style={{ width: 8, height: 8, background: sm.hex }} />
                <span className="text-muted">{dm(s.publish_date)}{s.publish_time ? ` · ${s.publish_time}` : ""} · {sm.label}</span>
                <button type="button" className="btn btn-link btn-sm p-0 ms-auto" onClick={() => onOpen(s.id)}>Abrir a publicação</button>
            </div>
            <div role="button" onClick={() => onOpen(s.id)}>
                <PostPreview channel="facebook" mediaFormat={s.media_format} caption={s.caption} hashtags={s.hashtags} items={s.media.items} cover={s.media.cover}
                    accountName={data.accounts.facebook.name} avatarUrl={data.accounts.facebook.avatar_url} />
            </div>
        </div>
    );
}

export default function FeedView({ companyId, reloadKey, onOpen }: { companyId: number; reloadKey: number; onOpen: (id: number) => void }) {
    const [data, setData] = useState<FeedData | null>(null);
    const [network, setNetwork] = useState<"instagram" | "facebook">("instagram");

    useEffect(() => {
        let alive = true;
        getEditorialGrid(companyId)
            .then((r: any) => { if (alive) setData(r.data); })
            .catch((e: any) => { if (alive) toast.error(e?.message || "Não foi possível carregar o feed."); });
        return () => { alive = false; };
    }, [companyId, reloadKey]);

    if (!data) return <div className="text-center py-5"><Spinner /></div>;

    const grid = (tiles: GridTile[]) => <div className="d-grid" style={{ gridTemplateColumns: "repeat(3, 1fr)", gap: 3 }}>{tiles.map((t) => <Tile key={t.id} t={t} onOpen={onOpen} />)}</div>;
    const divider = (label: string) => <div className="d-flex align-items-center gap-2 my-3 text-muted fs-12"><hr className="flex-grow-1 my-0" /><span>{label}</span><hr className="flex-grow-1 my-0" /></div>;
    const ig = data.accounts.instagram;
    const fb = data.facebook;

    return (
        <div className="mx-auto" style={{ maxWidth: 520 }}>
            <div className="btn-group w-100 mb-3" role="tablist" aria-label="Rede">
                {(["instagram", "facebook"] as const).map((n) => (
                    <button key={n} type="button" role="tab" aria-selected={network === n} className={`btn btn-sm ${network === n ? "btn-primary" : "btn-outline-primary"}`} onClick={() => setNetwork(n)}>
                        <i className={`${n === "instagram" ? "ri-instagram-line" : "ri-facebook-circle-line"} me-1`} />{n === "instagram" ? "Instagram" : "Facebook"}
                        <Badge color="light" className="text-body ms-1">{n === "instagram" ? data.upcoming.length : fb.upcoming.length}</Badge>
                    </button>
                ))}
            </div>
            {network === "instagram" ? (
                <>
                    <div className="d-flex align-items-center gap-2 mb-2">
                        {ig.avatar_url ? <img src={mediaSrc(ig.avatar_url)} alt="" className="rounded-circle" style={{ width: 40, height: 40, objectFit: "cover" }} /> : <i className="ri-instagram-line fs-3" />}
                        <strong>{ig.username || ig.name}</strong>
                    </div>
                    <p className="text-muted fs-12">Como o perfil vai ficar: as próximas publicações por cima das já publicadas. A cor do ponto é a etapa.</p>
                    {data.upcoming.length === 0 && data.published.length === 0 && <div className="text-muted text-center border rounded py-4 fs-13">Ainda não há publicações do Instagram com data.</div>}
                    {data.upcoming.length > 0 && grid(data.upcoming)}
                    {data.published.length > 0 && <>{divider("Já publicadas")}{grid(data.published)}</>}
                </>
            ) : (
                <>
                    <p className="text-muted fs-12">A cronologia da Página: as próximas publicações (a mais recente primeiro) por cima das já publicadas.</p>
                    {fb.upcoming.length === 0 && fb.published.length === 0 && <div className="text-muted text-center border rounded py-4 fs-13">Ainda não há publicações do Facebook com data.</div>}
                    {fb.upcoming.map((s) => <Story key={s.id} s={s} data={data} onOpen={onOpen} />)}
                    {fb.published.length > 0 && <>{divider("Já publicadas")}{fb.published.map((s) => <Story key={s.id} s={s} data={data} onOpen={onOpen} />)}</>}
                </>
            )}
        </div>
    );
}
