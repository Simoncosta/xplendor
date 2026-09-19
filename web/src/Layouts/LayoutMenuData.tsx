import React, { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useModules } from "contexts/ModulesContext";

const Navdata = () => {
    const history = useNavigate();
    //state data
    const [isDashboard, setIsDashboard] = useState(false);
    const [isApps, setIsApps] = useState(false);
    const [isAuth, setIsAuth] = useState(false);
    const [isPages, setIsPages] = useState(false);
    const [isBaseUi, setIsBaseUi] = useState(false);
    const [isAdvanceUi, setIsAdvanceUi] = useState(false);
    const [isForms, setIsForms] = useState(false);
    const [isTables, setIsTables] = useState(false);
    const [isCharts, setIsCharts] = useState(false);
    const [isIcons, setIsIcons] = useState(false);
    const [isMaps, setIsMaps] = useState(false);
    const [isMultiLevel, setIsMultiLevel] = useState(false);

    const [iscurrentState, setIscurrentState] = useState('Dashboard');
    const [isRoot, setIsRoot] = useState(false);
    const [isFinances, setIsFinances] = useState(false);
    const [isComercial, setIsComercial] = useState(false);
    const [isAnalytics, setIsAnalytics] = useState(false);
    const [isRestauracao, setIsRestauracao] = useState(false);
    const [isEquipa, setIsEquipa] = useState(false);
    const [isAdmin, setIsAdmin] = useState(false);
    const [isSettings, setIsSettings] = useState(false);

    // Fase 2/3 — módulos ATIVOS da empresa vêm da fonte única (ModulesContext),
    // partilhada com o guard de rotas. `has(module)` = ativo OU root/desconhecido.
    const { has: hasModule } = useModules();

    // Helper do Velzon para o modo two-column (icon sidebar). Guardado para não
    // rebentar no layout vertical (onde #two-column-menu pode não existir).
    function updateIconSidebar(e: any) {
        if (e && e.target && e.target.getAttribute("subitems")) {
            const ul: any = document.getElementById("two-column-menu");
            if (!ul) return;
            const iconItems: any = ul.querySelectorAll(".nav-icon.active");
            const activeIconItems = [...iconItems];
            activeIconItems.forEach((item: any) => {
                item.classList.remove("active");
                const id = item.getAttribute("subitems");
                if (document.getElementById(id)) document.getElementById(id)?.classList.remove("show");
            });
        }
    }

    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (authUser) {
            const obj = JSON.parse(authUser);
            setIsRoot(obj.role === 'root');
            console.log(obj.role);
        }
    }, []);

    useEffect(() => {
        document.body.classList.remove('twocolumn-panel');
        if (iscurrentState !== 'Finances') {
            setIsFinances(false);
        }
        if (iscurrentState !== 'Comercial') {
            setIsComercial(false);
        }
        if (iscurrentState !== 'Analytics') {
            setIsAnalytics(false);
        }
        if (iscurrentState !== 'Restauracao') {
            setIsRestauracao(false);
        }
        if (iscurrentState !== 'Equipa') {
            setIsEquipa(false);
        }
        if (iscurrentState !== 'Administracao') {
            setIsAdmin(false);
        }
        if (iscurrentState !== 'Settings') {
            setIsSettings(false);
        }
        if (iscurrentState !== 'Dashboard') {
            setIsDashboard(false);
        }
        if (iscurrentState !== 'Apps') {
            setIsApps(false);
        }
        if (iscurrentState !== 'Auth') {
            setIsAuth(false);
        }
        if (iscurrentState !== 'Pages') {
            setIsPages(false);
        }
        if (iscurrentState !== 'BaseUi') {
            setIsBaseUi(false);
        }
        if (iscurrentState !== 'AdvanceUi') {
            setIsAdvanceUi(false);
        }
        if (iscurrentState !== 'Forms') {
            setIsForms(false);
        }
        if (iscurrentState !== 'Tables') {
            setIsTables(false);
        }
        if (iscurrentState !== 'Charts') {
            setIsCharts(false);
        }
        if (iscurrentState !== 'Icons') {
            setIsIcons(false);
        }
        if (iscurrentState !== 'Maps') {
            setIsMaps(false);
        }
        if (iscurrentState !== 'MuliLevel') {
            setIsMultiLevel(false);
        }
        if (iscurrentState === 'Widgets') {
            history("/widgets");
            document.body.classList.add('twocolumn-panel');
        }
    }, [
        history,
        iscurrentState,
        isDashboard,
        isApps,
        isAuth,
        isPages,
        isBaseUi,
        isAdvanceUi,
        isForms,
        isTables,
        isCharts,
        isIcons,
        isMaps,
        isMultiLevel,
    ]);

    const menuItems: any = [
        {
            label: "Menu",
            isHeader: true,
        },
        {
            id: "dashboard",
            label: "Dashboards",
            icon: "ri-dashboard-2-line",
            link: "/dashboard",
            click: function (e: any) {
                e.preventDefault();
                setIscurrentState('Dashboard');
            }
        },
        // ── Comercial — a operação de stock/vendas/marketing num sub-nav ──
        {
            id: "comercial",
            label: "Comercial",
            icon: "ri-store-2-line",
            link: "/#",
            stateVariables: isComercial,
            click: function (e: any) {
                e.preventDefault();
                setIsComercial(!isComercial);
                setIscurrentState('Comercial');
                updateIconSidebar(e);
            },
            // Comercial = operação de carros (stock + CRM). Módulos por sub-item.
            subItems: [
                { id: "cars", label: "Carros", link: "/cars", parentId: "comercial", module: "stock" },
                { id: "stock-monitoring", label: "Monitorização de stock", link: "/stock/monitoring", parentId: "comercial", module: "stock" },
                { id: "leads", label: "Leads", link: "/leads", parentId: "comercial", module: "commercial_crm" },
                { id: "stock-promotion", label: "Candidatas a promoção", link: "/stock/promotion", parentId: "comercial", module: "commercial_crm" },
                // Clientes (CRM) — mantém o gate 'finance' (a rota /customers é gated por finance no backend).
                { id: "customers", label: "Clientes", link: "/customers", parentId: "comercial", module: "finance" },
            ],
        },
        // ── Marketing — TRANSVERSAL (GA4 + Meta + conteúdo). Serve qualquer ramo.
        {
            id: "analytics",
            label: "Marketing",
            icon: "ri-line-chart-line",
            link: "/#",
            stateVariables: isAnalytics,
            click: function (e: any) {
                e.preventDefault();
                setIsAnalytics(!isAnalytics);
                setIscurrentState('Analytics');
                updateIconSidebar(e);
            },
            subItems: [
                { id: "website-traffic", label: "Tráfego do site", link: "/trafego-site", parentId: "analytics", module: "marketing_analytics" },
                { id: "meta-ads", label: "Meta / Anúncios", link: "/meta-ads", parentId: "analytics", module: "marketing_analytics" },
                // Blogs — conteúdo/marketing. Sem módulo (BASE) — deixou de ser item solto.
                { id: "blogs", label: "Blogs", link: "/blogs", parentId: "analytics" },
            ],
        },
        // ── Restauração — a operação do restaurante (só o ramo, módulo pingwin).
        //    Grupo pensado para CRESCER: futuras secções entram como irmãs de "Lojas".
        //    (O dashboard de vendas vive no PAINEL PRINCIPAL quando é o ramo.)
        {
            id: "restauracao",
            label: "Restauração",
            icon: "ri-restaurant-2-line",
            link: "/#",
            module: "pingwin",
            stateVariables: isRestauracao,
            click: function (e: any) {
                e.preventDefault();
                setIsRestauracao(!isRestauracao);
                setIscurrentState('Restauracao');
                updateIconSidebar(e);
            },
            subItems: [
                { id: "pingwin-lojas", label: "Lojas", link: "/restauracao/lojas", parentId: "restauracao", module: "pingwin" },
            ],
        },
        {
            id: "finances",
            label: "Finanças",
            icon: "ri-wallet-3-line",
            link: "/#",
            stateVariables: isFinances,
            click: function (e: any) {
                e.preventDefault();
                setIsFinances(!isFinances);
                setIscurrentState('Finances');
                updateIconSidebar(e);
            },
            subItems: [
                { id: "expenses", label: "Despesas", link: "/expenses", parentId: "finances", module: "finance" },
                { id: "expense-categories", label: "Categorias de Despesa", link: "/expense-categories", parentId: "finances", module: "finance" },
                { id: "suppliers", label: "Fornecedores", link: "/suppliers", parentId: "finances", module: "finance" },
                { id: "document-templates", label: "Modelos de documento", link: "/document-templates", parentId: "finances", module: "documents" },
            ],
        },
        // ── Equipa — ferramentas transversais da equipa (qualquer ramo) ──────────
        {
            id: "equipa",
            label: "Equipa",
            icon: "ri-team-line",
            link: "/#",
            stateVariables: isEquipa,
            click: function (e: any) {
                e.preventDefault();
                setIsEquipa(!isEquipa);
                setIscurrentState('Equipa');
                updateIconSidebar(e);
            },
            subItems: [
                { id: "tasks", label: "Tarefas", link: "/tasks", parentId: "equipa", module: "support_tasks" },
                // Orçamentos fica BASE — visível a todos (o Simon envia orçamentos a qualquer cliente).
                { id: "quotes", label: "Orçamentos", link: "/quotes", parentId: "equipa" },
                { id: "support", label: "Suporte", link: "/support", parentId: "equipa", module: "support_tasks" },
            ],
        },
        // ── Configurações — só conta/organização (Empresas foi para Administração;
        //    Suporte foi para Equipa). Sub-itens BASE → grupo sempre visível.
        {
            id: "settings",
            label: "Configurações",
            icon: "ri-settings-3-line",
            link: "/#",
            stateVariables: isSettings,
            click: function (e: any) {
                e.preventDefault();
                setIsSettings(!isSettings);
                setIscurrentState('Settings');
                updateIconSidebar(e);
            },
            subItems: [
                { id: "user", label: "Colaboradores", link: "/users", parentId: "settings" },
                { id: "install-app", label: "Instalar app", link: "/install", parentId: "settings" },
            ],
        },
        // ── Administração — visível apenas a role root. Sub-nav único; a área
        //    /admin cresce aqui dentro (novas consolas entram como sub-itens).
        //    Gating ao nível do array (o `hidden` NÃO é respeitado em subItems).
        ...(isRoot ? [
            {
                label: "Administração",
                isHeader: true,
            },
            {
                id: "admin",
                label: "Administração",
                icon: "ri-shield-star-line",
                link: "/#",
                stateVariables: isAdmin,
                click: function (e: any) {
                    e.preventDefault();
                    setIsAdmin(!isAdmin);
                    setIscurrentState('Administracao');
                    updateIconSidebar(e);
                },
                subItems: [
                    // Empresas — gestão cross-tenant (era o root misturado no Configurações).
                    { id: "company", label: "Empresas", link: "/companies", parentId: "admin" },
                    { id: "admin-tickets", label: "Tickets", link: "/admin", parentId: "admin" },
                    { id: "admin-quotes", label: "Orçamentos (global)", link: "/admin/quotes", parentId: "admin" },
                    { id: "admin-stock", label: "Stock global", link: "/admin/stock", parentId: "admin" },
                ],
            },
        ] : []),
    ];

    // ── Mecanismo CENTRAL de esconder por módulo (Fase 2) ─────────────────────
    // Cada item/sub-item declara `module`; itens sem `module` são BASE (sempre
    // visíveis). Um grupo esconde-se se ficar sem sub-itens visíveis. activeModules
    // null (root/loading/falha) → mostra tudo.
    const moduleVisible = (mod?: string): boolean => hasModule(mod);

    const filterMenu = (items: any[]): any[] =>
        items.reduce((acc: any[], it: any) => {
            if (it.isHeader) { acc.push(it); return acc; }
            if (Array.isArray(it.subItems)) {
                if (!moduleVisible(it.module)) return acc;
                const subs = it.subItems.filter((s: any) => moduleVisible(s.module));
                if (subs.length === 0) return acc; // grupo vazio → esconde
                acc.push({ ...it, subItems: subs });
                return acc;
            }
            return moduleVisible(it.module) ? [...acc, it] : acc;
        }, []);

    return <React.Fragment>{filterMenu(menuItems)}</React.Fragment>;
};
export default Navdata;
