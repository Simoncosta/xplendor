export interface IMarketingRoiSummary {
    total_spend: number;
    total_leads: number;
    overall_conversion_rate: number;
    avg_cost_per_lead: number;
    best_channel: string;
    best_campaign: string;
}

export interface IMarketingRoiChannel {
    channel: string;
    sessions: number;
    leads: number;
    conversion_rate: number;
    total_spend: number;
    cost_per_lead: number;
    status: string;
}

export interface IMarketingRoiCampaign {
    campaign_id: string;
    campaign_name: string;
    platform: string;
    impressions: number;
    clicks: number;
    ctr: number;
    spend: number;
    leads: number;
    conversion_rate: number;
    cost_per_lead: number;
}

export interface IMarketingRoiCar {
    car_id: number;
    car_name: string;
    ips_score: number;
    views: number;
    leads: number;
    spend: number;
    cost_per_lead: number;
    recommendation: string;
}

export interface IAdsPriorityRankedCar {
    position: number;
    car_id: number;
    car_name: string;
    price_gross: number | null;
    promotion_score?: number;
    promotion_state?: "ready" | "candidate" | "watch" | "avoid";
    promotion_label?: string;
    confidence?: number;
    reasons?: string[];
    recommended_action?: {
        type: "promote_car" | "switch_car_investment" | "observe_car" | "avoid_investment" | "test_campaign_seed" | string;
        label: string;
    };
    flags?: string[];
    has_active_campaign?: boolean;
    priority_score: number;
    confidence_score: number;
    investment_label: "high_priority" | "medium_priority" | "low_priority" | "avoid_investment";
    reason: string | null;
    why_now: string | null;
    risk_note: string | null;
    smartads_decision: "scale_ads" | "test_campaign" | "test_campaign_seed" | "review_campaign" | "do_not_invest" | null;
}

export interface IMarketingRoi {
    summary: IMarketingRoiSummary;
    by_channel: IMarketingRoiChannel[];
    top_campaigns: IMarketingRoiCampaign[];
    top_cars_to_promote: IMarketingRoiCar[];
    insights: string[];
}
