import { useEffect, useState } from "react";
import { Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { deleteRecipeAll, deleteRecipeVersion, getRecipeDeleteEffects } from "helpers/laravel_helper";
import { RecipeStoreEffect, RecipeWriteInfo } from "common/models/recipe.model";
import { fmtDate } from "./recipeFormat";

/**
 * Fatia 4 — apagar uma versão da ficha (uma confirmação que diz o que muda em cada loja) ou a ficha
 * inteira (todas as versões, com dupla confirmação). Escreve na PingWin, em fila, uma ficha de cada vez.
 */
export default function DeleteRecipeModal({ companyId, productId, productName, signature, target, onClose, onQueued }: {
    companyId: number; productId: string; productName: string; signature: string;
    target: { bom_id: string; valid_from: string | null } | "all";
    onClose: () => void; onQueued: (w: RecipeWriteInfo) => void;
}) {
    const all = target === "all";
    const [effects, setEffects] = useState<RecipeStoreEffect[] | null>(null);
    const [step, setStep] = useState<1 | 2>(1);
    const [understood, setUnderstood] = useState(false);
    const [sending, setSending] = useState(false);
    const ids = all ? [] : [target.bom_id];

    useEffect(() => {
        let alive = true;
        getRecipeDeleteEffects(companyId, productId, all ? ["*"] : ids)
            .then((res: any) => { if (alive) setEffects(res?.data?.store_effects ?? []); })
            .catch(() => { if (alive) setEffects([]); });
        return () => { alive = false; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [companyId, productId, all, all ? "" : target.bom_id]);

    const send = async () => {
        setSending(true);
        try {
            const res: any = all
                ? await deleteRecipeAll(companyId, productId, { base_signature: signature, confirm: true, confirm_all: true })
                : await deleteRecipeVersion(companyId, productId, { bom_id: target.bom_id, base_signature: signature, confirm: true });
            onQueued(res?.data?.write);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível apagar.");
        } finally {
            setSending(false);
        }
    };

    return (
        <Modal isOpen toggle={() => !sending && onClose()} centered data-testid="delete-recipe">
            <ModalHeader toggle={() => !sending && onClose()}>
                {all ? `Apagar a ficha técnica de ${productName}` : `Apagar a versão de ${fmtDate(target.valid_from)}`}
            </ModalHeader>
            <ModalBody>
                {step === 1 ? (
                    <>
                        <p className="mb-2">
                            {all ? <>Vai apagar <strong>todas as versões</strong> da ficha na PingWin. O artigo fica sem ficha técnica.</>
                                : <>Vai apagar esta versão na PingWin. O que muda em cada loja:</>}
                        </p>
                        {effects === null ? <div className="text-muted fs-13"><Spinner size="sm" className="me-1" />A calcular…</div> : (
                            <ul className="fs-13 mb-2">
                                {effects.map((e) => (
                                    <li key={e.store_id} className={e.loses_bom ? "text-danger" : undefined}>
                                        {e.name}: {e.loses_bom ? "fica sem ficha em vigor"
                                            : e.changes ? `passa da versão de ${fmtDate(e.before)} para a de ${fmtDate(e.after)}`
                                                : e.after ? `continua com a versão de ${fmtDate(e.after)}` : "já não tinha ficha em vigor"}
                                    </li>
                                ))}
                            </ul>
                        )}
                        {all && (
                            <div className="form-check mt-2">
                                <Input type="checkbox" className="form-check-input" id="delete-all-understood" checked={understood} onChange={(e) => setUnderstood(e.target.checked)} />
                                <Label className="form-check-label" for="delete-all-understood">Percebo que todas as versões vão ser apagadas na PingWin.</Label>
                            </div>
                        )}
                    </>
                ) : (
                    <p className="mb-0"><strong>Confirme outra vez:</strong> apagar a ficha técnica inteira de <strong>{productName}</strong> na PingWin. Não há forma de a recuperar a partir da XPLENDOR (o histórico guarda o que tinha).</p>
                )}
            </ModalBody>
            <ModalFooter>
                <Button type="button" color="light" onClick={onClose} disabled={sending}>Cancelar</Button>
                {all && step === 1 ? (
                    <Button type="button" color="danger" disabled={!understood || effects === null} onClick={() => setStep(2)}>Continuar</Button>
                ) : (
                    <Button type="button" color="danger" onClick={send} disabled={sending || effects === null}>
                        {sending ? <><Spinner size="sm" className="me-1" />A pôr na fila…</> : all ? "Apagar a ficha inteira" : "Apagar a versão"}
                    </Button>
                )}
            </ModalFooter>
        </Modal>
    );
}
