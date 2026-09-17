import React from "react";
import { Navigate, useParams } from "react-router-dom";

// Dashboard
import Dashboard from "pages/Dashboards/Dashboard";

// login
import ForgetPasswordPage from "../pages/Authentication/ForgetPassword";
import Logout from "../pages/Authentication/Logout";
import Register from "../pages/Authentication/Register";
import Login from "../pages/Authentication/Login"

// Company
import CompanyList from "pages/Companies/CompanyList";
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
import UsersList from "pages/Users/UsersList";

// Landing
import Landing from "pages/Landing";
import LandingMotorhomes from "pages/LandingMotorhomes";
import PrivacyPolicy from "pages/Privacy";
// Pós-venda — relatório público de satisfação (sem auth)
import SatisfactionReport from "pages/SatisfactionReport";
import UserCreate from "pages/Users/User/UserCreate";
import UserUpdate from "pages/Users/User/UserUpdate";

// Suppliers (DMS 1c.1)
import SupplierList from "pages/Suppliers/SupplierList";
// Customers (DMS)
import CustomerList from "pages/Customers/CustomerList";
// Document templates (DMS Caminho B)
import DocumentTemplatesList from "pages/DocumentTemplates/DocumentTemplatesList";
// Expense categories (DMS 1c.2a)
import ExpenseCategoryList from "pages/ExpenseCategories/ExpenseCategoryList";
// Expenses (DMS 1c.2b)
import ExpenseList from "pages/Expenses/ExpenseList";
// Blogs
import BlogList from "pages/Blogs/BlogList";
import BlogCreate from "pages/Blogs/Blog/BlogCreate";
import BlogUpdate from "pages/Blogs/Blog/BlogUpdate";
import BlogShow from "pages/Blogs/Blog/BlogShow";

// Leads
import LeadList from "pages/Leads/LeadList";

// Stock & Promoção (Relatório A — flag manual de prioridade)
import StockPromotionPage from "pages/StockPromotion";

// Orçamentos (lado stand — a empresa vê/aprova os que a XPLENDOR lhe enviou)
import CompanyQuotesList from "pages/Quotes/CompanyQuotesList";

// Monitorização de stock (lado stand — junta métricas de stock já existentes)
import StockMonitoring from "pages/StockMonitoring";

// Internal tools
// Support tickets (lado stand)
import SupportTicketsList from "pages/Support/SupportTicketsList";
import SupportTicketDetail from "pages/Support/SupportTicketDetail";
// Tarefas internas do cliente (Kanban do stand).
import CompanyTasksKanban from "pages/Tasks/CompanyTasksKanban";
import CompanyTaskDetails from "pages/Tasks/CompanyTaskDetails";
// Tráfego do site do cliente (GA4).
import WebsiteTraffic from "pages/Analytics/WebsiteTraffic";
// Admin (super-admin / root) — consola transversal
import AdminTicketsList from "pages/Admin/AdminTicketsList";
import AdminTicketDetail from "pages/Admin/AdminTicketDetail";
import AdminQuotesList from "pages/Admin/AdminQuotesList";
import AdminStockList from "pages/Admin/AdminStockList";
import RequireSuperAdmin from "./RequireSuperAdmin";

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

    // Company
    { path: "/companies", component: <CompanyList /> },
    { path: "/companies/:id", component: <CompanyProfileUpdate /> },
    { path: "/companies/create", component: <CompanyProfileCreate /> },

    // Cars
    { path: "/cars", component: <CarList /> },
    { path: "/actions", component: <ActionCenterPage /> },
    { path: "/cars/create", component: <CarCreate /> },
    { path: "/cars/:id", component: <CarUpdate /> },
    { path: "/cars/:id/analytics", component: <CarAnalytics /> },
    { path: "/cars/:id/intelligence", component: <CarIntelligencePage /> },
    { path: "/cars/:id/ads", component: <CarAdsRedirect /> },
    { path: "/cars/:id/ficha", component: <CarFichaPage /> },
    // DMS Fase 3 — tab Documentos (ficha A4 + documentos de venda).
    { path: "/cars/:id/documents", component: <CarDocumentsPage /> },
    // Ficha de impressão A4 (2026-06-27) — rota própria com companyId no path
    // para simetria com os endpoints internos que scope por company (sec 11).
    { path: "/companies/:companyId/cars/:id/print-sheet", component: <CarPrintSheet /> },
    // DMS Fase 3 — documentos de venda (mesma aba, motor de impressão partilhado).
    { path: "/companies/:companyId/cars/:id/documents/:docId", component: <SaleDocumentPrint /> },
    { path: "/cars/:id/marketing", component: <CarMarketingRedirect /> },

    // Leads
    { path: "/leads", component: <LeadList /> },

    // Stock & Promoção
    { path: "/stock/promotion", component: <StockPromotionPage /> },

    // Monitorização de stock (métricas do stand)
    { path: "/stock/monitoring", component: <StockMonitoring /> },

    // Orçamentos (lado stand)
    { path: "/quotes", component: <CompanyQuotesList /> },

    // Users
    { path: "/users", component: <UsersList /> },
    { path: "/users/create", component: <UserCreate /> },
    { path: "/users/:id", component: <UserUpdate /> },

    // Blogs
    { path: "/blogs", component: <BlogList /> },
    { path: "/blogs/create", component: <BlogCreate /> },
    { path: "/blogs/:id", component: <BlogUpdate /> },
    { path: "/blogs/:id/show", component: <BlogShow /> },

    // Suppliers (DMS 1c.1)
    { path: "/suppliers", component: <SupplierList /> },

    // Customers (DMS)
    { path: "/customers", component: <CustomerList /> },

    // Document templates (DMS Caminho B)
    { path: "/document-templates", component: <DocumentTemplatesList /> },

    // Expense categories (DMS 1c.2a)
    { path: "/expense-categories", component: <ExpenseCategoryList /> },

    // Expenses (DMS 1c.2b)
    { path: "/expenses", component: <ExpenseList /> },

    // Internal tools
    // Suporte (lado stand) — tickets da própria empresa.
    { path: "/support", component: <SupportTicketsList /> },
    { path: "/support/:id", component: <SupportTicketDetail /> },

    // Tarefas — Kanban interno da equipa (partilhado por company_id).
    { path: "/tasks", component: <CompanyTasksKanban /> },
    // Detalhe de uma tarefa (visual TaskDetails do template).
    { path: "/tasks/:id", component: <CompanyTaskDetails /> },

    // Tráfego do site — dados GA4 da propriedade do cliente (scoped por company_id).
    { path: "/trafego-site", component: <WebsiteTraffic /> },

    // Consola de administração — SÓ root (segurança real no backend).
    // Primeira consola: tickets de suporte de todas as empresas.
    { path: "/admin", component: <RequireSuperAdmin><AdminTicketsList /></RequireSuperAdmin> },
    { path: "/admin/tickets/:id", component: <RequireSuperAdmin><AdminTicketDetail /></RequireSuperAdmin> },
    // 2ª consola da área /admin — gestão comercial (orçamentos avulsos).
    { path: "/admin/quotes", component: <RequireSuperAdmin><AdminQuotesList /></RequireSuperAdmin> },
    // 3ª consola — 1ª vista de DADOS transversais: stock global (todas as empresas ativas).
    { path: "/admin/stock", component: <RequireSuperAdmin><AdminStockList /></RequireSuperAdmin> },

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

    // Landing Page
    { path: "/", component: <Landing /> },

    // Landing dedicada — stands de autocaravanas
    { path: "/autocaravanas", component: <LandingMotorhomes /> },

    // Privacy Policy
    { path: "/privacy", component: <PrivacyPolicy /> },

    // PWA — instruções/botão de instalação (aberto a todos, também antes do login)
    { path: "/install", component: <InstallApp /> },

    // Pós-venda — relatório público de satisfação (aberto pelo cliente por link)
    { path: "/r/:token", component: <SatisfactionReport /> },
];

export { authProtectedRoutes, publicRoutes };
