import { useCallback, useEffect, useState } from "react";
import { Badge, Button, Spinner } from "reactstrap";
import PageCard from "Components/Common/PageCard";
import Skeleton from "Components/Common/Skeleton";
import { getAgencyMetaLinks } from "helpers/laravel_helper";
import type { AssetLink, AssetType, LinksData } from "./AgencyPortfoliosCard";

/**
 * Parceiro 5: o estado de cada ativo da Meta que a agência ligou a um cliente (ligado, sem tarefa na
 * Página ou sem acesso, com o motivo) e a última verificação. Inclui os que saíram dos portfólios, que já
 * não aparecem na lista dos portfólios.
 */
const TYPE_LABEL: Record<AssetType, string> = { page: "Página", instagram: "Instagram", ad_account: "Conta de anúncios" };
const fmtDateTime = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleString("pt-PT", { timeZone: "Europe/Lisbon", day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" }) : null);

function StatusBadge({ status }: { status: AssetLink["status"] }) {
    if (status === "ligado") return <Badge color="success-subtle" className="text-success fw-normal">Ligado</Badge>;
    if (status === "sem_tarefa") return <Badge color="warning-subtle" className="text-warning fw-normal">Sem tarefa na Página</Badge>;
    return <Badge color="danger-subtle" className="text-danger fw-normal">Sem acesso</Badge>;
}

export default function AgencyAssetStatusCard({ agencyId }: { agencyId: number }) {
    const [data, setData] = useState<LinksData | null>(null);
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);
    const load = useCallback(async () => {
        setLoading(true);
        try {
            const r: any = await getAgencyMetaLinks(agencyId);
            setData(r?.data ?? null);
            setFailed(false);
        } catch {
            setFailed(true);
        } finally {
            setLoading(false);
        }
    }, [agencyId]);
    useEffect(() => { void load(); }, [load]);

    const lost = data?.links.filter((l) => l.status !== "ligado").length ?? 0;
    return (
        <PageCard title="Ativos ligados aos clientes" data-testid="agency-asset-status" loading={loading && !!data}
            info="O estado de cada ativo da Meta ligado a um cliente. Todos os dias, e quando a agência volta a ligar a Meta, a XPLENDOR verifica os portfólios e renova o acesso nas ligações dos clientes."
            status={lost > 0 ? <Badge color="danger-subtle" className="text-danger">{lost} com problemas</Badge> : undefined}
            actions={<Button size="sm" color="light" disabled={loading} onClick={() => load()}>{loading ? <Spinner size="sm" /> : "Atualizar"}</Button>}>
            <div className="p-3">
                {!data && loading && <Skeleton variant="lines" lines={3} className="py-2" />}
                {failed && !data && <div className="alert alert-warning fs-13 mb-0">Não foi possível ler os ativos ligados.</div>}
                {data && data.links.length === 0 && <p className="text-muted fs-13 mb-0">Ainda nenhum ativo ligado a um cliente.</p>}
                {data && data.links.length > 0 && (
                    <ul className="list-unstyled mb-0">
                        {data.links.map((l) => (
                            <li key={l.id} className="d-flex flex-wrap align-items-start justify-content-between gap-2 py-2 border-bottom" data-testid="agency-asset-row">
                                <div className="min-w-0">
                                    <div className="fw-medium">{l.name || l.external_id} <span className="text-muted fs-12">· {TYPE_LABEL[l.asset_type]}</span></div>
                                    <div className="fs-12 text-muted">{l.company ?? `Empresa ${l.company_id}`}{l.checked_at ? ` · verificado a ${fmtDateTime(l.checked_at)}` : ""}</div>
                                    {l.status !== "ligado" && l.message && <div className="fs-12 text-danger mt-1">{l.message}</div>}
                                </div>
                                <StatusBadge status={l.status} />
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PageCard>
    );
}
