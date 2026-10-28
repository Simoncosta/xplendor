import { APIClient } from "./api_helper";

import * as url from "./url_helper";
import type { RadarItemsQuery } from "common/models/radar.model";

// Pedidos sem ficheiros: sempre em JSON. O axios da app envia os POST, por omissão, em
// multipart/form-data, e aí true/false chegam como "true"/"false" (a regra boolean recusa-os).
const JSON_BODY = { headers: { "Content-Type": "application/json" } };

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
export const postApiLogin = (data: any) => api.create(url.POST_FAKE_API_LOGIN, data, JSON_BODY);
// Logout Method
export const postApiLogout = (data: any) => api.create(url.POST_FAKE_API_LOGOUT, data, JSON_BODY);

// IMPERSONATION — start só root (/admin); stop/current com o token atual. Paths relativos à base /api/v1.
export const startImpersonation = (userId: number, reason?: string) =>
    api.create("/admin/impersonation/start", { user_id: userId, reason }, JSON_BODY);
export const stopImpersonation = () => api.create("/impersonation/stop", {}, JSON_BODY);
export const getCurrentImpersonation = () => api.get("/impersonation/current");
// ROOT (área /admin, transversal): empresas + utilizadores por empresa (base da impersonation).
export const getAdminCompanies = () => api.get("/admin/companies");
export const getAdminCompanyUsers = (companyId: number) => api.get(`/admin/companies/${companyId}/users`);
// User By Invite
export const getUserByInvite = (token: string) => api.get(url.GET_USER_BY_INVITE + token);
// Register By Invite
export const postRegisterByInvite = (data: any) => api.create(url.POST_REGISTER_BY_INVITE, data, JSON_BODY);

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
    api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_ALERTS + `/${alertId}` + url.POST_ALERT_READ, {}, JSON_BODY);

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
const BLOG_JSON = JSON_BODY;
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
// F2.8: "Ligar publicação" (autorização à parte; a leitura não muda).
export const getSocialPublishAuthUrl = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/integrations/social/publish-auth-url`);
export const disconnectSocialPublishing = (companyId: number) => api.delete(url.GET_COMPANIES + `/${companyId}/integrations/social/publishing`);
export const setSocialAutoPublishing = (companyId: number, enabled: boolean) => api.put(url.GET_COMPANIES + `/${companyId}/integrations/social/auto-publishing`, { enabled });
export const recordFollowers = (companyId: number, data: { platform: "instagram" | "facebook"; followers_count: number }) =>
    api.create(url.GET_COMPANIES + `/${companyId}/followers`, data, JSON_BODY);
// "Sugerir perfil" (IA): pedir e consultar. Nunca grava o perfil.
export const requestBrandProfileSuggestion = (companyId: number) => api.create(url.GET_COMPANIES + `/${companyId}/brand-profile/suggestions`, {}, JSON_BODY);
export const getBrandProfileSuggestion = (companyId: number, id: number) => api.get(url.GET_COMPANIES + `/${companyId}/brand-profile/suggestions/${id}`);
// "Sugerir criativo" (Linha Editorial) e criativo aceite campo a campo.
export const requestCreativeSuggestion = (companyId: number, postId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/editorial/posts/${postId}/creative-suggestions`, {}, JSON_BODY);
export const getCreativeSuggestion = (companyId: number, postId: number, id: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/editorial/posts/${postId}/creative-suggestions/${id}`);
export const getPostCreative = (companyId: number, postId: number) => api.get(url.GET_COMPANIES + `/${companyId}/editorial/posts/${postId}/creative`);
export const acceptPostCreative = (companyId: number, postId: number, data: any) =>
    api.put(url.GET_COMPANIES + `/${companyId}/editorial/posts/${postId}/creative`, data);
// "Gerar legenda" (IA, com as imagens da versão atual): propostas, nunca gravadas.
export const requestCaptionSuggestion = (companyId: number, postId: number, networks?: string[]) =>
    api.create(url.GET_COMPANIES + `/${companyId}/editorial/posts/${postId}/caption-suggestions`, networks ? { networks } : {}, JSON_BODY);
export const getCaptionSuggestion = (companyId: number, postId: number, id: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/editorial/posts/${postId}/caption-suggestions/${id}`);
// Modelos de IA por função e teste às cegas: só root.
export const getAiModels = () => api.get(`/admin/ai-models`);
// Compras sugeridas do Economato (só leitura).
export const getPurchaseSuggestions = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/restaurant/purchase-suggestions`);
// Configurações: Restauração e Auditoria (por empresa) e Admin › Integrações › PingWin (só root).
export const getRestaurantSettings = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/settings/restaurant`);
export const updateRestaurantSettings = (companyId: number, values: Record<string, unknown>, consent?: boolean) =>
    api.put(url.GET_COMPANIES + `/${companyId}/settings/restaurant`, { values, ...(consent ? { consent: true } : {}) });
export const getPingwinAdminSettings = () => api.get(`/admin/integrations/pingwin`);
export const updatePingwinPlatformSettings = (values: Record<string, unknown>, reason?: string) => api.put(`/admin/integrations/pingwin/platform`, { values, reason });
export const updatePingwinCompanySettings = (companyId: number, values: Record<string, unknown>, reason?: string) =>
    api.put(`/admin/integrations/pingwin/companies/${companyId}`, { values, reason });
export const updateAiModel = (fn: string, data: { model: string; effort: string }) => api.put(`/admin/ai-models/${fn}`, data);
export const applyAiModelToAll = (data: { model: string; effort: string }) => api.create(`/admin/ai-models/apply-all`, data, JSON_BODY);
export const getAiBlindTests = () => api.get(`/admin/ai-blind-tests`);
export const createAiBlindTest = (fn: string) => api.create(`/admin/ai-blind-tests`, { function: fn }, JSON_BODY);
export const getAiBlindTest = (id: number) => api.get(`/admin/ai-blind-tests/${id}`);
export const chooseAiBlindCase = (testId: number, caseId: number, choice: "left" | "right" | "tie") =>
    api.create(`/admin/ai-blind-tests/${testId}/cases/${caseId}/choice`, { choice }, JSON_BODY);
// Link de configuração do cliente (F1c) e histórico das ligações.
export const getSetupLink = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/setup-link`);
export const createSetupLink = (companyId: number, steps: string[], supportTicketId?: number | null) =>
    api.create(url.GET_COMPANIES + `/${companyId}/setup-links`, { steps, ...(supportTicketId ? { support_ticket_id: supportTicketId } : {}) }, JSON_BODY);
export const extendSetupLink = (companyId: number, linkId: number) => api.create(url.GET_COMPANIES + `/${companyId}/setup-links/${linkId}/extend`, {}, JSON_BODY);
export const revokeSetupLink = (companyId: number, linkId: number) => api.create(url.GET_COMPANIES + `/${companyId}/setup-links/${linkId}/revoke`, {}, JSON_BODY);
export const getIntegrationsHistory = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/integrations/history`);
export const getMetaAdAccounts = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/integrations/meta/ad-accounts`);
// Regras de formato (referência de mercado): só root.
export const getCreativeFormatRules = () => api.get(`/admin/creative-format-rules`);
export const createCreativeFormatRule = (data: any) => api.create(`/admin/creative-format-rules`, data, JSON_BODY);
export const updateCreativeFormatRule = (id: number, data: any) => api.put(`/admin/creative-format-rules/${id}`, data);
export const deleteCreativeFormatRule = (id: number) => api.delete(`/admin/creative-format-rules/${id}`);

// SUPPLIERS (DMS 1c.1)
export const getSuppliers = (companyId: number, params?: { perPage?: number; page?: number; search?: string; only_active?: number }) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_SUPPLIERS, params);
export const showSupplier = (companyId: number, id: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_SUPPLIERS + `/${id}`);
export const createSupplier = (companyId: number, data: any) =>
    api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_SUPPLIERS, data, JSON_BODY);
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
export const getAdminTickets = (params?: { status?: string; company_id?: number; type?: string; deleted?: 1 }) =>
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
// Editar e apagar (só o root, e só o que é dele) e a fatura separada do pagamento.
export const updateAdminTicketMessage = (id: number, messageId: number, body: string) =>
    api.update(url.GET_ADMIN + `/tickets/${id}/messages/${messageId}`, { body });
export const deleteAdminTicketMessage = (id: number, messageId: number) =>
    api.delete(url.GET_ADMIN + `/tickets/${id}/messages/${messageId}`);
export const deleteAdminTicketScreenshot = (id: number) => api.delete(url.GET_ADMIN + `/tickets/${id}/screenshot`);
/** Anexar ou trocar a fatura (multipart: só o ficheiro). */
export const uploadAdminTicketInvoice = (id: number, data: FormData) =>
    api.create(url.GET_ADMIN + `/tickets/${id}/invoice`, data, { headers: { "Content-Type": "multipart/form-data" } });
export const deleteAdminTicketInvoice = (id: number) => api.delete(url.GET_ADMIN + `/tickets/${id}/invoice`);
export const unmarkAdminTicketPaid = (id: number) => api.delete(url.GET_ADMIN + `/tickets/${id}/mark-paid`);
export const deleteAdminTicket = (id: number) => api.delete(url.GET_ADMIN + `/tickets/${id}`);

// XPLENDOR — Orçamentos avulsos (gestão comercial, /admin, só root).
export const getAdminQuotes = (params?: { status?: string; search?: string }) =>
    api.get(url.GET_ADMIN + `/quotes`, params);
export const getAdminQuotesSummary = () => api.get(url.GET_ADMIN + `/quotes/summary`);
export const getAdminQuoteDefaults = () => api.get(url.GET_ADMIN + `/quotes/defaults`);
// Dashboard root: contagens transversais da plataforma (users + carros).
export const getAdminPlatformSummary = () => api.get(url.GET_ADMIN + `/platform/summary`);
export const showAdminQuote = (id: number) => api.get(url.GET_ADMIN + `/quotes/${id}`);
// JSON (e não o multipart por omissão dos POST): os booleanos das linhas ("Opcional", "Do pacote") chegam como booleanos.
export const createAdminQuote = (data: any) => api.create(url.GET_ADMIN + `/quotes`, data, JSON_BODY);
export const updateAdminQuote = (id: number, data: any) => api.update(url.GET_ADMIN + `/quotes/${id}`, data);
export const sendAdminQuote = (id: number) => api.create(url.GET_ADMIN + `/quotes/${id}/send`, {}, JSON_BODY);
export const decideAdminQuote = (id: number, decision: "accept" | "refuse") =>
    api.update(url.GET_ADMIN + `/quotes/${id}/decision`, { decision });
export const duplicateAdminQuote = (id: number) => api.create(url.GET_ADMIN + `/quotes/${id}/duplicate`, {}, JSON_BODY);
export const deleteAdminQuote = (id: number) => api.delete(url.GET_ADMIN + `/quotes/${id}`);
export const getAdminQuoteCompanies = () => api.get(url.GET_ADMIN + `/quotes/companies`);
export const searchAdminQuoteCustomers = (search?: string) => api.get(url.GET_ADMIN + `/quotes/customers`, search ? { search } : undefined);
export const createAdminQuoteCustomer = (data: { name: string; phone?: string; email?: string }) =>
    api.create(url.GET_ADMIN + `/quotes/customers`, data, JSON_BODY);
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
    api.create(url.GET_COMPANIES + `/${companyId}/tasks`, data, JSON_BODY);
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
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/google/connect`, { property_id: propertyId }, JSON_BODY);
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
/** ACL: os módulos e as permissões efetivas da pessoa na empresa, com os motivos das recusas. */
export const getMyAccess = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/my-access`);
// E-mail 1: a minha caixa de correio (só o próprio; a palavra-passe nunca volta, só "has_password").
export const getMyMailbox = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/my-mailbox`);
export const saveMyMailbox = (companyId: number, data: Record<string, unknown>) => api.put(url.GET_COMPANIES + `/${companyId}/my-mailbox`, data);
export const deleteMyMailbox = (companyId: number) => api.delete(url.GET_COMPANIES + `/${companyId}/my-mailbox`);
// Os POST da caixa de correio vão em JSON (JSON_BODY, mais acima): por omissão o axios desta app envia
// multipart/form-data, e aí um true chega como o texto "true" (o servidor recusava o "marcar como lida" com 422).
export const testMyMailbox = (companyId: number) => api.create(url.GET_COMPANIES + `/${companyId}/my-mailbox/test`, {}, JSON_BODY);
// E-mail 3: testar o IMAP (pasta de Enviados e não lidos) e o número de não lidos (cabeçalho e menu).
export const testMyMailboxImap = (companyId: number) => api.create(url.GET_COMPANIES + `/${companyId}/my-mailbox/test-imap`, {}, JSON_BODY);
export const getMyMailboxUnread = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/my-mailbox/unread`);
// E-mail 4: a caixa de entrada (ao vivo do servidor de e-mail) e a "Conversa" da ficha e da venda.
export const getMailboxFolders = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/my-mailbox/folders`);
export const getMailboxMessages = (companyId: number, folder: string, page = 1, starred = false) =>
    api.get(url.GET_COMPANIES + `/${companyId}/my-mailbox/messages`, starred ? { folder, page, starred: 1 } : { folder, page });
export const getMailboxMessage = (companyId: number, uid: number, folder: string) => api.get(url.GET_COMPANIES + `/${companyId}/my-mailbox/messages/${uid}`, { folder });
export const setMailboxSeen = (companyId: number, uid: number, folder: string, seen: boolean) =>
    api.create(url.GET_COMPANIES + `/${companyId}/my-mailbox/messages/${uid}/seen`, { folder, seen }, JSON_BODY);
// E-mail 4b: a conversa toda num só pedido (uma ligação IMAP) e marcar várias de uma vez.
export const getMailboxThread = (companyId: number, folder: string, uids: number[]) =>
    api.get(url.GET_COMPANIES + `/${companyId}/my-mailbox/thread`, { folder, uids: uids.join(",") });
export const setMailboxSeenMany = (companyId: number, folder: string, uids: number[], seen: boolean) =>
    api.create(url.GET_COMPANIES + `/${companyId}/my-mailbox/seen`, { folder, uids, seen }, JSON_BODY);
// E-mail 10: a estrela (\\Flagged), apagar (para o Lixo da caixa, nunca de vez) e desfazer, em JSON.
export const setMailboxFlag = (companyId: number, folder: string, uids: number[], flagged: boolean) =>
    api.create(url.GET_COMPANIES + `/${companyId}/my-mailbox/flag`, { folder, uids, flagged }, JSON_BODY);
export const trashMailboxMessages = (companyId: number, folder: string, uids: number[], messageIds: (string | null | undefined)[]) =>
    api.create(url.GET_COMPANIES + `/${companyId}/my-mailbox/trash`, { folder, uids, message_ids: messageIds.filter(Boolean) }, JSON_BODY);
export const restoreMailboxMessages = (companyId: number, folder: string, messageIds: string[]) =>
    api.create(url.GET_COMPANIES + `/${companyId}/my-mailbox/restore`, { folder, message_ids: messageIds }, JSON_BODY);
// E-mail 10b: a pasta do Lixo nas definições da caixa (a marcada com \\Trash, ou a escolhida à mão).
export const getMailboxTrashFolder = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/my-mailbox/trash-folder`);
export const setMailboxTrashFolder = (companyId: number, folder: string | null) => api.put(url.GET_COMPANIES + `/${companyId}/my-mailbox/trash-folder`, { folder });
export const linkMailboxMessage = (companyId: number, uid: number, folder: string, customerId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/my-mailbox/messages/${uid}/link`, { folder, customer_id: customerId }, JSON_BODY);
// E-mail 7: um anexo (PDF ou imagem) para as Faturas, em JSON.
export const importMailboxAttachment = (companyId: number, uid: number, folder: string, index: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/my-mailbox/messages/${uid}/attachments/${index}/import-invoice`, { folder }, JSON_BODY);
export const mailboxAttachmentPath = (companyId: number, uid: number, folder: string, index: number, inline: boolean) =>
    `/companies/${companyId}/my-mailbox/messages/${uid}/attachments/${index}?folder=${encodeURIComponent(folder)}${inline ? "&inline=1" : ""}`;
// E-mail 8: Perfil da empresa › Correio (o prazo de retenção das mensagens guardadas), em JSON.
export const getMailSettings = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/mail-settings`);
export const saveMailSettings = (companyId: number, retentionMonths: number) =>
    api.put(url.GET_COMPANIES + `/${companyId}/mail-settings`, { retention_months: retentionMonths }); // o PUT já vai em JSON
export const getCustomerMailMessages = (companyId: number, customerId: number) => api.get(url.GET_COMPANIES + `/${companyId}/customers/${customerId}/mail-messages`);
export const getCarMailMessages = (companyId: number, carId: number) => api.get(url.GET_COMPANIES + `/${companyId}/cars/${carId}/mail-messages`);
// E-mail 5: escrever e responder pela minha caixa (em JSON, como os outros POST da caixa).
export type MailboxSendPayload = {
    to: string; cc: string; subject: string; body: string;
    reply_to?: { folder: string; uid: number } | null; customer_id?: number | null; car_id?: number | null;
    /** E-mail 5b: os identificadores dos anexos já carregados. */
    attachments?: string[];
    /** E-mail 9: o link de financiamento ou de retoma (o servidor confirma que o texto o leva e esconde-o na cópia guardada). */
    link?: { kind: "financing" | "trade_in"; id: number } | null;
};
// E-mail 9: a mensagem já preenchida para enviar o link de financiamento ou de retoma pela caixa.
export const getMailboxLinkContext = (companyId: number, kind: "financing" | "trade_in", linkId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/my-mailbox/link-context`, { kind, link_id: linkId });
export const getLeadMailMessages = (companyId: number, leadId: number) => api.get(url.GET_COMPANIES + `/${companyId}/leads/${leadId}/mail-messages`);
export const getMailboxComposeContext = (companyId: number, target: { customerId?: number; carId?: number } = {}) =>
    api.get(url.GET_COMPANIES + `/${companyId}/my-mailbox/compose-context`, { customer_id: target.customerId, car_id: target.carId });
// E-mail 5b: os anexos. O carregamento vai em multipart (tem o ficheiro), com o progresso; o resto em JSON.
export const uploadMailboxAttachment = (companyId: number, file: File, onProgress?: (percent: number) => void) => {
    const fd = new FormData();
    fd.append("file", file);
    return api.create(url.GET_COMPANIES + `/${companyId}/my-mailbox/attachments`, fd, {
        headers: { "Content-Type": "multipart/form-data" },
        onUploadProgress: (e: any) => { if (onProgress && e?.total) onProgress(Math.round((e.loaded / e.total) * 100)); },
    });
};
export const attachMailboxFile = (companyId: number, fileId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/my-mailbox/attachments/from-files`, { file_id: fileId }, JSON_BODY);
export const deleteMailboxAttachment = (companyId: number, id: string) => api.delete(url.GET_COMPANIES + `/${companyId}/my-mailbox/attachments/${id}`);
export const sendMailboxMessage = (companyId: number, data: MailboxSendPayload) =>
    api.create(url.GET_COMPANIES + `/${companyId}/my-mailbox/send`, data, JSON_BODY);
// ACL (F5): Utilizadores › Perfis e o teto da agência gestora.
export const getPermissionProfiles = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/permission-profiles`);
export const createPermissionProfile = (companyId: number, data: { name: string; description?: string | null; side: string; permissions: string[]; from_profile_id?: number | null }) =>
    api.create(url.GET_COMPANIES + `/${companyId}/permission-profiles`, data, JSON_BODY);
export const updatePermissionProfile = (companyId: number, profileId: number, data: { name?: string; description?: string | null; permissions?: string[] }) =>
    api.put(url.GET_COMPANIES + `/${companyId}/permission-profiles/${profileId}`, data);
export const deletePermissionProfile = (companyId: number, profileId: number) => api.delete(url.GET_COMPANIES + `/${companyId}/permission-profiles/${profileId}`);
export const previewPermissionProfile = (companyId: number, data: { side: string; permissions: string[] }) =>
    api.create(url.GET_COMPANIES + `/${companyId}/permission-profiles/preview`, data, JSON_BODY);
export const assignUserProfile = (companyId: number, userId: number, profileId: number) =>
    api.put(url.GET_COMPANIES + `/${companyId}/users/${userId}/profile`, { profile_id: profileId });
export const setAgencyCeiling = (companyId: number, profileId: number) =>
    api.put(url.GET_COMPANIES + `/${companyId}/management/guest-profile`, { profile_id: profileId });

// Cobranças da XPLENDOR. Root: todas as empresas; empresa: as suas (sem o módulo de Finanças).
// Espaço usado por cada empresa (por tipo e total), somado na base de dados.
export const getAdminStorageUsage = () => api.get(url.GET_ADMIN + `/storage-usage`);
export const getAdminSystemHealth = () => api.get(url.GET_ADMIN + `/health`);
// Radar de oportunidade (R1, só o root): o resumo da recolha e a lista do que entrou e do que o filtro tirou.
export const getAdminRadar = () => api.get(url.GET_ADMIN + `/radar`);
export const getAdminRadarItems = (params: RadarItemsQuery = {}) => api.get(url.GET_ADMIN + `/radar/items`, params);
// R2: a relevância por ramo e o interruptor do Radar por empresa (o módulo radar).
export const getAdminRadarRelevances = (params: { sector?: string; days?: number } = {}) => api.get(url.GET_ADMIN + `/radar/relevances`, params);
// Bússola 7: a pontuação a partir da qual um sinal gera aviso (sino e e-mail).
export const updateAdminRadarAlerts = (data: { min_score: number; reason?: string }) => api.put(url.GET_ADMIN + `/radar/alerts`, data);
export const switchAdminRadarCompany =(companyId: number, enabled: boolean) => api.create(url.GET_ADMIN + `/radar/companies/${companyId}/switch`, { enabled }, JSON_BODY);
export const getAdminCharges = (params?: { status?: string; company_id?: number; overdue?: 1 }) => api.get(url.GET_ADMIN + `/charges`, params);
export const createAdminCharge = (data: FormData) => api.create(url.GET_ADMIN + `/charges`, data, { headers: { "Content-Type": "multipart/form-data" } });
export const adminChargeAction = (id: number, action: "paid" | "cancel" | "refuse" | "send", body: Record<string, unknown> = {}) =>
    api.create(url.GET_ADMIN + `/charges/${id}/${action}`, body, JSON_BODY);
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
export const createAgencyCompanyRequest = (agencyId: number, data: Record<string, unknown>) => api.create(AG(agencyId) + `/company-requests`, data, JSON_BODY);
export const getAdminCompanyRequests = (status?: string) => api.get(url.GET_ADMIN + `/company-requests`, status ? { status } : undefined);
export const decideAdminCompanyRequest = (id: number, decision: "approve" | "decline", reason?: string) =>
    api.create(url.GET_ADMIN + `/company-requests/${id}/${decision}`, decision === "decline" ? { reason } : {}, JSON_BODY);
// Dashboard base (empresas sem viaturas nem restauração).
export const getBaseDashboard = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/dashboard/base`);
export const getCompany = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}`);
export const getAlertsUnreadCount = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/alerts/unread-count`);
// Bússola 7: as preferências de avisos de quem está ligado (hoje, os avisos dos sinais de nota alta do Radar).
export const getAlertPreferences = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/alerts/preferences`);
export const updateAlertPreferences = (companyId: number, data: { radar_high_score: boolean }) => api.put(url.GET_COMPANIES + `/${companyId}/alerts/preferences`, data);

// Gestão por agências: as empresas onde a pessoa pode trabalhar (a própria e as geridas; o root vê todas).
export const getWorkingCompanies = () => api.get(url.GET_COMPANIES);
// Empresa gerida: a agência gestora; terminar a relação (só o admin da empresa); primeiro admin (a agência).
export const getCompanyManagement = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/management`);
export const endCompanyManagement = (companyId: number, reason?: string, connections?: "keep" | "disconnect") =>
    api.delete(url.GET_COMPANIES + `/${companyId}/management`, { data: { reason: reason || null, connections: connections ?? null } });
// F1d: pedidos de gestão recebidos (só os admins da empresa) e a escolha sobre as ligações da agência.
export const getCompanyManagementRequests = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/management/requests`);
export const acceptCompanyManagementRequest = (companyId: number, requestId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/management/requests/${requestId}/accept`, {}, JSON_BODY);
export const declineCompanyManagementRequest = (companyId: number, requestId: number, reason?: string) =>
    api.create(url.GET_COMPANIES + `/${companyId}/management/requests/${requestId}/decline`, { reason: reason || null }, JSON_BODY);
export const decideCompanyConnections = (companyId: number, decision: "keep" | "disconnect") =>
    api.create(url.GET_COMPANIES + `/${companyId}/management/connections`, { decision }, JSON_BODY);
// F1d: a agência pede a gestão de uma empresa existente e termina a relação com um cliente.
export const getAgencyManagementRequests = (agencyId: number) => api.get(AG(agencyId) + `/management-requests`);
// F1e: quantas empresas contam para a agência (paga quem dá o acesso), mês atual e seguinte.
export const getAgencyBilling = (agencyId: number) => api.get(AG(agencyId) + `/billing`);
export const createAgencyManagementRequest = (agencyId: number, data: { nipc?: string; email?: string; message?: string; authorization_declared: boolean }) =>
    api.create(AG(agencyId) + `/management-requests`, data, JSON_BODY);
export const withdrawAgencyManagementRequest = (agencyId: number, requestId: number) => api.create(AG(agencyId) + `/management-requests/${requestId}/withdraw`, {}, JSON_BODY);
export const endAgencyManagement = (agencyId: number, companyId: number, reason: string) => api.create(AG(agencyId) + `/managed/${companyId}/end`, { reason }, JSON_BODY);
// Parceiro 2: a ligação da Meta da agência (estado, autorizar e desligar).
export const getAgencyMeta = (agencyId: number) => api.get(AG(agencyId) + `/meta`);
export const getAgencyMetaAuthUrl = (agencyId: number) => api.get(AG(agencyId) + `/meta/auth-url`);
export const disconnectAgencyMeta = (agencyId: number) => api.delete(AG(agencyId) + `/meta`);
// Parceiro 3: os portfólios e os ativos partilhados com a agência (só leitura).
export const getAgencyMetaPortfolios = (agencyId: number, refresh = false) => api.get(AG(agencyId) + `/meta/portfolios`, refresh ? { refresh: 1 } : undefined);
// Parceiro 4: ligar ativos da Meta da agência a uma empresa gerida (cada ativo só numa empresa).
export const getAgencyMetaLinks = (agencyId: number) => api.get(AG(agencyId) + `/meta/links`);
export const linkAgencyMetaAsset = (agencyId: number, data: { company_id: number; asset_type: "page" | "instagram" | "ad_account"; external_id: string; replace?: boolean }) =>
    api.create(AG(agencyId) + `/meta/links`, data, JSON_BODY);
export const unlinkAgencyMetaAsset = (agencyId: number, linkId: number) => api.delete(AG(agencyId) + `/meta/links/${linkId}`);
// F1d (root): pedidos de gestão com a situação de faturação, e terminar uma relação.
export const getAdminManagementRequests = (status?: string) => api.get(url.GET_ADMIN + `/management-requests`, status ? { status } : undefined);
export const endAdminCompanyManagement = (companyId: number, reason: string) => api.create(url.GET_ADMIN + `/companies/${companyId}/management/end`, { reason }, JSON_BODY);
export const inviteFirstAdmin = (companyId: number, data: { name: string; email: string }) =>
    api.create(url.GET_COMPANIES + `/${companyId}/management/first-admin`, data, JSON_BODY);
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
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/connect`, data, JSON_BODY);
export const syncPingwin = (companyId: number, date?: string) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/sync`, date ? { date } : {}, JSON_BODY);
// Gatilho do dashboard: mete a sincronização na FILA (não trava; notifica no sino).
export const queuePingwinSync = (companyId: number, date?: string) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/sync-queue`, date ? { date } : {}, JSON_BODY);
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
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/documents/sync`, {}, JSON_BODY);
// Documentos — LEITURA RICA (Fase D0): sincronizar config completa + detalhe por id.
export const syncPingwinDocumentsRich = (companyId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/documents/sync-rich`, {}, JSON_BODY);
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
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/documents`, { fields }, JSON_BODY);
// D4 (ESCRITA): anular (soft-delete) um documento ativo por id.
export const voidPingwinDocumentConfig = (companyId: number, externalId: string) =>
    api.delete(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/documents/${externalId}`);

export const getPingwinCatalog = (
    companyId: number,
    params?: { page?: number; perPage?: number; search?: string; family?: string; forsale?: number; forpurchase?: number; sort?: string; dir?: "asc" | "desc" }
) => api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/catalog`, params);
export const syncPingwinCatalog = (companyId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/catalog/sync`, {}, JSON_BODY);

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
// A cópia local do artigo (abre logo, sem ir ao PingWin).
export const getArticleCopy = (companyId: number, pingwinId: string) =>
    api.get(A(companyId) + `/${pingwinId}/copy`);
// "Atualizar do PingWin": lê o artigo ao vivo e grava na cópia (com limite de pedidos). Poll em read/{token}.
export const refreshArticle = (companyId: number, pingwinId: string) =>
    api.create(A(companyId) + `/${pingwinId}/refresh`, {}, JSON_BODY);
// ⚠️ ESCRITA: criar (exige confirm:true). Poll em articles/creation/{id}.
export const createArticle = (companyId: number, data: Record<string, any>) =>
    api.create(A(companyId), data, JSON_BODY);
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
    api.create(ED(companyId) + `/sector`, { sector_id: sectorId }, JSON_BODY);
// B3b — TROCA de ramo (PUT, destrutiva). Distinta da 1.ª escolha; devolve o calendar novo.
export const changeEditorialSector = (companyId: number, sectorId: number) =>
    api.put(ED(companyId) + `/sector`, { sector_id: sectorId });
// Calendário herdado dos próximos 12 meses (has_sector:false = ainda por escolher).
export const getEditorialCalendar = (companyId: number) => api.get(ED(companyId) + `/calendar`);
// B2 — máquina de estados dos meses (devolvem o array 'months' atualizado).
export const openEditorialMonth = (companyId: number, year: number, month: number) =>
    api.create(ED(companyId) + `/months/${year}/${month}/open`, {}, JSON_BODY);
export const closeEditorialMonth = (companyId: number, year: number, month: number) =>
    api.create(ED(companyId) + `/months/${year}/${month}/close`, {}, JSON_BODY);
// B3a — esconder/mostrar HERDADAS (id de content_anchors) por ocorrência; devolvem o calendar.
export const hideEditorialAnchor = (companyId: number, anchorId: number, year: number) =>
    api.create(ED(companyId) + `/anchors/${anchorId}/hide`, { year }, JSON_BODY);
export const showEditorialAnchor = (companyId: number, anchorId: number, year: number) =>
    api.create(ED(companyId) + `/anchors/${anchorId}/show`, { year }, JSON_BODY);
// B3a — criar/apagar PRÓPRIAS (id de editorial_own_anchors — espaço distinto).
export const createEditorialOwnAnchor = (companyId: number, payload: any) =>
    api.create(ED(companyId) + `/anchors`, payload, JSON_BODY);
export const deleteEditorialOwnAnchor = (companyId: number, ownAnchorId: number) =>
    api.delete(ED(companyId) + `/own-anchors/${ownAnchorId}`);
// Os POST vão em multipart por omissão (api_helper), onde true e false viram texto: a Linha Editorial envia JSON.
const ED_JSON = JSON_BODY;
// P1 — PUBLICAÇÕES (espaço de id distinto das âncoras); devolvem o calendar atualizado.
export const createEditorialPost = (companyId: number, payload: any) =>
    api.create(ED(companyId) + `/posts`, payload, ED_JSON);
export const updateEditorialPost = (companyId: number, postId: number, payload: any) =>
    api.put(ED(companyId) + `/posts/${postId}`, payload);
export const deleteEditorialPost = (companyId: number, postId: number) =>
    api.delete(ED(companyId) + `/posts/${postId}`);
// F3a: produção e aprovação (Kanban, versões, comentários, histórico, definições).
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
// Ficheiros → Linha Editorial: a media de um ficheiro, sem o copiar ("Escolher dos Ficheiros").
export const pickMediaFromFile = (companyId: number, storedFileId: number) =>
    api.create(ED(companyId) + `/media/from-file`, { stored_file_id: storedFileId }, { headers: { "Content-Type": "application/json" } });
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
// F2.8: "Tentar de novo" numa publicação automática que falhou.
export const retryPostPublication = (companyId: number, postId: number, network: string) =>
    api.create(ED(companyId) + `/posts/${postId}/retry-publication`, { network }, { headers: { "Content-Type": "application/json" } });
export const markPostPublished = (companyId: number, postId: number, data: { network: string; url: string; published_at: string }) =>
    api.create(ED(companyId) + `/posts/${postId}/published`, data, { headers: { "Content-Type": "application/json" } });
export const savePostResults = (companyId: number, postId: number, data: Record<string, unknown>) => api.put(ED(companyId) + `/posts/${postId}/results`, data);
export const getEditorialResults = (companyId: number, month: string) => api.get(ED(companyId) + `/results`, { month });
// F3c: links de aprovação por lote (o link em si é público: /aprovar#<token>).
const REVIEW_JSON = JSON_BODY;
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

// F1-3 do marketing da restauração: dados para o marketing e categorias das famílias.
export const getPingwinMarketingData = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/marketing-data`);
export const getPingwinHeatmap = (companyId: number, locationId?: number | null, weeks?: number, excludeSpecial?: boolean, forBussola?: boolean) =>
    // Na Bússola, a rota própria (bussola.ver; sem Finanças, as vendas em intensidade, sem euros).
    api.get(url.GET_COMPANIES + `/${companyId}/${forBussola ? "marketing/bussola/heatmap" : "integrations/pingwin/heatmap"}`, { ...(locationId ? { location_id: locationId } : {}), ...(weeks ? { weeks } : {}), ...(excludeSpecial ? { exclude_special: 1 } : {}) });
// Bússola (marketing da restauração): a página, criar publicação e sugerir texto a partir de uma jogada.
export const getBussola = (companyId: number, locationId?: number | null, summary?: boolean, playsOnly?: boolean) =>
    api.get(url.GET_COMPANIES + `/${companyId}/marketing/bussola`, { ...(locationId ? { location_id: locationId } : {}), ...(summary ? { summary: 1 } : {}), ...(playsOnly ? { plays_only: 1 } : {}) });
// "Não voltar a sugerir este artigo" (permanente) e "Voltar a incluir" (página das categorias).
export const excludeRestaurantItem = (companyId: number, key: string) => api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/signals/exclude-item`, { key }, JSON_BODY);
export const includeRestaurantItem = (companyId: number, itemId: number) => api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/excluded-items/${itemId}/include`, {}, JSON_BODY);
export const createPostFromPlay = (companyId: number, data: any) => api.create(url.GET_COMPANIES + `/${companyId}/marketing/bussola/post`, data, JSON_BODY);
export const requestPlayCaption = (companyId: number, data: { play_key: string; location_id?: number | null; networks: string[] }) =>
    api.create(url.GET_COMPANIES + `/${companyId}/marketing/bussola/caption`, data, JSON_BODY);
export const getPlayCaption = (companyId: number, id: number) => api.get(url.GET_COMPANIES + `/${companyId}/marketing/bussola/caption/${id}`);
// Bússola do stand (veículos): a página, criar publicação, sugerir texto, ignorar e não voltar a sugerir um veículo.
export const getBussolaStand = (companyId: number, type: "all" | "car" | "motorhome" = "all", playsOnly?: boolean) =>
    api.get(url.GET_COMPANIES + `/${companyId}/marketing/bussola-stand`, { type, ...(playsOnly ? { plays_only: 1 } : {}) });
export const createPostFromStandPlay = (companyId: number, data: any) => api.create(url.GET_COMPANIES + `/${companyId}/marketing/bussola-stand/post`, data, JSON_BODY);
export const requestStandPlayCaption = (companyId: number, data: { play_key: string; networks: string[] }) =>
    api.create(url.GET_COMPANIES + `/${companyId}/marketing/bussola-stand/caption`, data, JSON_BODY);
export const getStandPlayCaption = (companyId: number, id: number) => api.get(url.GET_COMPANIES + `/${companyId}/marketing/bussola-stand/caption/${id}`);
export const ignoreStandSignals = (companyId: number, keys: string[]) => api.create(url.GET_COMPANIES + `/${companyId}/marketing/bussola-stand/ignore`, { keys }, JSON_BODY);
export const restoreStandSignal = (companyId: number, key: string) => api.create(url.GET_COMPANIES + `/${companyId}/marketing/bussola-stand/restore`, { key }, JSON_BODY);
export const excludeStandVehicle = (companyId: number, key: string) => api.create(url.GET_COMPANIES + `/${companyId}/marketing/bussola-stand/exclude`, { key }, JSON_BODY);
// Bússola do imobiliário e a carteira de imóveis (Bússola Master, R3).
export const getBussolaImoveis = (companyId: number, type: "all" | "venda" | "arrendamento" = "all", playsOnly?: boolean) =>
    api.get(url.GET_COMPANIES + `/${companyId}/marketing/bussola-imoveis`, { type, ...(playsOnly ? { plays_only: 1 } : {}) });
export const createPostFromImoveisPlay = (companyId: number, data: any) => api.create(url.GET_COMPANIES + `/${companyId}/marketing/bussola-imoveis/post`, data, JSON_BODY);
export const requestImoveisPlayCaption = (companyId: number, data: { play_key: string; networks: string[] }) =>
    api.create(url.GET_COMPANIES + `/${companyId}/marketing/bussola-imoveis/caption`, data, JSON_BODY);
export const getImoveisPlayCaption = (companyId: number, id: number) => api.get(url.GET_COMPANIES + `/${companyId}/marketing/bussola-imoveis/caption/${id}`);
export const ignoreImoveisSignals = (companyId: number, keys: string[]) => api.create(url.GET_COMPANIES + `/${companyId}/marketing/bussola-imoveis/ignore`, { keys }, JSON_BODY);
export const excludeImoveisProperty = (companyId: number, key: string) => api.create(url.GET_COMPANIES + `/${companyId}/marketing/bussola-imoveis/exclude`, { key }, JSON_BODY);
export const getProperties = (companyId: number, params: { status?: string; listing?: string } = {}) => api.get(url.GET_COMPANIES + `/${companyId}/properties`, params);
export const createProperty = (companyId: number, data: any) => api.create(url.GET_COMPANIES + `/${companyId}/properties`, data, JSON_BODY);
export const updateProperty = (companyId: number, id: number, data: any) => api.put(url.GET_COMPANIES + `/${companyId}/properties/${id}`, data);
export const deleteProperty = (companyId: number, id: number) => api.delete(url.GET_COMPANIES + `/${companyId}/properties/${id}`);
// Site do consultor: o site dos imóveis, os empreendimentos, o perfil do site e os contactos recebidos.
const MULTIPART = { headers: { "Content-Type": "multipart/form-data" } };
const CO = (companyId: number) => url.GET_COMPANIES + `/${companyId}`;
export const getListingsSummary = (companyId: number) => api.get(CO(companyId) + "/consultant-site/listings-summary");
export const getPropertySite = (companyId: number, id: number) => api.get(CO(companyId) + `/properties/${id}/site`);
export const updatePropertySite = (companyId: number, id: number, data: any) => api.put(CO(companyId) + `/properties/${id}/site`, data);
export const publishProperty = (companyId: number, id: number) => api.create(CO(companyId) + `/properties/${id}/publish`, {}, JSON_BODY);
export const unpublishProperty = (companyId: number, id: number) => api.create(CO(companyId) + `/properties/${id}/unpublish`, {}, JSON_BODY);
export type ListingOwner = "properties" | "developments";
export const uploadListingImages = (companyId: number, owner: ListingOwner, id: number, files: File[]) => {
    const data = new FormData();
    files.forEach((f) => data.append("images[]", f));
    return api.create(CO(companyId) + `/${owner}/${id}/images`, data, MULTIPART);
};
export const orderListingImages = (companyId: number, owner: ListingOwner, id: number, ids: number[], coverId: number | null) =>
    api.put(CO(companyId) + `/${owner}/${id}/images/order`, { ids, cover_id: coverId });
export const updateListingImage = (companyId: number, owner: ListingOwner, id: number, imageId: number, alt: string) =>
    api.update(CO(companyId) + `/${owner}/${id}/images/${imageId}`, { alt });
export const deleteListingImage = (companyId: number, owner: ListingOwner, id: number, imageId: number) => api.delete(CO(companyId) + `/${owner}/${id}/images/${imageId}`);
export const getDevelopments = (companyId: number) => api.get(CO(companyId) + "/developments");
export const getDevelopment = (companyId: number, id: number) => api.get(CO(companyId) + `/developments/${id}`);
export const createDevelopment = (companyId: number, data: any) => api.create(CO(companyId) + "/developments", data, JSON_BODY);
export const updateDevelopment = (companyId: number, id: number, data: any) => api.put(CO(companyId) + `/developments/${id}`, data);
export const deleteDevelopment = (companyId: number, id: number) => api.delete(CO(companyId) + `/developments/${id}`);
export const publishDevelopment = (companyId: number, id: number) => api.create(CO(companyId) + `/developments/${id}/publish`, {}, JSON_BODY);
export const unpublishDevelopment = (companyId: number, id: number) => api.create(CO(companyId) + `/developments/${id}/unpublish`, {}, JSON_BODY);
export const getConsultantSite = (companyId: number) => api.get(CO(companyId) + "/consultant-site");
export const updateConsultantSite = (companyId: number, data: any) => api.put(CO(companyId) + "/consultant-site", data);
export const uploadConsultantSiteAsset = (companyId: number, slot: string, file: File) => {
    const data = new FormData();
    data.append("file", file);
    return api.create(CO(companyId) + `/consultant-site/assets/${slot}`, data, MULTIPART);
};
export const deleteConsultantSiteAsset = (companyId: number, slot: string) => api.delete(CO(companyId) + `/consultant-site/assets/${slot}`);
export const uploadConsultantSiteZonePhoto = (companyId: number, file: File, alt: string | null, parishId: number | null) => {
    const data = new FormData();
    data.append("file", file);
    if (alt) data.append("alt", alt);
    if (parishId) data.append("parish_id", String(parishId));
    return api.create(CO(companyId) + "/consultant-site/zone-photos", data, MULTIPART);
};
export const deleteConsultantSiteZonePhoto = (companyId: number, photoId: string) => api.delete(CO(companyId) + `/consultant-site/zone-photos/${photoId}`);
export const publishConsultantSite = (companyId: number) => api.create(CO(companyId) + "/consultant-site/publish", {}, JSON_BODY);
export const unpublishConsultantSite = (companyId: number) => api.create(CO(companyId) + "/consultant-site/unpublish", {}, JSON_BODY);
export const previewConsultantSite = (companyId: number) => api.create(CO(companyId) + "/consultant-site/preview", {}, JSON_BODY);
export const getConsultantSiteHosts = (companyId: number) => api.get(`/admin/companies/${companyId}/consultant-site/hosts`);
export const updateConsultantSiteHosts = (companyId: number, hosts: { host: string; is_primary: boolean }[]) => api.put(`/admin/companies/${companyId}/consultant-site/hosts`, { hosts });
export const getInboundContacts = (companyId: number, params: { status?: string; type?: string } = {}) => api.get(CO(companyId) + "/inbound-contacts", params);
export const getInboundContact = (companyId: number, id: number) => api.get(CO(companyId) + `/inbound-contacts/${id}`);
export const updateInboundContact = (companyId: number, id: number, status: string) => api.update(CO(companyId) + `/inbound-contacts/${id}`, { status });
export const deleteInboundContact = (companyId: number, id: number) => api.delete(CO(companyId) + `/inbound-contacts/${id}`);
// Radar de oportunidade (Bússola Master, R2): os cartões, "Reagir" (ideia sem texto), "Ignorar", "Não mostrar temas como este" e as definições.
export const getRadar = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/marketing/radar`);
export const reactRadar = (companyId: number, data: any) => api.create(url.GET_COMPANIES + `/${companyId}/marketing/radar/react`, data, JSON_BODY);
export const ignoreRadar = (companyId: number, signalId: number) => api.create(url.GET_COMPANIES + `/${companyId}/marketing/radar/ignore`, { signal_id: signalId }, JSON_BODY);
export const muteRadar = (companyId: number, signalId: number) => api.create(url.GET_COMPANIES + `/${companyId}/marketing/radar/mute`, { signal_id: signalId }, JSON_BODY);
export const unmuteRadar = (companyId: number, index: number) => api.create(url.GET_COMPANIES + `/${companyId}/marketing/radar/unmute`, { index }, JSON_BODY);
export const updateRadarSettings = (companyId: number, data: { sports_enabled: boolean; sports_clubs: string[] }) => api.put(url.GET_COMPANIES + `/${companyId}/marketing/radar/settings`, data);
// Bússola 5: o "Vem aí" (datas oficiais do ramo e do concelho) sem o módulo radar, no módulo linha_editorial.
export const getCompassCalendar = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}/marketing/calendar`);
export const reactCompassCalendar = (companyId: number, data: any) => api.create(url.GET_COMPANIES + `/${companyId}/marketing/calendar/react`, data, JSON_BODY);
// F3: "O que publicar e quando".
export const getRestaurantSignals = (companyId: number, locationId?: number | null, showIgnored?: boolean) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/signals`, { ...(locationId ? { location_id: locationId } : {}), ...(showIgnored ? { show_ignored: 1 } : {}) });
// Em JSON (o POST por omissão vai em multipart): a lista das redes chega como lista.
export const ignoreRestaurantSignal = (companyId: number, key: string) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/signals/ignore`, { key }, JSON_BODY);
export const restoreRestaurantSignal = (companyId: number, key: string) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/signals/restore`, { key }, JSON_BODY);
export const createPostFromSignal = (companyId: number, data: { key: string; title: string; publish_date: string; networks: string[]; format: string }) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/signals/post`, data, JSON_BODY);
export const getFamilyCategories = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/family-categories`);
export const suggestFamilyCategories = (companyId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/family-categories/ai-suggest`, {}, JSON_BODY);
export const updateAnnualLocals = (companyId: number, locals: string) =>
    api.put(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/annual-locals`, { locals });
export const confirmFamilyCategories = (companyId: number, items: { family_pingwin_id: string; category: string }[]) =>
    api.put(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/family-categories`, { items });
export const getPingwinFamilies = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/families`);
export const syncPingwinFamilies = (companyId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/families/sync`, {}, JSON_BODY);

export const getPingwinUnits = (
    companyId: number,
    params?: { page?: number; perPage?: number; search?: string; active?: number }
) => api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/units`, params);
export const syncPingwinUnits = (companyId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/units/sync`, {}, JSON_BODY);
// ⚠️ ESCRITA no PingWin: criar unidade (exige confirm:true). Ação deliberada.
export const createPingwinUnit = (companyId: number, data: Record<string, any>) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/units/create`, data, JSON_BODY);
export const getPingwinUnitCreation = (companyId: number, creationId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/units/creations/${creationId}`);
// ⚠️ ESCRITA: editar/anular unidade (exige confirm:true). Ação deliberada.
export const editPingwinUnit = (companyId: number, unitId: number, data: Record<string, any>) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/units/${unitId}/edit`, data, JSON_BODY);
export const anularPingwinUnit = (companyId: number, unitId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/units/${unitId}/anular`, { confirm: true }, JSON_BODY);
export const getPingwinUnitUsage = (companyId: number, unitId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/units/${unitId}/usage`);

export const getPingwinSuppliers = (
    companyId: number,
    params?: { page?: number; perPage?: number; search?: string; active?: number }
) => api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/suppliers`, params);
export const syncPingwinSuppliers = (companyId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/suppliers/sync`, {}, JSON_BODY);
// FN — ⚠️ ESCRITA de fornecedores no PingWin (assíncrona: devolve write_id → polling).
export const createPingwinSupplier = (companyId: number, data: Record<string, any>) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/suppliers`, data, JSON_BODY);
export const updatePingwinSupplier = (companyId: number, supplierId: number, data: Record<string, any>) =>
    api.put(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/suppliers/${supplierId}`, data);
export const voidPingwinSupplier = (companyId: number, supplierId: number) =>
    api.delete(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/suppliers/${supplierId}`);
export const getPingwinSupplierWrite = (companyId: number, writeId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/suppliers/writes/${writeId}`);
// Condições de Pagamento PingWin (Fatia 1, só leitura): lista paginada + sincronizar.
export const getPingwinPaymentConditions = (
    companyId: number,
    params?: { page?: number; perPage?: number; search?: string; active?: number }
) => api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/payment-conditions`, params);
export const syncPingwinPaymentConditions = (companyId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/payment-conditions/sync`, {}, JSON_BODY);
// Fatia 2a (ESCRITA): template de documentos (p/ o modal), criar, e polling da criação.
export const getPingwinPaymentConditionDocsTemplate = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/payment-conditions/docs-template`);
export const createPingwinPaymentCondition = (
    companyId: number,
    body: { code?: string; description: string; discount?: number; days?: number; tbdocs_unlinked?: string[] }
) => api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/payment-conditions`, body, JSON_BODY);
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
// S2 — Conta Corrente de Fornecedor (só leitura no PingWin). Valores em cêntimos.
export const getSupplierCcOverview = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/supplier-cc`);
export const getSupplierCc = (companyId: number, supplierId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/supplier-cc/${supplierId}`);
export const getSupplierCcStatement = (companyId: number, supplierId: number, params: { from?: string; to?: string; store?: string }) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/supplier-cc/${supplierId}/statement`, params);
export const refreshSupplierCc = (companyId: number, supplierId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/supplier-cc/${supplierId}/refresh`, {}, JSON_BODY);
export const getSupplierCcStatus = (companyId: number, supplierId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/supplier-cc/${supplierId}/status`);
// F1 — Documentos de fornecedor do PingWin (só leitura): lista, sync por período (async) e estado do run.
export const getPingwinSupplierDocuments = (
    companyId: number,
    params?: { page?: number; perPage?: number; from?: string; to?: string; types?: string; supplier?: string; missing_ref?: number }
) => api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/supplier-documents`, params);
export const syncPingwinSupplierDocuments = (companyId: number, period: { from: string; to: string }) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/supplier-documents/sync`, period, JSON_BODY);
export const getPingwinSupplierDocumentsRun = (companyId: number, runId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/supplier-documents/sync-runs/${runId}`);
export const uploadOcrInvoice = (companyId: number, file: File) => {
    const fd = new FormData();
    fd.append("file", file);
    return api.create(url.GET_COMPANIES + `/${companyId}/ocr/invoices`, fd, { headers: { "Content-Type": "multipart/form-data" } });
};
// Fatia 5: a conferência da fatura com as guias (só leitura).
export const getOcrInvoiceGuides = (companyId: number, invoiceId: number) => api.get(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/guides`);
export const getOcrInvoice = (companyId: number, invoiceId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}`);
// A rota só aceita PUT (o api.update envia PATCH e dava 405 ao gravar a fatura).
export const updateOcrInvoice = (companyId: number, invoiceId: number, data: Record<string, any>) =>
    api.put(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}`, data);
// F2a: reprocessar (async — volta a 'processing'; a página faz polling como no upload).
// "Reler do documento": keep_manual = manter as alterações manuais (voltam a ser aplicadas à nova leitura).
export const reprocessOcrInvoice = (companyId: number, invoiceId: number, data: { keep_manual?: boolean } = {}) =>
    api.create(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/reprocess`, data, JSON_BODY);
export const getOcrManualChanges = (companyId: number, invoiceId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/manual-changes`);
// "Revalidar": grava as linhas de agora (sem mudar o estado) e volta a correr as validações. Sem IA.
export const revalidateOcrInvoice = (companyId: number, invoiceId: number, data: Record<string, any>) =>
    api.create(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/revalidate`, data, { headers: { "Content-Type": "application/json" } });
// A imagem está num disco privado (atrás de auth) → buscar como blob (o axios põe o token).
export const getOcrInvoiceImageBlob = (companyId: number, invoiceId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/image`, { responseType: "blob" } as any);
// CoverManager (reservas) — Etapa 1: sincroniza o agregado por turno de uma data.
export const syncCoverManager = (companyId: number, date: string) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/covermanager/sync`, { date }, JSON_BODY);
// Sincronização por PERÍODO (um job por dia; notificação única no fim).
export const syncRestaurantPeriod = (companyId: number, from: string, to: string) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/restaurant/sync-period`, { from, to }, JSON_BODY);
// CoverManager — token AO NÍVEL DA EMPRESA (Integrações). O token nunca é devolvido.
export const getCoverManager = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/covermanager`);
export const connectCoverManager = (companyId: number, token: string) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/covermanager/connect`, { token }, JSON_BODY);
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
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/locations`, data, JSON_BODY);
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
    api.create(url.GET_ADMIN + `/companies/${companyId}/modules/preset`, { preset }, JSON_BODY);

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
    api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_EXPENSE_CATEGORIES, data, JSON_BODY);
export const updateExpenseCategory = (companyId: number, id: number, data: any) =>
    api.update(url.GET_COMPANIES + `/${companyId}` + url.GET_EXPENSE_CATEGORIES + `/${id}`, data);
export const deleteExpenseCategory = (companyId: number, id: number) =>
    api.delete(url.GET_COMPANIES + `/${companyId}` + url.GET_EXPENSE_CATEGORIES + `/${id}`);
export const importSuggestedExpenseCategories = (companyId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}` + url.POST_EXPENSE_CATEGORIES_IMPORT, {}, JSON_BODY);
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
// Lista de leads paginada no servidor: pesquisa (nome, telefone, email, carro) e ordenação (CarLeadController::sorts).
export const getLeads = (params: { perPage: number; page: number; companyId: number; search?: string; sort?: "created_at" | "name" | "status" | "car"; dir?: "asc" | "desc"; id?: number }) => {
    const { companyId, ...query } = params;
    return api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_LEADS_APIS, { params: query });
};
export const updateLead = (companyId: number, leadId: number, data: { status?: string; lost_reason?: string | null; notes?: string | null }) => api.put(url.GET_COMPANIES + `/${companyId}` + url.GET_LEADS_APIS + `/${leadId}`, data);
// CRM/funil — todas as leads da empresa (sem paginação) para montar o Kanban.
export const getCompanyLeadsAll = (companyId: number) => api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_LEADS_APIS);

// FINANCIAMENTO: envio seguro de documentos (links privados para o cliente; área "financiamento").
const finBase = (companyId: number) => url.GET_COMPANIES + `/${companyId}`;
export const getCarFinancingLinks = (companyId: number, carId: number) => api.get(finBase(companyId) + `/cars/${carId}/financing-links`);
export const createCarFinancingLink = (companyId: number, carId: number, data: { customer_name: string; items: string[]; other?: string; days?: number }) =>
    api.create(finBase(companyId) + `/cars/${carId}/financing-links`, data, JSON_BODY);
export const getLeadFinancingLinks = (companyId: number, leadId: number) => api.get(finBase(companyId) + `/leads/${leadId}/financing-links`);
export const createLeadFinancingLink = (companyId: number, leadId: number, data: { customer_name: string; items: string[]; other?: string; days?: number }) =>
    api.create(finBase(companyId) + `/leads/${leadId}/financing-links`, data, JSON_BODY);
export const getFinancingLinkUrl = (companyId: number, linkId: number) => api.get(finBase(companyId) + `/financing-links/${linkId}/url`);
export const revokeFinancingLink = (companyId: number, linkId: number) => api.create(finBase(companyId) + `/financing-links/${linkId}/revoke`, {}, JSON_BODY);
export const deleteFinancingLink = (companyId: number, linkId: number) => api.delete(finBase(companyId) + `/financing-links/${linkId}`);
export const getFinancingDocumentDownload = (companyId: number, documentId: number) => api.get(finBase(companyId) + `/financing-documents/${documentId}/download`);
export const deleteFinancingDocument = (companyId: number, documentId: number) => api.delete(finBase(companyId) + `/financing-documents/${documentId}`);
export const updateFinancingSettings = (companyId: number, retentionDays: number, defaultItems?: string[]) =>
    api.put(finBase(companyId) + `/financing-settings`, defaultItems ? { retention_days: retentionDays, default_items: defaultItems } : { retention_days: retentionDays });
// Pedido com 2 titulares: acrescentar o segundo e apagar o pedido inteiro.
export const addFinancingSecondHolder = (companyId: number, requestId: number, data: { customer_name?: string; relationship?: string; relationship_other?: string }) =>
    api.create(finBase(companyId) + `/financing-requests/${requestId}/second-holder`, data, JSON_BODY);
export const deleteFinancingRequest = (companyId: number, requestId: number) => api.delete(finBase(companyId) + `/financing-requests/${requestId}`);
// RETOMA (pedido de proposta): fotografias e DUA da lead.
export const getLeadTradeIn = (companyId: number, leadId: number) => api.get(finBase(companyId) + `/leads/${leadId}/trade-in`);
export const createLeadTradeInLink = (companyId: number, leadId: number, days?: number) => api.create(finBase(companyId) + `/leads/${leadId}/trade-in/link`, days ? { days } : {}, JSON_BODY);
export const getTradeInLinkUrl = (companyId: number, linkId: number) => api.get(finBase(companyId) + `/trade-in-links/${linkId}/url`);
export const revokeTradeInLink = (companyId: number, linkId: number) => api.create(finBase(companyId) + `/trade-in-links/${linkId}/revoke`, {}, JSON_BODY);
export const getTradeInFileDownload = (companyId: number, documentId: number) => api.get(finBase(companyId) + `/trade-in-files/${documentId}/download`);
export const deleteTradeInFile = (companyId: number, documentId: number) => api.delete(finBase(companyId) + `/trade-in-files/${documentId}`);
// AVISOS DO SITE (pop-up).
export const getSitePopups = (companyId: number) => api.get(finBase(companyId) + `/site-popups`);
export const getSitePopup = (companyId: number, popupId: number) => api.get(finBase(companyId) + `/site-popups/${popupId}`);
// Em JSON (o POST do painel vai como formulário por omissão, e os booleanos e os nulos não passariam).
export const createSitePopup = (companyId: number, data: any) => api.create(finBase(companyId) + `/site-popups`, data, { headers: { "Content-Type": "application/json" } });
export const updateSitePopup = (companyId: number, popupId: number, data: any) => api.put(finBase(companyId) + `/site-popups/${popupId}`, data);
export const deleteSitePopup = (companyId: number, popupId: number) => api.delete(finBase(companyId) + `/site-popups/${popupId}`);

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
        // Ordenação no servidor: só as chaves de CarRepository::sorts() (car, price, views, leads, interactions, conversion).
        sort?: string;
        dir?: 'asc' | 'desc';
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
            sort: params.sort,
            dir: params.dir,
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
    api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}/sale/link-lead`, { lead_id: leadId }, JSON_BODY);
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
export const postCarAiAnalyses = (companyId: number, carId: number) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS_ANALYSES + `/${carId}`, {}, JSON_BODY);
export const postCarRecalculate = (companyId: number, carId: number) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}/potential-score/recalculate`, {}, JSON_BODY);
export const postCarMetaAdsRefresh = (companyId: number, carId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}` + url.POST_CAR_META_ADS_REFRESH, {}, JSON_BODY);
export const postCarAnalysisRegenerate = (companyId: number, carId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}` + url.POST_CAR_ANALYSIS_REGENERATE, {}, JSON_BODY);

// USERS
// Colaboradores (equipa) e departamentos. As ações de acesso só funcionam para o admin
// da própria empresa e fora da sessão como cliente (o servidor recusa com 403).
const COL = (companyId: number) => url.GET_COMPANIES + `/${companyId}/collaborators`;
// Os POST da app vão por omissão como multipart (os booleanos chegariam como texto): estes vão em JSON.
const JSON_HEADERS = JSON_BODY;
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
export const setCollaboratorActive = (companyId: number, id: number, active: boolean) => api.create(`${COL(companyId)}/${id}/${active ? "activate" : "deactivate"}`, {}, JSON_BODY);
export const grantCollaboratorAccess = (companyId: number, id: number, email: string) => api.create(`${COL(companyId)}/${id}/access`, { email }, JSON_HEADERS);
export const resendCollaboratorInvite = (companyId: number, id: number) => api.create(`${COL(companyId)}/${id}/access/resend`, {}, JSON_BODY);
export const cancelCollaboratorInvite = (companyId: number, id: number) => api.delete(`${COL(companyId)}/${id}/access/invite`);
export const revokeCollaboratorAccess = (companyId: number, id: number) => api.create(`${COL(companyId)}/${id}/access/revoke`, {}, JSON_BODY);
export const restoreCollaboratorAccess = (companyId: number, id: number) => api.create(`${COL(companyId)}/${id}/access/restore`, {}, JSON_BODY);
const DEP = (companyId: number) => url.GET_COMPANIES + `/${companyId}/departments`;
export const getDepartments = (companyId: number) => api.get(DEP(companyId));
export const createDepartment = (companyId: number, data: any) => api.create(DEP(companyId), data, JSON_HEADERS);
export const updateDepartment = (companyId: number, id: number, data: any) => api.update(`${DEP(companyId)}/${id}`, data);
export const deleteDepartment = (companyId: number, id: number) => api.delete(`${DEP(companyId)}/${id}`);
export const createSuggestedDepartments = (companyId: number) => api.create(`${DEP(companyId)}/suggested`, {}, JSON_BODY);

export const getUsersPaginate = (params: { perPage: number; page: number; companyId: number; }) => api.get(url.GET_COMPANIES + `/${params.companyId}` + url.GET_USERS_APIS, { params });
export const showUser = (params: { companyId: number; id: number; }) => api.get(url.GET_COMPANIES + `/${params.companyId}` + url.GET_USERS_APIS + "/" + params.id);
export const updateUser = (companyId: number, id: number, data: FormData | any) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_USERS_APIS + "/" + id, data, { headers: { "Content-Type": "multipart/form-data" } });
export const createUser = (companyId: number, data: FormData | any) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_USERS_APIS, data, { headers: { "Content-Type": "multipart/form-data" } });

// CARMINE
export const showCarmine = (params: { companyId: number; id: number; }) => api.get(url.GET_COMPANIES + `/${params.companyId}` + url.GET_CARMINE_APIS + "/" + params.id);
export const createCarmine = (companyId: number, data: FormData | any) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARMINE_APIS, data);
export const updateCarmine = (companyId: number, id: number, data: FormData | any) => api.put(url.GET_COMPANIES + `/${companyId}` + url.GET_CARMINE_APIS + "/" + id, data);
export const syncCarmine = (companyId: number) => api.create(url.GET_COMPANIES + `/${companyId}` + url.GET_CARMINE_APIS + "/sync", {}, JSON_BODY);

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

// F3 — Ligação Fatura OCR ↔ documento(s) do PingWin (só espelhos; nunca escreve no PingWin).
export const searchOcrPingwinLink = (companyId: number, invoiceId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/pingwin-link`, {}, JSON_BODY);
export const confirmOcrPingwinLink = (companyId: number, invoiceId: number, data: { docheader_ids?: string[]; method?: string }) =>
    api.create(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/pingwin-link/confirm`, data, JSON_BODY);
export const unlinkOcrPingwinLink = (companyId: number, invoiceId: number) =>
    api.delete(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/pingwin-link`);

// F2b — Artigos nas linhas da fatura OCR (associar/criar; as escritas no PingWin são assíncronas).
export const searchOcrArticles = (companyId: number, params: { q?: string; nif?: string | null }) =>
    api.get(url.GET_COMPANIES + `/${companyId}/ocr/articles/search`, params);
export const getOcrArticleForm = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/ocr/articles/form`);
export const associateOcrLineArticle = (companyId: number, invoiceId: number, lineId: number, data: { article_id: number; method?: string; replace_code?: boolean }) =>
    api.create(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/lines/${lineId}/article`, data, JSON_BODY);
/** "Ligar selecionadas a…" e "Aplicar às linhas com a mesma descrição": várias linhas ao mesmo artigo. */
export const bulkAssociateOcrLines = (companyId: number, invoiceId: number, lineIds: number[], articleId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/lines/article-bulk`, { line_ids: lineIds, article_id: articleId }, JSON_BODY);
export const unlinkOcrLineArticle = (companyId: number, invoiceId: number, lineId: number) =>
    api.delete(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/lines/${lineId}/article`);
export const createOcrLineArticle = (companyId: number, invoiceId: number, lineId: number, data: Record<string, any>) =>
    api.create(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/lines/${lineId}/create-article`, data, JSON_BODY);
export const acceptOcrLineSuggestions = (companyId: number, invoiceId: number, minConfidence: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/lines/accept-suggestions`, { min_confidence: minConfidence }, JSON_BODY);

// FB-1 — "Lançar no PingWin" (rascunho → fechar / anular; escritas assíncronas com releitura).
export const getOcrLaunch = (companyId: number, invoiceId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/pingwin-launch`);
export const launchOcrInvoice = (companyId: number, invoiceId: number, data: { accept_check_diff?: boolean; close?: boolean }) =>
    api.create(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/pingwin-launch`, data, JSON_BODY);
export const closeOcrLaunch = (companyId: number, invoiceId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/pingwin-launch/close`, {}, JSON_BODY);
export const voidOcrLaunch = (companyId: number, invoiceId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/pingwin-launch/void`, {}, JSON_BODY);
/** Cadastros › Artigos: "Artigo de agrupamento" (definição da XPLENDOR; não mexe no PingWin). */
// Despesas gerais (sem stock): um artigo por taxa de IVA por fornecedor; só o root marca.
export const getSupplierGeneralExpense = (companyId: number, supplierId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/suppliers/${supplierId}/general-expense`);
export const setSupplierGeneralExpense = (companyId: number, supplierId: number, data: { enabled: boolean; articles?: Record<string, number | null> }) =>
    api.put(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/suppliers/${supplierId}/general-expense`, data);
/** "Aplicar a esta fatura": liga as linhas com valor ao artigo de despesa da sua taxa. */
export const applyOcrGeneralExpense = (companyId: number, invoiceId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/general-expense/apply`, {}, JSON_BODY);
export const getArticleGrouping = (companyId: number, catalogItemId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/articles/${catalogItemId}/grouping`);
export const setArticleGrouping = (companyId: number, catalogItemId: number, grouping: boolean) =>
    api.put(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/articles/${catalogItemId}/grouping`, { grouping });
/** Sem QR: "Conferi os totais com o papel" (marcar ou desmarcar). */
export const setOcrPaperCheck = (companyId: number, invoiceId: number, checked: boolean) =>
    api.create(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/pingwin-launch/paper-check`, { checked }, JSON_BODY);
export const setOcrLineLaunchUnit = (companyId: number, invoiceId: number, lineId: number, data: { unit_id: string; quantity?: number | null }) =>
    api.create(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/lines/${lineId}/launch-unit`, data, JSON_BODY);

// Finanças › Mês: resultado e tesouraria (só leitura) e a classificação manual dos "Por classificar".
export const getFinanceMonth = (companyId: number, params: { month?: string; store?: number | null }) =>
    api.get(url.GET_COMPANIES + `/${companyId}/finance/month`, { month: params.month, ...(params.store ? { store: params.store } : {}) });
export const classifyFinanceDocument = (companyId: number, data: { scope: "documento" | "fornecedor"; docheader_id: string; group: string }) =>
    api.create(url.GET_COMPANIES + `/${companyId}/finance/classifications`, data, JSON_HEADERS);
// ⚠️ "Fechar no PingWin" documentos de fornecedor em Aberto (no máximo 10, um de cada vez, com releitura).
export const closeSupplierDocuments = (companyId: number, docheaderIds: string[]) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/supplier-documents/close`, { docheader_ids: docheaderIds }, JSON_HEADERS);
export const getSupplierDocumentCloseBatch = (companyId: number, batch: string) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/supplier-documents/close-batches/${batch}`);
export const readSupplierDocumentLines = (companyId: number, docheaderIds: string[]) =>
    api.create(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/supplier-documents/read-lines`, { docheader_ids: docheaderIds }, JSON_HEADERS);
// Finanças v2 (só na XPLENDOR): regras de classificação, pagamentos registados e envio à contabilidade.
const FIN = (companyId: number) => url.GET_COMPANIES + `/${companyId}/finance`;
type FinanceScope = { month: string; docheader_ids?: string[] };
export const getFinanceRules = (companyId: number) => api.get(`${FIN(companyId)}/rules`);
export const createFinanceRule = (companyId: number, data: Record<string, any>) => api.create(`${FIN(companyId)}/rules`, data, JSON_HEADERS);
export const updateFinanceRule = (companyId: number, ruleId: number, data: Record<string, any>) => api.put(`${FIN(companyId)}/rules/${ruleId}`, data);
export const deleteFinanceRule = (companyId: number, ruleId: number) => api.delete(`${FIN(companyId)}/rules/${ruleId}`);
export const getFinancePayables = (companyId: number) => api.get(`${FIN(companyId)}/payables`);
export const registerFinancePayment = (companyId: number, data: { docheader_ids: string[]; paid_on: string; method: string; reference?: string | null }) =>
    api.create(`${FIN(companyId)}/payments`, data, JSON_HEADERS);
export const undoFinancePayment = (companyId: number, paymentId: number) => api.delete(`${FIN(companyId)}/payments/${paymentId}`);
export const getFinancePaymentHistory = (companyId: number, docheaderId: string) => api.get(`${FIN(companyId)}/payments/history/${docheaderId}`);
export const markFinanceSent = (companyId: number, data: { docheader_ids: string[]; sent_on: string; send_method: string }) =>
    api.create(`${FIN(companyId)}/accounting/sent`, data, JSON_HEADERS);
export const unmarkFinanceSent = (companyId: number, docheaderIds: string[]) => api.create(`${FIN(companyId)}/accounting/unsent`, { docheader_ids: docheaderIds }, JSON_HEADERS);
export const editFinanceDocument = (companyId: number, docheaderId: string, data: { note?: string | null; situation?: string | null }) =>
    api.put(`${FIN(companyId)}/accounting/documents/${docheaderId}`, data);
export const downloadFinanceSheet = (companyId: number, data: FinanceScope) =>
    api.create(`${FIN(companyId)}/accounting/sheet`, data, { headers: { "Content-Type": "application/json" }, responseType: "blob" } as any);
export const getFinanceAccountingFiles = (companyId: number, data: FinanceScope) => api.create(`${FIN(companyId)}/accounting/files`, data, JSON_HEADERS);
export const downloadFinanceZip = (companyId: number, data: FinanceScope) =>
    api.create(`${FIN(companyId)}/accounting/zip`, data, { headers: { "Content-Type": "application/json" }, responseType: "blob" } as any);
export const previewAccountantEmail = (companyId: number, data: FinanceScope) => api.create(`${FIN(companyId)}/accounting/email-preview`, data, JSON_HEADERS);
export const sendAccountantEmail = (companyId: number, data: FinanceScope) => api.create(`${FIN(companyId)}/accounting/email-send`, data, JSON_HEADERS);
export const getHabitualExclusions = (companyId: number) => api.get(`${FIN(companyId)}/habitual-exclusions`);
export const excludeHabitual = (companyId: number, data: { entity_pingwin_id: string; scope: "mes" | "sempre"; month: string; avg_cents?: number; reason?: string | null }) =>
    api.create(`${FIN(companyId)}/habitual-exclusions`, data, JSON_HEADERS);
export const restoreHabitual = (companyId: number, exclusionId: number) => api.delete(`${FIN(companyId)}/habitual-exclusions/${exclusionId}`);
export const importAccountingSheet = (companyId: number, file: File) => {
    const fd = new FormData();
    fd.append("file", file);
    return api.create(`${FIN(companyId)}/accounting/import`, fd);
};

// F2c — apagar faturas OCR (soft delete), repor, apagar várias; a lista com as apagadas.
export const getOcrInvoicesList = (companyId: number, params: { page?: number; perPage?: number; status?: string; deleted?: 1 }) =>
    api.get(url.GET_COMPANIES + `/${companyId}/ocr/invoices`, params);
export const deleteOcrInvoice = (companyId: number, invoiceId: number) =>
    api.delete(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}`);
export const restoreOcrInvoice = (companyId: number, invoiceId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/restore`, {}, JSON_BODY);
export const bulkDeleteOcrInvoices = (companyId: number, ids: number[]) =>
    api.create(url.GET_COMPANIES + `/${companyId}/ocr/invoices/bulk-delete`, { ids }, JSON_BODY);

// Ficheiros: o gestor de ficheiros da empresa.
const FL = (companyId: number) => url.GET_COMPANIES + `/${companyId}/files`;
const FL_JSON = { headers: { "Content-Type": "application/json" } };
export const getFiles = (companyId: number, params?: { folder_id?: number | null; q?: string }) =>
    api.get(FL(companyId), { ...(params?.folder_id ? { folder_id: params.folder_id } : {}), ...(params?.q ? { q: params.q } : {}) });
export const getFilesTrash = (companyId: number) => api.get(FL(companyId) + `/trash`);
export const getFileDetail = (companyId: number, id: number) => api.get(FL(companyId) + `/${id}`);
export const createFileFolder = (companyId: number, data: { name: string; parent_id?: number | null }) => api.create(FL(companyId) + `/folders`, data, FL_JSON);
export const updateFileFolder = (companyId: number, id: number, data: { name?: string; parent_id?: number | null }) => api.put(FL(companyId) + `/folders/${id}`, data);
export const deleteFileFolder = (companyId: number, id: number) => api.delete(FL(companyId) + `/folders/${id}`);
export const restoreFileFolder = (companyId: number, id: number) => api.create(FL(companyId) + `/folders/${id}/restore`, {}, FL_JSON);
export const uploadFileDocument = (companyId: number, file: File, folderId: number | null, onProgress?: (fraction: number) => void) => {
    const fd = new FormData();
    fd.append("file", file, file.name);
    if (folderId) fd.append("folder_id", String(folderId));
    return api.create(FL(companyId) + `/documents`, fd, { headers: { "Content-Type": "multipart/form-data" }, onUploadProgress: (e: any) => onProgress?.(e.total ? e.loaded / e.total : 0) });
};
export const startFileMediaUpload = (companyId: number, data: { name: string; size: number; mime: string; folder_id?: number | null }) => api.create(FL(companyId) + `/uploads`, data, FL_JSON);
export const sendFileMediaChunk = (companyId: number, id: string, offset: number, chunk: Blob, onProgress?: (loaded: number) => void) => {
    const fd = new FormData();
    fd.append("offset", String(offset));
    fd.append("chunk", chunk, "chunk");
    return api.create(FL(companyId) + `/uploads/${id}/chunk`, fd, { headers: { "Content-Type": "multipart/form-data" }, onUploadProgress: (e: any) => onProgress?.(e.loaded ?? 0) });
};
export const updateStoredFile = (companyId: number, id: number, data: { name?: string; folder_id?: number | null }) => api.put(FL(companyId) + `/${id}`, data);
export const deleteStoredFile = (companyId: number, id: number) => api.delete(FL(companyId) + `/${id}`);
export const restoreStoredFile = (companyId: number, id: number) => api.create(FL(companyId) + `/${id}/restore`, {}, FL_JSON);
export const saveFileToFolder = (companyId: number, id: number, folderId: number) => api.create(FL(companyId) + `/${id}/shortcuts`, { folder_id: folderId }, FL_JSON);
export const deleteFileShortcut = (companyId: number, id: number) => api.delete(FL(companyId) + `/shortcuts/${id}`);
export const restoreFileShortcut = (companyId: number, id: number) => api.create(FL(companyId) + `/shortcuts/${id}/restore`, {}, FL_JSON);


// F2d — vários códigos do fornecedor por artigo (principal no PingWin + alternativos), IVA da linha ≠ IVA do artigo.
export const makeOcrLineCodePrincipal = (companyId: number, invoiceId: number, lineId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/lines/${lineId}/supplier-code/principal`, {}, JSON_BODY);
export const correctOcrLineArticleVat = (companyId: number, invoiceId: number, lineId: number) =>
    api.create(url.GET_COMPANIES + `/${companyId}/ocr/invoices/${invoiceId}/lines/${lineId}/article-vat`, {}, JSON_BODY);
export const getArticleSupplierCodes = (companyId: number, catalogItemId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/articles/${catalogItemId}/supplier-codes`);
export const getArticleVatDivergences = (companyId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/catalog/vat-divergences`);
// Fatia 1: fichas técnicas (só leitura) — lista por Pareto e detalhe de um artigo (id do PingWin).
export const getRecipes = (
    companyId: number,
    params?: { page?: number; perPage?: number; search?: string; family?: string; location_id?: number; state?: string; sort?: string; dir?: "asc" | "desc" }
) => api.get(url.GET_COMPANIES + `/${companyId}/restaurant/recipes`, params);
export const getRecipe = (companyId: number, productId: string) =>
    api.get(url.GET_COMPANIES + `/${companyId}/restaurant/recipes/${productId}`);
// Fatia 1b (só na XPLENDOR): confirmar o rendimento de um semi-produto e o fator de uma unidade de compra.
export const confirmRecipeYield = (companyId: number, productId: string, body: { bom_id: string; quantity: number; reason?: string | null }) =>
    api.create(url.GET_COMPANIES + `/${companyId}/restaurant/recipes/${productId}/yield`, body, JSON_BODY);
export const confirmUnitConversion = (
    companyId: number,
    productId: string,
    body: { unit_key: string; unit_label?: string | null; factor: number; supplier_nif?: string | null; reason?: string | null }
) => api.create(url.GET_COMPANIES + `/${companyId}/restaurant/recipes/${productId}/conversion`, body, JSON_BODY);
// Fatia 4: editor das fichas técnicas (rascunho na XPLENDOR; gravar no PingWin em fila, uma ficha de cada vez).
const R = (companyId: number) => url.GET_COMPANIES + `/${companyId}/restaurant/recipes`;
export const getRecipeEditor = (companyId: number, productId: string) => api.get(R(companyId) + `/${productId}/editor`);
export const searchRecipeComponents = (companyId: number, q: string) => api.get(R(companyId) + `/components`, { q });
export const previewRecipe = (companyId: number, productId: string, body: object) => api.create(R(companyId) + `/${productId}/preview`, body, JSON_BODY);
export const saveRecipeDraft = (companyId: number, productId: string, body: { payload: object; base_signature: string | null }) =>
    api.put(R(companyId) + `/${productId}/draft`, body);
export const deleteRecipeDraft = (companyId: number, productId: string) => api.delete(R(companyId) + `/${productId}/draft`);
export const writeRecipe = (companyId: number, productId: string, body: object) => api.create(R(companyId) + `/${productId}/write`, body, JSON_BODY);
export const getRecipeWrite = (companyId: number, writeId: number) => api.get(R(companyId) + `/writes/${writeId}`);
export const getRecipeHistory = (companyId: number, productId: string) => api.get(R(companyId) + `/${productId}/history`);
export const getRecipeDeleteEffects = (companyId: number, productId: string, bomIds: string[]) =>
    api.get(R(companyId) + `/${productId}/delete-effects`, { bom_ids: bomIds.join(",") });
export const deleteRecipeVersion = (companyId: number, productId: string, body: { bom_id: string; base_signature: string; confirm: boolean }) =>
    api.create(R(companyId) + `/${productId}/delete-version`, body, JSON_BODY);
export const deleteRecipeAll = (companyId: number, productId: string, body: { base_signature: string; confirm: boolean; confirm_all: boolean }) =>
    api.create(R(companyId) + `/${productId}/delete-all`, body, JSON_BODY);

// Auditoria (Fatia 2, plataforma): a página central, a coluna "Auditoria" das listas, as corridas e os estados.
const AUD = (companyId: number) => url.GET_COMPANIES + `/${companyId}/audit`;
export const getAuditMeta = (companyId: number) => api.get(AUD(companyId) + "/meta");
export const getAuditFindings = (companyId: number, params?: {
    page?: number; perPage?: number; area?: string; rule?: string; family?: string; severity?: string; state?: string;
    entity_type?: string; entity_id?: string; search?: string; sort?: string; dir?: "asc" | "desc";
}) => api.get(AUD(companyId) + "/findings", params);
export const getAuditGroups = (companyId: number, params?: {
    area?: string; rule?: string; family?: string; severity?: string; state?: string; entity_type?: string; entity_id?: string; search?: string;
}) => api.get(AUD(companyId) + "/findings/groups", params);
export const getAuditFinding = (companyId: number, findingId: number) => api.get(AUD(companyId) + `/findings/${findingId}`);
export const getAuditCounts = (companyId: number, entityType: string, ids?: string[]) =>
    api.get(AUD(companyId) + "/counts", { entity_type: entityType, ids: ids && ids.length ? ids.join(",") : undefined });
export const startAuditRun = (companyId: number, area: string, origin: string) => api.create(AUD(companyId) + `/${area}/runs`, { origin }, JSON_BODY);
export const getAuditRun = (companyId: number, runId: number) => api.get(AUD(companyId) + `/runs/${runId}`);
export const setAuditFindingState = (companyId: number, area: string, findingId: number, action: "aceitar" | "ignorar" | "corrigido" | "reabrir", reason?: string) =>
    api.create(AUD(companyId) + `/${area}/findings/${findingId}/state`, { action, reason }, JSON_BODY);
export const setArticleGroupMain = (companyId: number, findingId: number, productId: string) =>
    api.put(url.GET_COMPANIES + `/${companyId}/restaurant/article-groups/main`, { finding_id: findingId, product_id: productId });
export const getSupplierDocumentLines = (companyId: number, docheaderId: string) =>
    api.get(url.GET_COMPANIES + `/${companyId}/integrations/pingwin/supplier-documents/${docheaderId}/lines`);
export const getRecipeConversionInfo = (companyId: number, productId: string, unitKey?: string) =>
    api.get(url.GET_COMPANIES + `/${companyId}/restaurant/recipes/${productId}/conversion`, { unit_key: unitKey });
// Auditoria v2-C (Dinheiro): o histórico de preços por fornecedor (só leitura).
export const getPriceHistory = (companyId: number, productId: string, products?: string[]) =>
    api.get(url.GET_COMPANIES + `/${companyId}/restaurant/money/price-history/${productId}`, { products });
// Auditoria v2: os euros (em jogo agora, resolvidos) e o Guião de unidades.
export const getAuditSummary = (companyId: number, area?: string) => api.get(AUD(companyId) + "/summary", { area });
const UG = (companyId: number) => url.GET_COMPANIES + `/${companyId}/restaurant/units/guide`;
/** A fila do Guião; com a pesquisa (nome, código ou fornecedor) e o estado, no servidor, sobre todos os artigos do Guião. */
export const getUnitGuideQueue = (companyId: number, fila?: string, search?: { q?: string | null; estado?: string | null }) =>
    api.get(UG(companyId), { fila, ...(search?.q ? { q: search.q } : {}), ...(search?.estado ? { estado: search.estado } : {}) });
export const getUnitGuide = (companyId: number, productId: string) => api.get(UG(companyId) + `/${productId}`);
export const previewUnitGuide = (companyId: number, productId: string, answers: Record<string, string | null>) =>
    api.create(UG(companyId) + `/${productId}/preview`, { answers }, JSON_BODY);
export const confirmUnitGuide = (companyId: number, productId: string, answers: Record<string, string | null>, reason?: string) =>
    api.create(UG(companyId) + `/${productId}/confirm`, { answers, reason }, JSON_BODY);
export const adjustUnitGuide = (companyId: number, productId: string, answers: Record<string, string | null>) =>
    api.create(UG(companyId) + `/${productId}/adjust`, { answers }, JSON_BODY);
export const skipUnitGuide = (companyId: number, productId: string) => api.create(UG(companyId) + `/${productId}/skip`, {}, JSON_BODY);
export const notApplicableUnitGuide = (companyId: number, productId: string, reason: string) =>
    api.create(UG(companyId) + `/${productId}/not-applicable`, { reason }, JSON_BODY);
export const infoUnitGuide = (companyId: number, productId: string, data: FormData) =>
    api.create(UG(companyId) + `/${productId}/info`, data, { headers: { "Content-Type": "multipart/form-data" } });
export const askAiUnitGuide = (companyId: number, productId: string) => api.create(UG(companyId) + `/${productId}/ai`, {}, JSON_BODY);
export const sendAdminUnitGuide = (companyId: number, productId: string, note?: string) =>
    api.create(UG(companyId) + `/${productId}/send-admin`, { note }, JSON_BODY);
export const applyUnitGuideStep = (companyId: number, productId: string, step: number) =>
    api.create(UG(companyId) + `/${productId}/apply`, { step, confirm: true }, JSON_BODY);
export const getUnitGuideWrite = (companyId: number, writeId: number) => api.get(UG(companyId) + `/writes/${writeId}`);
export const getGlobalUnitGuide = (companyId: number, unitId: string) => api.get(UG(companyId) + `/global/${unitId}`);
export const confirmAllUnitGuide = (companyId: number, productIds: string[]) =>
    api.create(UG(companyId) + "/confirm-all", { product_ids: productIds, confirm: true }, JSON_BODY);
export const undoUnitGuideBatch = (companyId: number, batch: string) => api.create(UG(companyId) + "/undo-batch", { batch }, JSON_BODY);
export const applyGlobalUnitGuide = (companyId: number, unitId: string) => api.create(UG(companyId) + `/global/${unitId}/apply`, { confirm: true }, JSON_BODY);
export const getRecipeSubstitute = (companyId: number, productId: string, component: string) =>
    api.get(url.GET_COMPANIES + `/${companyId}/restaurant/recipes/${productId}/substitute`, { component });
// Fatia 7a: stock (leitura do espelho; mínimo/máximo no PingWin, em fila) e armazéns.
const STK = (companyId: number) => url.GET_COMPANIES + `/${companyId}/restaurant/stock`;
const WH = (companyId: number) => url.GET_COMPANIES + `/${companyId}/restaurant/warehouses`;
export const getStock = (
    companyId: number,
    params?: { page?: number; perPage?: number; search?: string; warehouse_id?: string; family?: string; state?: string; sort?: string; dir?: "asc" | "desc" }
) => api.get(STK(companyId), params);
export const getStockArticle = (companyId: number, productId: string) => api.get(STK(companyId) + `/${productId}`);
export const writeStockLimits = (companyId: number, productId: string, body: { warehouse_id: string; min_stock?: number | null; max_stock?: number | null; disable_generic?: boolean }) =>
    api.create(STK(companyId) + `/${productId}/limits`, body, JSON_BODY);
export const getStockWrite = (companyId: number, writeId: number) => api.get(STK(companyId) + `/writes/${writeId}`);
export const refreshStockArticle = (companyId: number, productId: string) => api.create(STK(companyId) + `/${productId}/refresh`, {}, JSON_BODY);
export const getStockRun = (companyId: number, runId: number) => api.get(STK(companyId) + `/runs/${runId}`);
export const getWarehouses = (companyId: number) => api.get(WH(companyId));
export const createWarehouse = (companyId: number, body: { description: string; store_ids: string[]; inv_costmethod: number; exit_costmethod: number }) =>
    api.create(WH(companyId), body, JSON_BODY);
export const updateWarehouse = (companyId: number, warehouseId: string, body: { description?: string; store_ids?: string[]; inv_costmethod?: number; exit_costmethod?: number }) =>
    api.put(WH(companyId) + `/${warehouseId}`, body);
export const deleteWarehouse = (companyId: number, warehouseId: string, confirmName: string) =>
    api.delete(WH(companyId) + `/${warehouseId}`, { data: { confirm_name: confirmName } });
export const setStoreWarehouse = (companyId: number, storeId: string, warehouseId: string | null) =>
    api.put(WH(companyId) + `/store-defaults`, { store_id: storeId, warehouse_id: warehouseId });
// Fatia 7b: contagens, zonas, desperdício e movimentos.
const SC = (companyId: number) => url.GET_COMPANIES + `/${companyId}/restaurant/stock-counts`;
const WS = (companyId: number) => url.GET_COMPANIES + `/${companyId}/restaurant/waste`;
export const getStockCounts = (companyId: number) => api.get(SC(companyId));
export const createStockCount = (companyId: number, body: { warehouse_id: string; scope: string; zone_id?: number | null; moment: string; blind?: boolean }) =>
    api.create(SC(companyId), body, JSON_BODY);
export const getStockCount = (companyId: number, countId: number) => api.get(SC(companyId) + `/${countId}`);
export const saveStockCountLine = (companyId: number, countId: number, lineId: number, entries: { qty: number | string | null; unit: string }[]) =>
    api.put(SC(companyId) + `/${countId}/lines/${lineId}`, { entries });
export const finishStockCount = (companyId: number, countId: number) => api.create(SC(companyId) + `/${countId}/finish`, {}, JSON_BODY);
export const recountStockCount = (companyId: number, countId: number, lineIds: number[]) => api.create(SC(companyId) + `/${countId}/recount`, { line_ids: lineIds }, JSON_BODY);
export const sendStockCount = (companyId: number, countId: number) => api.create(SC(companyId) + `/${countId}/send`, {}, JSON_BODY);
export const voidStockCount = (companyId: number, countId: number) => api.create(SC(companyId) + `/${countId}/void`, {}, JSON_BODY);
export const discardStockCount = (companyId: number, countId: number) => api.delete(SC(companyId) + `/${countId}`);
export const getCountableProducts = (companyId: number, search: string) => api.get(SC(companyId) + `/countable`, { search });
export const refreshCountableProducts = (companyId: number) => api.create(SC(companyId) + `/countable/refresh`, {}, JSON_BODY);
export const getRealConsumption = (companyId: number, warehouseId: string) => api.get(SC(companyId) + `/consumption`, { warehouse_id: warehouseId });
export const getStockMovements = (companyId: number, productId: string, params: { from?: string; to?: string; warehouse_id?: string }) =>
    api.get(url.GET_COMPANIES + `/${companyId}/restaurant/stock/${productId}/movements`, params);
export const getStockZones = (companyId: number, warehouseId: string) => api.get(url.GET_COMPANIES + `/${companyId}/restaurant/stock-zones`, { warehouse_id: warehouseId });
export const createStockZone = (companyId: number, body: { warehouse_id: string; name: string }) => api.create(url.GET_COMPANIES + `/${companyId}/restaurant/stock-zones`, body, JSON_BODY);
export const updateStockZone = (companyId: number, zoneId: number, body: { name?: string; position?: number; product_ids?: string[] }) =>
    api.put(url.GET_COMPANIES + `/${companyId}/restaurant/stock-zones/${zoneId}`, body);
export const deleteStockZone = (companyId: number, zoneId: number) => api.delete(url.GET_COMPANIES + `/${companyId}/restaurant/stock-zones/${zoneId}`);
export const getWaste = (companyId: number, params: { warehouse_id?: string; date?: string }) => api.get(WS(companyId), params);
export const getWasteProducts = (companyId: number, search: string) => api.get(WS(companyId) + `/products`, { search });
export const saveWaste = (companyId: number, body: { warehouse_id: string; product_id: string; qty: string; unit: string; reason: string; note?: string }, id?: number) =>
    id ? api.put(WS(companyId) + `/${id}`, body) : api.create(WS(companyId), body, JSON_BODY);
export const deleteWaste = (companyId: number, id: number) => api.delete(WS(companyId) + `/${id}`);
export const sendWaste = (companyId: number, warehouseId: string) => api.create(WS(companyId) + `/send`, { warehouse_id: warehouseId }, JSON_BODY);
export const voidWasteBatch = (companyId: number, batchId: number) => api.create(WS(companyId) + `/batches/${batchId}/void`, {}, JSON_BODY);
export const getWasteReport = (companyId: number, params: { from?: string; to?: string; warehouse_id?: string }) => api.get(WS(companyId) + `/report`, params);

// ── Engenharia de menu (Fatia 3) ──
const ME = (companyId: number) => url.GET_COMPANIES + `/${companyId}/restaurant/menu-engineering`;
export type MenuParams = { location_id?: number; mode?: "media" | "ultimo"; from?: string; to?: string; family?: string };
export const getMenuEngineering = (companyId: number, params: MenuParams) => api.get(ME(companyId), params);
export const getMenuEngineeringItems = (companyId: number, params: MenuParams & { class?: string; confidence?: string; excluded?: string; search?: string; page?: number; perPage?: number; sort?: string; dir?: "asc" | "desc" }) =>
    api.get(ME(companyId) + `/items`, params);
export const getMenuEngineeringItem = (companyId: number, productId: string, params: MenuParams) => api.get(ME(companyId) + `/items/${productId}`, params);
export const recalculateMenuEngineering = (companyId: number, params: MenuParams) => api.create(ME(companyId) + `/recalculate`, params, JSON_BODY);
export const updateMenuEngineeringSettings = (companyId: number, body: Record<string, boolean>) => api.put(ME(companyId) + `/settings`, body);
export const excludeMenuItem = (companyId: number, productId: string, reason: string) => api.create(ME(companyId) + `/exclusions`, { product_id: productId, reason }, JSON_BODY);
export const includeMenuItem = (companyId: number, productId: string) => api.delete(ME(companyId) + `/exclusions/${productId}`);
/** O caminho do CSV da tabela (para o downloadGet). */
export const menuEngineeringCsvPath = (companyId: number, query: string) => `${url.GET_COMPANIES}/${companyId}/restaurant/menu-engineering/items?format=csv&${query}`;

// ── Transferências (Fatia 7c) ──
const TR = (companyId: number) => url.GET_COMPANIES + `/${companyId}/restaurant/transfers`;
export type TransferLineInput = { product_id: string; qty: string | number; unit?: string; request_line_id?: number; price_confirmed?: boolean; factor_ack?: boolean };
export const getStockTransfers = (companyId: number) => api.get(TR(companyId));
export const refreshTransferOrders = (companyId: number) => api.create(TR(companyId) + `/orders/refresh`, {}, JSON_BODY);
export const getTransferProducts = (companyId: number, search: string) => api.get(TR(companyId) + `/products`, { search });
export const prepareStockTransfer = (companyId: number, body: { route_id: number; request_id?: number | null; lines: TransferLineInput[] }) =>
    api.create(TR(companyId) + `/prepare`, body, JSON_BODY);
export const sendStockTransfer = (companyId: number, body: { route_id: number; request_id?: number | null; lines: TransferLineInput[]; note?: string }) =>
    api.create(TR(companyId), body, JSON_BODY);
export const getStockTransfer = (companyId: number, id: number) => api.get(TR(companyId) + `/${id}`);
export const receiveStockTransfer = (companyId: number, id: number, received: Record<string, string | number>) =>
    api.create(TR(companyId) + `/${id}/receive`, { received }, JSON_BODY);
export const voidStockTransfer = (companyId: number, id: number) => api.create(TR(companyId) + `/${id}/void`, {}, JSON_BODY);
export const updateTransferRoute = (companyId: number, routeId: number, body: Record<string, unknown>) => api.put(TR(companyId) + `/routes/${routeId}`, body);
