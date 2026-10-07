import { useMemo, useState } from "react";
import { Dropdown, DropdownMenu, DropdownToggle, Input } from "reactstrap";
import { useWorkingCompany, CompanyOption } from "contexts/WorkingCompanyContext";

/**
 * Selo sempre visível "A trabalhar em: [empresa]" com o seletor de cliente (gestão por
 * agências). Fora da própria empresa fica com a cor de aviso e com "Sair do contexto".
 * Para o root: primeiro as empresas geridas pela agência dele, com pesquisa em todas.
 */
const MAX_RESULTS = 40;

export default function WorkingCompanyBadge() {
    const ctx = useWorkingCompany();
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState("");

    const { home, managed, others } = useMemo(() => {
        const opts = ctx?.options ?? [];
        const term = q.trim().toLocaleLowerCase("pt");
        const match = (o: CompanyOption) => !term || o.name.toLocaleLowerCase("pt").includes(term);
        return {
            home: opts.filter((o) => o.home && match(o)),
            managed: opts.filter((o) => !o.home && o.managed && match(o)),
            // O root só vê as restantes ao pesquisar (são todas as empresas da plataforma).
            others: opts.filter((o) => !o.home && !o.managed && match(o) && (!ctx?.isRoot || term)).slice(0, MAX_RESULTS),
        };
    }, [ctx, q]);

    if (!ctx || !ctx.canSwitch) return null;

    const choose = (o: CompanyOption) => {
        setOpen(false);
        setQ("");
        if (o.id !== ctx.workingId) ctx.switchTo({ id: o.id, name: o.name });
    };
    const item = (o: CompanyOption) => (
        <button key={o.id} type="button" role="menuitemradio" aria-checked={o.id === ctx.workingId}
            className={`dropdown-item d-flex align-items-center gap-2 ${o.id === ctx.workingId ? "active" : ""}`} onClick={() => choose(o)}>
            <i className={o.home ? "ri-home-4-line" : "ri-building-line"} />
            <span className="text-truncate">{o.name}</span>
        </button>
    );
    const group = (title: string, list: CompanyOption[]) => list.length > 0 && (
        <>
            <h6 className="dropdown-header">{title}</h6>
            {list.map(item)}
        </>
    );

    return (
        <Dropdown isOpen={open} toggle={() => setOpen((v) => !v)} className="header-item ms-1 ms-sm-2" data-working-company={ctx.workingId}>
            <DropdownToggle tag="button" type="button" title={`A trabalhar em: ${ctx.workingName}`}
                className={`btn btn-sm d-flex align-items-center gap-1 rounded-pill px-2 px-sm-3 ${ctx.away ? "btn-warning" : "btn-soft-secondary"}`}
                style={{ maxWidth: "min(46vw, 320px)" }} aria-label={`A trabalhar em: ${ctx.workingName}. Mudar de empresa`}>
                <i className={ctx.away ? "ri-building-line" : "ri-home-4-line"} />
                <span className="d-none d-md-inline">A trabalhar em:</span>
                <strong className="text-truncate" data-testid="working-company-name">{ctx.workingName}</strong>
                <i className="ri-arrow-down-s-line" />
            </DropdownToggle>
            <DropdownMenu end className="p-0" style={{ width: 320, maxWidth: "92vw" }}>
                <div className="p-2 border-bottom">
                    <Input bsSize="sm" type="search" autoFocus value={q} onChange={(e) => setQ(e.target.value)}
                        placeholder={ctx.isRoot ? "Pesquisar em todas as empresas" : "Pesquisar cliente"} aria-label="Pesquisar empresa" />
                </div>
                <div style={{ maxHeight: 360, overflowY: "auto" }} className="py-1">
                    {group("A sua empresa", home)}
                    {group(ctx.isRoot ? "Geridas pela sua agência" : "Clientes geridos", managed)}
                    {group("Todas as empresas", others)}
                    {ctx.isRoot && !q.trim() && <div className="px-3 py-2 text-muted fs-12">Pesquise para ver todas as empresas da plataforma.</div>}
                    {home.length + managed.length + others.length === 0 && q.trim() && <div className="px-3 py-2 text-muted fs-13">Nenhuma empresa encontrada.</div>}
                </div>
                {ctx.away && (
                    <div className="border-top p-2">
                        <button type="button" className="btn btn-sm btn-soft-warning w-100" onClick={() => { setOpen(false); ctx.exit(); }}>
                            <i className="ri-logout-box-r-line me-1" />Sair do contexto
                        </button>
                    </div>
                )}
            </DropdownMenu>
        </Dropdown>
    );
}
