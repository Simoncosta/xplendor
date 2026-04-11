export type DecisionType = "ESCALAR" | "MANTER" | "CORRIGIR" | "PARAR" | "NO_ACTIVE_CAMPAIGN";
export type GuardrailSeverity = "high" | "medium" | "low";
export type ActionExecutionKey =
    | "pause_campaign"
    | "notify_client_whatsapp"
    | "generate_new_copy"
    | "suggest_new_vehicle"
    | "launch_template_campaign"
    | "duplicate_winning_campaign"
    | "swap_creative"
    | "mark_lead_low_quality";

export type ActionExecutionStatus = "executed" | "partial" | "prepared" | "stub" | "selection_required";

export interface PauseTargetOption {
    mapping_id: number;
    is_active?: boolean;
    meta_status?: string;
    level: "ad" | "adset" | "campaign";
    target_id: string;
    campaign_id: string | null;
    adset_id: string | null;
    ad_id: string | null;
    campaign_name: string | null;
    adset_name: string | null;
    ad_name: string | null;
    shared_with_other_cars: boolean;
    affected_cars_count: number;
}

export interface GuardrailAlert {
    type: "spend_without_qualified_lead" | "creative_fatigue" | "high_spend_low_intent" | "unanswered_leads";
    severity: GuardrailSeverity;
    title: string;
    message: string;
    recommended_action: string;
    manual_only: boolean;
}

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
    guardrails?: GuardrailAlert[];
}

export interface ActionCenterCarItem extends CarDecisionResponse {
    id: number;
    detailPath: string;
}

export interface ActionExecutionResponse {
    action: ActionExecutionKey;
    status: ActionExecutionStatus;
    message: string;
    warning?: string;
    success?: boolean;
    code?: string;
    data: {
        action: ActionExecutionKey;
        status: ActionExecutionStatus;
        execution_mode?: string;
        target_level?: "ad" | "adset" | "campaign";
        target_id?: string;
        options?: PauseTargetOption[];
        campaign?: {
            id: number;
            campaign_id: string;
            campaign_name: string | null;
            is_active: boolean;
        };
        recipient?: {
            name: string;
            phone: string | null;
        };
        message_text?: string;
        whatsapp_url?: string;
        copy?: {
            headline: string;
            primary_text: string;
            cta: string;
            angle: string;
        };
    };
}

export interface ActionExecutionOption {
    key: ActionExecutionKey;
    label: string;
    description: string;
    implemented: boolean;
}
