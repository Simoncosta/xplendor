import React from "react";
import { Navigate, useParams } from "react-router-dom";

// Dashboard
import Dashboard from "pages/Dashboards/Dashboard";
import PingwinDashboard from "pages/Dashboards/PingwinDashboard";
import LojasPage from "pages/Restauracao/LojasPage";
import CalendarioPage from "pages/Restauracao/CalendarioPage";
import DocumentosPage from "pages/Restauracao/DocumentosPage";
import ArtigosPage from "pages/Restauracao/ArtigosPage";
import ArtigoFormPage from "pages/Restauracao/ArtigoFormPage";
import EditorialPage from "pages/Editorial/EditorialPage";
import AgencyPanelPage from "pages/Agency/AgencyPanelPage";
import ManagementRequestPage from "pages/ManagementRequest";
import FamiliasPage from "pages/Restauracao/FamiliasPage";
import CategoriasFamiliasPage from "pages/Restauracao/CategoriasFamiliasPage";
import BussolaPage from "pages/Marketing/bussola/BussolaPage";
import FornecedoresPage from "pages/Restauracao/FornecedoresPage";
import FaturasPage from "pages/Restauracao/FaturasPage";
import FaturaValidacaoPage from "pages/Restauracao/FaturaValidacaoPage";
import UnidadesPage from "pages/Restauracao/UnidadesPage";
import CondicoesPagamentoPage from "pages/Restauracao/CondicoesPagamentoPage";
import ContaCorrenteFornecedoresPage from "pages/Restauracao/ContaCorrenteFornecedoresPage";
import ContaCorrenteFornecedorPage from "pages/Restauracao/ContaCorrenteFornecedorPage";

// login
import ForgetPasswordPage from "../pages/Authentication/ForgetPassword";
import Logout from "../pages/Authentication/Logout";
import Register from "../pages/Authentication/Register";
import Login from "../pages/Authentication/Login"

// Company
import CompanyList from "pages/Companies/CompanyList";
import AdminCompaniesPage from "pages/Admin/AdminCompaniesPage";
import AdminCompanyUsersPage from "pages/Admin/AdminCompanyUsersPage";
import CompanyProfileUpdate from "pages/Companies/CompanyProfile/CompanyProfileUpdate";
import CompanyProfileCreate from "pages/Companies/CompanyProfile/CompanyProfileCreate";

// Cars
import CarList from "pages/Cars/CarList";
import CarCreate from "pages/Cars/Car/CarCreate";
import CarUpdate from "pages/Cars/Car/CarUpdate";
import CarAnalytics from "pages/Cars/Car/CarAnalytics";
import CarIntelligencePage from "pages/Cars/Car/CarIntelligencePage";
import CarFichaPage from "pages/Cars/Car/CarFichaPage";
import CarDocumentsPage from "pages/Cars/Car/CarDocumentsPage";
import CarPrintSheet from "pages/Cars/Car/CarPrintSheet";
import SaleDocumentPrint from "pages/Cars/Car/documents/SaleDocumentPrint";
import ActionCenterPage from "pages/Actions/ActionCenterPage";

// Users
import CollaboratorsList from "pages/Collaborators/CollaboratorsList";
import CollaboratorEditor from "pages/Collaborators/CollaboratorEditor";

// Landing
// Landings antigas do CRA (LandingX/LandingMotorhomes) foram substituídas pelo Rayo na
// raiz; ficheiros mantidos (órfãos), imports removidos por já não serem roteados.
import PrivacyPolicy from "pages/Privacy";
// Pós-venda — relatório público de satisfação (sem auth)
import QuotePublicPage from "pages/QuotePublic";
import ChargePublicPage from "pages/ChargePublic";
import AdminChargesPage from "pages/Admin/AdminChargesPage";
import ContentReviewPage from "pages/ContentReview";
import ContentReviewPreview from "pages/ContentReview/Preview";
import QuotePublicPreview from "pages/Admin/QuotePublicPreview";
import SatisfactionReport from "pages/SatisfactionReport";
import UserUpdate from "pages/Users/User/UserUpdate";
import BrandProfilePage from "pages/BrandProfile";

// Suppliers (DMS 1c.1)
import SupplierList from "pages/Suppliers/SupplierList";
// Customers (DMS)
import CustomerList from "pages/Customers/CustomerList";
import CustomerHub from "pages/Customers/CustomerHub";
// Document templates (DMS Caminho B)
import DocumentTemplatesList from "pages/DocumentTemplates/DocumentTemplatesList";
// Expense categories (DMS 1c.2a)
import ExpenseCategoryList from "pages/ExpenseCategories/ExpenseCategoryList";
// Expenses (DMS 1c.2b)
import ExpenseList from "pages/Expenses/ExpenseList";
// Blogs
import BlogList from "pages/Blogs/BlogList";
import BlogEditor from "pages/Blogs/Blog/BlogEditor";
import BlogShow from "pages/Blogs/Blog/BlogShow";

// Leads
import LeadList from "pages/Leads/LeadList";

// Stock & Promoção (Relatório A — flag manual de prioridade)
import StockPromotionPage from "pages/StockPromotion";

// Orçamentos (lado stand — a empresa vê/aprova os que a XPLENDOR lhe enviou)
import CompanyQuotesList from "pages/Quotes/CompanyQuotesList";

// Internal tools
// Support tickets (lado stand)
import SupportTicketsList from "pages/Support/SupportTicketsList";
import OrcamentosStand from "pages/Support/OrcamentosStand";
import SupportTicketDetail from "pages/Support/SupportTicketDetail";
// Tarefas internas do cliente (Kanban do stand).
import CompanyTasksKanban from "pages/Tasks/CompanyTasksKanban";
import CompanyTaskDetails from "pages/Tasks/CompanyTaskDetails";
// Tráfego do site do cliente (GA4).
import WebsiteTraffic from "pages/Analytics/WebsiteTraffic";
// Meta / Anúncios (leitura dos dados já ingeridos).
import MetaAds from "pages/Analytics/MetaAds";
// Admin (super-admin / root) — consola transversal
import AdminTicketsList from "pages/Admin/AdminTicketsList";
import AdminTicketDetail from "pages/Admin/AdminTicketDetail";
import AdminQuotesList from "pages/Admin/AdminQuotesList";
import QuoteEditor from "pages/Admin/QuoteEditor";
import ServiceCatalogPage from "pages/Admin/ServiceCatalogPage";
import AdminStockList from "pages/Admin/AdminStockList";
import CreativeFormatRulesPage from "pages/Admin/CreativeFormatRulesPage";
import AiModelsPage from "pages/Admin/AiModelsPage";
import SetupPublicPage from "pages/SetupPublic";
import RequireSuperAdmin from "./RequireSuperAdmin";
import RequireModule from "./RequireModule";

// OAuth Meta
import MetaOAuthCallback from "pages/OAuthCallback/MetaOAuthCallback";

// PWA — página de instalação (pública: acessível antes do login)
import InstallApp from "pages/Install/InstallApp";

const CarMarketingRedirect = () => {
    const { id } = useParams();
    return <Navigate to={`/cars/${id}/analytics`} replace />;
};

const CarAdsRedirect = () => {
    const { id } = useParams();
    return <Navigate to={`/cars/${id}/analytics`} replace />;
};

const authProtectedRoutes = [
    { path: "/dashboard", component: <Dashboard /> },
    // Painel da agência (a própria página diz o que fazer fora do contexto da agência).
    { path: "/agency", component: <AgencyPanelPage /> },
    // ROOT — empresas → utilizadores → "entrar como" (impersonation). Root-only na UI; a
    // segurança real é o backend (/admin gated). As páginas recusam não-root na mesma.
    { path: "/root/companies", component: <AdminCompaniesPage /> },
    { path: "/root/companies/:companyId/users", component: <AdminCompanyUsersPage /> },
    // Dashboard de restauração — só empresas com o módulo pingwin (backend recusa na mesma).
    { path: "/restauracao", component: <RequireModule module="pingwin" permission="restauracao.ver"><PingwinDashboard /></RequireModule> },
    { path: "/restauracao/lojas", component: <RequireModule module="restauracao_lojas" permission="restauracao.ver"><LojasPage /></RequireModule> },
    { path: "/restauracao/calendario", component: <RequireModule module="restauracao_calendario" permission="restauracao.ver"><CalendarioPage /></RequireModule> },
    { path: "/restauracao/documentos", component: <RequireModule module="restauracao_documentos" permission="restauracao.ver"><DocumentosPage /></RequireModule> },
    { path: "/restauracao/artigos", component: <RequireModule module="restauracao_artigos" permission="restauracao.ver"><ArtigosPage /></RequireModule> },
    // Linha Editorial (transversal)
    { path: "/editorial", component: <RequireModule module="linha_editorial" permission="editorial.ver"><EditorialPage /></RequireModule> },
    // Link de aprovação visto pela equipa ("Ver como o cliente"): sem ações e sem contar como abertura.
    { path: "/editorial/aprovacao/:id/ver", component: <RequireModule module="linha_editorial" permission="editorial.ver"><ContentReviewPreview /></RequireModule> },
    { path: "/restauracao/artigos/novo", component: <RequireModule module="restauracao_artigos" permission="restauracao.ver"><ArtigoFormPage /></RequireModule> },
    { path: "/restauracao/artigos/:pingwinId", component: <RequireModule module="restauracao_artigos" permission="restauracao.ver"><ArtigoFormPage /></RequireModule> },
    { path: "/restauracao/familias", component: <RequireModule module="restauracao_familias" permission="restauracao.ver"><FamiliasPage /></RequireModule> },
    // F1-3 do marketing: categorias das famílias (módulo pingwin, como o backend).
    { path: "/restauracao/categorias", component: <RequireModule module="pingwin" permission="restauracao.ver"><CategoriasFamiliasPage /></RequireModule> },
    // F3 do marketing: "O que publicar e quando" (módulo pingwin, como o backend).
    { path: "/marketing/bussola", component: <RequireModule module="pingwin" permission="bussola.ver"><BussolaPage /></RequireModule> },
    // Endereço antigo da Bússola ("O que publicar e quando").
    { path: "/marketing/o-que-publicar", component: <Navigate to="/marketing/bussola" replace /> },
    { path: "/restauracao/fornecedores", component: <RequireModule module="restauracao_fornecedores" permission="restauracao.ver"><FornecedoresPage /></RequireModule> },
    { path: "/restauracao/faturas", component: <RequireModule module="restauracao_faturas" permission="restauracao.ver"><FaturasPage /></RequireModule> },
    { path: "/restauracao/faturas/:id", component: <RequireModule module="restauracao_faturas" permission="restauracao.ver"><FaturaValidacaoPage /></RequireModule> },
    { path: "/restauracao/unidades", component: <RequireModule module="restauracao_unidades" permission="restauracao.ver"><UnidadesPage /></RequireModule> },
    { path: "/restauracao/condicoes-pagamento", component: <RequireModule module="restauracao_condicoes_pagamento" permission="restauracao.ver"><CondicoesPagamentoPage /></RequireModule> },
    { path: "/restauracao/conta-corrente", component: <RequireModule module="restauracao_conta_corrente" permission="restauracao.ver"><ContaCorrenteFornecedoresPage /></RequireModule> },
    { path: "/restauracao/conta-corrente/:supplierId", component: <RequireModule module="restauracao_conta_corrente" permission="restauracao.ver"><ContaCorrenteFornecedorPage /></RequireModule> },

    // Company
    { path: "/companies", component: <CompanyList /> },
    { path: "/companies/:id", component: <CompanyProfileUpdate /> },
    { path: "/companies/create", component: <CompanyProfileCreate /> },

    // Cars — módulo STOCK (guard de rota; o backend recusa 403 na mesma)
    { path: "/cars", component: <RequireModule module="stock" permission="automovel.ver"><CarList /></RequireModule> },
    { path: "/actions", component: <RequireModule module="stock" permission="automovel.ver"><ActionCenterPage /></RequireModule> },
    { path: "/cars/create", component: <RequireModule module="stock" permission="automovel.ver"><CarCreate /></RequireModule> },
    { path: "/cars/:id", component: <RequireModule module="stock" permission="automovel.ver"><CarUpdate /></RequireModule> },
    { path: "/cars/:id/analytics", component: <RequireModule module="stock" permission="automovel.ver"><CarAnalytics /></RequireModule> },
    { path: "/cars/:id/intelligence", component: <RequireModule module="stock" permission="automovel.ver"><CarIntelligencePage /></RequireModule> },
    { path: "/cars/:id/ads", component: <RequireModule module="stock" permission="automovel.ver"><CarAdsRedirect /></RequireModule> },
    { path: "/cars/:id/ficha", component: <RequireModule module="stock" permission="automovel.ver"><CarFichaPage /></RequireModule> },
    // DMS Fase 3 — tab Documentos (ficha A4 + documentos de venda).
    { path: "/cars/:id/documents", component: <RequireModule module="stock" permission="automovel.ver"><CarDocumentsPage /></RequireModule> },
    // Ficha de impressão A4 (2026-06-27) — rota própria com companyId no path
    // para simetria com os endpoints internos que scope por company (sec 11).
    { path: "/companies/:companyId/cars/:id/print-sheet", component: <RequireModule module="stock" permission="automovel.ver"><CarPrintSheet /></RequireModule> },
    // DMS Fase 3 — documentos de venda (mesma aba, motor de impressão partilhado).
    { path: "/companies/:companyId/cars/:id/documents/:docId", component: <RequireModule module="stock" permission="automovel.ver"><SaleDocumentPrint /></RequireModule> },
    { path: "/cars/:id/marketing", component: <RequireModule module="stock" permission="automovel.ver"><CarMarketingRedirect /></RequireModule> },

    // Leads — módulo COMERCIAL/CRM
    { path: "/leads", component: <RequireModule module="commercial_crm" permission="automovel.ver"><LeadList /></RequireModule> },

    // Stock & Promoção — módulo COMERCIAL/CRM
    { path: "/stock/promotion", component: <RequireModule module="commercial_crm" permission="automovel.ver"><StockPromotionPage /></RequireModule> },

    // A antiga "Monitorização de stock" foi absorvida pelo separador "Stock" do
    // dashboard. A rota fica só para não partir links e favoritos antigos.
    { path: "/stock/monitoring", component: <Navigate to="/dashboard?tab=stock" replace /> },

    // Orçamentos (lado stand)
    { path: "/quotes", component: <RequireModule permission="suporte.ver"><CompanyQuotesList /></RequireModule> },

    // Users
    // Colaboradores (equipa) e departamentos. /users/:id continua a ser a conta do utilizador (perfil).
    { path: "/users", component: <RequireModule permission="utilizadores.ver"><CollaboratorsList /></RequireModule> },
    { path: "/users/create", component: <RequireModule permission="utilizadores.criar"><CollaboratorEditor /></RequireModule> },
    { path: "/users/collaborators/new", component: <RequireModule permission="utilizadores.criar"><CollaboratorEditor /></RequireModule> },
    { path: "/users/collaborators/:id", component: <RequireModule permission="utilizadores.ver"><CollaboratorEditor /></RequireModule> },
    { path: "/users/:id", component: <UserUpdate /> },
    // Perfil da Marca (página própria; antes era um modal no Blog).
    { path: "/brand-profile", component: <RequireModule permission="marca.ver"><BrandProfilePage /></RequireModule> },

    // Blogs
    { path: "/blogs", component: <RequireModule permission="blog.ver"><BlogList /></RequireModule> },
    { path: "/blogs/create", component: <RequireModule permission="blog.criar"><BlogEditor /></RequireModule> },
    { path: "/blogs/:id", component: <RequireModule permission="blog.ver"><BlogEditor /></RequireModule> },
    { path: "/blogs/:id/show", component: <RequireModule permission="blog.ver"><BlogShow /></RequireModule> },

    // Suppliers — módulo FINANÇAS
    { path: "/suppliers", component: <RequireModule module="finance" permission="financas.ver"><SupplierList /></RequireModule> },

    // Customers — módulo FINANÇAS
    { path: "/customers", component: <RequireModule module="finance" permission="financas.ver"><CustomerList /></RequireModule> },
    { path: "/customers/:id", component: <RequireModule module="finance" permission="financas.ver"><CustomerHub /></RequireModule> },

    // Document templates — módulo DOCUMENTOS
    { path: "/document-templates", component: <RequireModule module="documents" permission="automovel.ver"><DocumentTemplatesList /></RequireModule> },

    // Expense categories — módulo FINANÇAS
    { path: "/expense-categories", component: <RequireModule module="finance" permission="financas.ver"><ExpenseCategoryList /></RequireModule> },

    // Expenses — módulo FINANÇAS
    { path: "/expenses", component: <RequireModule module="finance" permission="financas.ver"><ExpenseList /></RequireModule> },

    // Internal tools
    // Suporte (lado stand) — tickets da própria empresa.
    { path: "/support", component: <RequireModule permission="suporte.ver"><SupportTicketsList /></RequireModule> },
    // Orçamentos-em-tickets do STAND (site_change): ver, somar, aprovar pacote.
    { path: "/orcamentos", component: <RequireModule permission="suporte.ver"><OrcamentosStand /></RequireModule> },
    { path: "/support/:id", component: <RequireModule permission="suporte.ver"><SupportTicketDetail /></RequireModule> },

    // Tarefas — Kanban interno da equipa (partilhado por company_id).
    { path: "/tasks", component: <RequireModule module="support_tasks" permission="tarefas.ver"><CompanyTasksKanban /></RequireModule> },
    // Detalhe de uma tarefa (visual TaskDetails do template).
    { path: "/tasks/:id", component: <RequireModule module="support_tasks" permission="tarefas.ver"><CompanyTaskDetails /></RequireModule> },

    // Tráfego do site — dados GA4 da propriedade do cliente (scoped por company_id).
    { path: "/trafego-site", component: <RequireModule module="marketing_analytics" permission="resultados.ver"><WebsiteTraffic /></RequireModule> },
    // Meta / Anúncios — dados factuais que o pipeline já ingere (scoped por company_id).
    { path: "/meta-ads", component: <RequireModule module="marketing_analytics" permission="resultados.ver"><MetaAds /></RequireModule> },

    // Consola de administração — SÓ root (segurança real no backend).
    // Primeira consola: tickets de suporte de todas as empresas.
    { path: "/admin", component: <RequireSuperAdmin><AdminTicketsList /></RequireSuperAdmin> },
    { path: "/admin/tickets/:id", component: <RequireSuperAdmin><AdminTicketDetail /></RequireSuperAdmin> },
    // 2ª consola da área /admin — gestão comercial (orçamentos avulsos).
    { path: "/admin/quotes", component: <RequireSuperAdmin><AdminQuotesList /></RequireSuperAdmin> },
    // Cobranças da XPLENDOR (todas as empresas).
    { path: "/admin/charges", component: <RequireSuperAdmin><AdminChargesPage /></RequireSuperAdmin> },
    { path: "/admin/quotes/new", component: <RequireSuperAdmin><QuoteEditor /></RequireSuperAdmin> },
    { path: "/admin/quotes/:id", component: <RequireSuperAdmin><QuoteEditor /></RequireSuperAdmin> },
    { path: "/admin/service-catalog", component: <RequireSuperAdmin><ServiceCatalogPage /></RequireSuperAdmin> },
    // 3ª consola — 1ª vista de DADOS transversais: stock global (todas as empresas ativas).
    { path: "/admin/stock", component: <RequireSuperAdmin><AdminStockList /></RequireSuperAdmin> },
    // Regras de formato (referência de mercado) do "Sugerir criativo": só o root.
    { path: "/admin/creative-format-rules", component: <RequireSuperAdmin><CreativeFormatRulesPage /></RequireSuperAdmin> },
    { path: "/admin/ai-models", component: <RequireSuperAdmin><AiModelsPage /></RequireSuperAdmin> },

    // this route should be at the end of all other routes
    // eslint-disable-next-line react/display-name
    {
        path: "/dashboard",
        exact: true,
        component: <Navigate to="/dashboard" />,
    },
    { path: "*", component: <Navigate to="/dashboard" /> },
];

const publicRoutes = [
    // Authentication Page
    { path: "/logout", component: <Logout /> },
    { path: "/login", component: <Login /> },
    // Pedido de gestão por uma agência: página só com o pedido (funciona sem acesso à plataforma; pede sessão).
    { path: "/pedido-gestao/:companyId", component: <ManagementRequestPage /> },
    { path: "/forgot-password", component: <ForgetPasswordPage /> },
    { path: "/register", component: <Register /> },

    { path: "/oauth/meta/callback", component: <MetaOAuthCallback /> },

    // ⚠️ A landing passou para o Rayo (Next.js) servido na RAIZ pelo nginx; a app CRA vive
    //    em /app. As landings antigas do CRA (LandingX/LandingMotorhomes) ficam ÓRFÃS
    //    (código mantido, fora do acesso) — o Rayo substitui-as na raiz.

    // Privacy Policy
    { path: "/privacy", component: <PrivacyPolicy /> },

    // PWA — instruções/botão de instalação (aberto a todos, também antes do login)
    { path: "/install", component: <InstallApp /> },

    // Pós-venda — relatório público de satisfação (aberto pelo cliente por link)
    { path: "/r/:token", component: <SatisfactionReport /> },
    // Orçamento: link público de uma versão enviada (sem login, sem indexação).
    // O token vem no fragmento (#): /orcamento#<token>.
    { path: "/orcamento", component: <QuotePublicPage /> },
    // Aprovação de conteúdos por lote (sem conta, sem indexação). Token no fragmento: /aprovar#<token>.
    { path: "/aprovar", component: <ContentReviewPage /> },
    { path: "/configurar", component: <SetupPublicPage /> },
    // Fatura da XPLENDOR (sem conta, sem indexação): ver, descarregar e "Já paguei". Token no fragmento: /cobranca#<token>.
    { path: "/cobranca", component: <ChargePublicPage /> },
    // A mesma página vista pela equipa (não conta como abertura; mesma aba, para manter a sessão).
    { path: "/admin/quotes/:id/preview/:version", component: <RequireSuperAdmin><QuotePublicPreview /></RequireSuperAdmin> },
];

export { authProtectedRoutes, publicRoutes };
