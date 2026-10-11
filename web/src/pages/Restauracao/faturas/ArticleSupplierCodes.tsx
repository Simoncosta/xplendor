import { useEffect, useState } from "react";
import { getArticleSupplierCodes } from "helpers/laravel_helper";
import { ArticleSupplierCodes as Payload } from "common/models/ocr.model";

/**
 * XPLENDOR — F2d: os códigos dos fornecedores deste artigo, na ficha (separador Compras). O PingWin
 * guarda UM código por fornecedor (o principal, na tabela acima); a XPLENDOR guarda também os
 * alternativos (ex.: a Makro fatura o mesmo produto com o EAN da unidade e o GTIN-14 da caixa),
 * para ligar as linhas das faturas. Só leitura; só aparece quando há algum alternativo.
 */
export default function ArticleSupplierCodes({ companyId, catalogItemId }: { companyId: number; catalogItemId: number | null }) {
    const [data, setData] = useState<Payload | null>(null);

    useEffect(() => {
        if (!companyId || !catalogItemId) return;
        let alive = true;
        getArticleSupplierCodes(companyId, catalogItemId)
            .then((r: any) => { if (alive) setData(r?.data ?? null); })
            .catch(() => { if (alive) setData(null); });
        return () => { alive = false; };
    }, [companyId, catalogItemId]);

    const withAlternatives = (data?.suppliers ?? []).filter((s) => s.codes.some((c) => !c.principal));
    if (withAlternatives.length === 0) return null;

    return (
        <div className="mt-3" data-testid="article-supplier-codes">
            <h6 className="mb-1">Códigos dos fornecedores na XPLENDOR</h6>
            <p className="fs-12 text-muted mb-2">O PingWin guarda um código por fornecedor (o principal). Os alternativos ficam na XPLENDOR e também ligam as linhas das faturas.</p>
            <div className="border rounded">
                {withAlternatives.map((s) => (
                    <div key={s.nif} className="d-flex flex-wrap gap-2 align-items-baseline px-3 py-2 border-bottom">
                        <span className="fw-medium me-2">{s.supplier ?? s.nif}</span>
                        {s.codes.map((c) => (
                            <span key={c.code} className="fs-13 text-nowrap" title={`${c.uses} ${c.uses === 1 ? "uso" : "usos"}`}>
                                <span className="font-monospace">{c.code}</span>{" "}
                                {c.principal ? <span className="badge bg-primary-subtle text-primary">principal</span> : <span className="badge bg-light text-body">alternativo</span>}
                                {c.suggested_principal && <span className="badge bg-info-subtle text-info ms-1" title="O mais usado">mais usado</span>}
                                {c.check && !c.check.valid && <span className="badge bg-warning-subtle text-warning ms-1">provavelmente mal lido</span>}
                            </span>
                        ))}
                    </div>
                ))}
            </div>
        </div>
    );
}
