// Assistentes do Perfil da Marca (IA): "Sugerir perfil" e "Sugerir criativo".
import type { BrandPillar, EmojiPolicy } from "./blog.model";

export type AiRequestStatus = "queued" | "processing" | "done" | "error";

export type ProfileSuggestionSource = "template" | "company_data" | "audience_data";

export type ProfileSuggestionValue = string | string[] | BrandPillar[] | EmojiPolicy;

export interface ProfileSuggestionField {
    value: ProfileSuggestionValue;
    reason: string;
    source: ProfileSuggestionSource;
}

export interface ProfileSuggestion {
    id: number;
    status: AiRequestStatus;
    result: { fields: Record<string, ProfileSuggestionField>; template: string; template_version: string } | null;
    template: string | null;
    audience_warning: string | null;
    error_message: string | null;
    stalled?: boolean;
    stalled_message?: string | null;
    used: number;
    cap: number;
}

export type CreativeSource = "market_reference" | "own_history" | "none";

export interface RankedFormat {
    format_key: string;
    label: string;
    engagement_rate: number | null;
    rank: number;
    note: string | null;
}

export interface CreativeSuggestionResult {
    media_format: string | null;
    media_format_note: string | null;
    hook: string;
    caption: string;
    hashtags: string[];
    cta: string;
    why: string;
    source: CreativeSource;
    source_label: string | null;
    source_url: string | null;
    followers: number | null;
    followers_date: string | null;
    ranked: RankedFormat[];
}

export interface CreativeSuggestion {
    id: number;
    post_id: number;
    status: AiRequestStatus;
    result: CreativeSuggestionResult | null;
    error_message: string | null;
    stalled?: boolean;
    stalled_message?: string | null;
    used: number;
    cap: number;
}

export interface PostCreative {
    post_id: number;
    channel: "instagram" | "facebook" | "site";
    media_format: string | null;
    formats: { value: string; label: string }[];
    creative: {
        media_format: string | null;
        hook: string | null;
        caption: string | null;
        hashtags: string[];
        cta: string | null;
        rationale: string | null;
        source: CreativeSource | null;
        source_label: string | null;
        accepted_at: string | null;
    } | null;
}

export interface CreativeFormatRule {
    id: number;
    channel: "instagram" | "facebook";
    followers_min: number;
    followers_max: number | null;
    format_key: string;
    engagement_rate: number | null;
    rank: number;
    note: string | null;
    source_label: string;
    source_url: string | null;
    is_active: boolean;
}

export const PROFILE_SOURCE_LABEL: Record<ProfileSuggestionSource, string> = {
    template: "Modelo do ramo",
    company_data: "Dados da empresa",
    audience_data: "Público medido",
};

export const CREATIVE_SOURCE_LABEL: Record<CreativeSource, string> = {
    market_reference: "Referência de mercado",
    own_history: "Dados da sua conta",
    none: "Sem referência",
};
