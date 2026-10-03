// XPLENDOR — Motor de recomendações (regras explicáveis). Espelha
// GET /companies/{id}/recommendations?vertical=restaurant|automotive

export type RecommendationLevel = "high" | "medium" | "low";

export interface Recommendation {
    rule_key: string;
    priority: number;          // 0 a 100
    level: RecommendationLevel;
    title: string;
    why: string;               // explicação com os factos (já em português)
    evidence: Record<string, unknown>;
    action: { label: string; url: string };
    generated_at: string;
}

export interface RecommendationNotice { rule_key: string; code: string; message: string; }

export interface RecommendationsResponse {
    vertical: "restaurant" | "automotive";
    recommendations: Recommendation[];
    notices: RecommendationNotice[];
}
