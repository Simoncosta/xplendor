import { useCallback, useEffect, useState } from "react";
import { Button, Container, Input, ListGroup, ListGroupItem } from "reactstrap";
import { toast } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import PageCard from "Components/Common/PageCard";
import XSelect from "Components/Common/Select";
import { createStockZone, deleteStockZone, getCountableProducts, getStockCounts, getStockZones, updateStockZone } from "helpers/laravel_helper";
import { useWorkingCompanyId } from "contexts/WorkingCompanyContext";

/**
 * XPLENDOR — Restauração › Stock › Zonas (Fatia 7b). As zonas de contagem de cada armazém (o caminho
 * de quem conta): criar, renomear, ordenar, atribuir artigos por pesquisa e ordená-los. Fica só na
 * XPLENDOR. Um artigo está numa só zona de cada armazém.
 */
type Item = { product_id: string; code: string | null; description: string };
type Zone = { id: number; name: string; position: number; items: Item[] };

export default function StockZonesPage() {
    document.title = "Zonas de contagem | Stock | Xplendor";
    const companyId = useWorkingCompanyId();
    const [warehouses, setWarehouses] = useState<{ value: string; label: string }[]>([]);
    const [wid, setWid] = useState("");
    const [zones, setZones] = useState<Zone[]>([]);
    const [canManage, setCanManage] = useState(false);
    const [name, setName] = useState("");
    const [active, setActive] = useState<number | null>(null);
    const [search, setSearch] = useState("");
    const [found, setFound] = useState<Item[]>([]);

    useEffect(() => {
        if (!companyId) return;
        getStockCounts(companyId).then((res: any) => {
            const ws = (res?.data?.warehouses ?? []).map((w: any) => ({ value: w.id, label: w.name }));
            setWarehouses(ws);
            setWid((cur) => cur || ws[0]?.value || "");
        }).catch(() => null);
    }, [companyId]);
    const load = useCallback(async () => {
        if (!companyId || !wid) return;
        const res: any = await getStockZones(companyId, wid);
        setZones(res?.data?.zones ?? []);
        setCanManage(!!res?.data?.can_manage);
    }, [companyId, wid]);
    useEffect(() => { load().catch(() => toast.error("Não foi possível carregar as zonas.")); }, [load]);
    useEffect(() => {
        if (!companyId || search.trim().length < 2) { setFound([]); return; }
        const t = setTimeout(() => getCountableProducts(companyId, search.trim()).then((r: any) => setFound((r?.data?.products ?? []).map((p: any) => ({ product_id: String(p.product_id), code: p.code, description: p.description })))).catch(() => null), 300);
        return () => clearTimeout(t);
    }, [companyId, search]);

    const run = async (fn: () => Promise<any>, ok: string) => {
        try { await fn(); toast.success(ok); await load(); } catch (e: any) { toast.error(e?.response?.data?.message ?? e?.message ?? "Não foi possível guardar."); }
    };
    const saveItems = (z: Zone, items: Item[]) => companyId && run(() => updateStockZone(companyId, z.id, { product_ids: items.map((i) => i.product_id) }), "Zona guardada.");
    const move = <T,>(arr: T[], i: number, d: number) => { const a = [...arr]; const j = i + d; if (j < 0 || j >= a.length) return a; [a[i], a[j]] = [a[j], a[i]]; return a; };
    const zone = zones.find((z) => z.id === active) ?? null;

    return (
        <div className="page-content">
            <Container fluid>
                <PageHeader title="Zonas de contagem" breadcrumbs={[{ label: "Restauração" }, { label: "Contagens", to: "/restauracao/stock/contagens" }]}
                    info="A ordem em que se percorre o armazém a contar. Os artigos sem zona aparecem no fim da contagem." />
                <div style={{ maxWidth: 320 }} className="mb-3"><XSelect ariaLabel="Armazém" options={warehouses} value={wid} onChange={(v) => { setWid(v); setActive(null); }} searchable={false} /></div>
                <div className="row g-3">
                    <div className="col-lg-5">
                        <PageCard title="Zonas">
                            <ListGroup flush data-testid="zones-list">
                                {zones.map((z, i) => (
                                    <ListGroupItem key={z.id} active={z.id === active} action tag="div" onClick={() => setActive(z.id)} className="d-flex align-items-center gap-2" style={{ cursor: "pointer" }}>
                                        <span className="flex-grow-1">{z.name} <span className="text-muted fs-12">({z.items.length})</span></span>
                                        {canManage && (
                                            <>
                                                <Button size="sm" color="link" aria-label="Subir" onClick={(e) => { e.stopPropagation(); const o = move(zones, i, -1); o.forEach((x, k) => companyId && updateStockZone(companyId, x.id, { position: k })); setZones(o); }}><i className="ri-arrow-up-line" /></Button>
                                                <Button size="sm" color="link" aria-label="Descer" onClick={(e) => { e.stopPropagation(); const o = move(zones, i, 1); o.forEach((x, k) => companyId && updateStockZone(companyId, x.id, { position: k })); setZones(o); }}><i className="ri-arrow-down-line" /></Button>
                                                <Button size="sm" color="link" aria-label="Renomear" onClick={(e) => { e.stopPropagation(); const n = window.prompt("Novo nome da zona", z.name); if (n && companyId) run(() => updateStockZone(companyId, z.id, { name: n }), "Zona renomeada."); }}><i className="ri-pencil-line" /></Button>
                                                <Button size="sm" color="link" className="text-danger" aria-label="Apagar" onClick={(e) => { e.stopPropagation(); if (companyId && window.confirm(`Apagar a zona «${z.name}»? Os artigos ficam sem zona.`)) run(() => deleteStockZone(companyId, z.id), "Zona apagada."); }}><i className="ri-delete-bin-line" /></Button>
                                            </>
                                        )}
                                    </ListGroupItem>
                                ))}
                                {zones.length === 0 && <ListGroupItem className="text-muted">Ainda não há zonas neste armazém.</ListGroupItem>}
                            </ListGroup>
                            {canManage && (
                                <div className="d-flex gap-2 p-3">
                                    <Input placeholder="Nova zona (por exemplo, Câmara frigorífica)" value={name} onChange={(e) => setName(e.target.value)} />
                                    <Button color="primary" disabled={!name.trim() || !wid} onClick={() => companyId && run(() => createStockZone(companyId, { warehouse_id: wid, name: name.trim() }), "Zona criada.").then(() => setName(""))}>Criar</Button>
                                </div>
                            )}
                        </PageCard>
                    </div>
                    <div className="col-lg-7">
                        <PageCard title={zone ? `Artigos de ${zone.name}` : "Artigos"}>
                            {!zone ? <p className="text-muted p-3 mb-0">Escolha uma zona.</p> : (
                                <div className="p-3">
                                    {canManage && (
                                        <div className="mb-3">
                                            <Input type="search" placeholder="Pesquisar artigo contável para acrescentar…" value={search} onChange={(e) => setSearch(e.target.value)} />
                                            {found.length > 0 && (
                                                <ListGroup className="mt-1">
                                                    {found.filter((f) => !zone.items.some((i) => i.product_id === f.product_id)).slice(0, 8).map((f) => (
                                                        <ListGroupItem key={f.product_id} action tag="button" onClick={() => { saveItems(zone, [...zone.items, f]); setSearch(""); }}>
                                                            <i className="ri-add-line me-1" />{f.description} <span className="text-muted fs-12">{f.code}</span>
                                                        </ListGroupItem>
                                                    ))}
                                                </ListGroup>
                                            )}
                                        </div>
                                    )}
                                    <ListGroup data-testid="zone-items">
                                        {zone.items.map((it, i) => (
                                            <ListGroupItem key={it.product_id} className="d-flex align-items-center gap-2">
                                                <span className="text-muted fs-12" style={{ width: 24 }}>{i + 1}</span>
                                                <span className="flex-grow-1">{it.description} <span className="text-muted fs-12">{it.code}</span></span>
                                                {canManage && (
                                                    <>
                                                        <Button size="sm" color="link" aria-label="Subir" onClick={() => saveItems(zone, move(zone.items, i, -1))}><i className="ri-arrow-up-line" /></Button>
                                                        <Button size="sm" color="link" aria-label="Descer" onClick={() => saveItems(zone, move(zone.items, i, 1))}><i className="ri-arrow-down-line" /></Button>
                                                        <Button size="sm" color="link" className="text-danger" aria-label="Tirar da zona" onClick={() => saveItems(zone, zone.items.filter((x) => x.product_id !== it.product_id))}><i className="ri-close-line" /></Button>
                                                    </>
                                                )}
                                            </ListGroupItem>
                                        ))}
                                        {zone.items.length === 0 && <ListGroupItem className="text-muted">Sem artigos.</ListGroupItem>}
                                    </ListGroup>
                                </div>
                            )}
                        </PageCard>
                    </div>
                </div>
            </Container>
        </div>
    );
}
