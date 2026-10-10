import { createPostFromPlay, excludeRestaurantItem, getPlayCaption, ignoreRestaurantSignal, requestPlayCaption } from "helpers/laravel_helper";
import type { CompassPlay } from "common/models/bussola.model";

/**
 * Bússola comum: o que muda de um ramo para o outro no cartão das jogadas e nas janelas (as
 * chamadas à API, o "Ignorar" e o "Não voltar a sugerir", os textos). O resto (o cartão, o "O
 * que fazer", as janelas, o "Sem acesso aos valores financeiros") é comum.
 */
export interface CompassApi {
    createPost: (companyId: number, data: any) => Promise<any>;
    requestCaption: (companyId: number, data: { play_key: string; location_id?: number | null; networks: string[] }) => Promise<any>;
    getCaption: (companyId: number, id: number) => Promise<any>;
}

export interface CompassAdapter {
    key: "restauracao" | "stand";
    api: CompassApi;
    /** Ignorar durante 4 semanas (todas as chaves da jogada) ou não voltar a sugerir (o sujeito). */
    ignore: (companyId: number, play: CompassPlay, permanent: boolean) => Promise<void>;
    exclude: { label: string; confirmTitle: string; confirmText: string; done: string; can: (p: CompassPlay) => boolean };
    empty: string;
}

/** A jogada é de um só artigo (pode ser excluído das sugestões). */
export const isSingleItem = (p: CompassPlay) => p.type !== "weak_period" && p.key.includes(":prod:");

/** A restauração: as rotas e os textos de sempre. */
export const restaurantAdapter: CompassAdapter = {
    key: "restauracao",
    api: { createPost: createPostFromPlay, requestCaption: requestPlayCaption, getCaption: getPlayCaption },
    ignore: async (companyId, play, permanent) => {
        if (permanent) await excludeRestaurantItem(companyId, play.signal_keys[0]);
        else for (const key of play.signal_keys) await ignoreRestaurantSignal(companyId, key);
    },
    exclude: {
        label: "Não voltar a sugerir este artigo",
        confirmTitle: "Não voltar a sugerir este artigo?",
        confirmText: "O artigo deixa de entrar nas jogadas e nos rankings, sem mudar a categoria da família. Pode voltar a incluí-lo na página das categorias.",
        done: "O artigo não volta a ser sugerido.",
        can: isSingleItem,
    },
    empty: "Esta semana não há jogadas: nenhum sinal das vendas e das reservas destas lojas pede uma ação.",
};
