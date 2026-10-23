import { useCallback, useEffect, useState } from "react";
import { Badge, Button, Input, Spinner } from "reactstrap";
import { toast } from "react-toastify";
import PageCard from "Components/Common/PageCard";
import Skeleton from "Components/Common/Skeleton";
import { getAgencyMetaLinks, getAgencyMetaPortfolios, linkAgencyMetaAsset, unlinkAgencyMetaAsset } from "helpers/laravel_helper";
import { confirmAction } from "helpers/swal";

/**
 * Parceiros 3 e 4: os portfólios da Meta que a agência vê e os ativos de cada um (Páginas com o Instagram,
 * contas de anúncios e contas de Instagram), da agência ou partilhados pelo cliente; cada ativo pode ligar-se
 * a um cliente ativo da agência (só a um). Uma Página sem tarefa atribuída à pessoa que ligou a Meta fica
 * registada com o que fazer.
 */
type AssetType = "page" | "instagram" | "ad_account";
type AssetLink = { id: number; asset_type: AssetType; external_id: string; company_id: number; company: string | null; status: "ligado" | "sem_tarefa"; message: string | null };
export type LinksData = { clients: { id: number; name: string }[]; links: AssetLink[] };

function LinkControl({ agencyId, type, externalId, data, onChanged }: { agencyId: number; type: AssetType; externalId: string; data: LinksData | null; onChanged: (d: LinksData) => void }) {
    const [companyId, setCompanyId] = useState("");
    const [busy, setBusy] = useState(false);
    if (!data) return null;
    const link = data.links.find((l) => l.asset_type === type && l.external_id === externalId);
    const reload = async () => { const r: any = await getAgencyMetaLinks(agencyId); if (r?.data) onChanged(r.data); };
    const doLink = async (replace = false) => {
        setBusy(true);
        try {
            await linkAgencyMetaAsset(agencyId, { company_id: Number(companyId), asset_type: type, external_id: externalId, ...(replace ? { replace: true } : {}) });
            await reload();
        } catch (e: any) {
            // A empresa tem a sua própria ligação: pergunta se passa para a da agência.
            if (e?.__status === 409 && !replace && /própria ligação/.test(e?.message ?? "")
                && await confirmAction({ title: "Passar para a ligação da agência?", text: e.message, confirmText: "Passar para a agência", icon: "warning" })) {
                setBusy(false);
                return doLink(true);
            }
            toast.error(e?.message ?? "Não foi possível ligar.");
        } finally {
            setBusy(false);
        }
    };
    const doUnlink = async () => {
        if (!link) return;
        setBusy(true);
        try { await unlinkAgencyMetaAsset(agencyId, link.id); await reload(); } catch (e: any) { toast.error(e?.message ?? "Não foi possível desligar."); } finally { setBusy(false); }
    };
    if (link) {
        return (
            <div className="fs-12 mt-1" data-testid={`asset-link-${type}-${externalId}`}>
                {link.status === "ligado"
                    ? <span className="text-success"><i className="ri-link me-1" aria-hidden />Ligado a {link.company ?? `empresa ${link.company_id}`}</span>
                    : <span className="text-warning"><i className="ri-error-warning-line me-1" aria-hidden />Registado para {link.company ?? `empresa ${link.company_id}`}, sem acesso: {link.message}</span>}
                <Button size="sm" color="link" className="py-0" disabled={busy} onClick={doUnlink}>Desligar</Button>
            </div>
        );
    }
    if (data.clients.length === 0) return null;
    return (
        <div className="d-flex gap-1 mt-1" data-testid={`asset-link-${type}-${externalId}`}>
            <Input type="select" bsSize="sm" value={companyId} onChange={(e) => setCompanyId(e.target.value)} aria-label="Ligar a um cliente" style={{ maxWidth: 200 }}>
                <option value="">Ligar a um cliente…</option>
                {data.clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Input>
            <Button size="sm" color="light" disabled={!companyId || busy} onClick={() => doLink()}>{busy ? <Spinner size="sm" /> : "Ligar"}</Button>
        </div>
    );
}
type Relation = "owned" | "client";
type Page = { id: string; name: string; relation: Relation; instagram: { id: string; username: string | null } | null };
type AdAccount = { account_id: string; name: string; relation: Relation; currency: string | null; account_status: number | null };
type Instagram = { id: string; username: string | null; relation: Relation };
type Business = { id: string; name: string; verification_status: string | null; pages: Page[]; ad_accounts: AdAccount[]; instagram: Instagram[] };
export type PortfoliosData = { connected: boolean; ok: boolean; error: string | null; kind: string | null; read_at: string | null; businesses: Business[] };

// account_status da Meta: 1 ativa, 2 desativada, 3 com pagamento em falta, 7 em revisão, 9 em período de tolerância, 100 a fechar, 101 fechada.
const AD_STATUS: Record<number, string> = { 1: "Ativa", 2: "Desativada", 3: "Pagamento em falta", 7: "Em revisão", 9: "Em tolerância", 100: "A fechar", 101: "Fechada" };
const relationBadge = (r: Relation) => r === "client"
    ? <Badge color="info-subtle" className="text-info fw-normal ms-1">do cliente</Badge>
    : <Badge color="light" className="text-muted fw-normal ms-1">da agência</Badge>;
const fmtTime = (iso: string | null) => (iso ? new Date(iso).toLocaleTimeString("pt-PT", { timeZone: "Europe/Lisbon", hour: "2-digit", minute: "2-digit" }) : "");

export default function AgencyPortfoliosCard({ agencyId }: { agencyId: number }) {
    const [data, setData] = useState<PortfoliosData | null>(null);
    const [links, setLinks] = useState<LinksData | null>(null);
    const [loading, setLoading] = useState(false);
    useEffect(() => { getAgencyMetaLinks(agencyId).then((r: any) => setLinks(r?.data ?? null)).catch(() => setLinks(null)); }, [agencyId]);
    const control = (type: AssetType, id: string) => <LinkControl agencyId={agencyId} type={type} externalId={id} data={links} onChanged={setLinks} />;
    const load = useCallback(async (refresh = false) => {
        setLoading(true);
        try {
            const res: any = await getAgencyMetaPortfolios(agencyId, refresh);
            setData(res?.data ?? null);
        } catch (e: any) {
            setData({ connected: true, ok: false, error: e?.message ?? "Não foi possível ler os portfólios.", kind: null, read_at: null, businesses: [] });
        } finally {
            setLoading(false);
        }
    }, [agencyId]);
    useEffect(() => { void load(); }, [load]);

    return (
        <PageCard title="Portfólios e ativos partilhados" data-testid="agency-portfolios" loading={loading && !!data}
            info="Os portfólios da Meta (Business Manager) que a agência vê, com as Páginas, as contas de anúncios e as contas de Instagram de cada um. Só leitura."
            status={data?.read_at ? <span className="text-muted fs-12">Lido às {fmtTime(data.read_at)}</span> : undefined}
            actions={<Button size="sm" color="light" disabled={loading} onClick={() => load(true)} data-testid="agency-portfolios-refresh">{loading ? <Spinner size="sm" /> : "Atualizar"}</Button>}>
            <div className="p-3">
                {!data && <Skeleton variant="lines" lines={4} className="py-2" />}
                {data && !data.connected && <p className="text-muted fs-13 mb-0">Ligue a Meta da agência (em cima) para ver os portfólios.</p>}
                {data && data.connected && !data.ok && <div className="alert alert-warning fs-13 mb-0" role="alert" data-testid="agency-portfolios-error">{data.error}</div>}
                {data && data.ok && data.businesses.length === 0 && <p className="text-muted fs-13 mb-0">A Meta não devolveu nenhum portfólio para este utilizador.</p>}
                {data && data.ok && data.businesses.map((b) => (
                    <section key={b.id} className="border rounded p-3 mb-3" data-testid="agency-portfolio">
                        <div className="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <h6 className="mb-0">{b.name}</h6>
                            {b.verification_status === "verified"
                                ? <Badge color="success-subtle" className="text-success fw-normal">Verificado</Badge>
                                : <Badge color="light" className="text-muted fw-normal">Não verificado</Badge>}
                            <span className="text-muted fs-12">ID {b.id}</span>
                        </div>
                        <div className="row g-3 fs-13">
                            <div className="col-12 col-lg-4">
                                <div className="text-uppercase text-muted fs-12 mb-1">Páginas ({b.pages.length})</div>
                                {b.pages.length === 0 ? <span className="text-muted">Nenhuma.</span> : (
                                    <ul className="list-unstyled mb-0">{b.pages.map((p) => (
                                        <li key={`${p.relation}-${p.id}`} className="py-1">{p.name}{relationBadge(p.relation)}
                                            {p.instagram && <div className="text-muted fs-12"><i className="ri-instagram-line me-1" aria-hidden />{p.instagram.username ? `@${p.instagram.username}` : p.instagram.id}</div>}
                                            {control("page", p.id)}
                                        </li>
                                    ))}</ul>
                                )}
                            </div>
                            <div className="col-12 col-lg-4">
                                <div className="text-uppercase text-muted fs-12 mb-1">Contas de anúncios ({b.ad_accounts.length})</div>
                                {b.ad_accounts.length === 0 ? <span className="text-muted">Nenhuma.</span> : (
                                    <ul className="list-unstyled mb-0">{b.ad_accounts.map((a) => (
                                        <li key={`${a.relation}-${a.account_id}`} className="py-1">{a.name}{relationBadge(a.relation)}
                                            <div className="text-muted fs-12">{[a.account_id, a.currency, a.account_status !== null ? (AD_STATUS[a.account_status] ?? `Estado ${a.account_status}`) : null].filter(Boolean).join(" · ")}</div>
                                            {control("ad_account", a.account_id)}
                                        </li>
                                    ))}</ul>
                                )}
                            </div>
                            <div className="col-12 col-lg-4">
                                <div className="text-uppercase text-muted fs-12 mb-1">Instagram ({b.instagram.length})</div>
                                {b.instagram.length === 0 ? <span className="text-muted">Nenhuma.</span> : (
                                    <ul className="list-unstyled mb-0">{b.instagram.map((i) => (
                                        <li key={`${i.relation}-${i.id}`} className="py-1">{i.username ? `@${i.username}` : i.id}{relationBadge(i.relation)}{control("instagram", i.id)}</li>
                                    ))}</ul>
                                )}
                            </div>
                        </div>
                    </section>
                ))}
            </div>
        </PageCard>
    );
}
