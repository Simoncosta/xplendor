import { useEffect, useState } from "react";
import { Spinner } from "reactstrap";
import { toast } from "react-toastify";
import ReasonButton from "Components/Common/ReasonButton";
import XSelect from "Components/Common/Select";
import { getMetaAdAccounts, setMetaAccountApi } from "helpers/laravel_helper";

/**
 * Escolher a conta de anúncios numa lista (as contas a que a autorização da Meta dá acesso),
 * em vez de escrever o ID. Escrever o ID só quando a lista vem vazia ou a Meta não responde.
 */
type Account = { id: string; name: string; currency: string | null; business: string | null; active: boolean };

export default function MetaAccountPicker({ companyId, current, onSaved }: { companyId: number; current: string | null; onSaved: () => void }) {
    const [accounts, setAccounts] = useState<Account[] | null>(null);
    const [failed, setFailed] = useState<string | null>(null);
    const [choice, setChoice] = useState<string | null>(current);
    const [manual, setManual] = useState("");
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        getMetaAdAccounts(companyId)
            .then((r: any) => { setAccounts(r?.data?.accounts ?? []); setChoice(r?.data?.selected ?? current); })
            .catch((e: any) => { setAccounts([]); setFailed(e?.message ?? null); });
    }, [companyId, current]);

    const save = async (accountId: string) => {
        setSaving(true);
        try {
            await setMetaAccountApi(companyId, accountId);
            toast.success("Conta de anúncios guardada. A sincronizar os últimos 90 dias.");
            onSaved();
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível guardar a conta de anúncios.");
        } finally { setSaving(false); }
    };

    if (accounts === null) return <div className="py-2"><Spinner size="sm" /></div>;

    if (accounts.length === 0) {
        return (
            <div className="border rounded p-2 mt-1" style={{ background: "var(--vz-tertiary-bg)" }} data-testid="meta-account-manual">
                <p className="fs-12 text-body mb-2">
                    {failed ? `${failed} ` : "A Meta não devolveu contas de anúncios para esta autorização. "}
                    Pode indicar o ID da conta (Meta Business Suite, Contas de anúncios, por exemplo <code>act_123456789</code>).
                </p>
                <input type="text" className="form-control form-control-sm mb-2" placeholder="123456789 ou act_123456789" aria-label="ID da conta de anúncios"
                    value={manual} onChange={(e) => setManual(e.target.value)} onKeyDown={(e) => e.key === "Enter" && manual.trim() && save(manual.trim())} />
                <ReasonButton color="outline-primary" size="sm" className="w-100" disabled={saving} onClick={() => save(manual.trim())}
                    reason={!saving && !manual.trim() ? "Indique o ID da conta de anúncios." : null}>
                    {saving ? <Spinner size="sm" /> : "Guardar conta de anúncios"}
                </ReasonButton>
            </div>
        );
    }

    return (
        <div className="border rounded p-2 mt-1" style={{ background: "var(--vz-tertiary-bg)" }} data-testid="meta-account-list">
            <p className="fs-12 text-body mb-2">Escolha a conta de anúncios a ligar:</p>
            <div className="mb-2">
                <XSelect small ariaLabel="Conta de anúncios" value={choice} onChange={setChoice} placeholder="Escolher a conta…"
                    options={accounts.map((a) => ({ value: a.id, label: `${a.name}${a.business ? `, ${a.business}` : ""} (${a.id})${a.active ? "" : ", inativa"}` }))} />
            </div>
            <ReasonButton color="outline-primary" size="sm" className="w-100" disabled={saving} onClick={() => choice && save(choice)}
                reason={!saving && !choice ? "Escolha a conta de anúncios." : !saving && choice === current ? "Esta já é a conta ligada." : null}>
                {saving ? <Spinner size="sm" /> : "Guardar conta de anúncios"}
            </ReasonButton>
        </div>
    );
}
