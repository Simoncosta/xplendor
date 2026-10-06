import { useState } from "react";
import type { PostChannel } from "common/models/editorialPost.model";
import { MediaAssetDto, fmtDuration, mediaSrc } from "common/models/editorialWorkflow.model";

/**
 * Pré-visualização aproximada de como a publicação fica na rede: feed e carrossel do
 * Instagram (com o recorte pela proporção do primeiro ficheiro, entre 4:5 e 1,91:1),
 * Reel e Story em 9:16 com as zonas seguras, e publicação do Facebook. É uma aproximação:
 * a rede pode mudar margens e cortes.
 */

type Props = {
    channel: PostChannel;
    mediaFormat: string | null;
    caption: string;
    hashtags: string[];
    items: MediaAssetDto[];
    cover: MediaAssetDto | null;
    accountName: string;
};

const clampRatio = (r: number | null) => (r ? Math.min(1.91, Math.max(0.8, r)) : 1);

/** Vertical (Reel e Story): em ciclo e sem controlos, como na rede; o clique pausa ou retoma. */
function Media({ asset, fit = "cover", poster, vertical = false }: { asset: MediaAssetDto; fit?: "cover" | "contain"; poster?: MediaAssetDto | null; vertical?: boolean }) {
    if (asset.kind === "video") {
        const posterUrl = mediaSrc(poster?.preview_url ?? asset.preview_url);
        return asset.original_url
            ? <video src={mediaSrc(asset.original_url)} poster={posterUrl} muted playsInline preload="metadata" controls={!vertical} autoPlay={vertical} loop={vertical}
                onClick={vertical ? (e) => { const v = e.currentTarget; if (v.paused) void v.play(); else v.pause(); } : undefined}
                style={{ width: "100%", height: "100%", objectFit: fit, background: "#000", cursor: vertical ? "pointer" : undefined }} />
            : <img src={posterUrl} alt="" style={{ width: "100%", height: "100%", objectFit: fit }} />;
    }
    return <img src={mediaSrc(asset.preview_url)} alt="" style={{ width: "100%", height: "100%", objectFit: fit }} />;
}

function Caption({ name, text, tags }: { name: string; text: string; tags: string[] }) {
    const [open, setOpen] = useState(false);
    const full = [text, tags.join(" ")].filter(Boolean).join("\n\n");
    const short = full.length > 125 && !open;
    return (
        <div className="fs-13 px-2 pb-2" style={{ whiteSpace: "pre-line" }}>
            <strong className="me-1">{name}</strong>
            {short ? <>{full.slice(0, 125)}… <button type="button" className="btn btn-link p-0 fs-13 text-muted align-baseline" onClick={() => setOpen(true)}>mais</button></> : full}
        </div>
    );
}

const Avatar = ({ name }: { name: string }) => (
    <span className="rounded-circle d-inline-flex align-items-center justify-content-center fw-semibold text-white flex-shrink-0"
        style={{ width: 30, height: 30, fontSize: 12, background: "linear-gradient(45deg,#f58529,#dd2a7b,#8134af)" }}>
        {name.slice(0, 1).toUpperCase()}
    </span>
);

/** Zonas onde a rede põe nome, botões e legenda: o texto importante deve ficar fora delas. */
function SafeZones({ top, bottom }: { top: number; bottom: number }) {
    const zone = (pos: "top" | "bottom", h: number) => (
        <div style={{ position: "absolute", left: 0, right: 0, [pos]: 0, height: `${h}%`, pointerEvents: "none", background: "rgba(255,255,255,0.12)", borderBottom: pos === "top" ? "1px dashed rgba(255,255,255,0.8)" : undefined, borderTop: pos === "bottom" ? "1px dashed rgba(255,255,255,0.8)" : undefined }}>
            <span className="position-absolute fs-11 text-white px-1" style={{ [pos === "top" ? "bottom" : "top"]: 2, right: 4, background: "rgba(0,0,0,0.45)", borderRadius: 3 }}>Fora da zona segura</span>
        </div>
    );
    return <>{zone("top", top)}{zone("bottom", bottom)}</>;
}

export default function PostPreview({ channel, mediaFormat, caption, hashtags, items, cover, accountName }: Props) {
    const [index, setIndex] = useState(0);
    const ready = items.filter((a) => a.status === "ready");
    const name = accountName.toLowerCase().replace(/\s+/g, "");

    if (ready.length === 0 && mediaFormat !== "fb_post") {
        return <div className="text-muted fs-13 text-center py-4 border rounded">Acrescente ficheiros para ver a pré-visualização.</div>;
    }

    // Reel e Story: 9:16, com as zonas seguras.
    if (mediaFormat && /(_reel|_story)$/.test(mediaFormat)) {
        const story = mediaFormat.endsWith("_story");
        return (
            <div className="mx-auto position-relative rounded overflow-hidden bg-black" style={{ width: 270, aspectRatio: "9 / 16" }}>
                {ready[0] && <Media asset={ready[0]} poster={cover} vertical />}
                <SafeZones top={story ? 14 : 8} bottom={story ? 20 : 30} />
                <div className="position-absolute start-0 end-0 px-2 text-white fs-12" style={{ bottom: story ? "4%" : "8%", textShadow: "0 1px 2px #000", pointerEvents: "none" }}>
                    {!story && <><strong>{name}</strong><div className="text-truncate">{caption}</div></>}
                </div>
                {ready[0]?.kind === "video" && <span className="position-absolute top-0 start-0 m-2 badge bg-dark">{fmtDuration(ready[0].duration_ms)}</span>}
            </div>
        );
    }

    // Facebook: publicação com a página, o texto e os ficheiros.
    if (channel === "facebook") {
        return (
            <div className="border rounded mx-auto bg-body" style={{ maxWidth: 420 }}>
                <div className="d-flex align-items-center gap-2 p-2"><Avatar name={accountName} /><div><div className="fw-semibold fs-13">{accountName}</div><div className="text-muted fs-11">Agora · Público</div></div></div>
                <div className="fs-13 px-2 pb-2" style={{ whiteSpace: "pre-line" }}>{[caption, hashtags.join(" ")].filter(Boolean).join("\n\n")}</div>
                {ready.length > 0 && (
                    <div className="d-grid gap-1" style={{ gridTemplateColumns: ready.length > 1 ? "1fr 1fr" : "1fr" }}>
                        {ready.slice(0, 4).map((a, i) => (
                            <div key={a.id} className="position-relative" style={{ aspectRatio: ready.length > 1 ? "1 / 1" : `${clampRatio(a.width && a.height ? a.width / a.height : null)}` }}>
                                <Media asset={a} poster={cover} />
                                {i === 3 && ready.length > 4 && <span className="position-absolute top-50 start-50 translate-middle fs-3 text-white fw-bold">+{ready.length - 4}</span>}
                            </div>
                        ))}
                    </div>
                )}
                <div className="d-flex justify-content-around border-top py-1 text-muted fs-13"><span><i className="ri-thumb-up-line me-1" />Gosto</span><span><i className="ri-chat-1-line me-1" />Comentar</span><span><i className="ri-share-forward-line me-1" />Partilhar</span></div>
            </div>
        );
    }

    // Instagram: feed ou carrossel, cortado pela proporção do primeiro ficheiro.
    const first = ready[0];
    const ratio = clampRatio(first?.width && first?.height ? first.width / first.height : null);
    const current = ready[Math.min(index, ready.length - 1)];
    return (
        <div className="border rounded mx-auto bg-body" style={{ maxWidth: 380 }}>
            <div className="d-flex align-items-center gap-2 p-2"><Avatar name={accountName} /><strong className="fs-13">{name}</strong></div>
            <div className="position-relative bg-black" style={{ aspectRatio: `${ratio}` }}>
                {current && <Media asset={current} poster={cover} />}
                {ready.length > 1 && (
                    <>
                        <span className="position-absolute top-0 end-0 m-2 badge bg-dark bg-opacity-75">{index + 1}/{ready.length}</span>
                        {index > 0 && <button type="button" aria-label="Anterior" className="btn btn-light btn-sm rounded-circle position-absolute top-50 start-0 translate-middle-y ms-1 opacity-75" onClick={() => setIndex(index - 1)}><i className="ri-arrow-left-s-line" /></button>}
                        {index < ready.length - 1 && <button type="button" aria-label="Seguinte" className="btn btn-light btn-sm rounded-circle position-absolute top-50 end-0 translate-middle-y me-1 opacity-75" onClick={() => setIndex(index + 1)}><i className="ri-arrow-right-s-line" /></button>}
                    </>
                )}
            </div>
            <div className="d-flex align-items-center gap-3 px-2 py-2 fs-18">
                <i className="ri-heart-line" /><i className="ri-chat-3-line" /><i className="ri-send-plane-line" />
                {ready.length > 1 && (
                    <span className="mx-auto d-flex gap-1">
                        {ready.map((a, i) => <span key={a.id} className="rounded-circle" style={{ width: 6, height: 6, background: i === index ? "var(--vz-primary)" : "var(--vz-border-color)" }} />)}
                    </span>
                )}
                <i className="ri-bookmark-line ms-auto" />
            </div>
            <Caption name={name} text={caption} tags={hashtags} />
        </div>
    );
}
