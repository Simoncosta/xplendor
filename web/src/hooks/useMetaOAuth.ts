import { useCallback, useEffect, useRef } from "react";
import { useDispatch } from "react-redux";
import { getMetaOAuthUrl } from "slices/metaAds/thunk";

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
    const dispatch: any = useDispatch();
    const popupRef = useRef<Window | null>(null);
    const pollingRef = useRef<NodeJS.Timeout | null>(null);

    // Limpar polling ao desmontar
    useEffect(() => {
        return () => {
            if (pollingRef.current) clearInterval(pollingRef.current);
        };
    }, []);

    const connect = useCallback(async () => {
        if (!companyId) return;

        try {
            const response = await dispatch(getMetaOAuthUrl({ companyId })).unwrap();
            const authUrl = response?.data?.url;

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
    }, [companyId, dispatch, onSuccess, onError]);

    return { connect };
}
