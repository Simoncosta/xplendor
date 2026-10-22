/**
 * Ações escondidas até haver suporte (decisão de 22/10/2026): o "Pausar campanha" falha porque a
 * ligação à Meta só tem ads_read; volta com a gestão de anúncios. O código fica; o servidor também
 * as recusa (ActionExecutionService::HIDDEN).
 */
export const HIDDEN_ACTIONS: readonly string[] = ["pause_campaign"];

export const isActionHidden = (key?: string | null): boolean => !!key && HIDDEN_ACTIONS.includes(key);
