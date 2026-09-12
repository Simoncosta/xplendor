// AUTH
export const POST_FAKE_API_LOGIN = "/login";
export const POST_FAKE_API_LOGOUT = "/logout";
export const GET_USER_BY_INVITE = "/user-by-invite/";
export const POST_REGISTER_BY_INVITE = "/register-by-invite";

// COMPANIES 
export const GET_COMPANIES = "/companies";
export const GET_INTEGRATIONS = "/integrations";
export const GET_META_INTEGRATIONS = "/integrations/meta";
export const GET_DECISIONS = "/decisions";
export const GET_ALERTS = "/alerts";
export const GET_ALERTS_UNREAD_COUNT = "/alerts/unread-count";
export const PATCH_ALERTS_READ = "/alerts/read";
export const POST_ALERT_READ = "/read";

// DASHBOARDS
export const GET_DASHBOARD_APIS = "/dashboard";

// LEADS
export const GET_LEADS_APIS = "/leads";

// BLOGS
export const GET_BLOGS_APIS = "/blogs";

// SUPPLIERS (DMS 1c.1)
export const GET_SUPPLIERS = "/suppliers";

// CUSTOMERS (DMS — clientes)
export const GET_CUSTOMERS = "/customers";
// DMS Caminho B — modelos de documento .docx
export const GET_DOCUMENT_TEMPLATES = "/document-templates";
// DMS — Consola de administração (super-admin / root)
export const GET_ADMIN = "/admin";
// DMS — Tickets de suporte (lado stand)
export const GET_SUPPORT_TICKETS = "/support-tickets";

// EXPENSE CATEGORIES (DMS 1c.2a)
export const GET_EXPENSE_CATEGORIES = "/expense-categories";
export const GET_EXPENSE_CATEGORIES_SUGGESTED = "/expense-categories/suggested";
export const POST_EXPENSE_CATEGORIES_IMPORT = "/expense-categories/import-suggested";

// EXPENSES (DMS 1c.2b)
export const GET_EXPENSES = "/expenses";
export const GET_EXPENSES_SUMMARY = "/expenses/summary";

// CARS
export const GET_CARS = "/cars";
export const GET_CAR_AD_CAMPAIGNS = "/ad-campaigns";
export const GET_CAR_AD_CAMPAIGN_ACTIVE_TARGETS = "/ad-campaigns/active-targets";
export const GET_CAR_SALES = "/sales";
export const GET_CAR_SALE = "/sale"; // singular — PATCH update do car_sale 1:1
export const GET_CAR_DECISION = "/decision";
export const POST_CAR_EXECUTE_ACTION = "/execute-action";
export const POST_CAR_META_ADS_REFRESH = "/meta-ads/refresh";
export const POST_CAR_ANALYSIS_REGENERATE = "/analysis/regenerate";
export const POST_CAR_GENERATE_DESCRIPTION = "/generate-description";
export const GET_CAR_SPECS = "/specs";

// ANALYSES
export const GET_CARS_ANALYSES = "/car-ai-analyses";

// META ADS
export const GET_META_OAUTH_URL = "/oauth-url";
export const GET_META_ADSETS = "/adsets";
export const POST_META_CALLBACK = "/callback";

// USERS
export const GET_USERS_APIS = "/users";

// CARMINE
export const GET_CARMINE_APIS = "/carmine-connection";

// CAR BRANDS
export const GET_CAR_BRANDS = "/car-brands";

// CAR MODELS
export const GET_CAR_MODELS = "/car-models";
export const GET_CAR_CATEGORIES = "/car-categories";

// DISTRICTS
export const GET_DISTRICTS = "/districts";

// SCRAPER
export const GET_SCRAPER = "/scraper";
