export type DecisionType = "ESCALAR" | "MANTER" | "CORRIGIR" | "PARAR" | "NO_ACTIVE_CAMPAIGN";
export type GuardrailSeverity = "high" | "medium" | "low";
export type AlertType = "urgent" | "warning" | "opportunity";
export type RecommendationUrgency = "low" | "medium" | "high";
export type RecommendationBucket = "cut" | "scale" | "fix" | "test";
export type ActionExecutionKey =
    | "pause_campaign"
    | "notify_client_whatsapp"
    | "generate_new_copy"
    | "duplicate_campaign"
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
    type: "spend_without_qualified_lead" | "creative_fatigue" | "high_spend_low_intent" | "unanswered_leads" | "decision_friction" | "contact_loss" | "contact_capture_failure" | "no_response";
    severity: GuardrailSeverity;
    title: string;
    message: string;
    recommended_action: string;
    manual_only: boolean;
}

export interface AlertItem {
    id: number;
    company_id: number;
    car_id: number;
    car_name: string;
    type: AlertType;
    title: string;
    message: string;
    severity: GuardrailSeverity;
    is_read: boolean;
    created_at: string;
    detail_path: string;
}

export interface IntentAnalysis {
    period?: {
        from?: string | null;
        to?: string | null;
    };
    intent_score: number;
    intent_level: "low" | "medium" | "high" | string;
    avg_time_on_page?: number;
    avg_scroll?: number;
    sessions?: number;
    unique_visitors?: number;
    strong_intent_users: number;
    strong_intent_users_logic?: string;
    whatsapp_clicks: number;
    leads: number;
    contacted_leads?: number;
    unanswered_leads?: number;
    contact_efficiency: number | null;
    contact_efficiency_confidence?: "low" | "medium" | "high" | string;
    confidence_score?: number;
    intent_distribution?: {
        very_high: number;
        high: number;
        medium: number;
        low: number;
    };
    diagnostic?: {
        primary_issue: string;
        message: string;
        confidence: number;
        confidence_reason?: string;
    };
    tags?: string[];
    relative_performance?: {
        intent_vs_avg: string | null;
        conversion_vs_avg: string | null;
        status: string;
        sample_size?: number;
        has_sufficient_sample?: boolean;
    };
}

export interface LeadRealityGap {
    primary_gap_state: "no_real_interest" | "decision_friction" | "contact_capture_failure" | "no_response" | "low_lead_quality" | "tracking_gap" | "healthy_flow" | string;
    secondary_gap_states: string[];
    severity: "urgent" | "high" | "medium" | "low" | string;
    confidence: number;
    confidence_reason: string;
    message: string;
    likely_failure_point: string;
    estimated_real_contact_probability: number;
    capture_gap_rate: number | null;
    response_gap_rate: number | null;
    intent_score?: number;
    strong_intent_users?: number;
    whatsapp_clicks?: number;
    leads?: number;
    contacted_leads?: number;
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
    intelligence?: IntentAnalysis;
    lead_reality_gap?: LeadRealityGap;
    recommendations?: SmartAdsRecommendations;
}

export interface ActionCenterCarItem extends CarDecisionResponse {
    id: number;
    detailPath: string;
}

export interface SmartAdsRecommendation {
    type: string;
    target_level: string;
    target_id: string;
    reason: string;
    data: {
        spend?: number;
        leads?: number;
        intent_score?: number;
        ctr?: number | null;
        frequency?: number | null;
    };
    impact: {
        estimated_loss?: number;
        estimated_gain?: number;
        urgency: RecommendationUrgency;
    };
    why: string;
    next_step: string;
    action_key?: string | null;
    confidence: number;
    hypothesis?: string;
    based_on?: string;
}

export interface SmartAdsRecommendations {
    cut: SmartAdsRecommendation[];
    scale: SmartAdsRecommendation[];
    fix: SmartAdsRecommendation[];
    test: SmartAdsRecommendation[];
    state?: string;
    primary_action?: Partial<SmartAdsRecommendation> & {
        type: string;
        reason: string;
        why: string;
        next_step: string;
        action_key?: string | null;
        confidence: number;
        impact?: {
            estimated_loss?: number;
            estimated_gain?: number;
            urgency?: RecommendationUrgency;
        };
    };
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
