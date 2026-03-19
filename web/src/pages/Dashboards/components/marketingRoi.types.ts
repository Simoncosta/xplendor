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

export interface IMarketingRoi {
    summary: IMarketingRoiSummary;
    by_channel: IMarketingRoiChannel[];
    top_campaigns: IMarketingRoiCampaign[];
    top_cars_to_promote: IMarketingRoiCar[];
    insights: string[];
}
