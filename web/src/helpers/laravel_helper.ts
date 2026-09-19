import { APIClient } from "./api_helper";

import * as url from "./url_helper";

const api = new APIClient();

export interface ApiResponse<T> {
    success: boolean;
    message: string;
    data: T;
}

// Gets the logged in user data from local session
export const getLoggedInUser = () => {
    const user = localStorage.getItem("user");
    if (user) return JSON.parse(user);
    return null;
};

// is user is logged in
export const isUserAuthenticated = () => {
    return getLoggedInUser() !== null;
};

// Login Method
export const postApiLogin = (data: any) => api.create(url.POST_FAKE_API_LOGIN, data);
// Logout Method
export const postApiLogout = (data: any) => api.create(url.POST_FAKE_API_LOGOUT, data);
// User By Invite
export const getUserByInvite = (token: string) => api.get(url.GET_USER_BY_INVITE + token);
// Register By Invite
export const postRegisterByInvite = (data: any) => api.create(url.POST_REGISTER_BY_INVITE, data);

// COMPANIES
export const getCompaniesPaginate = (params: { perPage: number; page: number; }) => api.get(url.GET_COMPANIES, params);
export const showCompany = (params: { id: number }) => api.get(url.GET_COMPANIES + "/" + params.id);
export const createCompany = (data: FormData | any) => api.create(url.GET_COMPANIES, data);
export const updateCompany = (id: number, data: FormData | any) => api.create(url.GET_COMPANIES + "/" + id, data, { headers: { "Content-Type": "multipart/form-data" } });
export const getCompanyDecisionsApi = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_DECISIONS);
export const getCompanyAlertsApi = (companyId: number, params?: { unread_only?: boolean; limit?: number }) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_ALERTS, params);
export const getCompanyAlertsUnreadCountApi = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_ALERTS_UNREAD_COUNT);
export const markCompanyAlertsReadApi = (companyId: number, data?: { ids?: number[] }) =>
    api.update(url.GET_COMPANIES + `/${companyId}` + url.PATCH_ALERTS_READ, data ?? {});
export const markCompanyAlertReadApi = (companyId: number, alertId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_ALERTS + `/${alertId}` + url.POST_ALERT_READ, {});

// DASHBOARDS
export const getAnalyticsDashboard = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_DASHBOARD_APIS);

// Visões 1+2 (2026-06-25) — stock por marca + tipo. Endpoint próprio, não
// inflar o blob do getAnalyticsDashboard.
export const getDashboardStockBreakdown = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_DASHBOARD_APIS + "/stock-breakdown");

// Ficha de impressão A4 (2026-06-27) — endpoint próprio.
// DMS Fase 2A — margem simples da viatura vendida.
export const getCarMargin = (companyId: number, carId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}/margin`);

// DMS Fase 3 — dados para os documentos de venda (empresa + viatura + cliente).
export const getSaleDocumentData = (companyId: number, carId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}/sale-document-data`);

export const getCarPrintSheet = (companyId: number, carId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}/print-sheet`);

// Visão 3 (2026-06-25) — FATURAÇÃO (NÃO é lucro) por período.
// `api.get` aceita um objecto plano que é serializado como query string.
export const getDashboardSalesRevenue = (
    companyId: number,
    params: { from: string; to: string; granularity?: "month" | "year" },
) =>
    api.get(
        url.GET_COMPANIES + `/${companyId}` + url.GET_DASHBOARD_APIS + "/sales-revenue",
        params,
    );

// META ADS
export const getCompanyIntegrationsApi = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_INTEGRATIONS);
export const getMetaOAuthUrlApi = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_META_INTEGRATIONS + url.GET_META_OAUTH_URL);
export const connectMetaAdsApi = (data: { code: string; state: string; account_id: string; }) =>
    api.create(url.GET_META_INTEGRATIONS + url.POST_META_CALLBACK, data, {
        headers: { "Content-Type": "application/json" }
    });
export const disconnectMetaAdsApi = (companyId: number, platform: string) =>
    api.delete(url.GET_COMPANIES + `/${companyId}` + url.GET_INTEGRATIONS + `/${platform}`);
export const getMetaAdsetsApi = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_META_INTEGRATIONS + url.GET_META_ADSETS);
export const getCarAdCampaignsApi = (companyId: number, carId: number | string) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}` + url.GET_CAR_AD_CAMPAIGNS);
export const getCarAdCampaignActiveTargetsApi = (companyId: number, carId: number | string) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}` + url.GET_CAR_AD_CAMPAIGN_ACTIVE_TARGETS);
export const storeCarAdCampaignApi = (
    companyId: number,
    carId: number | string,
    data: {
        platform: string;
        campaign_id: string;
        campaign_name?: string;
        adset_id?: string | null;
        adset_name?: string | null;
        ad_id?: string | null;
        ad_name?: string | null;
        level: string;
        spend_split_pct: number;
    }
) =>
    api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}` + url.GET_CAR_AD_CAMPAIGNS, data, {
        headers: { "Content-Type": "application/json" }
    });
export const deleteCarAdCampaignApi = (companyId: number, carId: number | string, id: number) =>
    api.delete(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}` + url.GET_CAR_AD_CAMPAIGNS + `/${id}`);
export const toggleCarAdCampaignApi = (companyId: number, carId: number | string, id: number) =>
    api.update(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}` + url.GET_CAR_AD_CAMPAIGNS + `/${id}/toggle`, {});

// BLOGS
export const getBlogs = (params: { perPage: number; page: number; companyId: number; }) => api.get(url.GET_COMPANIES + `/${params.companyId}` + url.GET_BLOGS_APIS, { params });
export const showBlog = (params: { companyId: number; id: number; }) => api.get(url.GET_COMPANIES + `/${params.companyId}` + url.GET_BLOGS_APIS + "/" + params.id);
export const createBlog = (companyId: number, data: FormData | any) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_BLOGS_APIS, data, { headers: { "Content-Type": "multipart/form-data" } });
export const updateBlog = (companyId: number, id: number, data: FormData | any) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_BLOGS_APIS + "/" + id, data, { headers: { "Content-Type": "multipart/form-data" } });
export const deleteBlog = (companyId: number, id: number) => api.delete(url.GET_COMPANIES + `/${companyId}` + url.GET_BLOGS_APIS + "/" + id);

// SUPPLIERS (DMS 1c.1)
export const getSuppliers = (companyId: number, params?: { perPage?: number; page?: number; search?: string; only_active?: number }) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_SUPPLIERS, params);
export const showSupplier = (companyId: number, id: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_SUPPLIERS + `/${id}`);
export const createSupplier = (companyId: number, data: any) =>
    api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_SUPPLIERS, data);
export const updateSupplier = (companyId: number, id: number, data: any) =>
    api.update(url.GET_COMPANIES + `/${companyId}` + url.GET_SUPPLIERS + `/${id}`, data);
export const deleteSupplier = (companyId: number, id: number) =>
    api.delete(url.GET_COMPANIES + `/${companyId}` + url.GET_SUPPLIERS + `/${id}`);

// CUSTOMERS (DMS — clientes). Create/update em JSON (evita o multipart default
// que converteria booleanos como contact_consent em "true"/"false").
export const getCustomers = (companyId: number, params?: { perPage?: number; page?: number; search?: string; only_active?: number }) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_CUSTOMERS, params);
export const showCustomer = (companyId: number, id: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_CUSTOMERS + `/${id}`);
// Fase 3 — ficha-hub do cliente (vendas + leads + docs + histórico).
export const getCustomerHub = (companyId: number, id: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_CUSTOMERS + `/${id}/hub`);
export const createCustomer = (companyId: number, data: any) =>
    api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CUSTOMERS, data, { headers: { "Content-Type": "application/json" } });
export const updateCustomer = (companyId: number, id: number, data: any) =>
    api.update(url.GET_COMPANIES + `/${companyId}` + url.GET_CUSTOMERS + `/${id}`, data);
export const deleteCustomer = (companyId: number, id: number) =>
    api.delete(url.GET_COMPANIES + `/${companyId}` + url.GET_CUSTOMERS + `/${id}`);

// DMS Caminho B — modelos de documento .docx
export const getDocumentTemplates = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_DOCUMENT_TEMPLATES);
export const getDocumentTemplateVariables = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_DOCUMENT_TEMPLATES + `/variables`);
export const createDocumentTemplate = (companyId: number, data: FormData) =>
    api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_DOCUMENT_TEMPLATES, data, { headers: { "Content-Type": "multipart/form-data" } });
export const replaceDocumentTemplateFile = (companyId: number, id: number, data: FormData) =>
    api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_DOCUMENT_TEMPLATES + `/${id}/replace`, data, { headers: { "Content-Type": "multipart/form-data" } });
export const updateDocumentTemplate = (companyId: number, id: number, data: any) =>
    api.update(url.GET_COMPANIES + `/${companyId}` + url.GET_DOCUMENT_TEMPLATES + `/${id}`, data);
export const deleteDocumentTemplate = (companyId: number, id: number) =>
    api.delete(url.GET_COMPANIES + `/${companyId}` + url.GET_DOCUMENT_TEMPLATES + `/${id}`);

// DMS — Consola de administração (super-admin). Acesso transversal atrás do
// portão ensure_super_admin no backend.
export const getAdminPing = () => api.get(url.GET_ADMIN + `/ping`);

// DMS — Tickets de suporte, LADO ADMIN (transversal, só root, atrás do portão).
export const getAdminTickets = (params?: { status?: string; company_id?: number; type?: string }) =>
    api.get(url.GET_ADMIN + `/tickets`, params);
export const getAdminTicketsSummary = () => api.get(url.GET_ADMIN + `/tickets/summary`);
export const showAdminTicket = (id: number) => api.get(url.GET_ADMIN + `/tickets/${id}`);
export const updateAdminTicketStatus = (id: number, status: string) =>
    api.update(url.GET_ADMIN + `/tickets/${id}/status`, { status });
export const reclassifyAdminTicketType = (id: number, type: string) =>
    api.update(url.GET_ADMIN + `/tickets/${id}/type`, { type });
export const addAdminTicketMessage = (id: number, body: string) =>
    api.create(url.GET_ADMIN + `/tickets/${id}/messages`, { body }, { headers: { "Content-Type": "application/json" } });

// DMS — Alteração ao site (pago): ações de ADMIN sobre o orçamento.
export const setAdminTicketQuote = (id: number, estimatedHours: number) =>
    api.update(url.GET_ADMIN + `/tickets/${id}/quote`, { estimated_hours: estimatedHours });
export const markAdminTicketPaid = (id: number, data: FormData) =>
    api.create(url.GET_ADMIN + `/tickets/${id}/mark-paid`, data, { headers: { "Content-Type": "multipart/form-data" } });
export const markAdminTicketCompleted = (id: number) =>
    api.update(url.GET_ADMIN + `/tickets/${id}/complete`, {});

// XPLENDOR — Orçamentos avulsos (gestão comercial, /admin, só root).
export const getAdminQuotes = (params?: { status?: string; search?: string }) =>
    api.get(url.GET_ADMIN + `/quotes`, params);
export const getAdminQuotesSummary = () => api.get(url.GET_ADMIN + `/quotes/summary`);
export const showAdminQuote = (id: number) => api.get(url.GET_ADMIN + `/quotes/${id}`);
export const createAdminQuote = (data: any) => api.create(url.GET_ADMIN + `/quotes`, data);
export const updateAdminQuote = (id: number, data: any) => api.update(url.GET_ADMIN + `/quotes/${id}`, data);
export const updateAdminQuoteStatus = (id: number, status: string) =>
    api.update(url.GET_ADMIN + `/quotes/${id}/status`, { status });
export const deleteAdminQuote = (id: number) => api.delete(url.GET_ADMIN + `/quotes/${id}`);
export const getAdminQuoteCompanies = () => api.get(url.GET_ADMIN + `/quotes/companies`);
export const markAdminQuotePaid = (id: number) => api.update(url.GET_ADMIN + `/quotes/${id}/mark-paid`, {});
export const markAdminQuoteCompleted = (id: number) => api.update(url.GET_ADMIN + `/quotes/${id}/complete`, {});

// XPLENDOR — Orçamentos no painel do STAND (empresa vê/aprova os seus).
export const getCompanyQuotes = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/quotes`);
export const decideCompanyQuote = (companyId: number, id: number, decision: "approve" | "reject") =>
    api.update(url.GET_COMPANIES + `/${companyId}/quotes/${id}/decision`, { decision });

// XPLENDOR — Tarefas internas do cliente (Kanban do stand). Partilhadas por
// company_id; toda a equipa vê/edita. Colunas fixas todo|doing|done.
export const getCompanyTasks = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/tasks`);
export const createCompanyTask = (companyId: number, data: { title: string; description?: string; status?: string; assignee_user_id?: number | null }) =>
    api.create(url.GET_COMPANIES + `/${companyId}/tasks`, data);
export const updateCompanyTask = (companyId: number, id: number, data: { title?: string; description?: string; assignee_user_id?: number | null }) =>
    api.update(url.GET_COMPANIES + `/${companyId}/tasks/${id}`, data);
export const moveCompanyTask = (companyId: number, id: number, status: string, orderedIds: number[]) =>
    api.update(url.GET_COMPANIES + `/${companyId}/tasks/${id}/move`, { status, ordered_ids: orderedIds });
export const deleteCompanyTask = (companyId: number, id: number) =>
    api.delete(url.GET_COMPANIES + `/${companyId}/tasks/${id}`);
// Utilizadores da empresa (fonte do responsável/assignee). Sem perPage → lista toda.
export const getCompanyUsers = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_USERS_APIS);

// XPLENDOR — GA4 (tráfego do site do cliente). Service Account no servidor;
// property_id por empresa. Scoped por company_id.
export const connectGoogleAnalytics = (companyId: number, propertyId: string) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/google/connect`, { property_id: propertyId });
export const disconnectGoogleAnalytics = (companyId: number) =>
    api.delete(url.GET_COMPANIES + `/${companyId}/integrations/google`);
export const getGa4Traffic = (companyId: number, days = 28, fresh = false) =>
    api.get(url.GET_COMPANIES + `/${companyId}/analytics/ga4/traffic`, fresh ? { days, fresh: 1 } : { days });
// Meta — leitura dos dados que o pipeline já ingere (gasto/cliques/CTR + atribuição).
export const getMetaOverview = (companyId: number, days = 28) =>
    api.get(url.GET_COMPANIES + `/${companyId}/analytics/meta/overview`, { days });
// Módulos ATIVOS da empresa do utilizador (Fase 2 — esconder secções no menu).
export const getMyModules = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/my-modules`);

// XPLENDOR — PingWin (POS restauração). A senha é cifrada no backend e NUNCA
// devolvida; o connect valida a ligação (login+logout) antes de gravar. Gated
// por ensure_module:pingwin (403 se a empresa não tem o módulo). Scoped por company.
export const getPingwin = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin`);
export const connectPingwin = (companyId: number, data: Record<string, any>) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/connect`, data);
export const syncPingwin = (companyId: number, date?: string) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/sync`, date ? { date } : {});
// Gatilho do dashboard: mete a sincronização na FILA (não trava; notifica no sino).
export const queuePingwinSync = (companyId: number, date?: string) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/sync-queue`, date ? { date } : {});
// Dashboard de restauração (cards anual/mensal/diário + lojas).
export const getPingwinDashboard = (companyId: number, date?: string) =>
    api.get(url.GET_COMPANIES + `/${companyId}/analytics/pingwin/dashboard`, date ? { date } : undefined);
// CoverManager (reservas) — Etapa 1: sincroniza o agregado por turno de uma data.
export const syncCoverManager = (companyId: number, date: string) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/covermanager/sync`, { date });
// CoverManager — token AO NÍVEL DA EMPRESA (Integrações). O token nunca é devolvido.
export const getCoverManager = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/covermanager`);
export const connectCoverManager = (companyId: number, token: string) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/covermanager/connect`, { token });
export const disconnectCoverManager = (companyId: number) =>
    api.delete(url.GET_COMPANIES + `/${companyId}/integrations/covermanager`);
// CoverManager — Etapa 2: flag do ticket médio (por empresa).
export const getCoverManagerSettings = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/covermanager/settings`);
export const updateCoverManagerSettings = (companyId: number, avgTicketEnabled: boolean) =>
    api.update(url.GET_COMPANIES + `/${companyId}/integrations/covermanager/settings`, { avg_ticket_enabled: avgTicketEnabled ? 1 : 0 });
// Cadastro manual de lojas PingWin (winrest_store_id → "Stores" do relatório).
export const getPingwinLocations = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/locations`);
export const createPingwinLocation = (companyId: number, data: Record<string, any>) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/locations`, data);
export const updatePingwinLocation = (companyId: number, locationId: number, data: Record<string, any>) =>
    api.update(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/locations/${locationId}`, data);
export const deletePingwinLocation = (companyId: number, locationId: number) =>
    api.delete(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/locations/${locationId}`);

// XPLENDOR — Stock GLOBAL (transversal, /admin, só root).
export const getAdminStock = (params?: {
    company_id?: number; status?: string; car_brand_id?: string;
    vehicle_type?: string; search?: string; page?: number; per_page?: number;
}) => api.get(url.GET_ADMIN + `/stock`, params);
export const getAdminStockSummary = () => api.get(url.GET_ADMIN + `/stock/summary`);
export const getAdminStockCompanies = () => api.get(url.GET_ADMIN + `/stock/companies`);

// XPLENDOR — Ativar/inativar empresa (root). Inativar tira acesso + exclui do stock.
export const setAdminCompanyStatus = (id: number, active: boolean) =>
    api.update(url.GET_ADMIN + `/companies/${id}/status`, { active });

// XPLENDOR — Módulos por empresa (super-admin). Ligar/desligar + presets de ramo.
export const getCompanyModules = (companyId: number) =>
    api.get(url.GET_ADMIN + `/companies/${companyId}/modules`);
export const setCompanyModule = (companyId: number, moduleKey: string, enabled: boolean) =>
    api.update(url.GET_ADMIN + `/companies/${companyId}/modules`, { module_key: moduleKey, enabled });
export const applyCompanyModulePreset = (companyId: number, preset: string) =>
    api.create(url.GET_ADMIN + `/companies/${companyId}/modules/preset`, { preset });

// DMS — Tickets de suporte (lado stand). Create pode ser multipart (bug + print).
export const getSupportTickets = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_SUPPORT_TICKETS);
export const showSupportTicket = (companyId: number, id: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_SUPPORT_TICKETS + `/${id}`);
export const createSupportTicket = (companyId: number, data: FormData) =>
    api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_SUPPORT_TICKETS, data, { headers: { "Content-Type": "multipart/form-data" } });
export const addSupportTicketMessage = (companyId: number, id: number, body: string) =>
    api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_SUPPORT_TICKETS + `/${id}/messages`, { body }, { headers: { "Content-Type": "application/json" } });

// DMS — Alteração ao site (pago): o STAND aprova/rejeita o orçamento.
export const decideSupportTicketQuote = (companyId: number, id: number, decision: "approve" | "reject") =>
    api.update(url.GET_COMPANIES + `/${companyId}` + url.GET_SUPPORT_TICKETS + `/${id}/quote-decision`, { decision });

// EXPENSE CATEGORIES (DMS 1c.2a)
export const getExpenseCategories = (companyId: number, params?: { only_active?: number }) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_EXPENSE_CATEGORIES, params);
export const createExpenseCategory = (companyId: number, data: any) =>
    api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_EXPENSE_CATEGORIES, data);
export const updateExpenseCategory = (companyId: number, id: number, data: any) =>
    api.update(url.GET_COMPANIES + `/${companyId}` + url.GET_EXPENSE_CATEGORIES + `/${id}`, data);
export const deleteExpenseCategory = (companyId: number, id: number) =>
    api.delete(url.GET_COMPANIES + `/${companyId}` + url.GET_EXPENSE_CATEGORIES + `/${id}`);
export const importSuggestedExpenseCategories = (companyId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}` + url.POST_EXPENSE_CATEGORIES_IMPORT, {});
export const getSuggestedExpenseCategories = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_EXPENSE_CATEGORIES_SUGGESTED);

// EXPENSES (DMS 1c.2b)
export const getExpenses = (companyId: number, params?: Record<string, any>) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_EXPENSES, params);
export const getExpensesSummary = (companyId: number, params?: Record<string, any>) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_EXPENSES_SUMMARY, params);
// JSON explícito: o axios default de POST é multipart/form-data (api_helper),
// que converte booleanos em "true"/"false" e o Laravel rejeita-os na regra
// `boolean` (ex.: is_paid). Forçar JSON preserva booleanos e nulls.
export const createExpense = (companyId: number, data: any) =>
    api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_EXPENSES, data, { headers: { "Content-Type": "application/json" } });
export const updateExpense = (companyId: number, id: number, data: any) =>
    api.update(url.GET_COMPANIES + `/${companyId}` + url.GET_EXPENSES + `/${id}`, data);
export const deleteExpense = (companyId: number, id: number) =>
    api.delete(url.GET_COMPANIES + `/${companyId}` + url.GET_EXPENSES + `/${id}`);

// LEADS
export const getLeads = (params: { perPage: number; page: number; companyId: number; }) => api.get(url.GET_COMPANIES + `/${params.companyId}` + url.GET_LEADS_APIS, { params });
export const updateLead = (companyId: number, leadId: number, data: { status?: string; lost_reason?: string | null; notes?: string | null }) => api.put(url.GET_COMPANIES + `/${companyId}` + url.GET_LEADS_APIS + `/${leadId}`, data);
// CRM/funil — todas as leads da empresa (sem paginação) para montar o Kanban.
export const getCompanyLeadsAll = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_LEADS_APIS);

// CARS
export const getCarsPaginate = (
    params: {
        perPage: number;
        page: number;
        companyId: number;
        // 2026-06-26 — filtro status passa a suportar múltipla selecção.
        // FE serializa como CSV (padrão de `car_brand_id`); BE aceita CSV OU
        // array e converte para `whereIn`. Uma única string continua a
        // funcionar para retro-compat.
        status?: Array<'active' | 'sold' | 'draft' | 'available_soon' | 'reserved' | 'inactive'> | string;
        is_resume?: boolean;
        has_active_campaign?: boolean;
        carBrandIds?: number[];
        carModelIds?: number[];
        mincost?: number;
        maxcost?: number;
        sort_by?: string;
        sort_direction?: 'asc' | 'desc';
    }) => api.get(url.GET_COMPANIES + `/${params.companyId}` + url.GET_CARS, {
        params: {
            perPage: params.perPage,
            page: params.page,
            status: Array.isArray(params.status)
                ? (params.status.length > 0 ? params.status.join(",") : undefined)
                : params.status,
            is_resume: params.is_resume,
            has_active_campaign: params.has_active_campaign,
            car_brand_id: params.carBrandIds?.join(",") ?? undefined,
            car_model_id: params.carModelIds?.join(",") ?? undefined,
            mincost: params.mincost,
            maxcost: params.maxcost,
            sort_by: params.sort_by,
            sort_direction: params.sort_direction,
        }
    });
export const showCar = (params: { companyId: number; id: number; }) => api.get(url.GET_COMPANIES + `/${params.companyId}` + url.GET_CARS + "/" + params.id);
export const createCar = (companyId: number, data: FormData | any) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS, data, { headers: { "Content-Type": "multipart/form-data" } });
export const updateCar = (companyId: number, id: number, data: FormData | any) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + "/" + id, data, { headers: { "Content-Type": "multipart/form-data" } });
export const closeCarSale = (companyId: number, carId: number, data: FormData | any) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + "/" + carId + url.GET_CAR_SALES, data, { headers: { "Content-Type": "multipart/form-data" } });
// PATCH dos dados PII do comprador (sem mexer no car nem disparar notificações).
export const updateCarSale = (companyId: number, carId: number, data: any) => api.update(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + "/" + carId + url.GET_CAR_SALE, data);
// Fase 2 — CRM: detetar lead aberta do cliente da venda + mover para "Venda".
export const getSaleLeadMatch = (companyId: number, carId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}/sale/lead-match`);
export const linkSaleLead = (companyId: number, carId: number, leadId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}/sale/link-lead`, { lead_id: leadId });
export const generateCarDescriptionApi = (companyId: number, data: any) =>
    api.create(
        url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + url.POST_CAR_GENERATE_DESCRIPTION,
        data,
        { headers: { "Content-Type": "application/json" } }
    );
export const analyticsCar = (companyId: number, carId: number) => api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + "/" + carId + "/analytics");
export const getCarDecisionApi = (companyId: number, carId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}` + url.GET_CAR_DECISION);
export const executeCarActionApi = (
    companyId: number,
    carId: number,
    payload: { action: string; context?: Record<string, any>; }
) => api.create(
    url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}` + url.POST_CAR_EXECUTE_ACTION,
    payload,
    { headers: { "Content-Type": "application/json" } }
);

// CAR AI ANALISES
export const postCarAiAnalyses = (companyId: number, carId: number) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS_ANALYSES + `/${carId}`, {});
export const postCarRecalculate = (companyId: number, carId: number) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}/potential-score/recalculate`, {});
export const postCarMetaAdsRefresh = (companyId: number, carId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}` + url.POST_CAR_META_ADS_REFRESH, {});
export const postCarAnalysisRegenerate = (companyId: number, carId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}` + url.POST_CAR_ANALYSIS_REGENERATE, {});

// USERS
export const getUsersPaginate = (params: { perPage: number; page: number; companyId: number; }) => api.get(url.GET_COMPANIES + `/${params.companyId}` + url.GET_USERS_APIS, { params });
export const showUser = (params: { companyId: number; id: number; }) => api.get(url.GET_COMPANIES + `/${params.companyId}` + url.GET_USERS_APIS + "/" + params.id);
export const updateUser = (companyId: number, id: number, data: FormData | any) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_USERS_APIS + "/" + id, data, { headers: { "Content-Type": "multipart/form-data" } });
export const createUser = (companyId: number, data: FormData | any) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_USERS_APIS, data, { headers: { "Content-Type": "multipart/form-data" } });

// CARMINE
export const showCarmine = (params: { companyId: number; id: number; }) => api.get(url.GET_COMPANIES + `/${params.companyId}` + url.GET_CARMINE_APIS + "/" + params.id);
export const createCarmine = (companyId: number, data: FormData | any) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARMINE_APIS, data);
export const updateCarmine = (companyId: number, id: number, data: FormData | any) => api.put(url.GET_COMPANIES + `/${companyId}` + url.GET_CARMINE_APIS + "/" + id, data);
export const syncCarmine = (companyId: number) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARMINE_APIS + "/sync", {});

// CAR BRANDS
export const getCarBrands = (vehicleType?: string) => api.get(url.GET_CAR_BRANDS, {
    params: {
        vehicle_type: vehicleType || undefined,
    }
});

// CAR MODELS
export const getCarModels = (
    paramsOrBrandId: number | number[] | { brand_id?: number | number[]; vehicle_type?: string; car_brand_id?: number | number[]; }
) => {
    const brandId = typeof paramsOrBrandId === "object" && !Array.isArray(paramsOrBrandId)
        ? (paramsOrBrandId.brand_id ?? paramsOrBrandId.car_brand_id)
        : paramsOrBrandId;

    const vehicleType = typeof paramsOrBrandId === "object" && !Array.isArray(paramsOrBrandId)
        ? paramsOrBrandId.vehicle_type
        : undefined;

    return api.get(url.GET_CAR_MODELS, {
        params: {
            car_brand_id: typeof brandId === "number"
                ? brandId.toString()
                : Array.isArray(brandId)
                    ? brandId.join(",")
                    : undefined,
            brand_id: typeof brandId === "number"
                ? brandId
                : undefined,
            vehicle_type: vehicleType || undefined,
        }
    });
};

export const getCarCategories = (vehicleType: string) =>
    api.get(url.GET_CAR_CATEGORIES, {
        params: {
            vehicle_type: vehicleType,
        }
    });

// DISTRICTS
export const getDistricts = () => api.get(url.GET_DISTRICTS);

// SCRAPER
export const runScraperApi = (companyId: number, data: { source: string; mode: string; vehicle_type?: string; filters: Record<string, any> }) =>
    api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_SCRAPER + "/run", data, { headers: { "Content-Type": "application/json" } });
export const getScraperExecutionsApi = (companyId: number, params?: { per_page?: number }) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_SCRAPER + "/executions", params);
export const getScraperExecutionApi = (companyId: number, runId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_SCRAPER + "/executions/" + runId);

// MUNICIPALITIES
export const getMunicipalities = (districtId: number) => api.get(`${url.GET_DISTRICTS}/${districtId}/municipalities`);
export const getParishes = (municipalityId: number) => api.get(`/municipalities/${municipalityId}/parishes`);
