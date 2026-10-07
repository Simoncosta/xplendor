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
import EditorialCalendarPage from "pages/Editorial/EditorialCalendarPage";
import FamiliasPage from "pages/Restauracao/FamiliasPage";
import FornecedoresPage from "pages/Restauracao/FornecedoresPage";
import FaturasPage from "pages/Restauracao/FaturasPage";
import FaturaValidacaoPage from "pages/Restauracao/FaturaValidacaoPage";
import UnidadesPage from "pages/Restauracao/UnidadesPage";
import CondicoesPagamentoPage from "pages/Restauracao/CondicoesPagamentoPage";

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
    // ROOT — empresas → utilizadores → "entrar como" (impersonation). Root-only na UI; a
    // segurança real é o backend (/admin gated). As páginas recusam não-root na mesma.
    { path: "/root/companies", component: <AdminCompaniesPage /> },
    { path: "/root/companies/:companyId/users", component: <AdminCompanyUsersPage /> },
    // Dashboard de restauração — só empresas com o módulo pingwin (backend recusa na mesma).
    { path: "/restauracao", component: <RequireModule module="pingwin"><PingwinDashboard /></RequireModule> },
    { path: "/restauracao/lojas", component: <RequireModule module="restauracao_lojas"><LojasPage /></RequireModule> },
    { path: "/restauracao/calendario", component: <RequireModule module="restauracao_calendario"><CalendarioPage /></RequireModule> },
    { path: "/restauracao/documentos", component: <RequireModule module="restauracao_documentos"><DocumentosPage /></RequireModule> },
    { path: "/restauracao/artigos", component: <RequireModule module="restauracao_artigos"><ArtigosPage /></RequireModule> },
    // Linha Editorial (transversal)
    { path: "/editorial", component: <RequireModule module="linha_editorial"><EditorialCalendarPage /></RequireModule> },
    // Link de aprovação visto pela equipa ("Ver como o cliente"): sem ações e sem contar como abertura.
    { path: "/editorial/aprovacao/:id/ver", component: <RequireModule module="linha_editorial"><ContentReviewPreview /></RequireModule> },
    { path: "/restauracao/artigos/novo", component: <RequireModule module="restauracao_artigos"><ArtigoFormPage /></RequireModule> },
    { path: "/restauracao/artigos/:pingwinId", component: <RequireModule module="restauracao_artigos"><ArtigoFormPage /></RequireModule> },
    { path: "/restauracao/familias", component: <RequireModule module="restauracao_familias"><FamiliasPage /></RequireModule> },
    { path: "/restauracao/fornecedores", component: <RequireModule module="restauracao_fornecedores"><FornecedoresPage /></RequireModule> },
    { path: "/restauracao/faturas", component: <RequireModule module="restauracao_faturas"><FaturasPage /></RequireModule> },
    { path: "/restauracao/faturas/:id", component: <RequireModule module="restauracao_faturas"><FaturaValidacaoPage /></RequireModule> },
    { path: "/restauracao/unidades", component: <RequireModule module="restauracao_unidades"><UnidadesPage /></RequireModule> },
    { path: "/restauracao/condicoes-pagamento", component: <RequireModule module="restauracao_condicoes_pagamento"><CondicoesPagamentoPage /></RequireModule> },

    // Company
    { path: "/companies", component: <CompanyList /> },
    { path: "/companies/:id", component: <CompanyProfileUpdate /> },
    { path: "/companies/create", component: <CompanyProfileCreate /> },

    // Cars — módulo STOCK (guard de rota; o backend recusa 403 na mesma)
    { path: "/cars", component: <RequireModule module="stock"><CarList /></RequireModule> },
    { path: "/actions", component: <RequireModule module="stock"><ActionCenterPage /></RequireModule> },
    { path: "/cars/create", component: <RequireModule module="stock"><CarCreate /></RequireModule> },
    { path: "/cars/:id", component: <RequireModule module="stock"><CarUpdate /></RequireModule> },
    { path: "/cars/:id/analytics", component: <RequireModule module="stock"><CarAnalytics /></RequireModule> },
    { path: "/cars/:id/intelligence", component: <RequireModule module="stock"><CarIntelligencePage /></RequireModule> },
    { path: "/cars/:id/ads", component: <RequireModule module="stock"><CarAdsRedirect /></RequireModule> },
    { path: "/cars/:id/ficha", component: <RequireModule module="stock"><CarFichaPage /></RequireModule> },
    // DMS Fase 3 — tab Documentos (ficha A4 + documentos de venda).
    { path: "/cars/:id/documents", component: <RequireModule module="stock"><CarDocumentsPage /></RequireModule> },
    // Ficha de impressão A4 (2026-06-27) — rota própria com companyId no path
    // para simetria com os endpoints internos que scope por company (sec 11).
    { path: "/companies/:companyId/cars/:id/print-sheet", component: <RequireModule module="stock"><CarPrintSheet /></RequireModule> },
    // DMS Fase 3 — documentos de venda (mesma aba, motor de impressão partilhado).
    { path: "/companies/:companyId/cars/:id/documents/:docId", component: <RequireModule module="stock"><SaleDocumentPrint /></RequireModule> },
    { path: "/cars/:id/marketing", component: <RequireModule module="stock"><CarMarketingRedirect /></RequireModule> },

    // Leads — módulo COMERCIAL/CRM
    { path: "/leads", component: <RequireModule module="commercial_crm"><LeadList /></RequireModule> },

    // Stock & Promoção — módulo COMERCIAL/CRM
    { path: "/stock/promotion", component: <RequireModule module="commercial_crm"><StockPromotionPage /></RequireModule> },

    // A antiga "Monitorização de stock" foi absorvida pelo separador "Stock" do
    // dashboard. A rota fica só para não partir links e favoritos antigos.
    { path: "/stock/monitoring", component: <Navigate to="/dashboard?tab=stock" replace /> },

    // Orçamentos (lado stand)
    { path: "/quotes", component: <CompanyQuotesList /> },

    // Users
    // Colaboradores (equipa) e departamentos. /users/:id continua a ser a conta do utilizador (perfil).
    { path: "/users", component: <CollaboratorsList /> },
    { path: "/users/create", component: <CollaboratorEditor /> },
    { path: "/users/collaborators/new", component: <CollaboratorEditor /> },
    { path: "/users/collaborators/:id", component: <CollaboratorEditor /> },
    { path: "/users/:id", component: <UserUpdate /> },
    // Perfil da Marca (página própria; antes era um modal no Blog).
    { path: "/brand-profile", component: <BrandProfilePage /> },

    // Blogs
    { path: "/blogs", component: <BlogList /> },
    { path: "/blogs/create", component: <BlogEditor /> },
    { path: "/blogs/:id", component: <BlogEditor /> },
    { path: "/blogs/:id/show", component: <BlogShow /> },

    // Suppliers — módulo FINANÇAS
    { path: "/suppliers", component: <RequireModule module="finance"><SupplierList /></RequireModule> },

    // Customers — módulo FINANÇAS
    { path: "/customers", component: <RequireModule module="finance"><CustomerList /></RequireModule> },
    { path: "/customers/:id", component: <RequireModule module="finance"><CustomerHub /></RequireModule> },

    // Document templates — módulo DOCUMENTOS
    { path: "/document-templates", component: <RequireModule module="documents"><DocumentTemplatesList /></RequireModule> },

    // Expense categories — módulo FINANÇAS
    { path: "/expense-categories", component: <RequireModule module="finance"><ExpenseCategoryList /></RequireModule> },

    // Expenses — módulo FINANÇAS
    { path: "/expenses", component: <RequireModule module="finance"><ExpenseList /></RequireModule> },

    // Internal tools
    // Suporte (lado stand) — tickets da própria empresa.
    { path: "/support", component: <SupportTicketsList /> },
    // Orçamentos-em-tickets do STAND (site_change): ver, somar, aprovar pacote.
    { path: "/orcamentos", component: <OrcamentosStand /> },
    { path: "/support/:id", component: <SupportTicketDetail /> },

    // Tarefas — Kanban interno da equipa (partilhado por company_id).
    { path: "/tasks", component: <CompanyTasksKanban /> },
    // Detalhe de uma tarefa (visual TaskDetails do template).
    { path: "/tasks/:id", component: <CompanyTaskDetails /> },

    // Tráfego do site — dados GA4 da propriedade do cliente (scoped por company_id).
    { path: "/trafego-site", component: <WebsiteTraffic /> },
    // Meta / Anúncios — dados factuais que o pipeline já ingere (scoped por company_id).
    { path: "/meta-ads", component: <MetaAds /> },

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
    // Fatura da XPLENDOR (sem conta, sem indexação): ver, descarregar e "Já paguei". Token no fragmento: /cobranca#<token>.
    { path: "/cobranca", component: <ChargePublicPage /> },
    // A mesma página vista pela equipa (não conta como abertura; mesma aba, para manter a sessão).
    { path: "/admin/quotes/:id/preview/:version", component: <RequireSuperAdmin><QuotePublicPreview /></RequireSuperAdmin> },
];

export { authProtectedRoutes, publicRoutes };
