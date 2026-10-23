import { useEffect, useState } from "react";
import { Input, Label } from "reactstrap";
import { toast } from "react-toastify";
import { getArticleGrouping, setArticleGrouping } from "helpers/laravel_helper";

/**
 * XPLENDOR — Cadastros › Artigos: "Artigo de agrupamento". Definição da XPLENDOR, por empresa e por artigo
 * (não mexe no PingWin): ao lançar uma fatura, as linhas deste artigo juntam-se numa só, com quantidade 1
 * e o total somado. Mostra quem mudou e quando.
 */
type State = { grouping: boolean; changed_at: string | null; changed_by_name: string | null };

export default function ArticleGroupingSwitch({ companyId, catalogItemId }: { companyId: number; catalogItemId: number | null }) {
    const [state, setState] = useState<State | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!companyId || !catalogItemId) return;
        let alive = true;
        getArticleGrouping(companyId, catalogItemId)
            .then((r: any) => { if (alive) setState(r?.data ?? null); })
            .catch(() => { if (alive) setState(null); });
        return () => { alive = false; };
    }, [companyId, catalogItemId]);

    if (!catalogItemId || !state) return null;

    const change = async (grouping: boolean) => {
        setBusy(true);
        try {
            const r: any = await setArticleGrouping(companyId, catalogItemId, grouping);
            setState(r?.data ?? { ...state, grouping });
            toast.success(r?.message ?? "Guardado.");
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível guardar.");
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="mt-3 border rounded px-3 py-2" data-testid="article-grouping">
            <div className="form-check form-switch mb-0">
                <Input id={`grouping-${catalogItemId}`} type="switch" role="switch" className="form-check-input" checked={state.grouping} disabled={busy}
                    onChange={(e) => change(e.target.checked)} />
                <Label for={`grouping-${catalogItemId}`} className="form-check-label fw-medium">Artigo de agrupamento</Label>
            </div>
            <div className="fs-13 text-muted">Ao lançar, as linhas deste artigo juntam-se numa só, com quantidade 1 e o total somado. Não muda nada no PingWin.</div>
            {state.changed_at && (
                <div className="fs-12 text-muted mt-1" data-testid="article-grouping-changed">
                    Mudado por {state.changed_by_name ?? "—"} a {new Date(state.changed_at).toLocaleString("pt-PT", { dateStyle: "short", timeStyle: "short" })}.
                </div>
            )}
        </div>
    );
}
