import { useCallback } from "react";
import { useDispatch } from "react-redux";
import { getMetaOAuthUrl } from "slices/metaAds/thunk";

interface UseMetaOAuthOptions {
    companyId: number;
    /** Mantido por compatibilidade; já não é usado (o retorno é via ?meta= na
     *  página de integrações após o redirect de volta). Opcional. */
    onSuccess?: () => void;
    onError: (message: string) => void;
}

/**
 * Hook do fluxo OAuth do Meta por REDIRECT DE PÁGINA INTEIRA (sem popup).
 *
 * Fluxo (depois da migração para /app + callback no backend):
 * 1. Pede ao backend a URL de autorização (com state = nonce).
 * 2. Navega a página inteira para a Meta (window.location.assign).
 * 3. A Meta redireciona o browser para /api/oauth/meta/callback (BACKEND), que
 *    troca o code pelo token e redireciona de volta para /app/companies/{id}
 *    com ?meta=connected|choose_account|error.
 * 4. A página de integrações lê ?meta= e reage (toast + escolher conta).
 *
 * Não há popup nem polling: o secret fica no backend e o basename /app é
 * respeitado porque o backend constrói o URL de retorno.
 */
export function useMetaOAuth({ companyId, onError }: UseMetaOAuthOptions) {
    const dispatch: any = useDispatch();

    const connect = useCallback(async () => {
        if (!companyId) return;

        try {
            const response = await dispatch(getMetaOAuthUrl({ companyId })).unwrap();
            const authUrl = response?.data?.url;

            if (!authUrl) {
                onError("Não foi possível obter a URL de autorização.");
                return;
            }

            // Redirect de página inteira para a Meta.
            window.location.assign(authUrl);
        } catch (err) {
            onError("Erro ao iniciar autenticação com o Meta.");
        }
    }, [companyId, dispatch, onError]);

    return { connect };
}
