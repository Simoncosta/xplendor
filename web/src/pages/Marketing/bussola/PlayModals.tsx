import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Badge, Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import XSelect from "Components/Common/Select";
import ReasonButton from "Components/Common/ReasonButton";
import AiRequestState from "Components/Common/AiRequestState";
import { createPostFromPlay, getPlayCaption, requestPlayCaption } from "helpers/laravel_helper";
import { AiPollable, useAiRequestPoll } from "hooks/useAiRequestPoll";
import { MEDIA_FORMATS } from "common/models/editorialPost.model";
import type { CompassPlay } from "common/models/bussola.model";

const NETWORKS: { key: "instagram" | "facebook"; label: string }[] = [{ key: "instagram", label: "Instagram" }, { key: "facebook", label: "Facebook" }];
const todayIso = () => new Date().toLocaleDateString("sv-SE", { timeZone: "Europe/Lisbon" });
const errorText = (e: any, fallback: string) => {
    const first = e?.errors ? (Object.values(e.errors).flat()[0] as string) : null;
    return first || e?.message || fallback;
};

export type PostPrefill = { caption?: string; hashtags?: string[]; cta?: string };

/**
 * "Criar publicação" a partir de uma jogada: já preenchido com o O quê (tema), o Onde (redes
 * e formato de cada uma) e o Quando (data); com a legenda escolhida, se vier do "Sugerir
 * texto". Cria uma ideia na Linha Editorial; nunca uma data passada.
 */
export function PlayPostModal({ companyId, locationId, play, prefill, formats, onClose, onCreated }: {
    companyId: number; locationId: number | null; play: CompassPlay | null; prefill?: PostPrefill | null; formats: string[];
    onClose: () => void; onCreated: (message: string) => void;
}) {
    const [title, setTitle] = useState("");
    const [date, setDate] = useState("");
    const [mediaFormats, setMediaFormats] = useState<Record<string, string>>({});
    const [format, setFormat] = useState("Imagem única");
    const [caption, setCaption] = useState("");
    const [hashtags, setHashtags] = useState("");
    const [cta, setCta] = useState("");
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<{ text: string; month?: boolean } | null>(null);

    useEffect(() => {
        if (!play) return;
        setTitle(play.what.text);
        setDate(play.when.date);
        setMediaFormats(Object.fromEntries(play.where.networks.map((n) => [n.network, n.format_key])));
        setFormat(play.format || "Imagem única");
        setCaption(prefill?.caption ?? "");
        setHashtags((prefill?.hashtags ?? []).join(" "));
        setCta(prefill?.cta ?? "");
        setError(null);
    }, [play, prefill]);

    const chosen = Object.keys(mediaFormats);
    const toggle = (n: string) => setMediaFormats((m) => {
        const next = { ...m };
        if (next[n]) delete next[n]; else next[n] = MEDIA_FORMATS[n as "instagram" | "facebook"][0].value;
        return next;
    });
    const invalid = !title.trim() ? "Indique o tema." : !date ? "Indique a data." : date < todayIso() ? "A data não pode ser anterior a hoje." : chosen.length === 0 ? "Escolha pelo menos uma rede." : null;

    const submit = async () => {
        if (!play || invalid) return;
        setSaving(true);
        setError(null);
        try {
            const res: any = await createPostFromPlay(companyId, {
                play_key: play.key, location_id: locationId || undefined, title: title.trim(), publish_date: date, networks: chosen, media_formats: mediaFormats, format,
                ...(caption.trim() ? { caption: caption.trim() } : {}), ...(hashtags.trim() ? { hashtags: hashtags.split(/[\s,]+/).filter(Boolean) } : {}), ...(cta.trim() ? { cta: cta.trim() } : {}),
            });
            onCreated(res?.message ?? "Ideia criada na Linha Editorial.");
        } catch (e: any) {
            const monthError = e?.errors?.publish_date?.[0];
            setError({ text: errorText(e, "Não foi possível criar a publicação."), month: !!monthError && /não está aberto/.test(monthError) });
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal isOpen={!!play} toggle={onClose} centered scrollable data-testid="play-post-modal">
            <ModalHeader toggle={onClose}>Criar publicação</ModalHeader>
            <ModalBody>
                <p className="text-muted fs-13">Cria uma ideia na Linha Editorial, já preenchida com o que fazer, onde e quando. Reveja antes de criar.</p>
                <div className="mb-3">
                    <Label for="play-post-title">Tema</Label>
                    <Input id="play-post-title" type="textarea" rows={2} maxLength={255} value={title} onChange={(e) => setTitle(e.target.value)} />
                </div>
                <div className="mb-3">
                    <Label for="play-post-date">Data</Label>
                    <Input id="play-post-date" type="date" min={todayIso()} value={date} onChange={(e) => setDate(e.target.value)} />
                </div>
                <div className="mb-3">
                    <span className="form-label d-block">Redes e formato</span>
                    <div className="vstack gap-2">
                        {NETWORKS.map((n) => (
                            <div key={n.key} className="d-flex flex-wrap align-items-center gap-2">
                                <div className="form-check mb-0" style={{ minWidth: 110 }}>
                                    <Input id={`play-net-${n.key}`} type="checkbox" className="form-check-input" checked={!!mediaFormats[n.key]} onChange={() => toggle(n.key)} />
                                    <Label for={`play-net-${n.key}`} className="form-check-label">{n.label}</Label>
                                </div>
                                {mediaFormats[n.key] && (
                                    <div className="flex-grow-1" style={{ minWidth: 180 }}>
                                        <XSelect ariaLabel={`Formato no ${n.label}`} value={mediaFormats[n.key]} options={MEDIA_FORMATS[n.key]}
                                            onChange={(v) => setMediaFormats((m) => ({ ...m, [n.key]: v }))} />
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
                <div className="mb-3">
                    <Label for="play-post-format">Tipo de conteúdo</Label>
                    <XSelect id="play-post-format" ariaLabel="Tipo de conteúdo" value={format} onChange={setFormat} options={formats.map((f) => ({ value: f, label: f }))} searchable />
                </div>
                {(prefill?.caption || caption) && (
                    <>
                        <div className="mb-2">
                            <Label for="play-post-caption">Legenda</Label>
                            <Input id="play-post-caption" type="textarea" rows={5} maxLength={2200} value={caption} onChange={(e) => setCaption(e.target.value)} />
                        </div>
                        <div className="row g-2">
                            <div className="col-md-6"><Label for="play-post-tags">Hashtags</Label><Input id="play-post-tags" value={hashtags} onChange={(e) => setHashtags(e.target.value)} /></div>
                            <div className="col-md-6"><Label for="play-post-cta">Chamada à ação</Label><Input id="play-post-cta" maxLength={300} value={cta} onChange={(e) => setCta(e.target.value)} /></div>
                        </div>
                    </>
                )}
                {error && (
                    <div className="alert alert-warning fs-13 mb-0 mt-3" role="alert">
                        {error.text}
                        {error.month && <> <Link to="/editorial">Abrir a Linha Editorial</Link></>}
                    </div>
                )}
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onClose}>Cancelar</Button>
                <ReasonButton color="primary" onClick={submit} disabled={saving} reason={saving ? null : invalid}>
                    {saving ? <Spinner size="sm" /> : "Criar ideia"}
                </ReasonButton>
            </ModalFooter>
        </Modal>
    );
}

type Proposal = { angle: string; captions: Record<string, string>; hashtags: string[]; cta: string };
type CaptionRequest = AiPollable & { used: number; cap: number; result: { networks: string[]; proposals: Proposal[] } | null };

/**
 * "Sugerir texto": o "Gerar legenda" com os dados da jogada, sem criar publicação. A proposta
 * escolhida abre o "Criar publicação" preenchido. Conta para o limite das legendas.
 */
export function PlayCaptionModal({ companyId, locationId, play, onClose, onUse }: {
    companyId: number; locationId: number | null; play: CompassPlay | null; onClose: () => void; onUse: (p: CompassPlay, prefill: PostPrefill) => void;
}) {
    const fetchOne = useCallback((id: number) => getPlayCaption(companyId, id), [companyId]);
    const { data, busy, start, reset } = useAiRequestPoll<CaptionRequest>(fetchOne);
    const [failed, setFailed] = useState<string | null>(null);

    const ask = useCallback(async () => {
        if (!play) return;
        setFailed(null);
        try {
            await start(() => requestPlayCaption(companyId, { play_key: play.key, location_id: locationId || undefined, networks: play.where.networks.map((n) => n.network) }));
        } catch (e: any) { setFailed(errorText(e, "Não foi possível pedir as propostas.")); }
    }, [play, companyId, locationId, start]);

    useEffect(() => { if (play) void ask(); else reset(); }, [play]); // eslint-disable-line react-hooks/exhaustive-deps

    const result = data?.status === "done" ? data.result : null;

    return (
        <Modal isOpen={!!play} toggle={onClose} size="lg" centered scrollable data-testid="play-caption-modal">
            <ModalHeader toggle={onClose}>Sugerir texto{play ? `: ${play.title}` : ""}</ModalHeader>
            <ModalBody>
                {play && <p className="text-muted fs-13">A IA escreve propostas a partir da jogada ({play.what.text}) e do Perfil da Marca. A que escolher abre o "Criar publicação"; nada fica gravado antes disso.</p>}
                {failed && <div className="alert alert-warning fs-13">{failed}</div>}
                <AiRequestState data={data} what="as propostas" />
                {busy && !data?.stalled && <div className="text-center py-3"><Spinner color="primary" /></div>}
                {result && (
                    <div className="vstack gap-3" data-testid="play-caption-proposals">
                        {result.proposals.map((p, i) => (
                            <div className="border rounded p-3" key={i}>
                                <div className="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <span className="fw-semibold">Proposta {i + 1}</span>
                                    {p.angle && <Badge color="light" className="text-body fw-normal">{p.angle}</Badge>}
                                    <Button size="sm" color="outline-primary" className="ms-auto" onClick={() => play && onUse(play, {
                                        caption: p.captions.instagram ?? p.captions.facebook ?? "", hashtags: p.hashtags, cta: p.cta,
                                    })}><i className="ri-check-line me-1" />Usar esta proposta</Button>
                                </div>
                                {result.networks.map((n) => (
                                    <div key={n} className="mb-2">
                                        <div className="fs-12 text-muted mb-1">{n === "instagram" ? "Instagram" : "Facebook"}</div>
                                        <div className="fs-13" style={{ whiteSpace: "pre-line" }}>{p.captions[n]}</div>
                                    </div>
                                ))}
                                {p.hashtags.length > 0 && <div className="fs-13 text-primary">{p.hashtags.join(" ")}</div>}
                                {p.cta && <div className="fs-13 mt-1"><span className="text-muted">Chamada à ação: </span>{p.cta}</div>}
                            </div>
                        ))}
                    </div>
                )}
                {data && <p className="text-muted fs-12 mt-3 mb-0">{data.used} de {data.cap} legendas geradas este mês.</p>}
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onClose}>Fechar</Button>
                {result && <Button color="outline-primary" disabled={busy} onClick={() => void ask()}><i className="ri-refresh-line me-1" />Gerar outras</Button>}
            </ModalFooter>
        </Modal>
    );
}

/** "Ver detalhe": as frases descritivas completas, a amostra e a confiança. */
export function PlayDetailModal({ play, onClose }: { play: CompassPlay | null; onClose: () => void }) {
    return (
        <Modal isOpen={!!play} toggle={onClose} size="lg" centered scrollable data-testid="play-detail-modal">
            <ModalHeader toggle={onClose}>{play?.title}</ModalHeader>
            <ModalBody>
                {play && (
                    <>
                        <h6 className="text-uppercase text-muted fs-12 mb-2">O que os números dizem</h6>
                        <ul className="ps-3 fs-14 lh-base mb-3">
                            {play.detail.sentences.map((s, i) => <li key={i} className="mb-2">{s}</li>)}
                        </ul>
                        {play.detail.sample && (
                            <>
                                <h6 className="text-uppercase text-muted fs-12 mb-1">Amostra</h6>
                                <p className="fs-14 mb-3">{play.detail.sample}</p>
                            </>
                        )}
                        <h6 className="text-uppercase text-muted fs-12 mb-2">Confiança</h6>
                        <div className="d-flex flex-wrap gap-2 mb-3">
                            {play.detail.confidence.map((c, i) => (
                                <Badge key={i} style={{ fontSize: 13 }} color={c.confidence === "alta" ? "success-subtle" : "info-subtle"} className={`fw-normal text-${c.confidence === "alta" ? "success" : "info"}`}>
                                    {c.title.replace(/^Período fraco: |^Em subida: |^Em descida: |^Parado: /, "")}: confiança {c.confidence === "alta" ? "alta" : "média"}
                                </Badge>
                            ))}
                        </div>
                        <p className="text-muted fs-13 mb-0">Os números descrevem o que aconteceu; não dizem porquê.{play.what.source === "ai" ? " A frase \"O quê\" foi escrita pela IA a partir destes dados." : ""}</p>
                    </>
                )}
            </ModalBody>
            <ModalFooter><Button color="light" onClick={onClose}>Fechar</Button></ModalFooter>
        </Modal>
    );
}

export const toastCreated = (message: string) => toast.success(message);
