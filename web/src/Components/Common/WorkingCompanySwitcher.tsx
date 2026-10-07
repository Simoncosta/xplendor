import { useEffect, useMemo, useState } from "react";
import { Dropdown, DropdownMenu, DropdownToggle, Input } from "reactstrap";
import { useWorkingCompany, CompanyOption } from "contexts/WorkingCompanyContext";
import ClientMark, { clientColor } from "./ClientMark";
import "./workingCompany.css";

/**
 * "A trabalhar em" (gestão por agências), no topo da barra lateral, logo abaixo do logótipo,
 * com pesquisa. Com a barra recolhida mostra só o logótipo ou as iniciais da empresa (o nome
 * fica na dica). Para o root: primeiro as empresas geridas pela agência dele, com pesquisa em
 * todas. No telemóvel fica no topo do menu lateral.
 */
const MAX_RESULTS = 40;

export default function WorkingCompanySwitcher() {
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

    const current = ctx.options.find((o) => o.id === ctx.workingId);
    const name = ctx.workingName;
    const choose = (o: CompanyOption) => {
        setOpen(false);
        setQ("");
        document.body.classList.remove("vertical-sidebar-enable");
        if (o.id !== ctx.workingId) ctx.switchTo({ id: o.id, name: o.name });
    };
    const item = (o: CompanyOption) => (
        <button key={o.id} type="button" role="menuitemradio" aria-checked={o.id === ctx.workingId}
            className={`dropdown-item d-flex align-items-center gap-2 ${o.id === ctx.workingId ? "active" : ""}`} onClick={() => choose(o)}>
            <ClientMark name={o.name} logoPath={o.logo_path} size={20} />
            <span className="text-truncate">{o.name}</span>
            {o.home && <i className="ri-home-4-line ms-auto text-muted" title="A sua empresa" />}
        </button>
    );
    const group = (title: string, list: CompanyOption[]) => list.length > 0 && (
        <>
            <h6 className="dropdown-header">{title}</h6>
            {list.map(item)}
        </>
    );

    return (
        <div className={`xp-wc ${ctx.away ? "is-away" : ""}`} data-working-company={ctx.workingId}>
            <Dropdown isOpen={open} toggle={() => setOpen((v) => !v)}>
                <DropdownToggle tag="button" type="button" className="xp-wc-toggle" title={`A trabalhar em: ${name}`}
                    aria-label={`A trabalhar em: ${name}. Mudar de empresa`}
                    style={ctx.away ? { boxShadow: `inset 3px 0 0 ${clientColor(name)}` } : undefined}>
                    <ClientMark name={name || "?"} logoPath={current?.logo_path} size={28} title={`A trabalhar em: ${name}`} />
                    <span className="xp-wc-text">
                        <span className="xp-wc-label">{ctx.away ? "A trabalhar no cliente" : "A trabalhar em"}</span>
                        <strong className="xp-wc-name" data-testid="working-company-name">{name}</strong>
                    </span>
                    <i className="ri-expand-up-down-line xp-wc-caret" />
                </DropdownToggle>
                <DropdownMenu container="body" className="p-0 xp-wc-menu" style={{ width: 300, maxWidth: "92vw" }}>
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
                            <button type="button" className="btn btn-sm btn-soft-warning w-100" onClick={() => { setOpen(false); document.body.classList.remove("vertical-sidebar-enable"); ctx.exit(); }}>
                                <i className="ri-logout-box-r-line me-1" />Voltar à sua empresa
                            </button>
                        </div>
                    )}
                </DropdownMenu>
            </Dropdown>
        </div>
    );
}

/**
 * A faixa fina de cor no topo da página enquanto se trabalha num cliente (a cor é a da
 * marca do cliente no seletor). Fica sempre visível, também no telemóvel.
 */
export function WorkingCompanyBand() {
    const ctx = useWorkingCompany();
    const away = !!ctx?.away;
    useEffect(() => {
        const root = document.documentElement;
        if (away) root.setAttribute("data-working-away", "true");
        else root.removeAttribute("data-working-away");
        return () => root.removeAttribute("data-working-away");
    }, [away]);
    if (!ctx || !away) return null;
    return (
        <div className="xp-wc-band" role="status" data-testid="working-company-band" style={{ background: clientColor(ctx.workingName || "?") }}
            title={`A trabalhar em: ${ctx.workingName}`}>
            <span className="visually-hidden">A trabalhar em: {ctx.workingName}</span>
        </div>
    );
}
