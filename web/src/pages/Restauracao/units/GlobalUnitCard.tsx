import { useEffect, useRef, useState } from "react";
import { Badge, Button, Card, CardBody, Modal, ModalBody, ModalFooter, ModalHeader, Progress, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import ReasonButton from "Components/Common/ReasonButton";
import HiddenMoney from "Components/Common/HiddenMoney";
import { applyGlobalUnitGuide, getUnitGuideWrite } from "helpers/laravel_helper";
import { GlobalUnitView, UnitWrite } from "common/models/unitGuide.model";

/**
 * XPLENDOR — Auditoria v2.1 (U9): a correção guiada de uma unidade global incoerente com o nome. As
 * unidades do PingWin são de todos os artigos e a quantidade não muda: primeiro a unidade certa
 * (reutilizada, ou criada uma vez), depois os artigos um a um ("1 de N"), cada um a passar para ela
 * como unidade de compra. A antiga deixa de se usar; nunca se anula nem se altera.
 */
const FINAL = ["ok", "mismatch", "refused", "failed"];
const eur = (c: number | null) => (c === null ? "" : (c / 100).toLocaleString("pt-PT", { maximumFractionDigits: 0 }) + " €");

export default function GlobalUnitCard({ companyId, view, canEdit, editReason, onChanged }: {
    companyId: number; view: GlobalUnitView; canEdit: boolean; editReason: string | null; onChanged: () => void;
}) {
    const [asking, setAsking] = useState(false);
    const [write, setWrite] = useState<UnitWrite | null>(null);
    const timer = useRef<ReturnType<typeof setInterval> | null>(null);
    useEffect(() => () => { if (timer.current) clearInterval(timer.current); }, []);
    const hidden = view.financial?.visible === false;
    const done = view.articles.filter((a) => a.done).length;
    const n = view.articles.length;
    const rightReady = view.right.status === "ok";

    const create = async () => {
        setAsking(false);
        try {
            const res: any = await applyGlobalUnitGuide(companyId, view.unit.id);
            const w: UnitWrite = res?.data?.write;
            setWrite(w);
            timer.current = setInterval(async () => {
                const r: any = await getUnitGuideWrite(companyId, w.id).catch(() => null);
                const now: UnitWrite | undefined = r?.data?.write;
                if (!now) return;
                setWrite(now);
                if (FINAL.includes(now.status)) {
                    if (timer.current) clearInterval(timer.current);
                    if (now.status === "ok") toast.success(now.note ?? "Unidade criada e confirmada pela releitura.");
                    else toast.error(now.error ?? "O PingWin não confirmou a unidade.");
                    onChanged();
                }
            }, 1500);
        } catch (e: any) {
            const first = e?.errors ? Object.values(e.errors).flat()[0] : null;
            toast.error((first as string) ?? e?.message ?? "Não foi possível gravar no PingWin.");
        }
    };

    return (
        <Card className="mb-3 border-warning" data-testid="guide-global-unit">
            <CardBody className="p-3 p-md-4">
                <div className="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <h6 className="mb-0 fs-14 text-uppercase fw-semibold text-muted">Unidade de todos os artigos</h6>
                    <Badge color="warning" className="bg-warning-subtle text-warning">«{view.unit.name}» conta {view.unit.now} em vez de {view.unit.says}</Badge>
                    <span className="ms-auto fs-13">Em jogo: <strong>{hidden ? <HiddenMoney seed={1} /> : eur(view.impact_cents)}</strong></span>
                </div>
                <p className="fs-13 mb-3">O PingWin não deixa mudar a quantidade de uma unidade e esta é de todos os artigos: não se anula nem se altera.
                    Corrige-se com a unidade certa, uma vez, e passando os artigos um a um para ela. «{view.unit.name}» deixa de se usar.</p>
                <ol className="fs-14 mb-0 ps-3">
                    <li className="mb-2" data-testid="guide-global-right">
                        <span>A unidade certa: <strong>{view.right.name}</strong> (1 = {view.unit.says}). </span>
                        {view.right.reuse ? <Badge color="success" className="bg-success-subtle text-success">Já existe no PingWin: reutiliza-se</Badge>
                            : rightReady ? <Badge color="success" className="bg-success-subtle text-success">Criada e confirmada</Badge> : (
                                <ReasonButton type="button" size="sm" color="primary" className="ms-1"
                                    reason={editReason ?? (view.guard ? view.guard.reason : null)} onClick={() => setAsking(true)}
                                    disabled={!canEdit || (write !== null && !FINAL.includes(write.status))} data-testid="guide-global-create">
                                    {write && !FINAL.includes(write.status) ? <Spinner size="sm" className="me-1" /> : null}Criar no PingWin
                                </ReasonButton>
                            )}
                        {view.right.error && <div className="text-danger fs-12">{view.right.error}</div>}
                    </li>
                    <li>
                        Passar os artigos para «{view.right.name}» como unidade de compra, um a um: <strong data-testid="guide-global-progress">{done} de {n}</strong> feitos.
                        <Progress value={n ? (done / n) * 100 : 0} className="mt-1" style={{ height: 6 }} />
                        {!view.right.reuse && !rightReady && <div className="text-muted fs-12 mt-1">Primeiro crie a unidade certa (passo 1).</div>}
                    </li>
                </ol>
            </CardBody>
            <Modal isOpen={asking} toggle={() => setAsking(false)} centered>
                <ModalHeader toggle={() => setAsking(false)}>Criar a unidade no PingWin</ModalHeader>
                <ModalBody>
                    <p className="mb-1">Vai criar a unidade <strong>{view.right.name}</strong> (1 = {view.unit.says}), de todos os artigos.</p>
                    <p className="text-muted fs-13 mb-0">Uma alteração só. Antes de gravar, a XPLENDOR procura uma unidade igual no PingWin e reutiliza-a se existir; no fim, relê.</p>
                </ModalBody>
                <ModalFooter>
                    <Button color="light" onClick={() => setAsking(false)}>Cancelar</Button>
                    <Button color="primary" onClick={create} data-testid="guide-global-create-confirm">Criar no PingWin</Button>
                </ModalFooter>
            </Modal>
        </Card>
    );
}
