// Registo do service worker (baseado no cra-template-pwa, adaptado a TS).
// Registar em produção ativa o comportamento de PWA (instalável, app-shell).
// A opção `onUpdate` é usada em index.tsx para aplicar novas versões sem
// deixar o utilizador preso a cache antiga.

const isLocalhost = Boolean(
    window.location.hostname === "localhost" ||
        window.location.hostname === "[::1]" ||
        window.location.hostname.match(/^127(?:\.(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)){3}$/)
);

type Config = {
    onSuccess?: (registration: ServiceWorkerRegistration) => void;
    onUpdate?: (registration: ServiceWorkerRegistration) => void;
};

export function register(config?: Config) {
    if (process.env.NODE_ENV === "production" && "serviceWorker" in navigator) {
        // O SW não funciona se a app e o SW forem de origens diferentes.
        const publicUrl = new URL(process.env.PUBLIC_URL || "", window.location.href);
        if (publicUrl.origin !== window.location.origin) {
            return;
        }

        window.addEventListener("load", () => {
            const swUrl = `${process.env.PUBLIC_URL || ""}/service-worker.js`;

            if (isLocalhost) {
                // Em localhost, valida se o SW ainda existe.
                checkValidServiceWorker(swUrl, config);
                navigator.serviceWorker.ready.then(() => {
                    // eslint-disable-next-line no-console
                    console.log("Esta app é servida com cache-first por um service worker (localhost).");
                });
            } else {
                registerValidSW(swUrl, config);
            }
        });
    }
}

function registerValidSW(swUrl: string, config?: Config) {
    navigator.serviceWorker
        .register(swUrl)
        .then((registration) => {
            // Verifica atualizações periodicamente (ex.: app aberta muito tempo).
            registration.onupdatefound = () => {
                const installingWorker = registration.installing;
                if (installingWorker == null) {
                    return;
                }
                installingWorker.onstatechange = () => {
                    if (installingWorker.state === "installed") {
                        if (navigator.serviceWorker.controller) {
                            // Há conteúdo novo em espera (versão antiga ainda em uso).
                            if (config && config.onUpdate) {
                                config.onUpdate(registration);
                            }
                        } else {
                            // Primeira vez: conteúdo em cache para uso offline.
                            if (config && config.onSuccess) {
                                config.onSuccess(registration);
                            }
                        }
                    }
                };
            };
        })
        .catch((error) => {
            // eslint-disable-next-line no-console
            console.error("Erro ao registar o service worker:", error);
        });
}

function checkValidServiceWorker(swUrl: string, config?: Config) {
    fetch(swUrl, { headers: { "Service-Worker": "script" } })
        .then((response) => {
            const contentType = response.headers.get("content-type");
            if (
                response.status === 404 ||
                (contentType != null && contentType.indexOf("javascript") === -1)
            ) {
                navigator.serviceWorker.ready.then((registration) => {
                    registration.unregister().then(() => {
                        window.location.reload();
                    });
                });
            } else {
                registerValidSW(swUrl, config);
            }
        })
        .catch(() => {
            // eslint-disable-next-line no-console
            console.log("Sem ligação. A app está a correr em modo offline.");
        });
}

export function unregister() {
    if ("serviceWorker" in navigator) {
        navigator.serviceWorker.ready
            .then((registration) => {
                registration.unregister();
            })
            .catch((error) => {
                // eslint-disable-next-line no-console
                console.error(error.message);
            });
    }
}
