import { createPostFromPlay, excludeRestaurantItem, getPlayCaption, ignoreRestaurantSignal, requestPlayCaption,
    createPostFromStandPlay, excludeStandVehicle, getStandPlayCaption, ignoreStandSignals, requestStandPlayCaption } from "helpers/laravel_helper";
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

/** O stand: as rotas da Bússola do stand; "Não voltar a sugerir este veículo" nas jogadas de um só veículo. */
export const standAdapter: CompassAdapter = {
    key: "stand",
    api: { createPost: createPostFromStandPlay, requestCaption: (c, d) => requestStandPlayCaption(c, { play_key: d.play_key, networks: d.networks }), getCaption: getStandPlayCaption },
    ignore: async (companyId, play, permanent) => {
        if (permanent) await excludeStandVehicle(companyId, play.signal_keys[0]);
        else await ignoreStandSignals(companyId, play.signal_keys);
    },
    exclude: {
        label: "Não voltar a sugerir este veículo",
        confirmTitle: "Não voltar a sugerir este veículo?",
        confirmText: "O veículo deixa de entrar nas jogadas da Bússola. Os blocos e o resto da plataforma continuam a mostrá-lo.",
        done: "O veículo não volta a ser sugerido.",
        can: (p) => !!p.car_id,
    },
    empty: "Esta semana não há jogadas: nenhum sinal do stock, dos preços, dos anúncios ou da procura pede uma ação.",
};
