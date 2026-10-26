import { Alert } from "reactstrap";
import { ArticleUnitChange } from "common/models/unitGuide.model";

/**
 * A cópia local do artigo (sem esperar pelo PingWin): o formulário abre logo com ela, desativado e com
 * a hora dos dados, enquanto a leitura ao vivo não chega (ver articleCopyForm e ArtigoFormPage).
 */
export type ArticleCopyUnit = { label: string; id: string | null; name: string | null };
export type ArticleCopy = {
    catalog_item_id: number; pingwin_id: string; code: string | null; description: string | null; family: string | null;
    product_status: string | null; is_active: boolean; saleprice_cents: number | null; purchaseprice_cents: number | null;
    forsale?: boolean; forpurchase?: boolean;
    units: Record<"base" | "purchase" | "sale" | "stock", ArticleCopyUnit>; read_at: string | null; live_read_at: string | null;
    unit_changes?: ArticleUnitChange[];
    /** O formulário com os valores da cópia (ids e nomes), para abrir logo, desativado. */
    form?: {
        family_id: string | null; status_id: string | null; product_type_id: string | null; product_type: string | null;
        taxgroup_id: string | null; taxgroup: string | null; stockconfig_id: string | null; forproduction: boolean | null;
        setexpireday: number | string | null; weight: number | string | null;
        label_unit: { id: string | null; name: string | null }; volume_unit: { id: string | null; name: string | null };
    };
};
export type ArticleChange = { field: string; label: string; from: string | null; to: string | null };

export const fmtWhen = (iso: string | null) => {
    if (!iso) return "data desconhecida";
    const d = new Date(iso);
    const day = d.toLocaleDateString("pt-PT", { timeZone: "Europe/Lisbon", day: "2-digit", month: "2-digit" });
    const time = d.toLocaleTimeString("pt-PT", { timeZone: "Europe/Lisbon", hour: "2-digit", minute: "2-digit" });
    return `${day} às ${time}`;
};

/** O que mudou face à cópia, quando a leitura ao vivo chega. */
export function ArticleChanges({ changes }: { changes: ArticleChange[] }) {
    if (changes.length === 0) return null;
    return (
        <Alert color="info" className="py-2 fs-13" data-testid="article-changes">
            <i className="ri-refresh-line me-1" /><strong>Mudou no PingWin desde a última leitura:</strong>{" "}
            {changes.map((c) => `${c.label}: ${c.from ?? "—"} → ${c.to ?? "—"}`).join(" · ")}. A cópia da XPLENDOR ficou atualizada.
        </Alert>
    );
}
