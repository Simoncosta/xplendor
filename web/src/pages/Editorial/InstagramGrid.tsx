import { useEffect, useState } from "react";
import { Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { getEditorialGrid } from "helpers/laravel_helper";
import { GridTile, STAGE_META, mediaSrc } from "common/models/editorialWorkflow.model";

/**
 * Grelha do Instagram: as próximas publicações (da mais distante para a mais próxima, como
 * ficarão no perfil) por cima das últimas já publicadas. Três colunas e mosaicos 3:4, como o
 * perfil atual. Um clique abre a produção da publicação.
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
            {(reel || carousel) && (
                <i className={`${reel ? "ri-film-line" : "ri-stack-line"} position-absolute top-0 end-0 m-1 text-white fs-16`} style={{ textShadow: "0 0 3px #000" }} />
            )}
            <span className="position-absolute bottom-0 start-0 end-0 d-flex align-items-center gap-1 px-1 fs-11 text-white"
                style={{ background: "linear-gradient(transparent, rgba(0,0,0,0.65))", paddingTop: 12 }}>
                <span className="rounded-circle flex-shrink-0" style={{ width: 8, height: 8, background: sm.hex }} />
                {dm(t.publish_date)}
            </span>
        </button>
    );
}

export default function InstagramGrid({ companyId, reloadKey, onOpen }: { companyId: number; reloadKey: number; onOpen: (id: number) => void }) {
    const [data, setData] = useState<{ upcoming: GridTile[]; published: GridTile[] } | null>(null);

    useEffect(() => {
        let alive = true;
        getEditorialGrid(companyId)
            .then((r: any) => { if (alive) setData(r.data); })
            .catch((e: any) => { if (alive) { setData({ upcoming: [], published: [] }); toast.error(e?.message || "Não foi possível carregar a grelha."); } });
        return () => { alive = false; };
    }, [companyId, reloadKey]);

    if (!data) return <div className="text-center py-5"><Spinner /></div>;

    const grid = (tiles: GridTile[]) => (
        <div className="d-grid" style={{ gridTemplateColumns: "repeat(3, 1fr)", gap: 3 }}>
            {tiles.map((t) => <Tile key={t.id} t={t} onOpen={onOpen} />)}
        </div>
    );

    return (
        <div className="mx-auto" style={{ maxWidth: 520 }}>
            <p className="text-muted fs-12">Como o perfil vai ficar: as próximas publicações do Instagram por cima das já publicadas. A cor do ponto é a etapa.</p>
            {data.upcoming.length === 0 && data.published.length === 0 && (
                <div className="text-muted text-center border rounded py-4 fs-13">Ainda não há publicações do Instagram com data.</div>
            )}
            {data.upcoming.length > 0 && grid(data.upcoming)}
            {data.published.length > 0 && (
                <>
                    <div className="d-flex align-items-center gap-2 my-3 text-muted fs-12">
                        <hr className="flex-grow-1 my-0" /><span>Já publicadas</span><hr className="flex-grow-1 my-0" />
                    </div>
                    {grid(data.published)}
                </>
            )}
        </div>
    );
}
