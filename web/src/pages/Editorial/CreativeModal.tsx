import { useCallback, useEffect, useState } from "react";
import { Badge, Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import Select from "react-select";
import { toast } from "react-toastify";
import { acceptPostCreative, getCreativeSuggestion, getPostCreative, requestCreativeSuggestion } from "helpers/laravel_helper";
import { reactSelectTheme } from "helpers/reactSelectStyles";
import { useAiRequestPoll } from "hooks/useAiRequestPoll";
import { mediaFormatLabel } from "common/models/editorialPost.model";
import { CREATIVE_SOURCE_LABEL, CreativeSuggestion, PostCreative } from "common/models/brandAssistants.model";

/**
 * Criativo de uma publicação (Instagram e Facebook): o criativo aceite e o "Sugerir
 * criativo". A IA propõe formato, gancho, legenda, hashtags e chamada à ação, com o porquê
 * e a fonte (referência de mercado, dados da conta ou nenhuma). O humano pode editar e
 * escolhe campo a campo o que guarda: só os campos marcados são gravados.
 */

type Field = "media_format" | "hook" | "caption" | "hashtags" | "cta";
const FIELD_LABELS: Record<Field, string> = {
    media_format: "Formato", hook: "Gancho", caption: "Legenda", hashtags: "Hashtags", cta: "Chamada à ação",
};

const rate = (v: number | null) => (v === null ? null : `${v.toLocaleString("pt-PT", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%`);

const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};

type Props = {
    isOpen: boolean;
    toggle: () => void;
    companyId: number;
    postId: number;
    postTitle: string;
    onSaved: (mediaFormat: string | null) => void;
};

export default function CreativeModal({ isOpen, toggle, companyId, postId, postTitle, onSaved }: Props) {
    const fetchOne = useCallback((id: number) => getCreativeSuggestion(companyId, postId, id), [companyId, postId]);
    const { data, busy, timedOut, start, reset } = useAiRequestPoll<CreativeSuggestion>(fetchOne);
    const [saved, setSaved] = useState<PostCreative | null>(null);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [draft, setDraft] = useState<Record<Field, string>>({ media_format: "", hook: "", caption: "", hashtags: "", cta: "" });
    const [accepted, setAccepted] = useState<Record<Field, boolean>>({ media_format: false, hook: false, caption: false, hashtags: false, cta: false });

    useEffect(() => {
        if (!isOpen) { reset(); return; }
        setLoading(true);
        getPostCreative(companyId, postId)
            .then((r: any) => setSaved(r?.data ?? null))
            .catch(() => toast.error("Não foi possível carregar o criativo."))
            .finally(() => setLoading(false));
    }, [isOpen, companyId, postId, reset]);

    // Quando a sugestão chega, pré-preenche os campos editáveis (nenhum fica aceite sozinho).
    const result = data?.status === "done" ? data.result : null;
    useEffect(() => {
        if (!result) return;
        setDraft({
            media_format: result.media_format ?? "",
            hook: result.hook, caption: result.caption, hashtags: result.hashtags.join(" "), cta: result.cta,
        });
        setAccepted({ media_format: false, hook: false, caption: false, hashtags: false, cta: false });
    }, [result]);

    const ask = async () => {
        try {
            await start(() => requestCreativeSuggestion(companyId, postId));
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível pedir a sugestão."));
        }
    };

    const chosen = (Object.keys(accepted) as Field[]).filter((k) => accepted[k]);

    const save = async () => {
        const payload: any = { suggestion_id: data?.id };
        chosen.forEach((k) => {
            payload[k] = k === "hashtags"
                ? draft.hashtags.split(/[\s,]+/).filter(Boolean)
                : k === "media_format" ? (draft.media_format || null) : draft[k];
        });
        setSaving(true);
        try {
            const r: any = await acceptPostCreative(companyId, postId, payload);
            setSaved(r?.data ?? null);
            toast.success("Criativo guardado.");
            onSaved(r?.data?.media_format ?? null);
            reset();
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível guardar o criativo."));
        } finally {
            setSaving(false);
        }
    };

    const formatOptions = (saved?.formats ?? []).map((f) => ({ value: f.value, label: f.label }));
    const check = (k: Field) => (
        <div className="form-check form-switch mb-0">
            <Input type="switch" className="form-check-input" id={`acc-${k}`} checked={accepted[k]}
                onChange={(e) => setAccepted((a) => ({ ...a, [k]: e.target.checked }))} />
            <Label className="form-check-label fs-12" for={`acc-${k}`}>Aceitar</Label>
        </div>
    );

    return (
        <Modal isOpen={isOpen} toggle={toggle} size="lg" centered scrollable>
            <ModalHeader toggle={toggle}>Criativo: {postTitle}</ModalHeader>
            <ModalBody>
                {loading ? <div className="text-center py-4"><Spinner size="sm" /></div> : (
                    <>
                        {saved?.creative && !result && (
                            <div className="border rounded p-3 mb-3">
                                <div className="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <span className="fw-semibold">Criativo guardado</span>
                                    {saved.creative.source && <Badge color="light" className="text-body fw-normal">{CREATIVE_SOURCE_LABEL[saved.creative.source]}{saved.creative.source_label ? `: ${saved.creative.source_label}` : ""}</Badge>}
                                </div>
                                <dl className="row mb-0 fs-13">
                                    {saved.media_format && <><dt className="col-sm-3">Formato</dt><dd className="col-sm-9">{mediaFormatLabel(saved.media_format)}</dd></>}
                                    {saved.creative.hook && <><dt className="col-sm-3">Gancho</dt><dd className="col-sm-9">{saved.creative.hook}</dd></>}
                                    {saved.creative.caption && <><dt className="col-sm-3">Legenda</dt><dd className="col-sm-9" style={{ whiteSpace: "pre-line" }}>{saved.creative.caption}</dd></>}
                                    {saved.creative.hashtags.length > 0 && <><dt className="col-sm-3">Hashtags</dt><dd className="col-sm-9">{saved.creative.hashtags.join(" ")}</dd></>}
                                    {saved.creative.cta && <><dt className="col-sm-3">Chamada à ação</dt><dd className="col-sm-9">{saved.creative.cta}</dd></>}
                                    {saved.creative.rationale && <><dt className="col-sm-3">Porquê</dt><dd className="col-sm-9 text-muted">{saved.creative.rationale}</dd></>}
                                </dl>
                            </div>
                        )}

                        {!result && !busy && (
                            <>
                                <p className="text-muted fs-13">
                                    A IA propõe o formato, o gancho, a legenda, as hashtags e a chamada à ação para esta publicação, a partir da data, da âncora,
                                    do tema e do perfil da marca. Escolhe depois, campo a campo, o que guardar.
                                </p>
                                <Button color="primary" onClick={ask}><i className="ri-magic-line me-1" />Sugerir criativo</Button>
                            </>
                        )}
                        {busy && <div className="d-flex align-items-center gap-2 text-muted py-3"><Spinner size="sm" /> A preparar a sugestão…</div>}
                        {timedOut && <div className="alert alert-warning fs-13">A sugestão está a demorar. Tente novamente dentro de alguns minutos.</div>}
                        {data?.status === "error" && <div className="alert alert-danger fs-13">{data.error_message}</div>}

                        {result && (
                            <>
                                <div className="border rounded p-3 mb-3 fs-13 bg-light-subtle">
                                    <div className="d-flex flex-wrap align-items-center gap-2 mb-1">
                                        <strong>Porquê</strong>
                                        <Badge color={result.source === "none" ? "secondary" : "info"} className="fw-normal">{CREATIVE_SOURCE_LABEL[result.source]}</Badge>
                                        {result.source_label && (
                                            result.source_url
                                                ? <a href={result.source_url} target="_blank" rel="noopener noreferrer" className="fs-12">{result.source_label} <i className="ri-external-link-line" /></a>
                                                : <span className="text-muted fs-12">Fonte: {result.source_label}</span>
                                        )}
                                    </div>
                                    <div>{result.why}</div>
                                    {result.ranked.length > 0 && (
                                        <div className="text-muted fs-12 mt-1">
                                            {result.followers !== null && <>Com {result.followers.toLocaleString("pt-PT")} seguidores. </>}
                                            Referência: {result.ranked.map((r) => [r.label, rate(r.engagement_rate)].filter(Boolean).join(" ")).join(", ")}.
                                            {result.ranked[0]?.note ? ` ${result.ranked[0].note}` : ""}
                                        </div>
                                    )}
                                    {result.source === "none" && (
                                        <div className="text-muted fs-12 mt-1">Sem referência: registe os seguidores no Perfil da Marca para a sugestão de formato usar a regra da sua faixa.</div>
                                    )}
                                </div>

                                <div className="vstack gap-3">
                                    <div>
                                        <div className="d-flex justify-content-between align-items-center mb-1"><Label className="mb-0">{FIELD_LABELS.media_format}</Label>{check("media_format")}</div>
                                        <Select
                                            styles={reactSelectTheme}
                                            menuPortalTarget={document.body}
                                            options={formatOptions}
                                            isSearchable={false}
                                            value={formatOptions.find((o) => o.value === draft.media_format) ?? null}
                                            onChange={(o: any) => setDraft((d) => ({ ...d, media_format: o?.value ?? "" }))}
                                            placeholder="Escolha o formato"
                                        />
                                        {result.media_format_note && <div className="form-text">{result.media_format_note}</div>}
                                    </div>
                                    <div>
                                        <div className="d-flex justify-content-between align-items-center mb-1"><Label className="mb-0">{FIELD_LABELS.hook}</Label>{check("hook")}</div>
                                        <Input maxLength={200} value={draft.hook} onChange={(e) => setDraft((d) => ({ ...d, hook: e.target.value }))} />
                                    </div>
                                    <div>
                                        <div className="d-flex justify-content-between align-items-center mb-1"><Label className="mb-0">{FIELD_LABELS.caption}</Label>{check("caption")}</div>
                                        <Input type="textarea" rows={6} maxLength={2200} value={draft.caption} onChange={(e) => setDraft((d) => ({ ...d, caption: e.target.value }))} />
                                        <div className="form-text">{draft.caption.length} de 2200 caracteres.</div>
                                    </div>
                                    <div>
                                        <div className="d-flex justify-content-between align-items-center mb-1"><Label className="mb-0">{FIELD_LABELS.hashtags}</Label>{check("hashtags")}</div>
                                        <Input value={draft.hashtags} onChange={(e) => setDraft((d) => ({ ...d, hashtags: e.target.value }))} placeholder="#exemplo #outra" />
                                    </div>
                                    <div>
                                        <div className="d-flex justify-content-between align-items-center mb-1"><Label className="mb-0">{FIELD_LABELS.cta}</Label>{check("cta")}</div>
                                        <Input maxLength={300} value={draft.cta} onChange={(e) => setDraft((d) => ({ ...d, cta: e.target.value }))} />
                                    </div>
                                </div>
                            </>
                        )}
                    </>
                )}
            </ModalBody>
            <ModalFooter className="justify-content-between">
                <span className="text-muted fs-12">{data ? `Sugestões este mês: ${data.used} de ${data.cap}` : ""}</span>
                <div className="d-flex gap-2">
                    {result && <Button color="light" onClick={ask} disabled={busy}><i className="ri-refresh-line me-1" />Outra sugestão</Button>}
                    <Button color="light" onClick={toggle}>Fechar</Button>
                    {result && (
                        <Button color="primary" onClick={save} disabled={saving || chosen.length === 0}>
                            {saving && <Spinner size="sm" className="me-1" />}Guardar {chosen.length === 1 ? "1 campo" : `${chosen.length} campos`}
                        </Button>
                    )}
                </div>
            </ModalFooter>
        </Modal>
    );
}
