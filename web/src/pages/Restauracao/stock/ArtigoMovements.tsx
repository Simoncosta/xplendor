import { useCallback, useEffect, useState } from "react";
import { Button, Input, Label, Spinner, Table } from "reactstrap";
import XSelect from "Components/Common/Select";
import { getStockMovements } from "helpers/laravel_helper";
import { Movement } from "common/models/stockcount.model";
import { fmtDate } from "pages/Auditoria/auditFormat";
import { fmtStockQty } from "./stockFormat";

/**
 * XPLENDOR — Fatia 7b: os Movimentos de um artigo (relatório do PingWin, a pedido, cache de 10 min).
 * Quantidade com sinal (qnt × stk_signal); as encomendas (sinal 0) ficam a cinzento: "Não mexe no
 * stock". A quantidade vem na unidade do documento.
 */
const iso = (d: Date) => d.toISOString().slice(0, 10);

export default function ArtigoMovements({ companyId, productId, warehouses }: { companyId: number | null; productId: string; warehouses: { id: string; name: string }[] }) {
    const today = new Date();
    const [from, setFrom] = useState(iso(new Date(today.getTime() - 90 * 86400000)));
    const [to, setTo] = useState(iso(today));
    const [wid, setWid] = useState("");
    const [rows, setRows] = useState<Movement[] | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // A leitura corre no worker: enquanto não está pronta, pergunta de 2 em 2 segundos (até 3 minutos).
    const load = useCallback(async (attempt = 0) => {
        if (!companyId) return;
        setLoading(true);
        setError(null);
        try {
            const res: any = await getStockMovements(companyId, productId, { from, to, warehouse_id: wid || undefined });
            if (res?.data?.pending) {
                if (attempt < 90) { setTimeout(() => load(attempt + 1), 2000); return; }
                setError("O PingWin está a demorar. Tente de novo dentro de momentos.");
            } else {
                setRows(res?.data?.movements ?? []);
            }
        } catch (e: any) {
            setError(e?.response?.data?.message ?? e?.message ?? "Não foi possível ler os movimentos.");
        }
        setLoading(false);
    }, [companyId, productId, from, to, wid]);
    useEffect(() => { load(); }, [load]);

    return (
        <div data-testid="artigo-movements">
            <div className="d-flex flex-wrap gap-2 align-items-end mb-3">
                <div><Label className="form-label fs-12 mb-1">De</Label><Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></div>
                <div><Label className="form-label fs-12 mb-1">Até</Label><Input type="date" value={to} onChange={(e) => setTo(e.target.value)} /></div>
                <div style={{ minWidth: 220 }}><Label className="form-label fs-12 mb-1">Armazém</Label>
                    <XSelect ariaLabel="Armazém" options={[{ value: "", label: "Todos os armazéns" }, ...warehouses.map((w) => ({ value: w.id, label: w.name }))]} value={wid} onChange={setWid} searchable={false} /></div>
                <Button size="sm" color="outline-primary" onClick={() => load()} disabled={loading}>{loading ? <Spinner size="sm" /> : <><i className="ri-refresh-line me-1" />Atualizar</>}</Button>
            </div>
            {error && <p className="text-danger">{error}</p>}
            {loading && !rows && <p className="text-muted">A ler os movimentos no PingWin…</p>}
            {rows && (
                <div className="table-responsive">
                    <Table size="sm" className="align-middle mb-0">
                        <thead className="table-light"><tr><th>Data</th><th>Documento</th><th>Tipo</th><th>Loja</th><th>Armazém</th><th className="text-end">Quantidade</th><th>Estado</th></tr></thead>
                        <tbody>
                            {rows.length === 0 && <tr><td colSpan={7} className="text-muted text-center py-3">Sem movimentos neste período.</td></tr>}
                            {rows.map((m, i) => (
                                <tr key={i} className={m.stk_signal === 0 ? "text-muted" : undefined}>
                                    <td className="text-nowrap">{fmtDate(m.doc_date)}</td>
                                    <td className="text-nowrap">{m.document}<div className="fs-12 text-muted">{m.entity_name}</div></td>
                                    <td>{m.doctype}</td><td>{m.store}</td><td>{m.warehouse}</td>
                                    <td className={`text-end text-nowrap ${m.signed_qty < 0 ? "text-danger" : ""}`}>
                                        {m.stk_signal === 0 ? <span title="As encomendas não mexem no stock">{fmtStockQty(m.qnt)} {m.unit_description} · Não mexe no stock</span>
                                            : <>{m.signed_qty > 0 ? "+" : ""}{fmtStockQty(m.signed_qty)} {m.unit_description}</>}
                                    </td>
                                    <td>{m.docstatus}</td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </div>
            )}
        </div>
    );
}
