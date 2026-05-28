// Tracking de clique em plano de pacote.
//
// Consentimento: o gtag respeita o Consent Mode automaticamente (default
// denied — se analytics_storage estiver denied, não persiste). O fbq só
// existe se o Meta Pixel carregou, e o pixel só carrega após consentimento
// (ver lib/metaPixel.ts) — logo já está protegido. Guards de typeof: se as
// funções não existirem (script falhou / sem consentimento), não rebenta.
//
// window.gtag e window.fbq já estão tipados globalmente (CookieBanner.tsx
// e metaPixel.ts) — não redeclarar aqui.

export function trackPlanClick(planId: string, planName: string): void {
    if (typeof window.gtag === "function") {
        window.gtag("event", "select_plan", {
            plan_id: planId,
            plan_name: planName,
        });
    }

    if (typeof window.fbq === "function") {
        window.fbq("track", "Lead", {
            content_name: planName,
            content_category: "pricing_plan",
        });
    }
}
