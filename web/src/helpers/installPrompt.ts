// Captura global do evento beforeinstallprompt (Android/Chrome).
// O evento dispara cedo no arranque — por isso este módulo começa a ouvir
// assim que é importado (ver index.tsx). A página "Instalar app" lê o estado
// através de subscribeInstall/canInstall e dispara o prompt nativo com promptInstall.

type BeforeInstallPromptEvent = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: "accepted" | "dismissed" }>;
};

let deferredPrompt: BeforeInstallPromptEvent | null = null;
let installed = false;
const listeners = new Set<() => void>();

const notify = () => listeners.forEach((cb) => cb());

if (typeof window !== "undefined") {
    window.addEventListener("beforeinstallprompt", (e) => {
        // Impede o mini-infobar do Chrome; guardamos para disparar no nosso botão.
        e.preventDefault();
        deferredPrompt = e as BeforeInstallPromptEvent;
        notify();
    });

    window.addEventListener("appinstalled", () => {
        installed = true;
        deferredPrompt = null;
        notify();
    });
}

/** Há um prompt de instalação nativo disponível agora (Android/Chrome)? */
export const canInstall = (): boolean => deferredPrompt !== null;

/** A app foi instalada nesta sessão (evento appinstalled). */
export const wasInstalled = (): boolean => installed;

/** Subscreve mudanças de estado (prompt disponível / instalado). Devolve unsubscribe. */
export const subscribeInstall = (cb: () => void): (() => void) => {
    listeners.add(cb);
    return () => {
        listeners.delete(cb);
    };
};

/** Dispara o prompt nativo de instalação. */
export const promptInstall = async (): Promise<"accepted" | "dismissed" | "unavailable"> => {
    if (!deferredPrompt) return "unavailable";
    await deferredPrompt.prompt();
    const choice = await deferredPrompt.userChoice;
    deferredPrompt = null;
    notify();
    return choice.outcome;
};
