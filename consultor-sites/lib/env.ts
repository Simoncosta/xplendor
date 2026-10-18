import "server-only";

/** As variáveis do servidor do site (nunca chegam ao navegador). */
export const env = {
    apiUrl: (process.env.CONSULTOR_API_URL ?? "").replace(/\/+$/, ""),
    apiToken: process.env.CONSULTOR_API_TOKEN ?? "",
    revalidateSecret: process.env.CONSULTOR_REVALIDATE_SECRET ?? "",
    devHosts: process.env.NODE_ENV === "production" ? "" : (process.env.CONSULTOR_DEV_HOSTS ?? ""),
};

export const isDev = process.env.NODE_ENV !== "production";
