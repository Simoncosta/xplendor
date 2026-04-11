import { useEffect, useMemo, useState } from "react";
import {
    Alert,
    Badge,
    Button,
    FormGroup,
    Input,
    Label,
    Modal,
    ModalBody,
    ModalFooter,
    ModalHeader,
    Spinner,
} from "reactstrap";
import { toast } from "react-toastify";

import { executeCarActionApi } from "../../../helpers/laravel_helper";
import {
    ActionCenterCarItem,
    ActionExecutionOption,
    ActionExecutionResponse,
    DecisionType,
} from "../types";

const actionOptions: ActionExecutionOption[] = [
    {
        key: "pause_campaign",
        label: "Pausar campanha",
        description: "Pausa o mapeamento ativo da campanha desta viatura.",
        implemented: true,
    },
    {
        key: "notify_client_whatsapp",
        label: "Avisar via WhatsApp",
        description: "Prepara uma mensagem pronta a enviar com contexto da decisão.",
        implemented: true,
    },
    {
        key: "generate_new_copy",
        label: "Gerar nova copy",
        description: "Cria uma nova copy sugerida com base na viatura e na decisão.",
        implemented: true,
    },
    {
        key: "suggest_new_vehicle",
        label: "Sugerir nova viatura",
        description: "Preparado para expansão futura.",
        implemented: false,
    },
    {
        key: "launch_template_campaign",
        label: "Lançar campanha template",
        description: "Preparado para expansão futura.",
        implemented: false,
    },
    {
        key: "duplicate_winning_campaign",
        label: "Duplicar campanha vencedora",
        description: "Preparado para expansão futura.",
        implemented: false,
    },
    {
        key: "swap_creative",
        label: "Trocar criativo",
        description: "Preparado para expansão futura.",
        implemented: false,
    },
    {
        key: "mark_lead_low_quality",
        label: "Marcar lead fraco",
        description: "Preparado para expansão futura.",
        implemented: false,
    },
];

interface ActionExecutionModalProps {
    item: ActionCenterCarItem;
    isOpen: boolean;
    toggle: () => void;
    onExecuted: (result: ActionExecutionResponse) => void;
    onExecutionStateChange?: (running: boolean) => void;
}

const readCompanyId = () => {
    const authUser = sessionStorage.getItem("authUser");
    if (!authUser) return 0;

    return Number(JSON.parse(authUser).company_id || 0);
};

const getDefaultAction = (decision: DecisionType | "INSUFFICIENT_DATA") => {
    switch (decision) {
        case "PARAR":
            return "pause_campaign";
        case "ESCALAR":
            return "notify_client_whatsapp";
        case "MANTER":
            return "notify_client_whatsapp";
        default:
            return "generate_new_copy";
    }
};

export default function ActionExecutionModal({
    item,
    isOpen,
    toggle,
    onExecuted,
    onExecutionStateChange,
}: ActionExecutionModalProps) {
    const [selectedAction, setSelectedAction] = useState(getDefaultAction(item.decision));
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<ActionExecutionResponse | null>(null);

    useEffect(() => {
        if (isOpen) {
            setSelectedAction(getDefaultAction(item.decision));
            setLoading(false);
            setError(null);
            setResult(null);
        }
    }, [isOpen, item.decision]);

    const selectedOption = useMemo(
        () => actionOptions.find((option) => option.key === selectedAction) ?? actionOptions[0],
        [selectedAction]
    );

    const executeAction = async () => {
        const companyId = readCompanyId();

        if (!companyId) {
            setError("Não foi possível identificar a empresa ativa.");
            return;
        }

        if (!selectedOption.implemented || loading) {
            return;
        }

        if (selectedOption.key === "pause_campaign") {
            const confirmed = window.confirm(
                "Tens a certeza que queres pausar esta campanha? Isto não pausa na Meta ainda."
            );

            if (!confirmed) return;
        }

        setLoading(true);
        setError(null);
        onExecutionStateChange?.(true);

        try {
            const response: any = await executeCarActionApi(companyId, item.id, {
                action: selectedOption.key,
                context: {
                    decision: item.decision,
                    confidence: item.confidence,
                    reason: item.reason,
                },
            });

            const payload = response?.data as ActionExecutionResponse;
            setResult(payload);
            onExecuted(payload);
            toast.success(payload.message || "Ação executada com sucesso.");
        } catch (err: any) {
            const message = typeof err === "string" ? err : "Não foi possível executar a ação.";
            setError(message);
            toast.error(message);
        } finally {
            setLoading(false);
            onExecutionStateChange?.(false);
        }
    };

    const copyText = async (value?: string | null) => {
        if (!value) return;

        try {
            await navigator.clipboard.writeText(value);
            toast.success("Conteúdo copiado.");
        } catch {
            toast.error("Não foi possível copiar o conteúdo.");
        }
    };

    return (
        <Modal isOpen={isOpen} toggle={!loading ? toggle : undefined} centered size="lg">
            <ModalHeader toggle={!loading ? toggle : undefined}>
                Executar ação para {item.car_name}
            </ModalHeader>
            <ModalBody>
                <div className="d-grid gap-3">
                    <div className="text-muted fs-14">
                        Escolhe a ação a executar. As primeiras três já estão operacionais neste MVP.
                    </div>

                    <div className="d-grid gap-2">
                        {actionOptions.map((option) => (
                            <FormGroup
                                check
                                key={option.key}
                                className="border rounded-3 px-3 py-2"
                                style={{
                                    opacity: option.implemented ? 1 : 0.65,
                                    background: selectedAction === option.key ? "#f8fafc" : "#fff",
                                }}
                            >
                                <Input
                                    type="radio"
                                    id={option.key}
                                    name="actionExecution"
                                    checked={selectedAction === option.key}
                                    onChange={() => option.implemented && setSelectedAction(option.key)}
                                    disabled={!option.implemented || loading}
                                />
                                <Label for={option.key} check className="w-100">
                                    <div className="d-flex align-items-start justify-content-between gap-2">
                                        <div>
                                            <div className="fw-semibold">{option.label}</div>
                                            <div className="text-muted fs-13">{option.description}</div>
                                        </div>
                                        {!option.implemented && (
                                            <Badge color="light" className="text-dark border">
                                                Em breve
                                            </Badge>
                                        )}
                                    </div>
                                </Label>
                            </FormGroup>
                        ))}
                    </div>

                    {error && <Alert color="danger" className="mb-0">{error}</Alert>}
                    {result && <Alert color="success" className="mb-0">{result.message}</Alert>}

                    {result?.data?.campaign && (
                        <div className="border rounded-3 p-3 bg-light-subtle">
                            <div className="fw-semibold mb-1">Campanha atualizada</div>
                            <div className="fs-14 text-muted">
                                {result.data.campaign.campaign_name || result.data.campaign.campaign_id}
                            </div>
                        </div>
                    )}

                    {result?.data?.message_text && (
                        <div className="border rounded-3 p-3 bg-light-subtle">
                            <div className="d-flex align-items-start justify-content-between gap-2 mb-2 flex-wrap">
                                <div>
                                    <div className="fw-semibold">
                                        Mensagem pronta para {result.data.recipient?.name || "WhatsApp"}
                                    </div>
                                    <div className="text-muted fs-13">
                                        {result.data.recipient?.phone || "Sem número disponível"}
                                    </div>
                                </div>
                                <div className="d-flex gap-2 flex-wrap">
                                    <Button color="light" className="border btn-sm" onClick={() => copyText(result.data.message_text)}>
                                        Copiar texto
                                    </Button>
                                    {result.data.whatsapp_url && (
                                        <Button
                                            color="success"
                                            className="btn-sm"
                                            tag="a"
                                            href={result.data.whatsapp_url}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            Abrir WhatsApp
                                        </Button>
                                    )}
                                </div>
                            </div>
                            <pre className="mb-0 fs-13" style={{ whiteSpace: "pre-wrap", fontFamily: "inherit" }}>
                                {result.data.message_text}
                            </pre>
                        </div>
                    )}

                    {result?.data?.copy && (
                        <div className="border rounded-3 p-3 bg-light-subtle">
                            <div className="d-flex align-items-start justify-content-between gap-2 mb-2 flex-wrap">
                                <div className="fw-semibold">Copy sugerida</div>
                                <Button
                                    color="light"
                                    className="border btn-sm"
                                    onClick={() => copyText(
                                        `${result.data.copy?.headline}\n\n${result.data.copy?.primary_text}\n\nCTA: ${result.data.copy?.cta}`
                                    )}
                                >
                                    Copiar copy
                                </Button>
                            </div>
                            <div className="fs-14 mb-2"><strong>Headline:</strong> {result.data.copy.headline}</div>
                            <div className="fs-14 mb-2"><strong>Texto:</strong> {result.data.copy.primary_text}</div>
                            <div className="fs-14 mb-2"><strong>CTA:</strong> {result.data.copy.cta}</div>
                            <div className="fs-13 text-muted"><strong>Ângulo:</strong> {result.data.copy.angle}</div>
                        </div>
                    )}
                </div>
            </ModalBody>
            <ModalFooter>
                <Button color="light" className="border" onClick={toggle} disabled={loading}>
                    Fechar
                </Button>
                <Button color="primary" onClick={executeAction} disabled={!selectedOption.implemented || loading}>
                    {loading ? <><Spinner size="sm" className="me-2" />A executar...</> : "Executar agora"}
                </Button>
            </ModalFooter>
        </Modal>
    );
}
