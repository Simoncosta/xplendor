import { useCallback, useEffect, useRef } from "react";

const API_URL = process.env.REACT_APP_API_URL ?? "";

interface UseMetaOAuthOptions {
    companyId: number;
    onSuccess: () => void;
    onError: (message: string) => void;
}

/**
 * Hook que gere o fluxo OAuth do Meta numa popup.
 *
 * Fluxo:
 * 1. Frontend pede ao backend a URL de autorização
 * 2. Abre numa popup (não redireciona a página)
 * 3. O Meta redireciona para /oauth/meta/callback?code=XXX
 * 4. A página de callback extrai o code e account_id, envia ao backend e fecha a popup
 * 5. O hook detecta o fechamento e chama onSuccess
 */
export function useMetaOAuth({ companyId, onSuccess, onError }: UseMetaOAuthOptions) {
    const popupRef = useRef<Window | null>(null);
    const pollingRef = useRef<NodeJS.Timeout | null>(null);

    // Limpar polling ao desmontar
    useEffect(() => {
        return () => {
            if (pollingRef.current) clearInterval(pollingRef.current);
        };
    }, []);

    const connect = useCallback(async () => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return;
        const { token } = JSON.parse(authUser);

        try {
            // 1. Pedir URL de autorização ao backend
            const res = await fetch(
                `${API_URL}/companies/${companyId}/integrations/meta/oauth-url`,
                { headers: { Authorization: `Bearer ${token}` } }
            );
            const data = await res.json();
            const authUrl = data?.data?.url;

            if (!authUrl) {
                onError("Não foi possível obter a URL de autorização.");
                return;
            }

            // 2. Abrir popup
            const width = 600;
            const height = 700;
            const left = window.screenX + (window.outerWidth - width) / 2;
            const top = window.screenY + (window.outerHeight - height) / 2;

            popupRef.current = window.open(
                authUrl,
                "meta_oauth",
                `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=yes`
            );

            // 3. Polling para detectar quando a popup fecha
            pollingRef.current = setInterval(() => {
                if (popupRef.current?.closed) {
                    clearInterval(pollingRef.current!);
                    // A popup fechou — verificar se a integração foi guardada
                    onSuccess();
                }
            }, 500);

        } catch (err) {
            onError("Erro ao iniciar autenticação com o Meta.");
        }
    }, [companyId, onSuccess, onError]);

    return { connect };
}