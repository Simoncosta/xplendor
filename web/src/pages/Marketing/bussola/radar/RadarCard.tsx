import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Badge, Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import PageCard from "Components/Common/PageCard";
import ActionsMenu from "Components/Common/ActionsMenu";
import ReasonButton from "Components/Common/ReasonButton";
import { useCan } from "contexts/ModulesContext";
import { MEDIA_FORMATS } from "common/models/editorialPost.model";
import { getRadar, ignoreRadar, muteRadar, reactRadar, unmuteRadar, updateRadarSettings } from "helpers/laravel_helper";
import type { RadarCalendarEntry, RadarPageData, RadarSettings, RadarSignal, RadarSource, RadarWhen } from "common/models/radar.model";
import Skeleton from "Components/Common/Skeleton";

/**
 * Radar de oportunidade (Bússola Master): "Está a subir" (o que está a acontecer agora) e "Vem aí"
 * (as datas com fonte oficial). O Radar nunca publica nem escreve o texto: "Reagir" cria uma
 * ideia na Linha Editorial com o gancho, as fontes e o prazo, sem texto. A fonte e a hora estão
 * sempre à vista; não há valores em euros.
 */

const NETWORKS: { key: "instagram" | "facebook"; label: string }[] = [{ key: "instagram", label: "Instagram" }, { key: "facebook", label: "Facebook" }];
const todayIso = () => new Date().toLocaleDateString("sv-SE", { timeZone: "Europe/Lisbon" });
const fmtDateTime = (iso: string | null) => iso ? new Date(iso).toLocaleString("pt-PT", { timeZone: "Europe/Lisbon", day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" }) : null;
const fmtTime = (iso: string | null) => iso ? new Date(iso).toLocaleTimeString("pt-PT", { timeZone: "Europe/Lisbon", hour: "2-digit", minute: "2-digit" }) : null;
const fmtDay = (d: string) => new Date(`${d}T12:00:00`).toLocaleDateString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric" });
const WEEKDAYS = ["domingo", "segunda-feira", "terça-feira", "quarta-feira", "quinta-feira", "sexta-feira", "sábado"];

/** A janela em texto relativo, pelo "when" da resposta: "até hoje", "até amanhã", "até sexta-feira, 16/10". */
export function windowLabel(when: RadarWhen, today: string = todayIso()): string {
    if (when.is_today || when.date <= today) return "Vale até hoje";
    const tomorrow = new Date(`${today}T12:00:00Z`);
    tomorrow.setUTCDate(tomorrow.getUTCDate() + 1);
    if (when.date === tomorrow.toISOString().slice(0, 10)) return "Vale até amanhã";
    const d = new Date(`${when.date}T12:00:00Z`);
    return `Vale até ${WEEKDAYS[d.getUTCDay()]}, ${when.date.slice(8, 10)}/${when.date.slice(5, 7)}`;
}

const errorText = (e: any, fallback: string) => (e?.errors ? (Object.values(e.errors).flat()[0] as string) : null) || e?.message || fallback;

export function RadarSources({ sources }: { sources: RadarSource[] }) {
    return (
        <ul className="list-unstyled mb-0 fs-12" data-testid="radar-sources">
            {sources.filter((s) => s.url).map((s, i) => (
                <li key={`${s.url}-${i}`} className="text-truncate">
                    <i className="ri-external-link-line me-1 text-muted" aria-hidden />
                    <a href={s.url ?? undefined} target="_blank" rel="noopener noreferrer" aria-label={`Abrir na fonte: ${s.source_name}`}>{s.title || s.source_name}</a>
                    <span className="text-muted">{s.title ? ` · ${s.source_name}` : ""}{s.published_at ? ` · ${fmtDateTime(s.published_at)}` : ""}</span>
                </li>
            ))}
        </ul>
    );
}

/** Um cartão "Está a subir: X"; compact no painel "Agora" da Linha Editorial. */
export function RadarSignalCard({ signal, canAct, canActReason, compact, onReact, onIgnore, onMute }: {
    signal: RadarSignal; canAct: boolean; canActReason: string | null; compact?: boolean;
    onReact: (s: RadarSignal) => void; onIgnore: (s: RadarSignal) => void; onMute?: (s: RadarSignal) => void;
}) {
    const [canCreate, createReason] = useCan("bussola.criar");
    const [canEdit, editReason] = useCan("bussola.editar");
    const reactReason = !canCreate ? createReason : !canAct ? canActReason : null;
    const editBlock = !canEdit ? editReason : !canAct ? canActReason : null;
    return (
        <div className="border rounded p-3 h-100 d-flex flex-column gap-2" data-testid="radar-signal">
            <div className="d-flex align-items-start gap-2">
                <span className="avatar-xs flex-shrink-0"><span className="avatar-title rounded bg-primary-subtle text-primary"><i className="ri-line-chart-line" aria-hidden /></span></span>
                <div className="min-w-0">
                    <div className="fw-semibold text-break">Está a subir: {signal.title}</div>
                    <div className="text-muted fs-12">{[signal.since_label, signal.volume_label].filter(Boolean).join(" · ")}</div>
                </div>
            </div>
            <div className="fs-13">
                <span className="text-muted">Porque lhe interessa: </span>{signal.why}
                {signal.why_source === "template" && <Badge color="light" className="text-muted fw-normal ms-1">frase automática</Badge>}
            </div>
            <div className="fs-12" data-testid="radar-window" title={fmtDateTime(signal.valid_until) ?? undefined}><i className="ri-time-line me-1 text-muted" aria-hidden />{windowLabel(signal.when)}</div>
            {signal.notices.map((n) => <div key={n} className="fs-12"><Badge color="warning-subtle" className="text-warning fw-normal text-wrap text-start">{n}</Badge></div>)}
            {!compact && <RadarSources sources={signal.sources} />}
            <div className="text-muted fs-12">{signal.attribution}</div>
            <div className="d-flex flex-wrap gap-2 mt-auto pt-1">
                <ReasonButton size="sm" color="primary" reason={reactReason} onClick={() => onReact(signal)} data-testid="radar-react">Reagir</ReasonButton>
                <ReasonButton size="sm" color="secondary" outline reason={editBlock} onClick={() => onIgnore(signal)} data-testid="radar-ignore">Ignorar</ReasonButton>
                {onMute && <ReasonButton size="sm" color="secondary" outline reason={editBlock} onClick={() => onMute(signal)} data-testid="radar-mute">Não mostrar temas como este</ReasonButton>}
            </div>
        </div>
    );
}

type ReactTarget = { kind: "signal"; signal: RadarSignal } | { kind: "date"; entry: RadarCalendarEntry };

/** "Reagir": a ideia com prazo, sem texto (título, data, redes e formato). */
export function RadarReactModal({ companyId, target, formats, onClose, onDone }: {
    companyId: number; target: ReactTarget | null; formats: string[]; onClose: () => void; onDone: (message: string) => void;
}) {
    const [title, setTitle] = useState("");
    const [date, setDate] = useState("");
    const [media, setMedia] = useState<Record<string, string>>({});
    const [format, setFormat] = useState(formats[0] ?? "Imagem única");
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<{ text: string; month: boolean } | null>(null);

    useEffect(() => {
        if (!target) return;
        setTitle(target.kind === "signal" ? `Está a subir: ${target.signal.title}` : target.entry.title);
        setDate(target.kind === "signal" ? target.signal.when.date : target.entry.when.date);
        setMedia({ instagram: MEDIA_FORMATS.instagram[0].value });
        setFormat(formats[0] ?? "Imagem única");
        setError(null);
    }, [target, formats]);

    const chosen = Object.keys(media);
    const invalid = !title.trim() ? "Indique o título." : !date ? "Indique a data." : date < todayIso() ? "A data não pode ser anterior a hoje." : chosen.length === 0 ? "Escolha pelo menos uma rede." : null;
    const submit = async () => {
        if (!target || invalid) return;
        setSaving(true);
        setError(null);
        try {
            const res: any = await reactRadar(companyId, {
                ...(target.kind === "signal" ? { signal_id: target.signal.id } : { calendar_key: target.entry.key, occurrence: target.entry.occurrence }),
                title: title.trim(), publish_date: date, networks: chosen, media_formats: media, format,
            });
            onDone(res?.message ?? "Ideia criada na Linha Editorial.");
        } catch (e: any) {
            const text = errorText(e, "Não foi possível criar a ideia.");
            setError({ text, month: /não está aberto/.test(text) });
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal isOpen={!!target} toggle={onClose} centered data-testid="radar-react-modal">
            <ModalHeader toggle={onClose}>Reagir: criar uma ideia</ModalHeader>
            <ModalBody>
                <p className="text-muted fs-13">A ideia entra na Linha Editorial com o gancho, as fontes e o prazo, sem texto. O "Sugerir texto" continua disponível na publicação, só a pedido.</p>
                <div className="mb-3"><Label for="radar-title">Título</Label><Input id="radar-title" value={title} maxLength={255} onChange={(e) => setTitle(e.target.value)} /></div>
                <div className="mb-3"><Label for="radar-date">Data de publicação</Label><Input id="radar-date" type="date" min={todayIso()} value={date} onChange={(e) => setDate(e.target.value)} /></div>
                <div className="mb-3">
                    <Label className="d-block">Redes</Label>
                    {NETWORKS.map((n) => (
                        <div key={n.key} className="form-check form-check-inline">
                            <Input id={`radar-net-${n.key}`} type="checkbox" checked={!!media[n.key]} onChange={() => setMedia((m) => {
                                const next = { ...m };
                                if (next[n.key]) delete next[n.key]; else next[n.key] = MEDIA_FORMATS[n.key][0].value;
                                return next;
                            })} />
                            <Label for={`radar-net-${n.key}`} className="form-check-label">{n.label}</Label>
                        </div>
                    ))}
                </div>
                <div className="mb-1"><Label for="radar-format">Tipo de conteúdo</Label>
                    <Input id="radar-format" type="select" value={format} onChange={(e) => setFormat(e.target.value)}>{formats.map((f) => <option key={f}>{f}</option>)}</Input>
                </div>
                {error && (
                    <div className="alert alert-warning fs-13 mt-3 mb-0" role="alert">{error.text}{error.month && <> <Link to="/editorial">Abrir a Linha Editorial</Link></>}</div>
                )}
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onClose}>Cancelar</Button>
                <ReasonButton color="primary" reason={invalid} disabled={saving} onClick={submit} data-testid="radar-react-submit">{saving ? <Spinner size="sm" /> : "Criar ideia"}</ReasonButton>
            </ModalFooter>
        </Modal>
    );
}

/** "Definições do Radar": o desporto (não / sim, com os clubes escolhidos) e os temas silenciados. */
function RadarSettingsModal({ companyId, settings, isOpen, onClose, onSaved }: {
    companyId: number; settings: RadarSettings; isOpen: boolean; onClose: () => void; onSaved: (s: RadarSettings) => void;
}) {
    const [sports, setSports] = useState(settings.sports_enabled);
    const [clubs, setClubs] = useState<string[]>(settings.sports_clubs);
    const [draft, setDraft] = useState("");
    const [saving, setSaving] = useState(false);
    useEffect(() => { if (isOpen) { setSports(settings.sports_enabled); setClubs(settings.sports_clubs); setDraft(""); } }, [isOpen, settings]);
    const addClub = () => {
        const c = draft.trim();
        if (c.length >= 2 && !clubs.includes(c) && clubs.length < 20) setClubs([...clubs, c.slice(0, 40)]);
        setDraft("");
    };
    const save = async () => {
        setSaving(true);
        try {
            const res: any = await updateRadarSettings(companyId, { sports_enabled: sports, sports_clubs: sports ? clubs : [] });
            toast.success(res?.message ?? "Definições do Radar guardadas.");
            onSaved(res?.data);
        } catch (e: any) {
            toast.error(errorText(e, "Não foi possível guardar as definições."));
        } finally {
            setSaving(false);
        }
    };
    const unmute = async (index: number) => {
        try {
            const res: any = await unmuteRadar(companyId, index);
            toast.success(res?.message ?? "O tema volta a aparecer.");
            onSaved(res?.data);
        } catch (e: any) {
            toast.error(errorText(e, "Não foi possível voltar a mostrar o tema."));
        }
    };
    return (
        <Modal isOpen={isOpen} toggle={onClose} centered data-testid="radar-settings-modal">
            <ModalHeader toggle={onClose}>Definições do Radar</ModalHeader>
            <ModalBody>
                <Label className="d-block">Desporto</Label>
                <div className="xp-seg mb-2" role="radiogroup" aria-label="Desporto">
                    <button type="button" role="radio" aria-checked={!sports} className={!sports ? "on" : ""} onClick={() => setSports(false)}>Não</button>
                    <button type="button" role="radio" aria-checked={sports} className={sports ? "on" : ""} onClick={() => setSports(true)}>Sim</button>
                </div>
                {sports && (
                    <div className="mb-3">
                        <Label for="radar-club" className="fs-13">Clubes (sem nenhum, entram todos os temas de desporto)</Label>
                        <Input id="radar-club" value={draft} maxLength={40} placeholder="Escreva e carregue em Enter" onChange={(e) => setDraft(e.target.value)}
                            onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); addClub(); } }} />
                        <div className="d-flex flex-wrap gap-1 mt-2">
                            {clubs.map((c) => (
                                <Badge key={c} color="primary-subtle" className="text-primary fw-normal">{c}
                                    <button type="button" className="btn btn-link p-0 ms-1 text-primary lh-1" aria-label={`Tirar ${c}`} onClick={() => setClubs(clubs.filter((x) => x !== c))}><i className="ri-close-line" /></button>
                                </Badge>
                            ))}
                        </div>
                    </div>
                )}
                <Label className="d-block mt-3">Temas silenciados</Label>
                {settings.muted.length === 0 ? <p className="text-muted fs-13 mb-0">Nenhum tema silenciado.</p> : (
                    <ul className="list-unstyled mb-0">
                        {settings.muted.map((m) => (
                            <li key={m.index} className="d-flex align-items-center justify-content-between gap-2 py-1 border-bottom">
                                <span className="fs-13 text-truncate">{m.title}{m.theme ? <span className="text-muted"> · {m.theme}</span> : null}</span>
                                <Button size="sm" color="secondary" outline className="flex-shrink-0" onClick={() => unmute(m.index)}>Voltar a mostrar</Button>
                            </li>
                        ))}
                    </ul>
                )}
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onClose}>Fechar</Button>
                <Button color="primary" disabled={saving} onClick={save} data-testid="radar-settings-save">{saving ? <Spinner size="sm" /> : "Guardar"}</Button>
            </ModalFooter>
        </Modal>
    );
}

/** Os dados do Radar e as ações, partilhados pelo bloco da Bússola e pelo painel "Agora". */
export function useRadar(companyId: number) {
    const [data, setData] = useState<RadarPageData | null>(null);
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);
    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res: any = await getRadar(companyId);
            setData(res?.data ?? null);
            setFailed(false);
        } catch {
            setFailed(true);
        } finally {
            setLoading(false);
        }
    }, [companyId]);
    useEffect(() => { void load(); }, [load]);
    const act = async (fn: () => Promise<any>, fallback: string) => {
        try {
            const res: any = await fn();
            toast.success(res?.message ?? fallback);
            void load();
        } catch (e: any) {
            toast.error(errorText(e, "Não foi possível concluir a ação."));
        }
    };
    return {
        data, setData, loading, failed, load,
        ignore: (s: RadarSignal) => act(() => ignoreRadar(companyId, s.id), "Sugestão ignorada."),
        mute: (s: RadarSignal) => { if (window.confirm("Deixar de mostrar temas como este? Pode voltar a mostrá-los nas definições do Radar.")) void act(() => muteRadar(companyId, s.id), "Temas como este deixam de aparecer."); },
    };
}

function CalendarList({ entries, canAct, canActReason, onReact }: { entries: RadarCalendarEntry[]; canAct: boolean; canActReason: string | null; onReact: (e: RadarCalendarEntry) => void }) {
    const [canCreate, createReason] = useCan("bussola.criar");
    if (entries.length === 0) return <p className="text-muted fs-13 mb-0" data-testid="radar-upcoming-empty">Sem datas confirmadas para o seu ramo nos próximos 30 dias.</p>;
    return (
        <ul className="list-unstyled mb-0" data-testid="radar-upcoming">
            {entries.map((e) => (
                <li key={`${e.key}@${e.occurrence}`} className="d-flex flex-wrap align-items-start justify-content-between gap-2 py-2 border-bottom">
                    <div className="min-w-0">
                        <div className="fw-medium">{e.title}</div>
                        <div className="fs-12 text-muted">{e.starts_on === e.ends_on ? fmtDay(e.starts_on) : `${fmtDay(e.starts_on)} a ${fmtDay(e.ends_on)}`}{e.note ? ` · ${e.note}` : ""}</div>
                        <div className="fs-12"><a href={e.source.url} target="_blank" rel="noopener noreferrer">Fonte: {e.source.name}</a><span className="text-muted"> · confirmada a {fmtDay(e.confirmed_on)}</span></div>
                    </div>
                    {e.reacted ? <Badge color="success-subtle" className="text-success fw-normal">Ideia criada</Badge>
                        : <ReasonButton size="sm" color="primary" outline reason={!canCreate ? createReason : !canAct ? canActReason : null} onClick={() => onReact(e)}>Reagir</ReasonButton>}
                </li>
            ))}
        </ul>
    );
}

/** O bloco "Radar" da página da Bússola (em todos os ramos). */
export default function RadarCard({ companyId }: { companyId: number }) {
    const { data, setData, loading, failed, load, ignore, mute } = useRadar(companyId);
    const [target, setTarget] = useState<ReactTarget | null>(null);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [canEdit, editReason] = useCan("bussola.editar");

    const updated = data?.refreshed_at ? `Última recolha às ${fmtTime(data.refreshed_at)}` : "Ainda sem recolhas";
    return (
        <PageCard title="Radar" data-testid="radar-card" loading={loading && !!data}
            info="O que está a subir agora (Google Trends, jornais e BCE) e as datas com fonte oficial. O Radar nunca publica nem escreve o texto: dá o gancho a tempo."
            status={data ? <span className="text-muted fs-12">{updated}</span> : undefined}
            actions={data ? <ActionsMenu items={[{ label: "Definições do Radar", icon: "ri-settings-3-line", onClick: () => setSettingsOpen(true), disabledReason: canEdit ? null : editReason }]} /> : undefined}>
            <div className="p-3">
                {!data && loading && <Skeleton variant="lines" lines={4} className="py-2" />}
                {failed && !data && <div className="alert alert-warning mb-0">Não foi possível carregar o Radar. Tente novamente dentro de momentos.</div>}
                {data && data.sector_missing && (
                    <p className="text-muted mb-0" data-testid="radar-sector-missing">Escolha o ramo da sua marca na Linha Editorial para o Radar saber o que lhe interessa.</p>
                )}
                {data && !data.sector_missing && (
                    <>
                        <h6 className="text-uppercase text-muted fs-12 mb-2">Está a subir</h6>
                        {data.signals.length === 0 ? (
                            <p className="text-muted fs-13" data-testid="radar-empty">{data.enabled ? "Nada a subir agora para o seu ramo. A recolha é de 15 em 15 minutos." : "O Radar não tem recolhas novas neste momento."}</p>
                        ) : (
                            <div className="row g-3 mb-3">
                                {data.signals.map((s) => (
                                    <div key={s.id} className="col-12 col-lg-6 col-xxl-4">
                                        <RadarSignalCard signal={s} canAct={data.can_act} canActReason={data.can_act_reason}
                                            onReact={(x) => setTarget({ kind: "signal", signal: x })} onIgnore={ignore} onMute={mute} />
                                    </div>
                                ))}
                            </div>
                        )}
                        <h6 className="text-uppercase text-muted fs-12 mb-2 mt-2">Vem aí</h6>
                        <CalendarList entries={data.calendar} canAct={data.can_act} canActReason={data.can_act_reason} onReact={(e) => setTarget({ kind: "date", entry: e })} />
                    </>
                )}
            </div>
            <RadarReactModal companyId={companyId} target={target} formats={data?.formats ?? ["Imagem única"]} onClose={() => setTarget(null)}
                onDone={(msg) => { setTarget(null); toast.success(msg); void load(); }} />
            {data && <RadarSettingsModal companyId={companyId} settings={data.settings} isOpen={settingsOpen} onClose={() => setSettingsOpen(false)}
                onSaved={(s) => { if (s) setData({ ...data, settings: s }); void load(); }} />}
        </PageCard>
    );
}
