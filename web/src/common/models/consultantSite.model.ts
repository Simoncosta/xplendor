/** Site do consultor: os tipos das respostas da API da app (o site dos imóveis, os empreendimentos, o perfil do site e os contactos recebidos). */

export interface ListingImage {
    id: number; url: string; thumb_url: string; width: number | null; height: number | null; order: number; is_cover: boolean; alt: string | null;
}

/** Um item do que falta para publicar: o campo (para levar lá), o nome curto e a frase. */
export interface PublishItem { field: string; label: string; message: string }

export interface PropertySite {
    id: number; reference: string; title: string; slug: string | null; development_id: number | null;
    bedrooms: number | null; bathrooms: number | null; gross_area_m2: number | null; construction_state: string | null;
    approx_lat: number | null; approx_lng: number | null; approx_radius_m: number | null;
    price_on_request: boolean; energy_precertificate: boolean; short_description: string | null; description: string | null; youtube_id: string | null;
    chave_nova_url: string | null; chave_nova_reference: string | null; site_status: "rascunho" | "publicado"; published_at: string | null;
    show_as_sold: boolean; featured: boolean; images: ListingImage[]; publish_missing: string[]; publish_checklist?: PublishItem[];
    options: { construction_states: Record<string, string>; developments: { id: number; name: string }[] };
}

export interface ListingSummaryRow { site_status: "rascunho" | "publicado"; slug: string | null; featured: boolean; cover_url: string | null; images: number; views_30: number }
export interface ListingsSummary { properties: Record<string, ListingSummaryRow>; developments: Record<string, { views_30: number }> }

export interface DevelopmentUnit { id: number; reference: string; title: string; typology: string | null; price: number; price_on_request: boolean; status: string; site_status: string }

export interface Development {
    id: number; name: string; slug: string; short_description: string | null; parish_id: number | null; parish: string | null;
    municipality_id: number | null; municipality: string | null; district_id: number | null; lat: number | null; lng: number | null;
    construction_phase: string; construction_phase_label: string; expected_completion: string | null; energy_class_expected: string | null;
    site_status: "rascunho" | "publicado"; published_at: string | null; featured: boolean; units_count: number; cover_url: string | null;
    description?: string | null; payment_plan?: string | null; youtube_id?: string | null; chave_nova_url?: string | null; chave_nova_reference?: string | null;
    images?: ListingImage[]; publish_missing?: string[]; publish_checklist?: PublishItem[]; units?: DevelopmentUnit[];
}

export interface DevelopmentOptions { phases: Record<string, string>; energy_classes: string[] }

export interface WhatsappTexts { popup: string; prefill: string }

export interface ZonePhoto { id: string; path: string; path_960: string | null; width: number | null; height: number | null; alt: string | null; parish_id: number | null }

export interface ConsultantSiteProfile {
    company: {
        public_name: string; mobile: string | null; whatsapp_number: string | null; socials: Record<string, string>; facebook_pixel_id: string | null;
        fiscal_name: string | null; nipc: string | null; address: string | null; email: string | null;
    };
    hero_promise: string | null; bio_short: string | null; intro_youtube_id: string | null; intro_video_enabled: boolean;
    /** Os vídeos em destaque da página inicial: IDs do YouTube, por ordem. */
    featured_videos: string[];
    /** As fotografias das zonas (fundos e separadores do site), sem EXIF nem GPS. */
    zone_photos: ZonePhoto[];
    brokerage_legal_name: string | null; brokerage_ami: string | null; network_label: string | null; network_text: string | null;
    legal_links: { complaints_book_url: string | null; ral: { name: string; url: string }[]; legal_info_text: string | null };
    /** null até ser escolhido. */
    controller_is_company: boolean | null; controller_legal_name: string | null; controller_nif: string | null; controller_address: string | null; controller_email: string | null;
    data_controller: { legal_name: string | null; nif: string | null; address: string | null; email: string | null; is_company: boolean | null };
    privacy_policy_version: string | null;
    credit_partner: { legal_name: string | null; bp_registration: string | null; text: string | null }; credit_partner_enabled: boolean;
    whatsapp_messages: Record<string, WhatsappTexts>;
    parishes: { id: number; name: string; municipality: string | null }[];
    ga4_id: string | null; colors: Record<string, string>; sections: Record<string, boolean>; show_made_with: boolean;
    assets: Record<string, string | null>; hosts: { host: string; is_primary: boolean }[];
    site_status: "rascunho" | "publicado"; published_at: string | null; publish_missing: string[];
    options: { sections: Record<string, string>; default_colors: Record<string, string>; default_whatsapp_messages: Record<string, WhatsappTexts> };
}

export interface InboundContact {
    id: number; channel: string; channel_label: string; type: string; type_label: string; name: string; email: string | null; phone: string | null;
    status: "novo" | "tratado" | "arquivado"; created_at: string | null;
    property: { id: number; reference: string; title: string } | null; development: { id: number; name: string } | null;
    consent_marketing: boolean; utm_source: string | null; utm_campaign: string | null;
    summary?: string; message?: string | null; details?: Record<string, any>; parishes?: Record<string, string>;
    consent_privacy_at?: string | null; consent_marketing_at?: string | null; consent_credit_share?: boolean; consent_credit_share_at?: string | null;
    policy_version?: string | null; origin?: Record<string, any>; handled_at?: string | null; retain_until?: string | null;
}

export interface InboundContactOptions { types: Record<string, string>; statuses: Record<string, string>; channels: Record<string, string> }

/** Caminho do disco público ("/storage/...") para o endereço da imagem. */
export const storageUrl = (path: string | null | undefined): string | null => {
    if (!path) return null;
    if (/^(https?:)?\/\//.test(path)) return path;
    return (process.env.REACT_APP_PUBLIC_URL || "") + path;
};
