/** Uma contagem própria do site (sem cookies nem identificador), enviada sem esperar resposta. */
export function track(event: "view" | "whatsapp_click" | "video_open" | "chave_nova_click", subject: "property" | "development" | "site", slug?: string): void {
    try {
        const body = JSON.stringify({ event, subject, slug });
        if (navigator.sendBeacon) navigator.sendBeacon("/api/evento", new Blob([body], { type: "application/json" }));
        else void fetch("/api/evento", { method: "POST", headers: { "Content-Type": "application/json" }, body, keepalive: true });
    } catch {
        /* uma contagem perdida não estraga a página */
    }
}
