import { useEffect, useState } from "react";
import { Alert, Button, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import { getSupplierGeneralExpense, setSupplierGeneralExpense } from "helpers/laravel_helper";
import { apiErrorMessage } from "helpers/apiError";
import { OcrGeneralExpenseArticle, SupplierGeneralExpense } from "common/models/ocr.model";
import ArticlePicker from "./ArticlePicker";

/**
 * Fornecedores › "Despesas gerais (sem stock)" (só o root). Definição da XPLENDOR por empresa e
 * fornecedor: um artigo por taxa de IVA, escolhido entre os que já existem (não cria artigos no
 * PingWin). Com a marca, ao ler ou reabrir uma fatura do fornecedor, as linhas com valor ligam-se ao
 * artigo da sua taxa e lançam-se numa linha por taxa; as linhas a 0,00 € não contam.
 */
const RATES = [23, 13, 6, 0];
type Chosen = Record<string, OcrGeneralExpenseArticle | null>;

export default function GeneralExpenseModal({ companyId, supplier, onClose }: {
    companyId: number; supplier: { id: number; name: string | null } | null; onClose: () => void;
}) {
    const [state, setState] = useState<SupplierGeneralExpense | null>(null);
    const [enabled, setEnabled] = useState(false);
    const [chosen, setChosen] = useState<Chosen>({});
    const [picking, setPicking] = useState<number | null>(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!supplier) return;
        let alive = true;
        setState(null); setError(null);
        getSupplierGeneralExpense(companyId, supplier.id)
            .then((r: any) => {
                if (!alive) return;
                const d: SupplierGeneralExpense = r?.data;
                setState(d);
                setEnabled(!!d?.enabled);
                setChosen(d?.articles ?? {});
            })
            .catch((e: any) => { if (alive) setError(apiErrorMessage(e, "Não foi possível carregar a definição do fornecedor.")); });
        return () => { alive = false; };
    }, [companyId, supplier]);

    const save = async () => {
        if (!supplier) return;
        setSaving(true); setError(null);
        try {
            const articles = Object.fromEntries(RATES.map((r) => [String(r), chosen[String(r)]?.id ?? null]));
            const r: any = await setSupplierGeneralExpense(companyId, supplier.id, enabled ? { enabled: true, articles } : { enabled: false });
            toast.success(r?.message ?? "Guardado.");
            onClose();
        } catch (e: any) {
            setError(apiErrorMessage(e, "Não foi possível guardar."));
        } finally {
            setSaving(false);
        }
    };

    const anyArticle = RATES.some((r) => chosen[String(r)]);

    return (
        <Modal isOpen={!!supplier} toggle={() => !saving && onClose()} centered data-testid="general-expense-modal">
            <ModalHeader toggle={() => !saving && onClose()}>Despesas gerais (sem stock)</ModalHeader>
            <ModalBody>
                <p className="fs-13 mb-2"><strong>{supplier?.name ?? "Fornecedor"}</strong></p>
                {!state && !error && <div className="text-center py-3"><Spinner size="sm" /></div>}
                {error && <Alert color="danger" className="fs-13 py-2">{error}</Alert>}
                {state && (
                    <>
                        <div className="form-check form-switch mb-2">
                            <Input id="general-expense-enabled" type="switch" role="switch" className="form-check-input" checked={enabled}
                                onChange={(e) => setEnabled(e.target.checked)} data-testid="general-expense-enabled" />
                            <Label for="general-expense-enabled" className="form-check-label fw-medium">Fornecedor de despesas gerais</Label>
                        </div>
                        <p className="fs-13 text-muted">
                            As linhas com valor das faturas deste fornecedor ligam-se ao artigo da sua taxa de IVA e vão para o PingWin numa linha por taxa,
                            com quantidade 1. As linhas a 0,00 € não contam. Escolha artigos que já existem: não se cria nada no PingWin.
                        </p>
                        {enabled && (
                            <table className="table table-sm align-middle mb-0 fs-13">
                                <tbody>
                                    {RATES.map((rate) => {
                                        const a = chosen[String(rate)];
                                        return (
                                            <tr key={rate} data-testid={`general-expense-rate-${rate}`}>
                                                <td className="fw-medium text-nowrap" style={{ width: 70 }}>{rate === 0 ? "Isento" : `${rate} %`}</td>
                                                <td>
                                                    {a ? <><span className="font-monospace me-1">{a.code}</span>{a.description ?? "—"}
                                                        {a.inactive && <span className="badge bg-danger-subtle text-danger ms-1">Inativo</span>}</>
                                                        : <span className="text-muted">Sem artigo: as linhas desta taxa ficam por ligar.</span>}
                                                </td>
                                                <td className="text-end text-nowrap">
                                                    <Button size="sm" color="outline-primary" onClick={() => setPicking(rate)}>{a ? "Mudar" : "Escolher"}</Button>
                                                    {a && <Button size="sm" color="link" className="text-danger" onClick={() => setChosen({ ...chosen, [String(rate)]: null })}
                                                        aria-label={`Tirar o artigo da taxa ${rate} %`}><i className="ri-close-line" /></Button>}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        )}
                    </>
                )}
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onClose} disabled={saving}>Cancelar</Button>
                <Button color="primary" onClick={save} disabled={saving || !state || (enabled && !anyArticle)} data-testid="general-expense-save">
                    {saving ? <Spinner size="sm" /> : "Guardar"}
                </Button>
            </ModalFooter>
            <ArticlePicker
                isOpen={picking !== null}
                companyId={companyId}
                supplierNif={null}
                supplierName={supplier?.name ?? null}
                initialQuery="despesa"
                lineLabel={picking === null ? "" : picking === 0 ? "Despesas gerais isentas" : `Despesas gerais a ${picking} %`}
                title="Artigo de despesa"
                onPick={(a) => { setChosen({ ...chosen, [String(picking)]: { id: a.id, code: a.code, description: a.description } }); setPicking(null); }}
                onClose={() => setPicking(null)}
            />
        </Modal>
    );
}
