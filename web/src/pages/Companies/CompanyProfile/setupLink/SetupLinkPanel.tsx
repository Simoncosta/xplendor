import { useCallback, useEffect, useState } from "react";
import { Badge, Button, Input, InputGroup, Label, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import ActionsMenu from "Components/Common/ActionsMenu";
import ReasonButton from "Components/Common/ReasonButton";
import { confirmAction } from "helpers/swal";
import { createSetupLink, extendSetupLink, getSetupLink, revokeSetupLink } from "helpers/laravel_helper";
import { META_REVIEW_NOTICE, STEP_ICON, STEP_STATUS, SetupLinkPayload, SetupStepKey, stepDetailText } from "common/models/setupLink.model";

/**
 * Link de configuração do cliente, do lado da equipa: o estado do link e de cada passo,
 * copiar, enviar por WhatsApp ou email, renovar, revogar e gerar um link novo (escolher os
 * passos). Usado nas Integrações da empresa, no Painel da agência e no ticket de arranque.
 * Quem não pode gerir (o servidor responde 403) não vê nada.
 */
type Props = {
    companyId: number;
    /** O ticket de arranque de onde o link é gerado (as tarefas dele marcam-se pela chave). */
    supportTicketId?: number | null;
    /** Passos pré-escolhidos ao gerar (por omissão, todos). */
    presetSteps?: SetupStepKey[];
    /** Abre já o formulário de gerar (quando não há link ativo). */
    startGenerating?: boolean;
    onForbidden?: () => void;
};

const dmy = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString("pt-PT", { day: "2-digit", month: "2-digit", year: "numeric" }) : "");
const dmyHm = (iso: string | null) => (iso ? new Date(iso).toLocaleString("pt-PT", { dateStyle: "short", timeStyle: "short" }) : "");
const errorText = (e: any, fallback: string) => {
    const body = e?.response?.data ?? e;
    const first = body?.errors ? (Object.values(body.errors).flat()[0] as string) : null;
    return first || body?.message || fallback;
};

export default function SetupLinkPanel({ companyId, supportTicketId, presetSteps, startGenerating, onForbidden }: Props) {
    const [data, setData] = useState<SetupLinkPayload | null>(null);
    const [forbidden, setForbidden] = useState(false);
    const [generating, setGenerating] = useState(false);
    const [steps, setSteps] = useState<SetupStepKey[]>(presetSteps ?? ["social", "meta_ads", "ga4"]);
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => {
        getSetupLink(companyId)
            .then((r: any) => {
                const d: SetupLinkPayload = r?.data;
                setData(d);
                if (startGenerating && d?.link?.state !== "open") setGenerating(true);
            })
            .catch((e: any) => {
                if (e?.__status === 403) {
                    setForbidden(true);
                    onForbidden?.();
                } else {
                    toast.error(errorText(e, "Não foi possível carregar o link de configuração."));
                }
            });
    }, [companyId, startGenerating, onForbidden]);
    useEffect(() => { load(); }, [load]);

    if (forbidden) return null;
    if (!data) return <div className="text-center py-3"><Spinner size="sm" /></div>;

    const link = data.link;
    const open = link?.state === "open";
    const withMeta = steps.includes("social") || steps.includes("meta_ads");
    const message = `Olá. Para ligarmos as plataformas de marketing de ${data.company_name} à XPLENDOR, abra este link no telemóvel e siga os passos. Só autoriza a leitura dos dados e pode retirar o acesso a qualquer momento.`;

    const run = async (fn: () => Promise<any>, ok: string, fail: string) => {
        setBusy(true);
        try {
            const r: any = await fn();
            setData(r?.data ?? data);
            toast.success(ok);
            return true;
        } catch (e: any) {
            toast.error(errorText(e, fail));
            return false;
        } finally { setBusy(false); }
    };
    const generate = async () => {
        if (await run(() => createSetupLink(companyId, steps, supportTicketId), "Link de configuração gerado.", "Não foi possível gerar o link.")) setGenerating(false);
    };
    const revoke = async () => {
        if (!link) return;
        const ok = await confirmAction({ title: "Revogar o link de configuração?", text: "O link deixa de funcionar de imediato. As ligações já feitas mantêm-se.", confirmText: "Revogar", icon: "warning", confirmVariant: "danger" });
        if (ok) await run(() => revokeSetupLink(companyId, link.id), "Link revogado.", "Não foi possível revogar o link.");
    };
    const copy = async () => {
        if (!link?.url) return;
        try { await navigator.clipboard.writeText(link.url); toast.success("Link copiado."); }
        catch { toast.error("Não foi possível copiar. Selecione o link e copie-o à mão."); }
    };

    if (generating) {
        return (
            <div data-testid="setup-link-form">
                <p className="fs-13 mb-2">Escolha o que o cliente vai autorizar. O link é válido durante {data.validity_days} dias e não precisa de conta na XPLENDOR.</p>
                <div className="vstack gap-2 mb-3">
                    {data.steps.map((s) => (
                        <div className="form-check" key={s.key}>
                            <Input type="checkbox" className="form-check-input" id={`setup-step-${companyId}-${s.key}`} checked={steps.includes(s.key)}
                                onChange={(e) => setSteps((prev) => (e.target.checked ? data.steps.map((x) => x.key).filter((k) => k === s.key || prev.includes(k)) : prev.filter((k) => k !== s.key)))} />
                            <Label className="form-check-label" for={`setup-step-${companyId}-${s.key}`}><i className={`${STEP_ICON[s.key]} me-1`} />{s.label}</Label>
                        </div>
                    ))}
                </div>
                {data.meta_app_review_pending && withMeta && (
                    <div className="alert alert-warning fs-13 py-2" role="alert" data-testid="meta-review-notice"><i className="ri-error-warning-line me-1" />{META_REVIEW_NOTICE}</div>
                )}
                {open && <p className="text-muted fs-12">Gerar um link novo revoga o link atual.</p>}
                <div className="d-flex flex-wrap gap-2 justify-content-end">
                    {(link || !startGenerating) && <Button color="light" disabled={busy} onClick={() => setGenerating(false)}>Cancelar</Button>}
                    <ReasonButton color="primary" disabled={busy} onClick={generate} reason={steps.length === 0 ? "Escolha pelo menos um passo." : null}>
                        {busy ? <Spinner size="sm" /> : <><i className="ri-link me-1" />Gerar link</>}
                    </ReasonButton>
                </div>
            </div>
        );
    }

    return (
        <div data-testid="setup-link-panel">
            {!link || !open ? (
                <>
                    <p className="fs-13 mb-2">
                        {!link ? "Ainda não foi enviado nenhum link de configuração."
                            : link.state === "expired" ? `O último link expirou a ${dmy(link.expires_at)}.`
                                : link.revoked_reason === "replaced" ? "O último link foi substituído." : `O último link foi revogado a ${dmy(link.revoked_at)}.`}
                        {" "}O cliente abre o link no telemóvel, sem conta, e autoriza ele próprio as ligações.
                    </p>
                    {link && link.steps.some((s) => s.status === "done") && <StepList link={link} />}
                    <Button color="outline-primary" onClick={() => setGenerating(true)}><i className="ri-send-plane-line me-1" />Enviar link de configuração</Button>
                </>
            ) : (
                <>
                    <div className="d-flex flex-wrap align-items-center gap-2 mb-2">
                        {link.completed_at ? <Badge color="success-subtle" className="text-success fw-normal">Concluído a {dmy(link.completed_at)}</Badge>
                            : <Badge color="info-subtle" className="text-info fw-normal">Ativo até {dmy(link.expires_at)}</Badge>}
                        <span className="text-muted fs-12">
                            {link.open_count > 0 ? `Aberto ${link.open_count} ${link.open_count === 1 ? "vez" : "vezes"}, a última a ${dmyHm(link.last_opened_at)}` : "Ainda não foi aberto"}
                        </span>
                        <span className="ms-auto">
                            <ActionsMenu size="sm" label="Mais ações do link de configuração" items={[
                                { label: `Renovar por mais ${data.validity_days} dias`, icon: "ri-time-line", onClick: () => void run(() => extendSetupLink(companyId, link.id), "Link renovado.", "Não foi possível renovar o link.") },
                                { label: "Gerar um link novo", icon: "ri-refresh-line", onClick: () => { setSteps(link.steps.map((s) => s.key)); setGenerating(true); } },
                                { label: "Revogar o link", icon: "ri-forbid-2-line", danger: true, onClick: () => void revoke() },
                            ]} />
                        </span>
                    </div>
                    <StepList link={link} />
                    <InputGroup size="sm" className="mt-3">
                        <Input readOnly value={link.url ?? ""} aria-label="Endereço do link de configuração" onFocus={(e) => e.target.select()} />
                        <Button color="outline-primary" onClick={copy}><i className="ri-file-copy-line me-1" />Copiar</Button>
                    </InputGroup>
                    <div className="d-flex flex-wrap gap-2 mt-2">
                        <a className="btn btn-outline-primary btn-sm" target="_blank" rel="noreferrer noopener"
                            href={`https://wa.me/?text=${encodeURIComponent(`${message}\n${link.url}`)}`}><i className="ri-whatsapp-line me-1" />Enviar por WhatsApp</a>
                        <a className="btn btn-outline-primary btn-sm"
                            href={`mailto:?subject=${encodeURIComponent(`Configuração das ligações de marketing: ${data.company_name}`)}&body=${encodeURIComponent(`${message}\n\n${link.url}`)}`}><i className="ri-mail-send-line me-1" />Enviar por email</a>
                    </div>
                </>
            )}
        </div>
    );
}

function StepList({ link }: { link: NonNullable<SetupLinkPayload["link"]> }) {
    return (
        <ul className="list-unstyled vstack gap-2 mb-3" data-testid="setup-link-steps">
            {link.steps.map((s) => {
                const st = STEP_STATUS[s.status];
                const detail = stepDetailText(s);
                return (
                    <li key={s.key} className="d-flex align-items-start gap-2">
                        <i className={`${STEP_ICON[s.key]} fs-18 text-muted`} aria-hidden />
                        <div className="min-w-0 flex-grow-1">
                            <div className="d-flex flex-wrap align-items-center gap-2">
                                <span className="fw-medium fs-13">{s.label}</span>
                                <Badge color={`${st.color}-subtle`} className={`fw-normal ${st.color === "light" ? "text-body" : `text-${st.color}`}`}>{st.label}</Badge>
                            </div>
                            {s.status === "done" && detail && <div className="text-muted fs-12">{detail}</div>}
                            {s.status !== "done" && s.error && <div className="text-muted fs-12">{s.error}</div>}
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
