/* eslint-disable no-restricted-globals */
// Service worker da XPLENDOR.
// Objetivo: tornar a app um PWA instalável e válido, SEM cache agressivo que
// prenda o utilizador a uma versão velha. Só precache dos assets do build
// (nomes com hash de conteúdo → seguros) + app-shell para a SPA navegar.
// A ativação da versão nova é controlada por mensagem (ver serviceWorkerRegistration),
// para que as atualizações cheguem sempre ao utilizador na próxima abertura.

import { clientsClaim } from "workbox-core";
import { precacheAndRoute, createHandlerBoundToURL } from "workbox-precaching";
import { registerRoute } from "workbox-routing";

// O novo SW assume o controlo dos clientes assim que ativa.
clientsClaim();

// Precache de todos os assets gerados pelo build (injetado pelo InjectManifest).
// Como os ficheiros têm hash no nome, novas versões nunca colidem com as antigas.
precacheAndRoute(self.__WB_MANIFEST);

// App-shell: qualquer navegação (mode "navigate") é servida pelo index.html
// em cache — necessário para o routing client-side (React Router) da SPA.
// Exclui pedidos a ficheiros com extensão e rotas internas (/_...).
const fileExtensionRegexp = new RegExp("/[^/?]+\\.[^/]+$");
registerRoute(
    ({ request, url }) => {
        if (request.mode !== "navigate") return false;
        if (url.pathname.startsWith("/_")) return false;
        if (url.pathname.match(fileExtensionRegexp)) return false;
        return true;
    },
    createHandlerBoundToURL((process.env.PUBLIC_URL || "") + "/index.html")
);

// Quando a app avisa que há versão nova pronta, o SG em espera assume já.
// (a página faz reload no evento controllerchange — sem ficar preso a cache velha.)
self.addEventListener("message", (event) => {
    if (event.data && event.data.type === "SKIP_WAITING") {
        self.skipWaiting();
    }
});
