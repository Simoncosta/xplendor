export type DecisionType = "ESCALAR" | "MANTER" | "CORRIGIR" | "PARAR";

export interface CarDecisionResponse {
    car_id: number;
    car_name: string;
    decision: DecisionType | "INSUFFICIENT_DATA";
    confidence: number;
    reason: string;
    main_metric: string;
    actions: string[];
    scores?: Record<string, number>;
    funnel?: Record<string, unknown>;
}

export interface ActionCenterCarItem extends CarDecisionResponse {
    id: number;
    detailPath: string;
}
