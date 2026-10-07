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

// IMPERSONATION — start só root (/admin); stop/current com o token atual. Paths relativos à base /api/v1.
export const startImpersonation = (userId: number, reason?: string) =>
    api.create("/admin/impersonation/start", { user_id: userId, reason });
export const stopImpersonation = () => api.create("/impersonation/stop", {});
export const getCurrentImpersonation = () => api.get("/impersonation/current");
// ROOT (área /admin, transversal): empresas + utilizadores por empresa (base da impersonation).
export const getAdminCompanies = () => api.get("/admin/companies");
export const getAdminCompanyUsers = (companyId: number) => api.get(`/admin/companies/${companyId}/users`);
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
// Meta: { purge, confirmation: "APAGAR" } apaga também os dados da Meta já recebidos.
export const disconnectMetaAdsApi = (companyId: number, platform: string, options?: { purge?: boolean; confirmation?: string }) =>
    api.delete(url.GET_COMPANIES + `/${companyId}` + url.GET_INTEGRATIONS + `/${platform}`, options ? { data: options } : undefined);
export const getMetaAdsetsApi = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_META_INTEGRATIONS + url.GET_META_ADSETS);
// Define a conta de anúncios após o OAuth (callback no backend). PATCH.
export const setMetaAccountApi = (companyId: number, accountId: string) =>
    api.update(url.GET_COMPANIES + `/${companyId}` + url.GET_META_INTEGRATIONS + url.PATCH_META_ACCOUNT, { account_id: accountId });
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

// BLOGS (Ticket 11): conteúdo em multipart (banner); fluxo e IA em JSON.
const BLOG = (companyId: number) => url.GET_COMPANIES + `/${companyId}` + url.GET_BLOGS_APIS;
const BLOG_JSON = { headers: { "Content-Type": "application/json" } };
export const getBlogs = (companyId: number, params?: { status?: string; search?: string; perPage?: number; page?: number }) => api.get(BLOG(companyId), params);
export const showBlog = (companyId: number, id: number) => api.get(`${BLOG(companyId)}/${id}`);
export const createBlog = (companyId: number, data: FormData) => api.create(BLOG(companyId), data, { headers: { "Content-Type": "multipart/form-data" } });
export const updateBlog = (companyId: number, id: number, data: FormData) => { data.append("_method", "PUT"); return api.create(`${BLOG(companyId)}/${id}`, data, { headers: { "Content-Type": "multipart/form-data" } }); };
export const deleteBlog = (companyId: number, id: number) => api.delete(`${BLOG(companyId)}/${id}`);
export const deleteBlogBanner = (companyId: number, id: number) => api.delete(`${BLOG(companyId)}/${id}/banner`);
export const submitBlog = (companyId: number, id: number) => api.create(`${BLOG(companyId)}/${id}/submit`, {}, BLOG_JSON);
export const approveBlog = (companyId: number, id: number, publishAt: string | null) => api.create(`${BLOG(companyId)}/${id}/approve`, { publish_at: publishAt }, BLOG_JSON);
export const requestBlogChanges = (companyId: number, id: number, note: string) => api.create(`${BLOG(companyId)}/${id}/request-changes`, { note }, BLOG_JSON);
export const blogBackToDraft = (companyId: number, id: number) => api.create(`${BLOG(companyId)}/${id}/back-to-draft`, {}, BLOG_JSON);
export const getBlogAiContext = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/blog-ai/context`);
export const createBlogAiDraft = (companyId: number, data: any) => api.create(url.GET_COMPANIES + `/${companyId}/blog-ai/drafts`, data, BLOG_JSON);
export const getBlogAiDraft = (companyId: number, id: number) => api.get(url.GET_COMPANIES + `/${companyId}/blog-ai/drafts/${id}`);
// Pedidos à IA à espera: retomar ao voltar à página (último do contexto) e descartar.
export const getLatestAiRequest = (companyId: number, params: { mode: string; editorial_post_id?: number; blog_id?: number | null; year?: number; month?: number }) =>
    api.get(url.GET_COMPANIES + `/${companyId}/ai-requests/latest`, params);
export const dismissAiRequest = (companyId: number, id: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/ai-requests/${id}/dismiss`, {}, { headers: { "Content-Type": "application/json" } });
export const getBrandProfile = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/brand-profile`);
export const updateBrandProfile = (companyId: number, data: any) => api.put(url.GET_COMPANIES + `/${companyId}/brand-profile`, data);
// Seguidores (Perfil da Marca): estado e crescimento; registo manual de hoje.
export const getFollowers = (companyId: number, days = 90) => api.get(url.GET_COMPANIES + `/${companyId}/followers`, { days });
// Redes sociais (Instagram e Facebook): ligação separada da dos anúncios.
export const getSocialConnection = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/integrations/social`);
export const getSocialAuthUrl = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/integrations/social/auth-url`);
export const getSocialCandidates = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/integrations/social/candidates`);
export const saveSocialAccounts = (companyId: number, data: { facebook: string[]; instagram: string[]; primary_facebook: string | null; primary_instagram: string | null }) =>
    api.put(url.GET_COMPANIES + `/${companyId}/integrations/social/accounts`, data);
export const disconnectSocial = (companyId: number, options?: { purge: boolean; confirmation?: string }) =>
    api.delete(url.GET_COMPANIES + `/${companyId}/integrations/social`, options ? { data: options } : undefined);
export const recordFollowers = (companyId: number, data: { platform: "instagram" | "facebook"; followers_count: number }) =>
    api.create(url.GET_COMPANIES + `/${companyId}/followers`, data);
// "Sugerir perfil" (IA): pedir e consultar. Nunca grava o perfil.
export const requestBrandProfileSuggestion = (companyId: number) => api.create(url.GET_COMPANIES + `/${companyId}/brand-profile/suggestions`, {});
export const getBrandProfileSuggestion = (companyId: number, id: number) => api.get(url.GET_COMPANIES + `/${companyId}/brand-profile/suggestions/${id}`);
// "Sugerir criativo" (Linha Editorial) e criativo aceite campo a campo.
export const requestCreativeSuggestion = (companyId: number, postId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/editorial/posts/${postId}/creative-suggestions`, {});
export const getCreativeSuggestion = (companyId: number, postId: number, id: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/editorial/posts/${postId}/creative-suggestions/${id}`);
export const getPostCreative = (companyId: number, postId: number) => api.get(url.GET_COMPANIES + `/${companyId}/editorial/posts/${postId}/creative`);
export const acceptPostCreative = (companyId: number, postId: number, data: any) =>
    api.put(url.GET_COMPANIES + `/${companyId}/editorial/posts/${postId}/creative`, data);
// Regras de formato (referência de mercado): só root.
export const getCreativeFormatRules = () => api.get(`/admin/creative-format-rules`);
export const createCreativeFormatRule = (data: any) => api.create(`/admin/creative-format-rules`, data);
export const updateCreativeFormatRule = (id: number, data: any) => api.put(`/admin/creative-format-rules/${id}`, data);
export const deleteCreativeFormatRule = (id: number) => api.delete(`/admin/creative-format-rules/${id}`);

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
// confirmReset: obrigatório quando a mudança anula um orçamento orçado ou rejeitado (sem ele: 409).
export const reclassifyAdminTicketType = (id: number, type: string, confirmReset = false) =>
    api.update(url.GET_ADMIN + `/tickets/${id}/type`, confirmReset ? { type, confirm_reset: true } : { type });
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
export const getAdminQuoteDefaults = () => api.get(url.GET_ADMIN + `/quotes/defaults`);
// Dashboard root: contagens transversais da plataforma (users + carros).
export const getAdminPlatformSummary = () => api.get(url.GET_ADMIN + `/platform/summary`);
export const showAdminQuote = (id: number) => api.get(url.GET_ADMIN + `/quotes/${id}`);
// JSON (e não o multipart por omissão dos POST): os booleanos das linhas ("Opcional", "Do pacote") chegam como booleanos.
const JSON_BODY = { headers: { "Content-Type": "application/json" } };
export const createAdminQuote = (data: any) => api.create(url.GET_ADMIN + `/quotes`, data, JSON_BODY);
export const updateAdminQuote = (id: number, data: any) => api.update(url.GET_ADMIN + `/quotes/${id}`, data);
export const sendAdminQuote = (id: number) => api.create(url.GET_ADMIN + `/quotes/${id}/send`, {});
export const decideAdminQuote = (id: number, decision: "accept" | "refuse") =>
    api.update(url.GET_ADMIN + `/quotes/${id}/decision`, { decision });
export const duplicateAdminQuote = (id: number) => api.create(url.GET_ADMIN + `/quotes/${id}/duplicate`, {});
export const deleteAdminQuote = (id: number) => api.delete(url.GET_ADMIN + `/quotes/${id}`);
export const getAdminQuoteCompanies = () => api.get(url.GET_ADMIN + `/quotes/companies`);
export const searchAdminQuoteCustomers = (search?: string) => api.get(url.GET_ADMIN + `/quotes/customers`, search ? { search } : undefined);
export const createAdminQuoteCustomer = (data: { name: string; phone?: string; email?: string }) =>
    api.create(url.GET_ADMIN + `/quotes/customers`, data);
// PDFs (blob, via download_helper): pré-visualização do estado atual e versões congeladas.
export const adminQuotePdfPath = (id: number) => url.GET_ADMIN + `/quotes/${id}/pdf`;
export const adminQuoteVersionPdfPath = (id: number, version: number) => url.GET_ADMIN + `/quotes/${id}/versions/${version}/pdf`;
// Link público: atividade (link, aberturas, respostas) e a página vista pela equipa (não conta).
export const getAdminQuoteActivity = (id: number) => api.get(url.GET_ADMIN + `/quotes/${id}/activity`);
export const getAdminQuotePublicPreview = (id: number, version: number) => api.get(url.GET_ADMIN + `/quotes/${id}/versions/${version}/public-preview`);
// Tarefas do ticket de arranque.
export const updateAdminTicketTask = (ticketId: number, taskId: number, done: boolean) =>
    api.update(url.GET_ADMIN + `/tickets/${ticketId}/tasks/${taskId}`, { done });
// Catálogo de serviços (tabela padrão).
export const getServiceCatalog = (activeOnly = false) => api.get(url.GET_ADMIN + `/service-catalog`, activeOnly ? { active_only: 1 } : undefined);
export const createServiceCatalogItem = (data: any) => api.create(url.GET_ADMIN + `/service-catalog`, data, JSON_BODY);
export const updateServiceCatalogItem = (id: number, data: any) => api.update(url.GET_ADMIN + `/service-catalog/${id}`, data);

export const getCompanyQuotes = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/quotes`);
// XPLENDOR — Orçamentos no painel da empresa ligada (vê os enviados, decide, abre o PDF).
export const companyQuotePdfPath = (companyId: number, id: number) => url.GET_COMPANIES + `/${companyId}/quotes/${id}/pdf`;
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

// Cobranças da XPLENDOR. Root: todas as empresas; empresa: as suas (sem o módulo de Finanças).
export const getAdminCharges = (params?: { status?: string; company_id?: number; overdue?: 1 }) => api.get(url.GET_ADMIN + `/charges`, params);
export const createAdminCharge = (data: FormData) => api.create(url.GET_ADMIN + `/charges`, data, { headers: { "Content-Type": "multipart/form-data" } });
export const adminChargeAction = (id: number, action: "paid" | "cancel" | "refuse" | "send", body: Record<string, unknown> = {}) =>
    api.create(url.GET_ADMIN + `/charges/${id}/${action}`, body);
export const adminChargeFilePath = (id: number, file: "invoice" | "proof") => url.GET_ADMIN + `/charges/${id}/${file}`;
export const getCompanyCharges = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/xplendor-charges`);
export const companyChargeInvoicePath = (companyId: number, id: number) => url.GET_COMPANIES + `/${companyId}/xplendor-charges/${id}/invoice`;
export const indicateCompanyChargePaid = (companyId: number, id: number, data: FormData) =>
    api.create(url.GET_COMPANIES + `/${companyId}/xplendor-charges/${id}/paid`, data, { headers: { "Content-Type": "multipart/form-data" } });

// Vista da agência (F1b): todas as empresas que a pessoa vê; filtro por cliente com company_ids[].
const AG = (agencyId: number) => `/agencies/${agencyId}`;
type AgencyFilter = { company_ids?: number[] };
/** Query com listas no formato do Laravel (company_ids[]=1&company_ids[]=2). */
const agencyQuery = (params: Record<string, string | number | undefined>, f: AgencyFilter = {}) => {
    const q = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => { if (v !== undefined && v !== "") q.append(k, String(v)); });
    (f.company_ids ?? []).forEach((id) => q.append("company_ids[]", String(id)));
    const s = q.toString();
    return s ? `?${s}` : "";
};
export const getAgencyCompanies = (agencyId: number) => api.get(AG(agencyId) + `/companies`);
export const getAgencyPosts = (agencyId: number, month: string, f: AgencyFilter = {}) => api.get(AG(agencyId) + `/editorial/board` + agencyQuery({ month }, f));
export const getAgencyToday = (agencyId: number, f: AgencyFilter = {}) => api.get(AG(agencyId) + `/editorial/today` + agencyQuery({}, f));
export const getAgencyAwaiting = (agencyId: number, f: AgencyFilter = {}) => api.get(AG(agencyId) + `/editorial/awaiting` + agencyQuery({}, f));
export const getAgencyResults = (agencyId: number, month: string, f: AgencyFilter = {}) => api.get(AG(agencyId) + `/editorial/results` + agencyQuery({ month }, f));
export const getAgencyPanel = (agencyId: number) => api.get(AG(agencyId) + `/panel`);
export const getAgencyAssignments = (agencyId: number) => api.get(AG(agencyId) + `/assignments`);
export const setAgencyAssignment = (agencyId: number, companyId: number, teamScope: "all" | "assigned", memberIds: number[]) =>
    api.put(AG(agencyId) + `/assignments/${companyId}`, { team_scope: teamScope, member_ids: memberIds });
export const getAgencyCompanyRequests = (agencyId: number) => api.get(AG(agencyId) + `/company-requests`);
export const createAgencyCompanyRequest = (agencyId: number, data: Record<string, unknown>) => api.create(AG(agencyId) + `/company-requests`, data);
export const getAdminCompanyRequests = (status?: string) => api.get(url.GET_ADMIN + `/company-requests`, status ? { status } : undefined);
export const decideAdminCompanyRequest = (id: number, decision: "approve" | "decline", reason?: string) =>
    api.create(url.GET_ADMIN + `/company-requests/${id}/${decision}`, decision === "decline" ? { reason } : {});
// Dashboard base (empresas sem viaturas nem restauração).
export const getBaseDashboard = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/dashboard/base`);
export const getCompany = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}`);
export const getAlertsUnreadCount = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/alerts/unread-count`);

// Gestão por agências: as empresas onde a pessoa pode trabalhar (a própria e as geridas; o root vê todas).
export const getWorkingCompanies = () => api.get(url.GET_COMPANIES);
// Empresa gerida: a agência gestora; terminar a relação (só o admin da empresa); primeiro admin (a agência).
export const getCompanyManagement = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/management`);
export const endCompanyManagement = (companyId: number, reason?: string) =>
    api.delete(url.GET_COMPANIES + `/${companyId}/management`, { data: { reason: reason || null } });
export const inviteFirstAdmin = (companyId: number, data: { name: string; email: string }) =>
    api.create(url.GET_COMPANIES + `/${companyId}/management/first-admin`, data);
// Root: agências e agência gestora de cada empresa (com o histórico da relação).
export const getAdminAgencies = () => api.get(url.GET_ADMIN + `/agencies`);
export const getAdminCompanyManagement = (companyId: number) => api.get(url.GET_ADMIN + `/companies/${companyId}/management`);
export const setAdminCompanyAgency = (companyId: number, enabled: boolean, notificationEmail?: string | null) =>
    api.update(url.GET_ADMIN + `/companies/${companyId}/agency`, { enabled, notification_email: notificationEmail || null });
export const setAdminCompanyManagement = (companyId: number, agencyCompanyId: number | null) =>
    api.put(url.GET_ADMIN + `/companies/${companyId}/management`, { agency_company_id: agencyCompanyId });

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
// Motor de recomendações (regras explicáveis) de uma empresa, por ramo.
export const getRecommendations = (companyId: number, vertical: "restaurant" | "automotive") =>
    api.get(url.GET_COMPANIES + `/${companyId}/recommendations`, { vertical });
// Dashboard de restauração — bloco "marketing e resultados" de um mês (AAAA-MM; omisso = mês em curso).
export const getRestaurantMarketing = (companyId: number, month?: string) =>
    api.get(url.GET_COMPANIES + `/${companyId}/analytics/restaurant/marketing`, month ? { month } : undefined);
// Hub do automóvel (separador "Stock"): resumo + recomendações + avisos; funil por viatura (14 ou 30 dias).
export const getAutomotiveHub = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/automotive-hub`);
export const getAutomotiveHubFunnel = (
    companyId: number,
    days: 14 | 30,
    page = 1,
    perPage = 10,
    sort: "days_in_stock" | "views" | "contacts" | "leads" = "days_in_stock",
    direction: "asc" | "desc" = "desc",
) =>
    // A ordenação é feita no backend, sobre todas as viaturas, antes da paginação.
    api.get(url.GET_COMPANIES + `/${companyId}/automotive-hub/funnel`, { days, page, per_page: perPage, sort, direction });
// Dashboard do automóvel — bloco "Marketing e resultados" de um mês (AAAA-MM; omisso = mês em curso).
export const getAutomotiveMarketing = (companyId: number, month?: string) =>
    api.get(url.GET_COMPANIES + `/${companyId}/analytics/automotive/marketing`, month ? { month } : undefined);
export const getPingwinDashboard = (companyId: number, date?: string) =>
    api.get(url.GET_COMPANIES + `/${companyId}/analytics/pingwin/dashboard`, date ? { date } : undefined);
// Calendário de faturação: números por dia de um mês (YYYY-MM) + filtro por loja.
export const getPingwinCalendar = (companyId: number, month: string, locationId?: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/analytics/pingwin/calendar`, locationId ? { month, location_id: locationId } : { month });
// Faturação mensal por loja (uma série por loja) de um ano — gráfico de linha.
export const getPingwinMonthlyBilling = (companyId: number, year: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/analytics/pingwin/monthly-billing`, { year });
// Documentos PingWin (Fase 1, só leitura): lista guardada + sincronizar.
export const getPingwinDocuments = (
    companyId: number,
    params?: { page?: number; perPage?: number; search?: string; entitytype?: string }
) => api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/documents`, params);
export const syncPingwinDocuments = (companyId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/documents/sync`, {});
// Documentos — LEITURA RICA (Fase D0): sincronizar config completa + detalhe por id.
export const syncPingwinDocumentsRich = (companyId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/documents/sync-rich`, {});
export const getPingwinDocumentConfigDetail = (companyId: number, externalId: string) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/documents/${externalId}`);
// D1 (ESCRITA): editar o maindataset de um documento ativo + polling do estado.
export const updatePingwinDocumentConfig = (
    companyId: number, externalId: string, fields: Record<string, any>,
    children?: Record<string, Array<{ id: string; deleted: number }>>,
    docaccount?: Array<{ docaccount_id: string; deleted: number; credit: number; debit: number }>
) => api.update(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/documents/${externalId}`, { fields, children, docaccount });
export const getPingwinDocumentConfigWrite = (companyId: number, writeId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/documents/writes/${writeId}`);
// D3 (ESCRITA): criar documento novo (fields = maindataset principal incl. code).
export const createPingwinDocumentConfig = (companyId: number, fields: Record<string, any>) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/documents`, { fields });
// D4 (ESCRITA): anular (soft-delete) um documento ativo por id.
export const voidPingwinDocumentConfig = (companyId: number, externalId: string) =>
    api.delete(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/documents/${externalId}`);

export const getPingwinCatalog = (
    companyId: number,
    params?: { page?: number; perPage?: number; search?: string; family?: string; forsale?: number; forpurchase?: number }
) => api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/catalog`, params);
export const syncPingwinCatalog = (companyId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/catalog/sync`, {});

// ── Artigos: CRUD no PingWin ──────────────────────────────────────────────────
// ⚠️ IDs: LER usa o pingwin_id (id do PingWin); EDITAR/ANULAR usam o catalog_item_id
// (id do espelho local). São diferentes — não trocar (ver ArtigoForm).
const A = (companyId: number) => url.GET_COMPANIES + `/${companyId}/integrations/pingwin/articles`;
// ⚠️ LEITURAS são ASSÍNCRONAS (Opção 1): o docker exec corre no worker/root, não no
// www-data. Estas devolvem { read_id } → fazer polling em getArticleRead(token).
// Form de criação: próximo code + lookups vivos do servidor.
export const getArticleFormLookups = (companyId: number) =>
    api.get(A(companyId) + `/form-lookups`);
// Ler/abrir um artigo pelo PINGWIN_ID (leitura autoritativa OPEN,GET,INFO).
export const getArticle = (companyId: number, pingwinId: string) =>
    api.get(A(companyId) + `/${pingwinId}`);
// Poll do resultado de uma leitura assíncrona (form-lookups ou artigo), pelo token.
export const getArticleRead = (companyId: number, token: string) =>
    api.get(A(companyId) + `/read/${token}`);
// ⚠️ ESCRITA: criar (exige confirm:true). Poll em articles/creation/{id}.
export const createArticle = (companyId: number, data: Record<string, any>) =>
    api.create(A(companyId), data);
export const getArticleCreation = (companyId: number, creationId: number) =>
    api.get(A(companyId) + `/creation/${creationId}`);
// ⚠️ ESCRITA: editar pelo CATALOG_ITEM_ID (exige confirm:true). Poll em articles/edition/{id}.
export const updateArticle = (companyId: number, catalogItemId: number, data: Record<string, any>) =>
    api.update(A(companyId) + `/${catalogItemId}`, data);
export const getArticleEdition = (companyId: number, creationId: number) =>
    api.get(A(companyId) + `/edition/${creationId}`);
// ⚠️ ESCRITA (destrutiva): anular pelo CATALOG_ITEM_ID (exige confirm:true). Poll em articles/deletion/{id}.
export const deleteArticle = (companyId: number, catalogItemId: number) =>
    api.delete(A(companyId) + `/${catalogItemId}`, { data: { confirm: true } });
export const getArticleDeletion = (companyId: number, creationId: number) =>
    api.get(A(companyId) + `/deletion/${creationId}`);

// ── Linha Editorial ──────────────────────────────────────────────────────────
const ED = (companyId: number) => url.GET_COMPANIES + `/${companyId}/editorial`;
// Folhas selecionáveis (ecrã de escolha de ramo).
export const getEditorialSectors = (companyId: number) => api.get(ED(companyId) + `/sectors`);
// 1.ª escolha do ramo (POST). O backend recusa se já houver ramo → isso é a troca (PUT).
export const setEditorialSector = (companyId: number, sectorId: number) =>
    api.create(ED(companyId) + `/sector`, { sector_id: sectorId });
// B3b — TROCA de ramo (PUT, destrutiva). Distinta da 1.ª escolha; devolve o calendar novo.
export const changeEditorialSector = (companyId: number, sectorId: number) =>
    api.put(ED(companyId) + `/sector`, { sector_id: sectorId });
// Calendário herdado dos próximos 12 meses (has_sector:false = ainda por escolher).
export const getEditorialCalendar = (companyId: number) => api.get(ED(companyId) + `/calendar`);
// B2 — máquina de estados dos meses (devolvem o array 'months' atualizado).
export const openEditorialMonth = (companyId: number, year: number, month: number) =>
    api.create(ED(companyId) + `/months/${year}/${month}/open`, {});
export const closeEditorialMonth = (companyId: number, year: number, month: number) =>
    api.create(ED(companyId) + `/months/${year}/${month}/close`, {});
// B3a — esconder/mostrar HERDADAS (id de content_anchors) por ocorrência; devolvem o calendar.
export const hideEditorialAnchor = (companyId: number, anchorId: number, year: number) =>
    api.create(ED(companyId) + `/anchors/${anchorId}/hide`, { year });
export const showEditorialAnchor = (companyId: number, anchorId: number, year: number) =>
    api.create(ED(companyId) + `/anchors/${anchorId}/show`, { year });
// B3a — criar/apagar PRÓPRIAS (id de editorial_own_anchors — espaço distinto).
export const createEditorialOwnAnchor = (companyId: number, payload: any) =>
    api.create(ED(companyId) + `/anchors`, payload);
export const deleteEditorialOwnAnchor = (companyId: number, ownAnchorId: number) =>
    api.delete(ED(companyId) + `/own-anchors/${ownAnchorId}`);
// P1 — PUBLICAÇÕES (espaço de id distinto das âncoras); devolvem o calendar atualizado.
export const createEditorialPost = (companyId: number, payload: any) =>
    api.create(ED(companyId) + `/posts`, payload);
export const updateEditorialPost = (companyId: number, postId: number, payload: any) =>
    api.put(ED(companyId) + `/posts/${postId}`, payload);
export const deleteEditorialPost = (companyId: number, postId: number) =>
    api.delete(ED(companyId) + `/posts/${postId}`);
// F3a: produção e aprovação (Kanban, versões, comentários, histórico, definições).
const ED_JSON = { headers: { "Content-Type": "application/json" } };
export const getEditorialBoard = (companyId: number, month: string) => api.get(ED(companyId) + `/board`, { month });
export const getPostWorkflow = (companyId: number, postId: number) => api.get(ED(companyId) + `/posts/${postId}/workflow`);
export const movePostStage = (companyId: number, postId: number, stage: string) => api.create(ED(companyId) + `/posts/${postId}/move`, { stage }, ED_JSON);
export const savePostContent = (companyId: number, postId: number, data: any) => api.put(ED(companyId) + `/posts/${postId}/content`, data);
export const commentOnPost = (companyId: number, postId: number, body: string, visibility: "internal" | "shared") =>
    api.create(ED(companyId) + `/posts/${postId}/comments`, { body, visibility }, ED_JSON);
export const approvePost = (companyId: number, postId: number, message?: string) => api.create(ED(companyId) + `/posts/${postId}/approve`, { message: message || null }, ED_JSON);
export const requestPostChanges = (companyId: number, postId: number, message: string) => api.create(ED(companyId) + `/posts/${postId}/request-changes`, { message }, ED_JSON);
export const approveAllPosts = (companyId: number, postIds: number[]) => api.create(ED(companyId) + `/approvals/approve-all`, { post_ids: postIds }, ED_JSON);
export const getWorkflowSettings = (companyId: number) => api.get(ED(companyId) + `/workflow-settings`);
export const updateWorkflowSettings = (companyId: number, data: { content_approval_required: boolean; internal_review_required: boolean; production_mode?: "self" | "team" }) => api.put(ED(companyId) + `/workflow-settings`, data);
export const setContentApprover = (companyId: number, userId: number, value: boolean) => api.put(ED(companyId) + `/approvers/${userId}`, { can_approve_content: value });
// F3b: media (envio em partes de 8 MB, retomável), media da versão e grelha do Instagram.
export const startMediaUpload = (companyId: number, file: { name: string; size: number; mime: string }) =>
    api.create(ED(companyId) + `/media/uploads`, file, { headers: { "Content-Type": "application/json" } });
export const getMediaUpload = (companyId: number, id: string) => api.get(ED(companyId) + `/media/uploads/${id}`);
export const sendMediaChunk = (companyId: number, id: string, offset: number, chunk: Blob, onProgress?: (loaded: number) => void) => {
    const fd = new FormData();
    fd.append("offset", String(offset));
    fd.append("chunk", chunk, "chunk");
    return api.create(ED(companyId) + `/media/uploads/${id}/chunk`, fd, {
        headers: { "Content-Type": "multipart/form-data" },
        onUploadProgress: (e: any) => onProgress?.(e.loaded ?? 0),
    });
};
export const getMediaAsset = (companyId: number, assetId: number) => api.get(ED(companyId) + `/media/${assetId}`);
export const setPostMedia = (companyId: number, postId: number, items: number[], coverId: number | null) =>
    api.put(ED(companyId) + `/posts/${postId}/media`, { items, cover_id: coverId });
export const getEditorialGrid = (companyId: number) => api.get(ED(companyId) + `/grid`);
// F3d: para publicar hoje, marcar como publicada, resultados à mão e resultados do mês.
export const getEditorialToday = (companyId: number) => api.get(ED(companyId) + `/today`);
export const getEditorialFormats = (companyId: number) => api.get(ED(companyId) + `/formats`);
export const skipPostNetwork = (companyId: number, postId: number, data: { network: string; reason: string }) =>
    api.create(ED(companyId) + `/posts/${postId}/skip-network`, data, { headers: { "Content-Type": "application/json" } });
export const markPostPublished = (companyId: number, postId: number, data: { network: string; url: string; published_at: string }) =>
    api.create(ED(companyId) + `/posts/${postId}/published`, data, { headers: { "Content-Type": "application/json" } });
export const savePostResults = (companyId: number, postId: number, data: Record<string, unknown>) => api.put(ED(companyId) + `/posts/${postId}/results`, data);
export const getEditorialResults = (companyId: number, month: string) => api.get(ED(companyId) + `/results`, { month });
// F3c: links de aprovação por lote (o link em si é público: /aprovar#<token>).
const REVIEW_JSON = { headers: { "Content-Type": "application/json" } };
export const getReviewLinks = (companyId: number) => api.get(ED(companyId) + `/review-links`);
export const getReviewCandidates = (companyId: number) => api.get(ED(companyId) + `/review-links/candidates`);
export const createReviewLink = (companyId: number, data: { title: string; post_ids: number[]; recipient_name?: string; recipient_email?: string }) =>
    api.create(ED(companyId) + `/review-links`, data, REVIEW_JSON);
export const resendReviewLink = (companyId: number, linkId: number, data: { title: string; post_ids: number[]; recipient_name?: string; recipient_email?: string }) =>
    api.put(ED(companyId) + `/review-links/${linkId}`, data);
export const extendReviewLink = (companyId: number, linkId: number) => api.create(ED(companyId) + `/review-links/${linkId}/extend`, {}, REVIEW_JSON);
export const revokeReviewLink = (companyId: number, linkId: number) => api.create(ED(companyId) + `/review-links/${linkId}/revoke`, {}, REVIEW_JSON);
export const getReviewLinkPreview = (companyId: number, linkId: number) => api.get(ED(companyId) + `/review-links/${linkId}/preview`);
// "Gerar ideias do mês" (IA) e aceitação ideia a ideia (cada uma vira uma publicação em rascunho).
export const requestEditorialIdeas = (companyId: number, year: number, month: number) =>
    api.create(ED(companyId) + `/ideas`, { year, month }, { headers: { "Content-Type": "application/json" } });
export const getEditorialIdeas = (companyId: number, id: number) => api.get(ED(companyId) + `/ideas/${id}`);
export const acceptEditorialIdea = (companyId: number, id: number, payload: { index: number; publish_date?: string; channel?: string }) =>
    api.create(ED(companyId) + `/ideas/${id}/accept`, payload, { headers: { "Content-Type": "application/json" } });

export const getPingwinFamilies = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/families`);
export const syncPingwinFamilies = (companyId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/families/sync`, {});

export const getPingwinUnits = (
    companyId: number,
    params?: { page?: number; perPage?: number; search?: string; active?: number }
) => api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/units`, params);
export const syncPingwinUnits = (companyId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/units/sync`, {});
// ⚠️ ESCRITA no PingWin: criar unidade (exige confirm:true). Ação deliberada.
export const createPingwinUnit = (companyId: number, data: Record<string, any>) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/units/create`, data);
export const getPingwinUnitCreation = (companyId: number, creationId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/units/creations/${creationId}`);
// ⚠️ ESCRITA: editar/anular unidade (exige confirm:true). Ação deliberada.
export const editPingwinUnit = (companyId: number, unitId: number, data: Record<string, any>) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/units/${unitId}/edit`, data);
export const anularPingwinUnit = (companyId: number, unitId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/units/${unitId}/anular`, { confirm: true });
export const getPingwinUnitUsage = (companyId: number, unitId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/units/${unitId}/usage`);

export const getPingwinSuppliers = (
    companyId: number,
    params?: { page?: number; perPage?: number; search?: string; active?: number }
) => api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/suppliers`, params);
export const syncPingwinSuppliers = (companyId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/suppliers/sync`, {});
// Condições de Pagamento PingWin (Fatia 1, só leitura): lista paginada + sincronizar.
export const getPingwinPaymentConditions = (
    companyId: number,
    params?: { page?: number; perPage?: number; search?: string; active?: number }
) => api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/payment-conditions`, params);
export const syncPingwinPaymentConditions = (companyId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/payment-conditions/sync`, {});
// Fatia 2a (ESCRITA): template de documentos (p/ o modal), criar, e polling da criação.
export const getPingwinPaymentConditionDocsTemplate = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/payment-conditions/docs-template`);
export const createPingwinPaymentCondition = (
    companyId: number,
    body: { code?: string; description: string; discount?: number; days?: number; tbdocs_unlinked?: string[] }
) => api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/payment-conditions`, body);
export const getPingwinPaymentConditionCreation = (companyId: number, creationId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/payment-conditions/creation/${creationId}`);
// Fatia 2b (ESCRITA): editar condição ATIVA por id (pingwin_id). code read-only; envia só as mudanças de tbdocs.
export const updatePingwinPaymentCondition = (
    companyId: number,
    paycondId: string,
    body: { description: string; discount?: number; days?: number; tbdocs_changes?: { docconfig_id: string; deleted: number }[] }
) => api.update(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/payment-conditions/${paycondId}`, body);
// Fatia 2c (ESCRITA): anular (soft-delete) condição ATIVA por id (pingwin_id).
export const voidPingwinPaymentCondition = (companyId: number, paycondId: string) =>
    api.delete(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/payment-conditions/${paycondId}`);
// Tab Compras (C1/C3): linhas de fornecedor de um artigo, do ESPELHO (recarregar após salvar).
export const getPingwinArticleSupplierPrices = (companyId: number, catalogItemId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/articles/${catalogItemId}/supplier-prices`);

// ── OCR de faturas de fornecedor (Fase A) ─────────────────────────────────────
export const getOcrInvoices = (
    companyId: number,
    params?: { page?: number; perPage?: number; status?: string }
) => api.get(url.GET_COMPANIES + `/${companyId}/ocr/invoices`, params);
export const uploadOcrInvoice = (companyId: number, file: File) => {
    const fd = new FormData();
    fd.append("file", file);
    return api.create(url.GET_COMPANIES + `/${companyId}/ocr/invoices`, fd, { headers: { "Content-Type": "multipart/form-data" } });
};
export const getOcrInvoice = (companyId: number, invoiceId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}`);
export const updateOcrInvoice = (companyId: number, invoiceId: number, data: Record<string, any>) =>
    api.update(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}`, data);
// A imagem está num disco privado (atrás de auth) → buscar como blob (o axios põe o token).
export const getOcrInvoiceImageBlob = (companyId: number, invoiceId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/image`, { responseType: "blob" } as any);
// CoverManager (reservas) — Etapa 1: sincroniza o agregado por turno de uma data.
export const syncCoverManager = (companyId: number, date: string) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/covermanager/sync`, { date });
// Sincronização por PERÍODO (um job por dia; notificação única no fim).
export const syncRestaurantPeriod = (companyId: number, from: string, to: string) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/restaurant/sync-period`, { from, to });
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

// Orçamentos-em-tickets do STAND (site_change): lista+pipeline e aprovar pacote.
export const getCompanyTicketQuotes = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_SUPPORT_TICKETS + `/quotes`);
export const approveCompanyTicketQuotes = (companyId: number, ids: number[]) =>
    api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_SUPPORT_TICKETS + `/quotes/approve`, { ids }, { headers: { "Content-Type": "application/json" } });
// ADMIN: pipeline de orçamentos-em-tickets (site_change), filtro company_id opcional.
export const getAdminTicketsQuotePipeline = (params?: { company_id?: number }) =>
    api.get(url.GET_ADMIN + `/tickets/quote-pipeline`, params);

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
// Colaboradores (equipa) e departamentos. As ações de acesso só funcionam para o admin
// da própria empresa e fora da sessão como cliente (o servidor recusa com 403).
const COL = (companyId: number) => url.GET_COMPANIES + `/${companyId}/collaborators`;
// Os POST da app vão por omissão como multipart (os booleanos chegariam como texto): estes vão em JSON.
const JSON_HEADERS = { headers: { "Content-Type": "application/json" } };
export const getCollaborators = (companyId: number, params?: { search?: string; status?: string; department_id?: number }) => api.get(COL(companyId), params);
export const getCollaborator = (companyId: number, id: number) => api.get(`${COL(companyId)}/${id}`);
export const createCollaborator = (companyId: number, data: any) => api.create(COL(companyId), data, JSON_HEADERS);
export const updateCollaborator = (companyId: number, id: number, data: any) => api.update(`${COL(companyId)}/${id}`, data);
export const deleteCollaborator = (companyId: number, id: number) => api.delete(`${COL(companyId)}/${id}`);
export const uploadCollaboratorPhoto = (companyId: number, id: number, file: File) => {
    const fd = new FormData();
    fd.append("photo", file);
    return api.create(`${COL(companyId)}/${id}/photo`, fd, { headers: { "Content-Type": "multipart/form-data" } });
};
export const deleteCollaboratorPhoto = (companyId: number, id: number) => api.delete(`${COL(companyId)}/${id}/photo`);
export const setCollaboratorActive = (companyId: number, id: number, active: boolean) => api.create(`${COL(companyId)}/${id}/${active ? "activate" : "deactivate"}`, {});
export const grantCollaboratorAccess = (companyId: number, id: number, email: string) => api.create(`${COL(companyId)}/${id}/access`, { email }, JSON_HEADERS);
export const resendCollaboratorInvite = (companyId: number, id: number) => api.create(`${COL(companyId)}/${id}/access/resend`, {});
export const cancelCollaboratorInvite = (companyId: number, id: number) => api.delete(`${COL(companyId)}/${id}/access/invite`);
export const revokeCollaboratorAccess = (companyId: number, id: number) => api.create(`${COL(companyId)}/${id}/access/revoke`, {});
export const restoreCollaboratorAccess = (companyId: number, id: number) => api.create(`${COL(companyId)}/${id}/access/restore`, {});
const DEP = (companyId: number) => url.GET_COMPANIES + `/${companyId}/departments`;
export const getDepartments = (companyId: number) => api.get(DEP(companyId));
export const createDepartment = (companyId: number, data: any) => api.create(DEP(companyId), data, JSON_HEADERS);
export const updateDepartment = (companyId: number, id: number, data: any) => api.update(`${DEP(companyId)}/${id}`, data);
export const deleteDepartment = (companyId: number, id: number) => api.delete(`${DEP(companyId)}/${id}`);
export const createSuggestedDepartments = (companyId: number) => api.create(`${DEP(companyId)}/suggested`, {});

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
