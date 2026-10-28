import { Link } from "react-router-dom";
import { Alert, Button, Spinner } from "reactstrap";
import { OcrGeneralExpense } from "common/models/ocr.model";

/**
 * A fatura de um fornecedor de despesas gerais: "Despesa geral: N linhas, 1 por taxa", o aviso da taxa
 * sem artigo e o "Aplicar a esta fatura" (para as já lidas e ainda por lançar). O root tem o atalho
 * para a definição do fornecedor, marcado ou não.
 */
const rateLabel = (r: number | null) => (r === null ? "sem taxa" : r === 0 ? "isenta" : `${r} %`);
const eur = (cents: number) => (cents / 100).toLocaleString("pt-PT", { style: "currency", currency: "EUR" });

export default function GeneralExpenseCard({ expense, supplierId, isRoot, canApply, applying, onApply }: {
    expense: OcrGeneralExpense | null | undefined;
    supplierId: number | null | undefined;
    isRoot: boolean;
    canApply: boolean;
    applying: boolean;
    onApply: () => void;
}) {
    const shortcut = isRoot && supplierId
        ? <Link to={`/restauracao/fornecedores?despesas=${supplierId}`} className="fs-12" data-testid="general-expense-shortcut">
            {expense ? "Mudar a definição do fornecedor" : "Marcar o fornecedor como despesas gerais"}
        </Link>
        : null;

    if (!expense) {
        return shortcut ? <div className="mb-2">{shortcut}</div> : null;
    }

    const rates = expense.rates.length;
    return (
        <Alert color={expense.missing_rates.length ? "warning" : "info"} className="py-2 fs-13 mb-3" data-testid="general-expense">
            <div className="d-flex flex-wrap align-items-center gap-2">
                <span className="fw-semibold" data-testid="general-expense-title">
                    Despesa geral: {expense.lines} {expense.lines === 1 ? "linha" : "linhas"}, 1 por taxa
                </span>
                <span className="text-muted">
                    ({expense.rates.map((r) => `${rateLabel(r.rate)}: ${r.lines} → ${eur(r.total_cents)}`).join("; ")}
                    {rates === 0 ? "sem linhas com valor" : ""})
                </span>
                {expense.ignored_zero > 0 && (
                    <span className="text-muted">{expense.ignored_zero} a 0,00 € não {expense.ignored_zero === 1 ? "conta" : "contam"}.</span>
                )}
                <span className="ms-auto d-flex align-items-center gap-2">
                    {shortcut}
                    {canApply && (
                        <Button size="sm" color="outline-primary" onClick={onApply} disabled={applying} data-testid="general-expense-apply">
                            {applying ? <Spinner size="sm" /> : "Aplicar a esta fatura"}
                        </Button>
                    )}
                </span>
            </div>
            {expense.missing_rates.length > 0 && (
                <div className="mt-1" data-testid="general-expense-missing">
                    Falta o artigo de despesa da taxa {expense.missing_rates.map(rateLabel).join(", ")}: essas linhas ficam por ligar.
                    {isRoot ? " Escolha-o na definição do fornecedor." : " Peça à equipa XPLENDOR para o escolher."}
                </div>
            )}
        </Alert>
    );
}
