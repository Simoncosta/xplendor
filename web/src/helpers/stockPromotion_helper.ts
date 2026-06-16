import axios from "axios";
import type {
    ListPromotionCandidatesParams,
    PromotionCandidatesPage,
    PromotionSummary,
    PromotionCandidatePriority,
} from "../types/api";

// O interceptor (api_helper.ts) devolve `response.data` directamente. Logo
// `res` é o BODY JSON e `res.data` é o campo `data` interior — mesmo padrão
// do `marketAggregate_helper.ts` (corrigido em X7.1).

const baseUrl = (companyId: number): string =>
    `/companies/${companyId}/stock/promotion-candidates`;

export async function listPromotionCandidates(
    companyId: number,
    params: ListPromotionCandidatesParams,
): Promise<PromotionCandidatesPage> {
    // Endpoint usa CollectionResource → body é `{ data, meta, links, ... }`.
    // Devolvemos o body inteiro — tipo cobre `data` + `meta.thresholds` + paginator.
    const res = await axios.get<PromotionCandidatesPage>(
        baseUrl(companyId),
        { params: normalizeParams(params) },
    );
    return res as unknown as PromotionCandidatesPage;
}

export async function getPromotionSummary(companyId: number): Promise<PromotionSummary> {
    const res = await axios.get<PromotionSummary>(`${baseUrl(companyId)}/summary`);
    return res.data;
}

export async function markPromotion(
    companyId: number,
    carId: number,
    note: string | null,
): Promise<PromotionCandidatePriority> {
    const res = await axios.post<PromotionCandidatePriority>(
        `${baseUrl(companyId)}/${carId}`,
        { note },
    );
    return res.data;
}

export async function unmarkPromotion(
    companyId: number,
    carId: number,
): Promise<{ was_active: boolean }> {
    const res = await axios.delete<{ was_active: boolean }>(
        `${baseUrl(companyId)}/${carId}`,
    );
    return res.data;
}

/**
 * Limpa params para axios:
 *   - remove keys undefined/null/string vazia / arrays vazios
 *   - **booleans → `1` quando true, OMITIDO quando false**
 *
 * Razão (validada empiricamente em 2026-06-16): axios serializa JS `true` como
 * string literal `"true"` na query string, e a regra `boolean` do Laravel
 * aceita `1`/`0`/`"1"`/`"0"` mas **NÃO** aceita `"true"`/`"false"` — devolve 422
 * *"O campo X tem de ser verdadeiro ou falso."* Filtros booleanos opcionais
 * em query string seguem a convenção *presença=1, ausência=omitido* — mais
 * limpo que enviar `"false"` (que também dá 422).
 *
 * Esta normalização é o ponto canónico para QUALQUER filtro booleano futuro
 * deste helper. Não duplicar a conversão nos call-sites.
 */
function normalizeParams(p: ListPromotionCandidatesParams): Record<string, unknown> {
    const out: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(p)) {
        if (v === undefined || v === null || v === "") continue;
        if (Array.isArray(v) && v.length === 0) continue;
        if (typeof v === "boolean") {
            if (v) out[k] = 1;
            // false → omitido, semântica "filtro inactivo"
            continue;
        }
        out[k] = v;
    }
    return out;
}
