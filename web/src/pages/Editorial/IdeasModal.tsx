import { useCallback, useEffect, useState } from "react";
import { Badge, Button, Input, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { acceptEditorialIdea, dismissAiRequest, getBrandProfile, getEditorialIdeas, getLatestAiRequest, requestEditorialIdeas } from "helpers/laravel_helper";
import { useAiRequestPoll } from "hooks/useAiRequestPoll";
import AiRequestState from "Components/Common/AiRequestState";
import { EditorialIdea, EditorialIdeasRequest, POST_CHANNEL_META, PostChannel, mediaFormatLabel } from "common/models/editorialPost.model";
import XSelect from "./XSelect";
import useOpenInCompany from "./useOpenInCompany";
import ReasonButton from "Components/Common/ReasonButton";

/**
 * "Gerar ideias do mês": a IA propõe 8 a 12 ideias para o mês aberto (âncoras com o gancho
 * sugerido, pilares e perfil da marca, sem repetir as publicações que já existem). Nada é
 * criado sozinho: o utilizador aceita ideia a ideia, podendo mudar a data e o canal, e cada
 * ideia aceite vira uma publicação em rascunho no calendário.
 */

const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};

const CHANNELS: PostChannel[] = ["instagram", "facebook", "site"];

type Props = {
    isOpen: boolean;
    toggle: () => void;
    companyId: number;
    year: number;
    month: number;
    monthLabel: string;
    /** Calendário atualizado depois de aceitar uma ideia. */
    onAccepted: (calendar: any) => void;
};

type Edit = { date: string; channel: PostChannel };

export default function IdeasModal({ isOpen, toggle, companyId, year, month, monthLabel, onAccepted }: Props) {
    const openInCompany = useOpenInCompany();
    const fetchOne = useCallback((id: number) => getEditorialIdeas(companyId, id), [companyId]);
    const { data, busy, stalled, start, resume, reset } = useAiRequestPoll<EditorialIdeasRequest>(fetchOne);
    const [profileEmpty, setProfileEmpty] = useState<boolean | null>(null);
    const [edits, setEdits] = useState<Record<number, Edit>>({});
    const [accepting, setAccepting] = useState<number | null>(null);
    const [acceptedNow, setAcceptedNow] = useState<Record<number, number>>({});

    const pad = (n: number) => String(n).padStart(2, "0");
    const lastDay = new Date(year, month, 0).getDate();
    const today = new Date();
    const todayIso = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`;
    const firstIso = `${year}-${pad(month)}-01`;
    const minDate = todayIso > firstIso ? todayIso : firstIso;
    const maxDate = `${year}-${pad(month)}-${pad(lastDay)}`;

    // Outro mês: começa do zero.
    useEffect(() => { reset(); setEdits({}); setAcceptedNow({}); }, [year, month, reset]);

    // Ao abrir: retoma as ideias que ficaram à espera para este mês (fechar não cancela nada).
    useEffect(() => {
        if (!isOpen || !companyId || data) return;
        getLatestAiRequest(companyId, { mode: "ideas", year, month })
            .then((r: any) => { if (r?.data) resume(r.data); })
            .catch(() => undefined);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isOpen, companyId, year, month]);

    /** Arquivar estas ideias: deixam de ficar à espera. */
    const discard = () => {
        if (data) dismissAiRequest(companyId, data.id).catch(() => undefined);
        reset();
        setEdits({});
        setAcceptedNow({});
    };

    useEffect(() => {
        if (!isOpen || !companyId) return;
        getBrandProfile(companyId)
            .then((r: any) => setProfileEmpty(!!r?.data?.is_empty))
            .catch(() => setProfileEmpty(null));
    }, [isOpen, companyId]);

    const result = data?.status === "done" ? data.result : null;
    useEffect(() => {
        if (!result) return;
        setEdits(Object.fromEntries(result.ideas.map((i, idx) => [idx, { date: i.date, channel: i.channel }])));
        setAcceptedNow({});
    }, [result]);

    const generate = async () => {
        try {
            await start(() => requestEditorialIdeas(companyId, year, month));
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível pedir as ideias."));
        }
    };

    const accept = async (idx: number, idea: EditorialIdea) => {
        if (!data) return;
        const e = edits[idx] ?? { date: idea.date, channel: idea.channel };
        setAccepting(idx);
        try {
            const r: any = await acceptEditorialIdea(companyId, data.id, {
                index: idx,
                publish_date: e.date !== idea.date ? e.date : undefined,
                channel: e.channel !== idea.channel ? e.channel : undefined,
            });
            onAccepted(r?.data?.calendar ?? {});
            setAcceptedNow((p) => ({ ...p, [idx]: r?.data?.post_id ?? -1 }));
            toast.success("Ideia aceite: publicação criada em rascunho.");
        } catch (err: any) {
            toast.error(errorMessage(err, "Não foi possível aceitar a ideia."));
        } finally {
            setAccepting(null);
        }
    };

    const setEdit = (idx: number, patch: Partial<Edit>) => setEdits((p) => ({ ...p, [idx]: { ...p[idx], ...patch } }));
    const isDone = (idx: number, idea: EditorialIdea) => !!idea.accepted_post_id || !!acceptedNow[idx];
    const acceptedCount = result?.ideas.filter((i, idx) => isDone(idx, i)).length ?? 0;
    const limitReached = !!data && data.used >= data.cap;

    return (
        <Modal isOpen={isOpen} toggle={toggle} size="lg" centered scrollable>
            <ModalHeader toggle={toggle}><i className="ri-lightbulb-flash-line me-1" />Ideias para {monthLabel}</ModalHeader>
            <ModalBody>
                <p className="text-muted fs-13">
                    A IA propõe ideias a partir das âncoras do mês, dos pilares e do perfil da marca, sem repetir as publicações que já existem.
                    Nada é criado sozinho: aceite as que quiser, uma a uma.
                </p>

                {profileEmpty && (
                    <div className="alert alert-warning fs-13 py-2">
                        <i className="ri-error-warning-line me-1" />
                        O Perfil da Marca está por preencher: as ideias vão usar só o ramo e as âncoras.{" "}
                        <button type="button" className="btn btn-link alert-link p-0 align-baseline fs-13" onClick={() => { toggle(); openInCompany(companyId, "/brand-profile"); }}>Preencher o Perfil da Marca</button>
                    </div>
                )}

                <AiRequestState data={data} what="as ideias" />
                {!result && (!busy || stalled) && (
                    <div className="text-center py-3">
                        <Button color="primary" onClick={generate}>
                            <i className="ri-lightbulb-flash-line me-1" />{data?.status === "error" || stalled ? "Tentar outra vez" : "Gerar ideias"}
                        </Button>
                    </div>
                )}

                {result && (
                    <>
                        <div className="d-flex flex-wrap align-items-center gap-2 mb-3 fs-12">
                            <Badge color="light" className="text-body">{result.ideas.length} ideias</Badge>
                            {acceptedCount > 0 && <Badge color="success-subtle" className="text-success">{acceptedCount} aceites</Badge>}
                            {result.skipped_duplicates > 0 && (
                                <span className="text-muted">{result.skipped_duplicates === 1 ? "1 ideia repetida foi descartada." : `${result.skipped_duplicates} ideias repetidas foram descartadas.`}</span>
                            )}
                        </div>
                        <div className="vstack gap-2">
                            {result.ideas.map((idea, idx) => {
                                const e = edits[idx] ?? { date: idea.date, channel: idea.channel };
                                const done = isDone(idx, idea);
                                return (
                                    <div key={idx} className={`border rounded p-3 ${done ? "bg-success-subtle border-success-subtle" : ""}`}>
                                        <div className="d-flex align-items-start justify-content-between gap-2">
                                            <div className="fw-semibold text-break">{idea.title}</div>
                                            {done && <Badge color="success" className="flex-shrink-0"><i className="ri-check-line me-1" />Criada</Badge>}
                                        </div>
                                        {idea.why && <p className="text-muted fs-13 mb-2 mt-1">{idea.why}</p>}
                                        <div className="d-flex flex-wrap gap-1 mb-2">
                                            {idea.anchor_title && <Badge color="secondary-subtle" className="text-secondary fw-normal"><i className="ri-calendar-event-line me-1" />{idea.anchor_title}</Badge>}
                                            {idea.pillar && <Badge color="primary-subtle" className="text-primary fw-normal"><i className="ri-stack-line me-1" />{idea.pillar}</Badge>}
                                            {e.channel === idea.channel && idea.channel !== "site" && <Badge color="light" className="text-body fw-normal" title="Tipo de conteúdo">{idea.content_type}</Badge>}
                                            {e.channel === idea.channel && idea.media_format && <Badge color="dark-subtle" className="text-body fw-normal" title="Formato"><i className="ri-layout-grid-line me-1" />{mediaFormatLabel(idea.media_format)}</Badge>}
                                            {idea.keyword && <Badge color="info-subtle" className="text-info fw-normal">#{idea.keyword}</Badge>}
                                        </div>
                                        {!done && (
                                            <div className="row g-2 align-items-center">
                                                <div className="col-6 col-md-4">
                                                    <XSelect small ariaLabel="Canal" value={e.channel} onChange={(c) => setEdit(idx, { channel: c as PostChannel })}
                                                        options={CHANNELS.map((c) => ({ value: c, label: POST_CHANNEL_META[c].label }))} />
                                                </div>
                                                <div className="col-6 col-md-4">
                                                    <Input type="date" bsSize="sm" aria-label="Data" value={e.date} min={minDate} max={maxDate} onChange={(ev) => setEdit(idx, { date: ev.target.value })} />
                                                </div>
                                                <div className="col-12 col-md-4 text-md-end">
                                                    <Button color="success" size="sm" className="w-100" disabled={accepting !== null || !e.date} onClick={() => accept(idx, idea)}>
                                                        {accepting === idx ? <Spinner size="sm" /> : <><i className="ri-check-line me-1" />Aceitar</>}
                                                    </Button>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </>
                )}
            </ModalBody>
            <ModalFooter className="justify-content-between">
                <span className="text-muted fs-12">{data ? `${data.used}/${data.cap} pedidos de ideias este mês` : ""}</span>
                <div className="d-flex gap-2">
                    {result && (
                        <ReasonButton color="outline-primary" onClick={generate} disabled={busy} reason={limitReached ? "Atingiu o limite de pedidos de ideias deste mês." : null}>
                            {busy ? <Spinner size="sm" /> : <><i className="ri-refresh-line me-1" />Gerar outras</>}
                        </ReasonButton>
                    )}
                    {result && <Button color="outline-primary" onClick={discard}>Descartar</Button>}
                    <Button color="light" onClick={toggle}>Fechar</Button>
                </div>
            </ModalFooter>
        </Modal>
    );
}
