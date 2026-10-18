import { Table } from "reactstrap";

/**
 * XPLENDOR — Auditoria v2-C (C2): simular o preço de venda do prato (+0,25, +0,50 e +1,00 € no preço
 * com IVA): a margem que volta por mês e o food cost novo. Só um cálculo: não muda nenhum preço.
 */
const eur = (v: number) => `${v.toLocaleString("pt-PT", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`;
const pct = (v: number) => `${v.toLocaleString("pt-PT", { maximumFractionDigits: 1 })}%`;

export function simulatePrice(a: { price: number; cost: number; cost_avg: number; monthly_qty: number; vat_pct: number }, step: number) {
    const net = step / (1 + a.vat_pct / 100);
    const price = a.price + net;
    return { step, price, monthly: net * a.monthly_qty, food_cost: (a.cost / price) * 100, covers: net >= a.cost - a.cost_avg };
}

export default function PriceSimulator({ action }: { action: { price: number; cost: number; cost_avg: number; monthly_qty: number; vat_pct: number; steps: number[] } }) {
    const now = (action.cost / action.price) * 100;
    return (
        <section className="mb-3" data-testid="audit-simulator">
            <h6 className="fs-13 text-uppercase text-muted mb-1">Simular o preço</h6>
            <p className="fs-13 mb-2">Hoje: {eur(action.price)} sem IVA, food cost de {pct(now)} aos últimos preços, cerca de {Math.round(action.monthly_qty).toLocaleString("pt-PT")} vendidos por mês.</p>
            <Table size="sm" className="align-middle mb-1 fs-13">
                <thead className="table-light"><tr><th>Subir o preço (com IVA)</th><th className="text-end">Margem a mais por mês</th><th className="text-end">Food cost</th><th>Cobre a subida do custo?</th></tr></thead>
                <tbody>
                    {action.steps.map((s) => {
                        const r = simulatePrice(action, s);
                        return (
                            <tr key={s} data-testid={`simulator-${s}`}>
                                <td>+{eur(s)}</td>
                                <td className="text-end">{eur(r.monthly)}</td>
                                <td className="text-end">{pct(r.food_cost)}</td>
                                <td>{r.covers ? <span className="text-success">Sim</span> : <span className="text-muted">Ainda não</span>}</td>
                            </tr>
                        );
                    })}
                </tbody>
            </Table>
            <div className="text-muted fs-12">Com as mesmas vendas por mês. Só uma simulação: o preço muda-se no PingWin.</div>
        </section>
    );
}
