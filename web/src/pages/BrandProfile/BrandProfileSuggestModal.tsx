import { useCallback, useEffect, useMemo, useState } from "react";
import { Badge, Button, Input, Modal, ModalBody, ModalFooter, ModalHeader, Table } from "reactstrap";
import { toast } from "react-toastify";
import { dismissAiRequest, getBrandProfileSuggestion, getLatestAiRequest, requestBrandProfileSuggestion } from "helpers/laravel_helper";
import { useAiRequestPoll } from "hooks/useAiRequestPoll";
import AiRequestState from "Components/Common/AiRequestState";
import type { BrandPillar, IBrandProfile } from "common/models/blog.model";
import { PROFILE_SOURCE_LABEL, ProfileSuggestion, ProfileSuggestionValue } from "common/models/brandAssistants.model";

/**
 * "Sugerir perfil": a IA propõe cada campo (com o porquê e a fonte) e o humano aceita, ou
 * não, campo a campo. Os campos aceites passam para o FORMULÁRIO; nada é gravado até carregar
 * em "Guardar" na página. Campos já preenchidos nunca são substituídos sem a caixa marcada.
 */

const FIELD_LABELS: Record<string, string> = {
    tone_of_voice: "Tom de voz",
    audience: "Público",
    pillars: "Pilares de conteúdo",
    words_to_use: "Palavras a usar",
    words_to_avoid: "Palavras a evitar",
    topics_to_avoid: "Temas a evitar",
    hashtags_default: "Hashtags habituais",
    cta_default: "Chamada à ação habitual",
    emoji_policy: "Emojis",
};
const FIELD_ORDER = Object.keys(FIELD_LABELS);
const EMOJI_LABEL: Record<string, string> = { none: "Sem emojis", light: "Poucos emojis", free: "Emojis à vontade" };

const render = (field: string, value: unknown): string => {
    if (value === null || value === undefined || value === "") return "";
    if (field === "pillars") return (value as BrandPillar[]).map((p, i) => `${i + 1}. ${p.name}${p.description ? `: ${p.description}` : ""}`).join("\n");
    if (field === "emoji_policy") return EMOJI_LABEL[value as string] ?? String(value);
    if (Array.isArray(value)) return value.join(", ");
    return String(value);
};

const isFilled = (value: unknown) => (Array.isArray(value) ? value.length > 0 : value !== null && value !== undefined && String(value).trim() !== "");

const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};

type Props = {
    isOpen: boolean;
    toggle: () => void;
    companyId: number;
    current: IBrandProfile;
    onApply: (patch: Partial<IBrandProfile>) => void;
};

export default function BrandProfileSuggestModal({ isOpen, toggle, companyId, current, onApply }: Props) {
    const fetchOne = useCallback((id: number) => getBrandProfileSuggestion(companyId, id), [companyId]);
    const { data, busy, stalled, start, resume, reset } = useAiRequestPoll<ProfileSuggestion>(fetchOne);
    const [accepted, setAccepted] = useState<Record<string, boolean>>({});

    // Ao abrir: retoma o pedido que ficou à espera (pendente, pronto ou com erro). Fechar
    // não cancela nada: o pedido continua no servidor e o aviso chega ao sino.
    useEffect(() => {
        if (!isOpen || !companyId || data) return;
        getLatestAiRequest(companyId, { mode: "brand_profile" })
            .then((r: any) => { if (r?.data) resume(r.data); })
            .catch(() => undefined);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isOpen, companyId]);

    /** O resultado foi usado ou descartado: deixa de ficar à espera. */
    const dismiss = () => {
        if (data) dismissAiRequest(companyId, data.id).catch(() => undefined);
        reset();
        setAccepted({});
    };

    const fields = useMemo(() => {
        const f = data?.status === "done" ? data.result?.fields ?? {} : {};
        return FIELD_ORDER.filter((k) => f[k]).map((k) => ({ key: k, ...f[k] }));
    }, [data]);

    const ask = async () => {
        setAccepted({});
        try {
            await start(() => requestBrandProfileSuggestion(companyId));
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível pedir a sugestão."));
        }
    };

    const apply = () => {
        const patch: Partial<IBrandProfile> = {};
        fields.filter((f) => accepted[f.key]).forEach((f) => { (patch as any)[f.key] = f.value as ProfileSuggestionValue; });
        onApply(patch);
        toast.info("Campos aplicados ao formulário. Reveja e carregue em Guardar para os gravar.");
        dismiss();
        toggle();
    };

    const chosen = fields.filter((f) => accepted[f.key]).length;

    return (
        <Modal isOpen={isOpen} toggle={toggle} size="xl" centered scrollable>
            <ModalHeader toggle={toggle}>Sugerir perfil</ModalHeader>
            <ModalBody>
                <p className="text-muted fs-13">
                    A IA propõe cada campo a partir do modelo do seu ramo, dos dados da empresa e do público medido (só quando há volume suficiente).
                    Escolha campo a campo o que quer usar: nada é gravado até carregar em Guardar na página.
                </p>

                {(!data || data.status === "error" || stalled) && (!busy || stalled) && (
                    <Button color="primary" className="mb-3" onClick={ask}><i className="ri-magic-line me-1" />{data ? "Pedir outra vez" : "Pedir sugestão"}</Button>
                )}
                <AiRequestState data={data} what="a sugestão" />
                {data?.audience_warning && data.status === "done" && (
                    <div className="alert alert-info fs-13 py-2"><i className="ri-information-line me-1" />{data.audience_warning}</div>
                )}

                {data?.status === "done" && (fields.length === 0 ? (
                    <p className="text-muted mb-0">A IA não teve nada de novo a propor. O perfil atual mantém-se.</p>
                ) : (
                    <div className="table-responsive">
                        <Table className="align-middle fs-13 mb-0">
                            <thead className="text-muted">
                                <tr>
                                    <th style={{ width: 150 }}>Campo</th>
                                    <th>Atual</th>
                                    <th>Proposta</th>
                                    <th className="text-center" style={{ width: 90 }}>Aceitar</th>
                                </tr>
                            </thead>
                            <tbody>
                                {fields.map((f) => {
                                    const now = (current as any)[f.key];
                                    const filled = isFilled(now);
                                    return (
                                        <tr key={f.key}>
                                            <td className="fw-medium">{FIELD_LABELS[f.key]}</td>
                                            <td className="text-muted" style={{ whiteSpace: "pre-line" }}>{filled ? render(f.key, now) : <em>Por preencher</em>}</td>
                                            <td>
                                                <div style={{ whiteSpace: "pre-line" }}>{render(f.key, f.value)}</div>
                                                <div className="d-flex flex-wrap align-items-center gap-1 mt-1">
                                                    <Badge color="light" className="text-body fw-normal">{PROFILE_SOURCE_LABEL[f.source]}</Badge>
                                                    {filled && <Badge color="warning-subtle" className="text-warning fw-normal">Substitui o valor atual</Badge>}
                                                </div>
                                                {f.reason && <div className="text-muted fs-12 mt-1">{f.reason}</div>}
                                            </td>
                                            <td className="text-center">
                                                <Input
                                                    type="checkbox"
                                                    aria-label={`Aceitar ${FIELD_LABELS[f.key]}`}
                                                    checked={!!accepted[f.key]}
                                                    onChange={(e) => setAccepted((a) => ({ ...a, [f.key]: e.target.checked }))}
                                                />
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </Table>
                    </div>
                ))}
            </ModalBody>
            <ModalFooter className="justify-content-between">
                <span className="text-muted fs-12">{data ? `Sugestões este mês: ${data.used} de ${data.cap}` : ""}</span>
                <div className="d-flex gap-2">
                    {data?.status === "done" && <Button color="light" onClick={dismiss}>Descartar</Button>}
                    <Button color="light" onClick={toggle}>Fechar</Button>
                    {data?.status === "done" && fields.length > 0 && (
                        <Button color="primary" onClick={apply} disabled={chosen === 0}>
                            Aplicar {chosen === 1 ? "1 campo" : `${chosen} campos`} ao formulário
                        </Button>
                    )}
                </div>
            </ModalFooter>
        </Modal>
    );
}
