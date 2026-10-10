import { useEffect, useState } from "react";
import { Button, Col, Input, Label, Modal, ModalBody, ModalFooter, ModalHeader, Row, Spinner } from "reactstrap";
import XSelect from "Components/Common/Select";
import ReasonButton from "Components/Common/ReasonButton";
import { getOcrArticleForm } from "helpers/laravel_helper";
import { OcrArticleFormOptions, OcrInvoiceLine } from "common/models/ocr.model";

/**
 * XPLENDOR — F2b: "Criar artigo" a partir de uma linha da fatura. Pré-preenche a descrição, a
 * unidade, o IVA (→ grupo de IVA do PingWin) e o preço de compra; a família escolhe-se. Usa o
 * fluxo de criação de artigos que já existe (assíncrono, com confirmação por releitura); quando o
 * artigo existir, a linha liga-se e o código do fornecedor é gravado no artigo.
 */

export type CreateArticleData = { description: string; family_id: string; taxgroup_id: string; unit_id: string; purchase_price: number | null };

type Props = {
    isOpen: boolean;
    companyId: number;
    line: OcrInvoiceLine | null;
    supplierName: string | null;
    busy: boolean;
    onSubmit: (d: CreateArticleData) => void;
    onClose: () => void;
};

const unitFor = (u: string | null | undefined, units: OcrArticleFormOptions["units"]) => {
    const k = (u ?? "").trim().toUpperCase();
    const code = ["KG", "KGS", "KILO", "QUILOGRAMA"].includes(k) ? "KG" : ["L", "LT", "LTS", "LITRO"].includes(k) ? "LT" : "UN";
    return units.find((x) => x.code === code)?.value ?? units[0]?.value ?? "";
};

export default function CreateArticleModal({ isOpen, companyId, line, supplierName, busy, onSubmit, onClose }: Props) {
    const [opts, setOpts] = useState<OcrArticleFormOptions | null>(null);
    const [description, setDescription] = useState("");
    const [familyId, setFamilyId] = useState("");
    const [taxgroupId, setTaxgroupId] = useState("");
    const [unitId, setUnitId] = useState("");
    const [price, setPrice] = useState("");

    useEffect(() => {
        if (!isOpen || opts || !companyId) return;
        getOcrArticleForm(companyId).then((r: any) => setOpts(r?.data ?? null)).catch(() => setOpts({ families: [], taxgroups: [], units: [] }));
    }, [isOpen, opts, companyId]);

    // Pré-preenchimento com os dados da linha.
    useEffect(() => {
        if (!isOpen || !line || !opts) return;
        setDescription((line.item ?? "").toUpperCase().slice(0, 120));
        setFamilyId("");
        setTaxgroupId(opts.taxgroups.find((t) => t.rate === line.vat_rate)?.value ?? "");
        setUnitId(unitFor(line.unit, opts.units));
        setPrice(line.unit_price !== null && line.unit_price !== undefined ? String(line.unit_price) : "");
    }, [isOpen, line, opts]);

    const reason = !description.trim() ? "Indique a descrição." : !familyId ? "Escolha a família." : !taxgroupId ? "Escolha o IVA." : !unitId ? "Escolha a unidade." : null;

    return (
        <Modal isOpen={isOpen} toggle={() => !busy && onClose()} size="lg" centered data-testid="create-article-modal">
            <ModalHeader toggle={() => !busy && onClose()}>Criar artigo no PingWin</ModalHeader>
            <ModalBody>
                {!opts ? (
                    <div className="text-center py-4"><Spinner size="sm" className="me-1" />A carregar…</div>
                ) : (
                    <Row className="g-3">
                        <Col md={12}>
                            <Label className="form-label" for="ca-desc">Descrição</Label>
                            <Input id="ca-desc" value={description} maxLength={120} onChange={(e) => setDescription(e.target.value)} />
                        </Col>
                        <Col md={12}>
                            <Label className="form-label" for="ca-family">Família</Label>
                            <XSelect id="ca-family" ariaLabel="Família" options={opts.families} value={familyId} onChange={(v) => setFamilyId(v)} placeholder="Escolher família…" searchable />
                        </Col>
                        <Col md={4}>
                            <Label className="form-label" for="ca-tax">IVA</Label>
                            <XSelect id="ca-tax" ariaLabel="IVA" options={opts.taxgroups.map(({ value, label }) => ({ value, label }))} value={taxgroupId} onChange={(v) => setTaxgroupId(v)} searchable={false} />
                        </Col>
                        <Col md={4}>
                            <Label className="form-label" for="ca-unit">Unidade</Label>
                            <XSelect id="ca-unit" ariaLabel="Unidade" options={opts.units.map(({ value, label }) => ({ value, label }))} value={unitId} onChange={(v) => setUnitId(v)} searchable={false} />
                        </Col>
                        <Col md={4}>
                            <Label className="form-label" for="ca-price">Preço de compra (€)</Label>
                            <Input id="ca-price" type="number" step="0.000001" min={0} className="text-end" value={price} onChange={(e) => setPrice(e.target.value)} />
                        </Col>
                        <Col md={6}>
                            <Label className="form-label" for="ca-supplier">Fornecedor</Label>
                            <Input id="ca-supplier" value={supplierName ?? "—"} readOnly className="bg-light" />
                        </Col>
                        <Col md={6}>
                            <Label className="form-label" for="ca-code">Código do fornecedor</Label>
                            <Input id="ca-code" value={line?.supplier_code ?? ""} readOnly className="bg-light" placeholder="—" />
                        </Col>
                        <Col md={12}>
                            <p className="fs-12 text-muted mb-0">
                                O artigo é criado no PingWin como artigo de compra. Quando estiver criado, a linha fica ligada a ele
                                {line?.supplier_code ? " e o código do fornecedor fica gravado no artigo" : ""}.
                            </p>
                        </Col>
                    </Row>
                )}
            </ModalBody>
            <ModalFooter>
                <Button color="light" onClick={onClose} disabled={busy}>Cancelar</Button>
                <ReasonButton color="primary" reason={opts ? reason : "A carregar…"} disabled={busy}
                    onClick={() => onSubmit({ description: description.trim(), family_id: familyId, taxgroup_id: taxgroupId, unit_id: unitId, purchase_price: price === "" ? null : Number(price) })}>
                    {busy ? <><Spinner size="sm" className="me-1" />A pedir…</> : <><i className="ri-add-line me-1" />Criar artigo</>}
                </ReasonButton>
            </ModalFooter>
        </Modal>
    );
}
