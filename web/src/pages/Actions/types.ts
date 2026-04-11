export type DecisionType = "ESCALAR" | "MANTER" | "CORRIGIR" | "PARAR";
export type ActionExecutionKey =
    | "pause_campaign"
    | "notify_client_whatsapp"
    | "generate_new_copy"
    | "suggest_new_vehicle"
    | "launch_template_campaign"
    | "duplicate_winning_campaign"
    | "swap_creative"
    | "mark_lead_low_quality";

export type ActionExecutionStatus = "executed" | "prepared" | "stub";

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

export interface ActionExecutionResponse {
    action: ActionExecutionKey;
    status: ActionExecutionStatus;
    message: string;
    data: {
        action: ActionExecutionKey;
        status: ActionExecutionStatus;
        execution_mode?: string;
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
