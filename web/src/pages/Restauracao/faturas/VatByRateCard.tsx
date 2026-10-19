import PageCard from "Components/Common/PageCard";
import { OcrInvoiceDiscount, OcrInvoiceLine, OcrVatByRate } from "common/models/ocr.model";

/**
 * XPLENDOR — Faturas: "IVA por taxa" (o lido e o calculado das linhas, lado a lado, com a diferença
 * e a linha que a explica) e os descontos de promoção (cada um no seu artigo; sem par, "Desconto por
 * atribuir", que não deixa lançar). Calculado das linhas gravadas: depois de mudar linhas, "Revalidar".
 */

type Props = {
    vat: OcrVatByRate | null | undefined;
    discounts: OcrInvoiceDiscount[];
    lines: OcrInvoiceLine[];
    ignored: string[];
};

const eur = (n?: number | null) => (n === null || n === undefined ? "—" : n.toLocaleString("pt-PT", { style: "currency", currency: "EUR" }));
const signed = (n: number | null) => (n === null ? "—" : `${n > 0 ? "+" : ""}${eur(n)}`);
const rateLabel = (r: number) => (r === 0 ? "Isento" : `${r} %`);
const SOURCE: Record<OcrVatByRate["source"], string> = { qr: "QR da AT", fatura: "quadro do IVA da fatura", sumario: "sumário" };

export default function VatByRateCard({ vat, discounts, lines, ignored }: Props) {
    if (!vat || (vat.rows.length === 0 && discounts.length === 0)) return null;
    const lineLabel = (id: number) => {
        const i = lines.findIndex((l) => l.id === id);
        return i < 0 ? `linha #${id}` : `linha ${i + 1} · ${lines[i].item ?? "—"}`;
    };
    const unassigned = discounts.filter((d) => d.status === "por_atribuir");

    return (
        <PageCard
            title="IVA por taxa"
            info="O lido na fatura e o calculado das linhas (já com os descontos de promoção), por taxa. Calculado das linhas gravadas: depois de mudar linhas, carregue em Revalidar."
            status={vat.ok && unassigned.length === 0
                ? <span className="badge bg-success-subtle text-success" data-testid="vat-ok">Bate com a fatura</span>
                : <span className="badge bg-warning-subtle text-warning" data-testid="vat-diff">Há diferenças</span>}
            flush={false}
            data-testid="vat-by-rate"
        >
            <div className="table-responsive">
                <table className="table table-sm align-middle mb-2 fs-13">
                    <caption className="visually-hidden">IVA por taxa: lido e calculado</caption>
                    <thead className="table-light text-muted">
                        <tr>
                            <th>Taxa</th><th>Código</th><th className="text-end">Base lida</th><th className="text-end">Base calculada</th>
                            <th className="text-end">IVA lido</th><th className="text-end">IVA calculado</th><th className="text-end">Diferença</th>
                        </tr>
                    </thead>
                    <tbody>
                        {vat.rows.map((r) => (
                            <tr key={r.rate} className={r.ok ? undefined : "table-warning fw-semibold"} data-testid={`vat-row-${r.rate}`}>
                                <td>{rateLabel(r.rate)}</td>
                                <td className="font-monospace">{r.codes.join(", ") || "—"}</td>
                                <td className="text-end">{eur(r.read_base)}</td>
                                <td className="text-end">{eur(r.calc_base)}</td>
                                <td className="text-end">{eur(r.read_vat)}</td>
                                <td className="text-end">{eur(r.calc_vat)}</td>
                                <td className="text-end">
                                    {r.ok ? <i className="ri-check-line text-success" aria-label="Igual" /> : <>
                                        <div>Base {signed(r.diff_base)} · IVA {signed(r.diff_vat)}</div>
                                        {r.explained_by.map((id) => <div key={id} className="fw-normal text-body">Explica: {lineLabel(id)}</div>)}
                                        {r.explained_by_discounts.map((c) => <div key={c} className="fw-normal text-body">Explica: desconto por atribuir {c}</div>)}
                                    </>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="fs-12 text-muted mb-2">Lido: {SOURCE[vat.source]}.{vat.lines_without_rate.length > 0 && <> {vat.lines_without_rate.length} linhas sem taxa de IVA.</>}</div>

            {discounts.length > 0 && (
                <>
                    <div className="fs-12 text-muted text-uppercase fw-semibold mb-1">Descontos de promoção</div>
                    <ul className="list-unstyled mb-2 fs-13" data-testid="discounts">
                        {discounts.map((d) => (
                            <li key={d.id} className="mb-1">
                                <span className="fw-medium">{d.label ?? "Desconto"} {d.code}: −{eur(d.amount)}</span>
                                {d.status === "por_atribuir"
                                    ? <span className="badge bg-danger-subtle text-danger ms-2" data-testid="discount-unassigned">Desconto por atribuir</span>
                                    : <span className="text-muted"> · {d.allocations.map((a) => `${a.line_id ? lineLabel(a.line_id) : "—"} (−${eur(a.amount)})`).join("; ")}</span>}
                                {d.warning && <div className="text-warning"><i className="ri-error-warning-line me-1" />{d.warning}</div>}
                            </li>
                        ))}
                    </ul>
                    {unassigned.length > 0 && (
                        <div className="fs-13 text-danger mb-2">Um desconto por atribuir não deixa lançar: escreva o código da promoção na linha do artigo e carregue em Revalidar.</div>
                    )}
                </>
            )}
            {ignored.length > 0 && (
                <div className="fs-12 text-muted" data-testid="ignored-lines">Linhas informativas ignoradas (já incluídas no preço): {ignored.join(" · ")}</div>
            )}
        </PageCard>
    );
}
