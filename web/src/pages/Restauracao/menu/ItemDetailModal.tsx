import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Alert, Badge, Button, Input, Modal, ModalBody, ModalFooter, ModalHeader, Spinner, Table } from "reactstrap";
import { toast } from "react-toastify";
import { MenuParams, excludeMenuItem, getMenuEngineeringItem, includeMenuItem } from "helpers/laravel_helper";
import { MenuItem } from "common/models/menuEngineering.model";
import { CLASS_META, CONFIDENCE_META, MISSING_TEXT, costLabel, fmtEur, fmtPct, fmtQty } from "./menuFormat";

/**
 * XPLENDOR — Fatia 3: o detalhe de um artigo da engenharia de menu: a classe e a jogada, a ficha (as
 * versões usadas no período), o custo de cada ingrediente e de onde veio o preço (as compras, o
 * fornecedor, a unidade e se o fator foi confirmado). O gestor pode excluí-lo da matriz com um motivo.
 */
type Recipe = {
    versions: { bom_id: string; valid_from: string | null; qty: number; cost_eur: number | null; complete: boolean;
        lines: { component_id: string; code: string | null; description: string; qnt_base: number; unit: string | null; waste: number; cost_source: string; cost_status: string;
            unit_cost_eur: number | null; line_cost_eur: number | null; warnings: string[];
            purchases: { date: string; document: string | null; supplier: string | null; qnt: number; unit: string; total_eur: number | null; unit_price_eur: number | null; source: string; header_discount: boolean }[];
            semi: { product_id: string; yield: number | null; yield_confirmed: boolean; cost_per_unit_eur: number | null } | null }[] }[];
};

const STATUS: Record<string, string> = { ok: "com preço", antigo: "preço antigo", sem_custo: "sem compras", sem_conversao: "sem conversão", zero: "custo 0", parcial: "parcial", ciclo: "ciclo", fundo: "demasiados níveis" };

export default function ItemDetailModal({ companyId, item, params, hidden, canManage, onClose, onChanged }: {
    companyId: number; item: MenuItem; params: MenuParams; hidden: boolean; canManage: boolean; onClose: () => void; onChanged: () => void;
}) {
    const [detail, setDetail] = useState<{ item: MenuItem; recipe: Recipe } | null>(null);
    const [reason, setReason] = useState("");
    const [busy, setBusy] = useState(false);
    useEffect(() => {
        let alive = true;
        getMenuEngineeringItem(companyId, item.product_id, params).then((r: any) => alive && setDetail(r?.data ?? null))
            .catch((e: any) => toast.error(e?.message ?? "Não foi possível abrir o artigo."));
        return () => { alive = false; };
    }, [companyId, item.product_id, params]);
    const it = detail?.item ?? item;

    const toggle = async (exclude: boolean) => {
        setBusy(true);
        try {
            if (exclude) await excludeMenuItem(companyId, it.product_id, reason.trim());
            else await includeMenuItem(companyId, it.product_id);
            toast.info(exclude ? "Artigo excluído. A recalcular." : "Artigo incluído. A recalcular.");
            onChanged();
            onClose();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível guardar.");
        } finally {
            setBusy(false);
        }
    };

    return (
        <Modal isOpen toggle={onClose} size="xl" scrollable fullscreen="md" data-testid="menu-detail">
            <ModalHeader toggle={onClose}>
                {it.description} {it.class && <Badge color={CLASS_META[it.class].color} className="ms-1">{CLASS_META[it.class].label}</Badge>}{" "}
                <Badge color={CONFIDENCE_META[it.confidence].color}>{CONFIDENCE_META[it.confidence].label}</Badge>
            </ModalHeader>
            <ModalBody>
                <div className="d-flex flex-wrap gap-4 fs-13 mb-3">
                    <div><div className="text-muted">Vendidos</div><strong>{fmtQty(it.qty)}</strong> (quota {fmtPct(it.share_pct)})</div>
                    <div><div className="text-muted">Preço médio sem IVA</div><strong>{fmtEur(it.price_eur)}</strong>{it.vat_pct !== null && <span className="text-muted"> · IVA {fmtPct(it.vat_pct)}</span>}</div>
                    <div><div className="text-muted">Custo</div><strong>{costLabel(it)}</strong></div>
                    <div><div className="text-muted">Margem unitária</div><strong>{fmtEur(it.margin_eur)}</strong></div>
                    <div><div className="text-muted">Food cost</div><strong>{fmtPct(it.food_cost_pct)}</strong></div>
                </div>
                {it.play && <Alert color={it.class ? CLASS_META[it.class].color : "light"} className="py-2"><strong>Jogada:</strong> {it.play}</Alert>}
                {it.fragile && <Alert color="warning" className="py-2 fs-13"><strong>Classe frágil.</strong> {it.fragile_reasons.map((f) => f.text).join(" ")}</Alert>}
                {it.excluded_reason && <Alert color="light" className="py-2 fs-13">Fora da matriz: {it.excluded_reason}.</Alert>}
                {it.confidence !== "confirmado" && (
                    <Alert color="light" className="py-2 fs-13">
                        {it.confidence === "sem_custo"
                            ? <>Sem ficha técnica em vigor nas vendas do período. <Link to={`/restauracao/fichas-tecnicas/${it.product_id}?editar=1`}>Criar ficha</Link></>
                            : <>O custo é um mínimo: falta {it.missing.map((m) => `${m.description} (${MISSING_TEXT[m.reason] ?? m.reason})`).join("; ")}.</>}
                    </Alert>
                )}
                {it.changed && <p className="fs-13 text-info"><i className="ri-history-line me-1" />Ficha alterada no período: o custo é a média das versões, pesada pelas vendas de cada uma.</p>}
                {!detail ? <div className="text-center py-3"><Spinner size="sm" /></div> : detail.recipe.versions.map((v) => (
                    <div key={v.bom_id} className="mb-3">
                        <h6 className="mb-1">Versão de {v.valid_from ?? "—"} · {fmtQty(v.qty)} vendidos · custo {hidden ? "—" : fmtEur(v.cost_eur)}{!v.complete && " (incompleto)"}
                            <Link className="ms-2 fs-12" to={`/restauracao/fichas-tecnicas/${it.product_id}`}>Abrir a ficha</Link></h6>
                        <Table size="sm" responsive className="fs-13 mb-0">
                            <thead><tr><th>Ingrediente</th><th className="text-end">Quantidade</th><th className="text-end">Preço por unidade</th><th className="text-end">Custo</th><th>De onde veio o preço</th></tr></thead>
                            <tbody>
                                {v.lines.map((l) => (
                                    <tr key={l.component_id + l.description}>
                                        <td>{l.code} {l.description}{l.waste > 0 && <div className="fs-11 text-muted">desperdício {fmtPct(l.waste)}</div>}
                                            {l.warnings.map((w, i) => <div key={i} className="fs-11 text-warning-emphasis">{w}</div>)}</td>
                                        <td className="text-end text-nowrap">{fmtQty(l.qnt_base)} {l.unit}</td>
                                        <td className="text-end text-nowrap">{fmtEur(l.unit_cost_eur, 4)}</td>
                                        <td className="text-end text-nowrap">{fmtEur(l.line_cost_eur)}</td>
                                        <td className="fs-12">
                                            <Badge color={l.cost_status === "ok" || l.cost_status === "zero" ? "success" : "warning"} className="fs-10 me-1">{STATUS[l.cost_status] ?? l.cost_status}</Badge>
                                            {l.semi ? <>Semi-produto: custo da ficha ÷ rendimento {l.semi.yield_confirmed ? `confirmado (${fmtQty(l.semi.yield)})` : "por confirmar"}</>
                                                : l.purchases.length === 0 ? "Sem compras." : (
                                                    <ul className="mb-0 ps-3">
                                                        {l.purchases.slice(0, 3).map((p, i) => (
                                                            <li key={i}>{p.date} · {p.supplier ?? "—"} · {fmtQty(p.qnt)} {p.unit}{!hidden && <> · {fmtEur(p.total_eur)} → {fmtEur(p.unit_price_eur, 4)}/un.</>} · {p.source}{p.header_discount ? " · desconto no cabeçalho" : ""}</li>
                                                        ))}
                                                    </ul>
                                                )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </div>
                ))}
            </ModalBody>
            <ModalFooter className="justify-content-between">
                {canManage ? (
                    it.excluded === "manual" ? (
                        <Button color="outline-primary" disabled={busy} onClick={() => toggle(false)}>Voltar a incluir na matriz</Button>
                    ) : (
                        <div className="d-flex gap-2 align-items-center">
                            <Input bsSize="sm" style={{ width: 260 }} placeholder="Motivo para excluir da matriz" value={reason} onChange={(e) => setReason(e.target.value)} aria-label="Motivo da exclusão" />
                            <Button size="sm" color="outline-danger" disabled={busy || reason.trim().length < 3} onClick={() => toggle(true)}>Excluir da matriz</Button>
                        </div>
                    )
                ) : <span />}
                <Button color="light" onClick={onClose}>Fechar</Button>
            </ModalFooter>
        </Modal>
    );
}
