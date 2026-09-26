import { useCallback, useMemo, useState } from "react";

/**
 * XPLENDOR — Tab Compras (C3): staging EM MEMÓRIA das linhas de fornecedor. O estado
 * pendente vive no frontend; só efetiva quando se GRAVA o artigo (envia o pacote à C2).
 * Diff: new (verde) · edited (amarela) · deleted (vermelha, reversível) · clean.
 */

export type LineState = "clean" | "new" | "edited" | "deleted";

export type SupplierLine = {
    _key: string;
    _state: LineState;
    _prev?: LineState;              // estado antes de apagar (para Restaurar)
    line_pingwin_id: string | null; // existentes têm; novas não
    supplier: { id: number | null; pingwin_id: string | null; name: string | null };
    table: { header_id: string | null; name: string | null };
    start_date: string | null;
    end_date: string | null;
    currency: string | null;
    unit: { id: string | null; name: string | null };
    sup_product_description: string | null;
    sup_product_code: string | null;
    sup_product_barcode: string | null;
    price_cents: number | null;
    discount1: string | number | null;
    discount2_mul: string | number | null;
};

/** Campos editáveis vindos do modal (o resto — supplier/table/datas/moeda — é da cascata). */
export type LineDraft = {
    supplier: { id: number | null; pingwin_id: string | null; name: string | null };
    table: { header_id: string | null; name: string | null };
    start_date: string | null;
    end_date: string | null;
    currency: string | null;
    unit: { id: string | null; name: string | null };
    sup_product_description: string | null;
    sup_product_code: string | null;
    sup_product_barcode: string | null;
    price_cents: number | null;
    discount1: string | number | null;
    discount2_mul: string | number | null;
};

export type ChangesPackage = {
    create: any[];
    update: any[];
    delete: string[];
};

let _seq = 0;
const nextKey = () => `sp-${Date.now()}-${_seq++}`;

export function useSupplierPricesStaging() {
    const [rows, setRows] = useState<SupplierLine[]>([]);

    /** (Re)carrega do servidor — todas clean, sem pendentes (após load ou após gravar). */
    const setClean = useCallback((lines: any[]) => {
        setRows((lines ?? []).map((l) => ({
            _key: nextKey(),
            _state: "clean" as LineState,
            line_pingwin_id: l.line_pingwin_id ?? null,
            supplier: l.supplier ?? { id: null, pingwin_id: null, name: null },
            table: l.table ?? { header_id: null, name: null },
            start_date: l.start_date ?? null,
            end_date: l.end_date ?? null,
            currency: l.currency ?? null,
            unit: l.unit ?? { id: null, name: null },
            sup_product_description: l.sup_product_description ?? null,
            sup_product_code: l.sup_product_code ?? null,
            sup_product_barcode: l.sup_product_barcode ?? null,
            price_cents: l.price_cents ?? null,
            discount1: l.discount1 ?? null,
            discount2_mul: l.discount2_mul ?? null,
        })));
    }, []);

    const addRow = useCallback((d: LineDraft) => {
        setRows((p) => [...p, { ...d, _key: nextKey(), _state: "new", line_pingwin_id: null }]);
    }, []);

    const editRow = useCallback((key: string, d: LineDraft) => {
        setRows((p) => p.map((r) => r._key !== key ? r : {
            ...r, ...d,
            _state: r._state === "new" ? "new" : "edited", // editar uma nova mantém-na nova
        }));
    }, []);

    const deleteRow = useCallback((key: string) => {
        setRows((p) => p.flatMap((r) => {
            if (r._key !== key) return [r];
            if (r._state === "new") return []; // nunca existiu no servidor → desaparece
            return [{ ...r, _prev: r._state, _state: "deleted" as LineState }];
        }));
    }, []);

    const restoreRow = useCallback((key: string) => {
        setRows((p) => p.map((r) => r._key !== key ? r : { ...r, _state: (r._prev ?? "clean"), _prev: undefined }));
    }, []);

    const hasPending = useMemo(() => rows.some((r) => r._state !== "clean"), [rows]);

    /** Monta o pacote para a C2 (preço em cêntimos; supplier_id LOCAL — o PHP resolve). */
    const buildPackage = useCallback((): ChangesPackage => {
        const fields = (r: SupplierLine) => ({
            supplier_id: r.supplier.id,
            price_cents: r.price_cents,
            unit_id: r.unit.id,
            sup_product_description: r.sup_product_description,
            sup_product_code: r.sup_product_code,
            sup_product_barcode: r.sup_product_barcode,
            discount1: r.discount1,
            discount2_mul: r.discount2_mul,
        });
        const create = rows.filter((r) => r._state === "new").map((r) => ({
            supprice_header_id: r.table.header_id,
            ...fields(r),
        }));
        const update = rows.filter((r) => r._state === "edited" && r.line_pingwin_id).map((r) => ({
            line_pingwin_id: r.line_pingwin_id,
            ...fields(r),
        }));
        const del = rows.filter((r) => r._state === "deleted" && r.line_pingwin_id).map((r) => r.line_pingwin_id as string);
        return { create, update, delete: del };
    }, [rows]);

    return { rows, setClean, addRow, editRow, deleteRow, restoreRow, hasPending, buildPackage };
}

export type SupplierPricesStaging = ReturnType<typeof useSupplierPricesStaging>;
