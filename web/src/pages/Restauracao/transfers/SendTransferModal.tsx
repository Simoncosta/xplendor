import { useCallback, useEffect, useRef, useState } from "react";
import { Alert, Badge, Button, Input, Label, ListGroup, ListGroupItem, Modal, ModalBody, ModalFooter, ModalHeader, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import XSelect from "Components/Common/Select";
import { TransferLineInput, getRecipeConversionInfo, getTransferProducts, prepareStockTransfer, sendStockTransfer } from "helpers/laravel_helper";
import { PrepLine, TransferPrepare, TransferRequest, TransferRoute } from "common/models/transfer.model";
import ConfirmFactorModal, { FactorLine } from "../fichas/ConfirmFactorModal";
import { StockMoney, fmtStockQty } from "../stock/stockFormat";
import { qtyLabel, sendDisabledReason, unitWord } from "./transferFormat";

/**
 * XPLENDOR — Fatia 7c: preparar e enviar UMA transferência (a partir de uma encomenda ou sem encomenda).
 * Cada alteração pede ao servidor as contas e os bloqueios (só leitura). Bloqueia a linha sem fator
 * confirmado ou com o fator diferente do PingWin (abre o ConfirmFactorModal); pede a confirmação
 * explícita do preço (PCM 0 ou afastado do último preço de compra); avisa do stock insuficiente.
 */
type Draft = TransferLineInput & { code?: string | null; description?: string | null };

export default function SendTransferModal({ open, onClose, onSent, companyId, routes, request, canSend, hidden }: {
    open: boolean; onClose: () => void; onSent: (id: number) => void; companyId: number | null; routes: TransferRoute[];
    request: TransferRequest | null; canSend: boolean; hidden: boolean;
}) {
    const [routeId, setRouteId] = useState<number | null>(null);
    const [lines, setLines] = useState<Draft[]>([]);
    const [prep, setPrep] = useState<TransferPrepare | null>(null);
    const [loading, setLoading] = useState(false);
    const [sending, setSending] = useState(false);
    const [search, setSearch] = useState("");
    const [found, setFound] = useState<{ product_id: string; code: string | null; description: string }[]>([]);
    const [factor, setFactor] = useState<{ line: FactorLine; index: number } | null>(null);
    const seq = useRef(0);

    useEffect(() => {
        if (!open) return;
        setRouteId(request?.route_id ?? routes.find((r) => r.active)?.id ?? null);
        setLines(request ? request.lines.filter((l) => l.product_id).map((l) => ({
            product_id: l.product_id as string, qty: l.qnt, unit: l.unit_desc ?? undefined, request_line_id: l.id, code: l.code, description: l.description,
        })) : []);
        setPrep(null);
        setSearch("");
    }, [open, request, routes]);

    const refresh = useCallback(async () => {
        if (!companyId || !routeId || !lines.length) { setPrep(lines.length ? null : { lines: [], total_value_eur: 0, blocked: false, needs_confirmation: false, warnings: 0, send_block: null }); return; }
        const mine = ++seq.current;
        setLoading(true);
        try {
            const res: any = await prepareStockTransfer(companyId, { route_id: routeId, request_id: request?.id ?? null, lines: lines.map(({ code, description, ...l }) => l) });
            if (mine === seq.current) setPrep(res?.data?.prepare ?? null);
        } catch (e: any) {
            if (mine === seq.current) toast.error(e?.message ?? "Não foi possível preparar o envio.");
        } finally {
            if (mine === seq.current) setLoading(false);
        }
    }, [companyId, routeId, lines, request]);
    useEffect(() => {
        if (!open) return;
        const t = setTimeout(refresh, 350);
        return () => clearTimeout(t);
    }, [open, refresh]);

    useEffect(() => {
        if (!companyId || request || search.trim().length < 2) { setFound([]); return; }
        const t = setTimeout(() => getTransferProducts(companyId, search.trim()).then((r: any) => setFound(r?.data?.products ?? [])).catch(() => null), 300);
        return () => clearTimeout(t);
    }, [companyId, search, request]);

    const patch = (i: number, p: Partial<Draft>) => setLines((ls) => ls.map((l, k) => (k === i ? { ...l, ...p } : l)));
    const openFactor = async (l: PrepLine) => {
        if (!companyId) return;
        try {
            const res: any = await getRecipeConversionInfo(companyId, l.product_id);
            if (res?.data?.line) setFactor({ line: res.data.line, index: l.index });
            else toast.info("Este artigo não tem compras noutra embalagem: envie na unidade de stock.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível abrir a confirmação da embalagem.");
        }
    };
    const send = async () => {
        if (!companyId || !routeId) return;
        setSending(true);
        try {
            const res: any = await sendStockTransfer(companyId, { route_id: routeId, request_id: request?.id ?? null, lines: lines.map(({ code, description, ...l }) => l) });
            toast.info("A enviar para o PingWin. Acompanhe no separador Envios.");
            onSent(res?.data?.transfer?.id);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível enviar.");
        } finally {
            setSending(false);
        }
    };

    const route = routes.find((r) => r.id === routeId) ?? null;
    const reason = loading ? "A preparar o envio." : sendDisabledReason(prep, canSend);

    return (
        <Modal isOpen={open} toggle={onClose} size="xl" scrollable fullscreen="md">
            <ModalHeader toggle={onClose}>{request ? `Enviar ${request.document ?? "a encomenda"}` : "Novo envio sem encomenda"}</ModalHeader>
            <ModalBody>
                <div className="d-flex flex-wrap gap-3 align-items-end mb-3">
                    <div style={{ minWidth: 260 }}>
                        <Label className="form-label mb-1">Circuito</Label>
                        <XSelect<number> value={routeId} disabled={!!request?.route_id} ariaLabel="Circuito"
                            options={routes.filter((r) => r.active).map((r) => ({ value: r.id, label: r.name }))} onChange={(v) => setRouteId(v)} />
                    </div>
                    {route && <div className="small text-muted">Sai do <strong>{route.origin_name}</strong> e entra no <strong>{route.dest_name}</strong> · {route.auto_receive ? "receção automática" : "o restaurante confirma a receção"}</div>}
                </div>

                {!request && (
                    <div className="mb-3 position-relative">
                        <Label className="form-label mb-1" for="tr-search">Acrescentar artigo</Label>
                        <Input id="tr-search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Nome ou código (pelo menos 2 letras)" autoComplete="off" />
                        {found.length > 0 && (
                            <ListGroup className="position-absolute w-100 shadow" style={{ zIndex: 5 }}>
                                {found.map((p) => (
                                    <ListGroupItem key={p.product_id} action tag="button" type="button" onClick={() => {
                                        setLines((ls) => [...ls, { product_id: p.product_id, qty: 1, code: p.code, description: p.description }]);
                                        setSearch(""); setFound([]);
                                    }}>{p.code} · {p.description}</ListGroupItem>
                                ))}
                            </ListGroup>
                        )}
                    </div>
                )}

                {lines.length === 0 && <Alert color="light" className="mb-0">Sem artigos. {request ? "Esta encomenda não tem linhas lidas." : "Procure um artigo para começar."}</Alert>}
                <ul className="list-unstyled mb-0" data-testid="transfer-lines">
                    {lines.map((d, i) => {
                        const p = prep?.lines.find((x) => x.index === i) ?? null;
                        const units = p?.units ?? [];
                        return (
                            <li key={`${d.product_id}-${i}`} className={`border rounded p-2 mb-2 ${p?.blocks.length ? "border-danger" : ""}`} data-testid="transfer-line">
                                <div className="d-flex flex-wrap justify-content-between gap-2">
                                    <div className="fw-semibold">{p?.code ?? d.code} · {p?.description ?? d.description}</div>
                                    {!request && <Button size="sm" color="link" className="text-danger p-0" onClick={() => setLines((ls) => ls.filter((_, k) => k !== i))}>Tirar</Button>}
                                </div>
                                <div className="d-flex flex-wrap align-items-center gap-2 mt-1">
                                    <Input type="text" inputMode="decimal" bsSize="sm" style={{ width: 90 }} aria-label={`Quantidade de ${p?.description ?? d.description}`}
                                        value={String(d.qty)} onChange={(e) => patch(i, { qty: e.target.value.replace(",", ".") })} />
                                    {units.length > 1 ? (
                                        <Input type="select" bsSize="sm" style={{ width: 170 }} value={d.unit ?? p?.stock_unit ?? ""} aria-label="Unidade" onChange={(e) => patch(i, { unit: e.target.value, factor_ack: false })}>
                                            {[...units.map((u) => u.unit), ...(d.unit && !units.some((u) => u.unit === d.unit) ? [d.unit] : [])].map((u) => <option key={u} value={u}>{u}</option>)}
                                        </Input>
                                    ) : <span className="small">{d.unit ?? p?.stock_unit ?? ""}</span>}
                                    {p && <span className="small text-muted">{p.factor !== null ? `= ${fmtStockQty(p.qty_base ?? 0)} ${unitWord(p.stock_unit, p.qty_base ?? 0)}` : ""}</span>}
                                    {p && (
                                        <span className="small ms-auto text-end">
                                            PCM <StockMoney value={p.origin_pcm_eur} hidden={hidden} unit /> · <StockMoney value={p.value_eur} hidden={hidden} />
                                            {p.origin_stock !== null && <span className="text-muted"> · Economato: {fmtStockQty(p.origin_stock)}</span>}
                                        </span>
                                    )}
                                </div>
                                {p?.blocks.map((b) => (
                                    <div key={b.code} className="small text-danger mt-1" data-testid="line-block">
                                        <i className="ri-lock-line me-1" />{b.text}
                                        {(b.code === "fator_por_confirmar" || b.code === "fator_diferente") && (
                                            <Button size="sm" color="link" className="p-0 ms-2" onClick={() => openFactor(p)}>Confirmar o fator</Button>
                                        )}
                                    </div>
                                ))}
                                {p?.confirm && (
                                    <div className="form-check small mt-1">
                                        <Input type="checkbox" className="form-check-input" id={`pc-${i}`} checked={!!d.price_confirmed} onChange={(e) => patch(i, { price_confirmed: e.target.checked })} />
                                        <Label className="form-check-label text-warning-emphasis" for={`pc-${i}`}>{p.confirm.text} Confirmo este preço.</Label>
                                    </div>
                                )}
                                {p?.warning && <div className="small text-warning-emphasis mt-1"><i className="ri-error-warning-line me-1" />{p.warning.text}</div>}
                                {p && p.factor !== null && d.unit && d.unit !== p.stock_unit && (
                                    <div className="small text-muted mt-1">{qtyLabel(Number(d.qty) || 0, d.unit, p.factor, p.stock_unit)}: a Guia vai em {unitWord(p.stock_unit, 2)}.</div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            </ModalBody>
            <ModalFooter className="justify-content-between">
                <div className="small">
                    {prep && <>Total ao PCM: <strong><StockMoney value={prep.total_value_eur} hidden={hidden} /></strong>{prep.warnings > 0 && <Badge color="warning" className="ms-2">{prep.warnings} com stock insuficiente</Badge>}</>}
                    {reason && <div className="text-muted" data-testid="send-reason">{reason}</div>}
                </div>
                <div className="d-flex gap-2">
                    <Button color="light" onClick={onClose}>Cancelar</Button>
                    <Button color="primary" onClick={send} disabled={!!reason || sending} data-testid="transfer-send">
                        {sending ? <Spinner size="sm" /> : <><i className="ri-truck-line me-1" />Enviar para o PingWin</>}
                    </Button>
                </div>
            </ModalFooter>
            {factor && (
                <ConfirmFactorModal open line={factor.line} companyId={companyId} hidden={hidden} onClose={() => setFactor(null)}
                    onDone={() => { patch(factor.index, { factor_ack: true }); setFactor(null); }} />
            )}
        </Modal>
    );
}
