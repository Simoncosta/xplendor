import { useEffect, useState } from "react";
import { Card, CardBody, CardHeader, Input, Label, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { getWorkflowSettings, setContentApprover, updateWorkflowSettings } from "helpers/laravel_helper";
import { PRODUCTION_MODE_LABEL, ProductionMode, WorkflowSettings } from "common/models/editorialWorkflow.model";
import XSelect from "Components/Common/Select";
import useProducerLabel, { byProducer, capitalize } from "./useProducerLabel";

/**
 * Definições do fluxo de produção e aprovação da empresa (F3a): aprovação do cliente e
 * revisão interna (o administrador ou a equipa XPLENDOR), e quem aprova os conteúdos (só o
 * administrador da própria empresa, fora de sessão como cliente). O administrador aprova
 * sempre.
 */
const errorMessage = (e: any, fallback: string) => {
    const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
    return (first as string) || e?.message || fallback;
};

export default function WorkflowSettingsCard({ companyId }: { companyId: number }) {
    const producer = useProducerLabel(companyId);
    const [data, setData] = useState<WorkflowSettings | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!companyId) return;
        getWorkflowSettings(companyId).then((r: any) => setData(r.data)).catch(() => setData(null));
    }, [companyId]);

    const save = async (patch: Partial<Pick<WorkflowSettings, "content_approval_required" | "internal_review_required" | "production_mode">>) => {
        if (!data) return;
        setBusy(true);
        try {
            const r: any = await updateWorkflowSettings(companyId, {
                content_approval_required: patch.content_approval_required ?? data.content_approval_required,
                internal_review_required: patch.internal_review_required ?? data.internal_review_required,
                ...(patch.production_mode ? { production_mode: patch.production_mode } : {}),
            });
            setData(r.data);
            toast.success("Definições guardadas.");
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível guardar."));
        } finally {
            setBusy(false);
        }
    };

    const toggleApprover = async (userId: number, value: boolean) => {
        setBusy(true);
        try {
            const r: any = await setContentApprover(companyId, userId, value);
            setData(r.data);
        } catch (e: any) {
            toast.error(errorMessage(e, "Não foi possível atualizar."));
        } finally {
            setBusy(false);
        }
    };

    if (!data) return null;

    return (
        <Card className="mt-3">
            <CardHeader>
                <h5 className="card-title mb-1">Produção e aprovação</h5>
                <p className="text-muted fs-13 mb-0">Como as publicações passam de Produção a Programado.</p>
            </CardHeader>
            <CardBody>
                <div className="mb-3">
                    <Label className="fw-semibold mb-1" for="wf-mode">Modo de produção</Label>
                    <XSelect id="wf-mode" value={data.production_mode} disabled={!data.can_change_mode || busy}
                        onChange={(m) => save({ production_mode: m as ProductionMode })}
                        options={(Object.keys(PRODUCTION_MODE_LABEL) as ProductionMode[]).map((m) => ({ value: m, label: m === "team" ? `Produção ${byProducer(producer)}` : PRODUCTION_MODE_LABEL[m] }))} />
                    <div className="form-text">
                        {data.production_mode === "team"
                            ? `${capitalize(producer)} produz. Os utilizadores da empresa comentam, aprovam e pedem alterações, mas não editam o conteúdo nem mudam etapas.`
                            : "Os utilizadores da empresa produzem, aprovam e publicam."}
                        {!data.can_change_mode && ` Só ${producer} muda o modo de produção.`}
                    </div>
                </div>
                <div className="form-check form-switch mb-2">
                    <Input type="checkbox" role="switch" className="form-check-input" id="wf-approval" disabled={!data.can_edit || busy}
                        checked={data.content_approval_required} onChange={(e) => save({ content_approval_required: e.target.checked })} />
                    <Label className="form-check-label" for="wf-approval">As publicações precisam da aprovação do cliente</Label>
                </div>
                <div className="form-check form-switch mb-3">
                    <Input type="checkbox" role="switch" className="form-check-input" id="wf-internal" disabled={!data.can_edit || busy}
                        checked={data.internal_review_required} onChange={(e) => save({ internal_review_required: e.target.checked })} />
                    <Label className="form-check-label" for="wf-internal">Revisão interna obrigatória, feita por outra pessoa</Label>
                </div>

                <Label className="fw-semibold mb-1">Quem aprova os conteúdos</Label>
                <p className="text-muted fs-12 mb-2">
                    O administrador aprova sempre. Pode juntar outros utilizadores da empresa.
                    {!data.can_manage_approvers && " Só o administrador da empresa altera esta lista."}
                </p>
                <ul className="list-unstyled mb-0">
                    {data.users.map((u) => (
                        <li key={u.id} className="d-flex align-items-center justify-content-between gap-2 py-1 border-bottom">
                            <span className="text-break">{u.name}{u.by_role && <span className="text-muted fs-12"> (administrador)</span>}</span>
                            <div className="form-check form-switch mb-0">
                                <Input type="checkbox" role="switch" className="form-check-input" aria-label={`${u.name} aprova conteúdos`}
                                    checked={u.is_approver} disabled={u.by_role || !data.can_manage_approvers || busy}
                                    onChange={(e) => toggleApprover(u.id, e.target.checked)} />
                            </div>
                        </li>
                    ))}
                </ul>
                {busy && <Spinner size="sm" className="mt-2" />}
            </CardBody>
        </Card>
    );
}
