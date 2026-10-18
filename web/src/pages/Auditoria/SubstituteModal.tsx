import { useEffect, useState } from "react";
import { Badge, Button, Modal, ModalBody, ModalFooter, ModalHeader, Spinner, Table } from "reactstrap";
import { toast } from "react-toastify";
import HiddenMoney from "Components/Common/HiddenMoney";
import { getRecipeSubstitute } from "helpers/laravel_helper";
import { RecipeSubstitute } from "common/models/unitGuide.model";
import RecipeEditorModal, { RecipePrefill } from "pages/Restauracao/fichas/RecipeEditor";

/**
 * XPLENDOR — Auditoria v2 (M2): o substituto de um ingrediente sem compras numa ficha, com o custo
 * por porção e por mês antes e depois. Escolher abre o editor da ficha (Fatia 4) já com a troca:
 * revê-se, grava-se no PingWin com confirmação e releitura, e o achado fica marcado como corrigido.
 */
const eur = (v: number | null, d = 2) => (v === null ? "Sem preço" : v.toLocaleString("pt-PT", { minimumFractionDigits: d, maximumFractionDigits: d }) + " €");

export default function SubstituteModal({ companyId, productId, componentId, onClose, onSaved }: {
    companyId: number; productId: string; componentId: string; onClose: () => void; onSaved: () => void;
}) {
    const [data, setData] = useState<RecipeSubstitute | null>(null);
    const [editor, setEditor] = useState<RecipePrefill | null>(null);

    useEffect(() => {
        getRecipeSubstitute(companyId, productId, componentId)
            .then((res: any) => setData(res?.data ?? null))
            .catch((e: any) => { toast.error(e?.message ?? "Não foi possível procurar o substituto."); onClose(); });
    }, [companyId, productId, componentId, onClose]);

    const hidden = data?.financial?.visible === false;
    const money = (v: number | null, seed: number, d = 2) => (hidden ? <HiddenMoney seed={seed} /> : eur(v, d));

    if (editor && data?.dish) {
        return (
            <RecipeEditorModal companyId={companyId} productId={productId} bomId={data.dish.bom_id} hidden={hidden} prefill={editor}
                onSaved={onSaved} onClose={onClose} />
        );
    }

    return (
        <Modal isOpen toggle={onClose} centered size="lg" data-testid="substitute-modal">
            <ModalHeader toggle={onClose}>Substituto do ingrediente</ModalHeader>
            <ModalBody>
                {!data ? <div className="text-center py-4"><Spinner size="sm" /></div> : !data.found ? (
                    <p className="mb-0">{data.reason}</p>
                ) : (
                    <>
                        <p className="fs-13">A ficha de <strong>{data.dish?.label}</strong> usa <strong>{data.component?.label}</strong>
                            {data.component?.last_purchase ? `, comprado pela última vez a ${new Date(data.component.last_purchase).toLocaleDateString("pt-PT")}` : ", sem compras"}.
                            Estes artigos compram-se e servem de substituto:</p>
                        <Table size="sm" responsive className="fs-13 mb-0">
                            <thead><tr><th>Artigo</th><th>Porquê</th><th className="text-end">Por porção</th><th className="text-end">Por mês</th><th /></tr></thead>
                            <tbody>
                                {data.candidates.map((c, k) => (
                                    <tr key={c.product_id}>
                                        <td>{c.code ? `${c.code} · ` : ""}{c.description}{!c.same_unit_family && <Badge color="warning" className="ms-1 bg-warning-subtle text-warning">outra unidade</Badge>}</td>
                                        <td className="text-muted">{c.why}{c.note ? ` ${c.note}` : ""}</td>
                                        <td className="text-end">{money(c.cost_before_eur, k, 4)} → {money(c.cost_after_eur, k + 1, 4)}</td>
                                        <td className="text-end">{money(c.monthly_before_eur, k + 2)} → {money(c.monthly_after_eur, k + 3)}</td>
                                        <td className="text-end">
                                            <Button size="sm" color="primary" data-testid={`substitute-pick-${c.product_id}`} onClick={() => setEditor({
                                                note: `Troca proposta pela auditoria: ${data.component?.label} → ${c.description}. Reveja a quantidade e grave.`,
                                                lines: [{ key: c.line.replaces_key, replace_component_id: componentId, component_id: c.line.component_id, description: c.line.description,
                                                    unit_id: c.line.unit_id, qnt: c.line.qnt, units: c.line.units }],
                                            })}>Usar este</Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </>
                )}
            </ModalBody>
            <ModalFooter><Button color="light" onClick={onClose}>Fechar</Button></ModalFooter>
        </Modal>
    );
}
