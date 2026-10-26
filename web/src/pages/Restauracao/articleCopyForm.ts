import { ArticleCopy } from "./ArticleCopyCard";

/**
 * XPLENDOR — O formulário do artigo (Cadastros › Artigos) a partir da cópia local, para abrir logo
 * (desativado) antes da leitura ao vivo do PingWin, e o que essa leitura mudou face à cópia.
 * Os campos de que a cópia só sabe o nome levam um valor "copia:…" (só para mostrar; nunca se gravam:
 * o formulário só se ativa com a leitura nova, que os substitui todos).
 */
export const EMPTY_FORM = {
    code: "", description: "", status: "1", family_id: "",
    forsale: true, forpurchase: false, forproduction: false,
    base_unit_id: "", sale_unit_id: "", purchase_unit_id: "",
    saleprice: "", change_sale_price: false, purchaseprice: "",
    product_type: "1", taxgroup_id: "", stockconfig_id: "", setexpireday: "",
    shortname: "", button_name: "",
    stock_unit_id: "", label_unit_id: "", volume_unit_id: "", weight: "",
};
export type ArticleForm = typeof EMPTY_FORM;

/** cêntimos → "12,34" (ou "" se null). */
export const centsToEur = (c: number | null | undefined): string =>
    c === null || c === undefined ? "" : (Number(c) / 100).toFixed(2).replace(".", ",");

const COPY = "copia:";
const str = (v: unknown) => (v === null || v === undefined ? "" : String(v));
/** O id da cópia; sem id mas com o nome, um valor só para mostrar. */
const idOrName = (id: string | null | undefined, name: string | null | undefined, key: string) => (id ? String(id) : name ? COPY + key : "");

/** O formulário com os valores da cópia local. */
export function formFromCopy(copy: ArticleCopy): ArticleForm {
    const fm = copy.form;
    const u = copy.units;
    return {
        ...EMPTY_FORM,
        code: str(copy.code), description: str(copy.description),
        status: idOrName(fm?.status_id, copy.product_status, "status"),
        family_id: idOrName(fm?.family_id, copy.family, "family"),
        forsale: !!copy.forsale, forpurchase: !!copy.forpurchase, forproduction: !!fm?.forproduction,
        base_unit_id: idOrName(u.base.id, u.base.name, "base"),
        sale_unit_id: idOrName(u.sale.id, u.sale.name, "sale"),
        purchase_unit_id: idOrName(u.purchase.id, u.purchase.name, "purchase"),
        stock_unit_id: idOrName(u.stock.id, u.stock.name, "stock"),
        label_unit_id: idOrName(fm?.label_unit?.id, fm?.label_unit?.name, "label"),
        volume_unit_id: idOrName(fm?.volume_unit?.id, fm?.volume_unit?.name, "volume"),
        product_type: idOrName(fm?.product_type_id, fm?.product_type, "type"),
        taxgroup_id: idOrName(fm?.taxgroup_id, fm?.taxgroup, "tax"),
        stockconfig_id: str(fm?.stockconfig_id),
        setexpireday: str(fm?.setexpireday),
        weight: str(fm?.weight).replace(".", ","),
        saleprice: centsToEur(copy.saleprice_cents), purchaseprice: centsToEur(copy.purchaseprice_cents),
    };
}

/** As opções dos selects com o que a cópia sabe (o valor e o nome), até chegarem as do PingWin. */
export function lookupsFromCopy(copy: ArticleCopy): Record<string, any> {
    const f = formFromCopy(copy);
    const one = (id: string, name: string | null | undefined) => (id && name ? [{ id, description: name }] : []);
    const fm = copy.form;
    const u = copy.units;
    const units = [one(f.base_unit_id, u.base.name), one(f.stock_unit_id, u.stock.name), one(f.label_unit_id, fm?.label_unit?.name), one(f.volume_unit_id, fm?.volume_unit?.name)].flat();
    return {
        families: f.family_id && copy.family ? [{ id: f.family_id, description: copy.family, complete_name: copy.family, isleaf: 1, deleted: false }] : [],
        lkstatus: one(f.status, copy.product_status ?? (copy.is_active ? "Ativo" : "Anulado")),
        product_type: one(f.product_type, fm?.product_type),
        lktaxgroup: one(f.taxgroup_id, fm?.taxgroup),
        lk_stockconfig: [],
        units: {
            base: units.filter((x, i) => units.findIndex((y) => y.id === x.id) === i),
            sale: one(f.sale_unit_id, u.sale.name),
            purchase: one(f.purchase_unit_id, u.purchase.name),
        },
    };
}

/** O formulário com os valores da leitura ao vivo (readProduct). */
export function formFromLive(art: any): ArticleForm {
    const m = art?.maindataset ?? {};
    return {
        code: str(m.code),
        description: str(m.description),
        status: m.status != null ? String(m.status) : "1",
        family_id: m.family_id ? String(m.family_id) : "",
        forsale: !!Number(m.forsale),
        forpurchase: !!Number(m.forpurchase),
        forproduction: !!Number(m.forproduction),
        base_unit_id: m.base_unit_id ? String(m.base_unit_id) : "",
        sale_unit_id: m.default_sale_unit_id ? String(m.default_sale_unit_id) : "",
        purchase_unit_id: m.default_purchase_unit_id ? String(m.default_purchase_unit_id) : "",
        stock_unit_id: m.default_stock_unit_id ? String(m.default_stock_unit_id) : "",
        label_unit_id: m.label_unit_id ? String(m.label_unit_id) : "",
        volume_unit_id: m.volume_unit_id ? String(m.volume_unit_id) : "",
        product_type: m.product_type != null ? String(m.product_type) : "1",
        taxgroup_id: m.taxgroup_id ? String(m.taxgroup_id) : "",
        stockconfig_id: m.stockconfig_id ? String(m.stockconfig_id) : "",
        setexpireday: m.setexpireday != null && m.setexpireday !== "" ? String(m.setexpireday) : "",
        shortname: str(m.shortname),
        button_name: str(m.button_name),
        change_sale_price: !!Number(m.change_sale_price),
        weight: m.weight != null && m.weight !== "" ? String(m.weight).replace(".", ",") : "",
        saleprice: centsToEur(art?.saleprice_cents),
        purchaseprice: centsToEur(art?.purchaseprice_cents),
    };
}

const COMPARED: (keyof ArticleForm)[] = [
    "code", "description", "status", "family_id", "forsale", "forpurchase", "forproduction", "base_unit_id", "sale_unit_id", "purchase_unit_id",
    "stock_unit_id", "label_unit_id", "volume_unit_id", "product_type", "taxgroup_id", "stockconfig_id", "setexpireday", "weight", "saleprice", "purchaseprice",
];

/**
 * Os campos que a leitura ao vivo mudou face à cópia (para os assinalar). Só os que a cópia conhecia:
 * um campo vazio na cópia, ou só com o nome, não conta como mudança.
 */
export function changedFields(copy: ArticleCopy | null, live: ArticleForm): (keyof ArticleForm)[] {
    if (!copy) return [];
    const before = formFromCopy(copy);
    return COMPARED.filter((k) => {
        const b = before[k];
        if (typeof b === "string" && (b === "" || b.startsWith(COPY))) return false;
        if (k === "forproduction" && (copy.form?.forproduction === null || copy.form?.forproduction === undefined)) return false;
        // Os números comparam-se pelo valor ("1,500" na cópia é "1,5" na leitura).
        if (k === "weight" || k === "setexpireday") return Number(String(b).replace(",", ".")) !== Number(String(live[k]).replace(",", "."));
        return b !== live[k];
    });
}
