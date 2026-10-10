import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Spinner } from "reactstrap";
import { toast } from "react-toastify";
import XSelect from "Components/Common/Select";
import { getPermissionProfiles, setAgencyCeiling } from "helpers/laravel_helper";
import { PermissionProfile, ProfilesPayload } from "common/models/permissionProfile.model";

/**
 * ACL (F5, D2): no cartão da agência gestora, o TETO que a empresa dá à agência. Cada pessoa da
 * agência fica com o seu perfil na agência, dentro deste teto. Só o administrador da empresa o
 * muda (é uma decisão do cliente); a agência vê-o, para saber porque é que algo lhe é recusado.
 */
export default function AgencyCeilingSection({ companyId, agencyName }: { companyId: number; agencyName: string }) {
    const [data, setData] = useState<ProfilesPayload | null>(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => {
        getPermissionProfiles(companyId).then((r: any) => setData(r?.data ?? null)).catch(() => setData(null));
    }, [companyId]);
    useEffect(() => { load(); }, [load]);

    if (!data?.management) return null;
    const ceilings = data.profiles.filter((p) => p.side === "teto" && !p.is_suggestion);
    const current: PermissionProfile | undefined = ceilings.find((p) => p.id === data.management?.guest_profile_id);
    const areas = (current?.summary ?? []).filter((s) => s.actions.length > 0);

    const change = async (id: number) => {
        setBusy(true);
        try {
            await setAgencyCeiling(companyId, id);
            toast.success("O que a agência pode fazer foi atualizado.");
            load();
        } catch (e: any) {
            const first = e?.errors ? (Object.values(e.errors).flat()[0] as string) : null;
            toast.error(first || e?.message || "Não foi possível guardar.");
        } finally { setBusy(false); }
    };

    return (
        <div className="border-top mt-3 pt-3" data-testid="agency-ceiling">
            <h6 className="fs-13 mb-1">O que a agência pode fazer</h6>
            <p className="text-muted fs-12 mb-2">
                Cada pessoa da {agencyName} fica com o que o seu perfil na agência permite, dentro deste teto. A aprovação dos conteúdos e as decisões sobre orçamentos são sempre desta empresa.
            </p>
            {data.can_set_ceiling ? (
                <div className="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <div style={{ flex: "1 1 220px", minWidth: 0 }}>
                        <XSelect small ariaLabel="Teto da agência" value={current?.id ?? 0} disabled={busy} searchable={false}
                            onChange={(v) => { if (v && v !== current?.id) void change(Number(v)); }}
                            options={ceilings.map((p) => ({ value: p.id, label: p.name }))} />
                    </div>
                    {busy && <Spinner size="sm" />}
                    <Link to="/users?tab=perfis" className="fs-12">Criar um teto à medida</Link>
                </div>
            ) : (
                <p className="fs-13 mb-2"><strong>{current?.name ?? "Sem teto definido"}</strong></p>
            )}
            {areas.length > 0 && (
                <ul className="list-unstyled vstack gap-1 mb-0 fs-12">
                    {areas.map((s) => (
                        <li key={s.area} className="d-flex flex-wrap gap-1"><span className="fw-medium">{s.label}:</span><span className="text-muted">{s.text}</span></li>
                    ))}
                </ul>
            )}
        </div>
    );
}
