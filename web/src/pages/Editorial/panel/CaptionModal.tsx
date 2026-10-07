import { useCallback, useEffect, useState } from "react";
import { Badge, Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader } from "reactstrap";
import { toast } from "react-toastify";
import { dismissAiRequest, getCaptionSuggestion, getLatestAiRequest, requestCaptionSuggestion } from "helpers/laravel_helper";
import { AiPollable, useAiRequestPoll } from "hooks/useAiRequestPoll";
import AiRequestState from "Components/Common/AiRequestState";
import ReasonButton from "Components/Common/ReasonButton";
import { Network, POST_CHANNEL_META } from "common/models/editorialPost.model";

/**
 * "Gerar legenda": a IA escreve várias propostas a partir do tema, do Perfil da Marca, das
 * redes escolhidas (uma variação por rede) e das imagens carregadas (o modelo vê-as). A
 * pessoa escolhe uma, que passa para o rascunho para editar. Nada é gravado aqui.
 */

export type CaptionProposal = { angle: string; captions: Partial<Record<Network, string>>; hashtags: string[]; cta: string };
type CaptionSuggestion = AiPollable & {
    post_id: number; images_sent: number | null; used: number; cap: number;
    result: { networks: Network[]; proposals: CaptionProposal[] } | null;
};

const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};

type Props = {
    isOpen: boolean; toggle: () => void; companyId: number; postId: number;
    networks: Network[]; imageCount: number;
    onApply: (proposal: CaptionProposal, networks: Network[]) => void;
};

export default function CaptionModal({ isOpen, toggle, companyId, postId, networks, imageCount, onApply }: Props) {
    const fetchOne = useCallback((id: number) => getCaptionSuggestion(companyId, postId, id), [companyId, postId]);
    const { data, busy, start, resume, reset } = useAiRequestPoll<CaptionSuggestion>(fetchOne);
    const [chosen, setChosen] = useState<Network[]>(networks);

    useEffect(() => { reset(); setChosen(networks); }, [postId, reset]); // eslint-disable-line react-hooks/exhaustive-deps
    useEffect(() => {
        if (!isOpen || data) return;
        // Retoma as propostas que ficaram à espera para esta publicação (fechar não cancela).
        getLatestAiRequest(companyId, { mode: "caption", editorial_post_id: postId }).then((r: any) => { if (r?.data) resume(r.data); }).catch(() => undefined);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isOpen, companyId, postId]);

    const ask = async () => {
        try { await start(() => requestCaptionSuggestion(companyId, postId, chosen)); }
        catch (e: any) { toast.error(errorMessage(e, "Não foi possível pedir as propostas.")); }
    };
    const use = (p: CaptionProposal) => {
        onApply(p, data?.result?.networks ?? chosen);
        if (data) dismissAiRequest(companyId, data.id).catch(() => undefined);
        reset();
        toggle();
    };
    const toggleNetwork = (n: Network, on: boolean) => setChosen((c) => (on ? networks.filter((x) => x === n || c.includes(x)) : c.filter((x) => x !== n)));
    const result = data?.status === "done" ? data.result : null;

    return (
        <Modal isOpen={isOpen} toggle={toggle} size="lg" centered scrollable data-testid="caption-modal">
            <ModalHeader toggle={toggle}>Gerar legenda</ModalHeader>
            <ModalBody>
                {!result && (
                    <>
                        <p className="fs-13 mb-2">
                            A IA propõe várias legendas a partir do tema, do Perfil da Marca e {imageCount > 0 ? `das imagens carregadas (vê ${Math.min(imageCount, 4)} ${Math.min(imageCount, 4) === 1 ? "imagem" : "imagens"})` : "do tema (ainda não há imagens carregadas)"}, com uma variação por rede.
                            A proposta que escolher passa para o rascunho e só fica gravada quando a gravar.
                        </p>
                        {networks.length > 1 && (
                            <div className="d-flex flex-wrap gap-3 mb-2" role="group" aria-label="Redes">
                                {networks.map((n) => (
                                    <div className="form-check" key={n}>
                                        <Input type="checkbox" className="form-check-input" id={`cap-net-${n}`} checked={chosen.includes(n)} disabled={busy}
                                            onChange={(e) => toggleNetwork(n, e.target.checked)} />
                                        <Label className="form-check-label" for={`cap-net-${n}`}><i className={`${POST_CHANNEL_META[n].icon} me-1`} />{POST_CHANNEL_META[n].label}</Label>
                                    </div>
                                ))}
                            </div>
                        )}
                    </>
                )}
                <AiRequestState data={data} what="as propostas" />
                {result && (
                    <div className="vstack gap-3" data-testid="caption-proposals">
                        {result.proposals.map((p, i) => (
                            <div className="border rounded p-3" key={i}>
                                <div className="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <span className="fw-semibold">Proposta {i + 1}</span>
                                    {p.angle && <Badge color="light" className="text-body fw-normal">{p.angle}</Badge>}
                                    <Button size="sm" color="outline-primary" className="ms-auto" onClick={() => use(p)}><i className="ri-check-line me-1" />Usar esta proposta</Button>
                                </div>
                                {result.networks.map((n) => (
                                    <div key={n} className="mb-2">
                                        <div className="fs-12 text-muted mb-1"><i className={`${POST_CHANNEL_META[n].icon} me-1`} />{POST_CHANNEL_META[n].label}</div>
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
                <Button color="light" onClick={toggle}>Fechar</Button>
                <ReasonButton color={result ? "outline-primary" : "primary"} disabled={busy} onClick={ask}
                    reason={chosen.length === 0 ? "Escolha pelo menos uma rede." : null}>
                    <i className="ri-magic-line me-1" />{result ? "Gerar outras" : busy ? "A gerar" : "Gerar propostas"}
                </ReasonButton>
            </ModalFooter>
        </Modal>
    );
}
