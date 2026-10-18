/** As respostas da API do site na XPLENDOR (SitePresenter). Só campos públicos. */

export type WhatsappTexts = { popup: string; prefill: string };

export type ZonePhoto = { src: string; src_960: string; width: number | null; height: number | null; alt: string | null; parish: string | null };

/** O valor mediano por m² do INE de uma freguesia, com o período e a atribuição (tal e qual). */
export type Market = { median_m2: number; period: string; attribution: string };

export type Profile = {
    public_name: string;
    primary_host: string | null;
    signature_url: string | null;
    photo_url: string | null;
    /** A fotografia com fundo (página Sobre) e a imagem de partilha (1200 × 630). */
    about_photo_url: string | null;
    share_image_url: string | null;
    hero: { video_mp4_url: string | null; video_webm_url: string | null; poster_url: string | null; poster_960_url?: string | null; promise: string | null };
    bio_short: string | null;
    intro_video: { youtube_id: string } | null;
    /** Os vídeos em destaque da página inicial (Shorts), por ordem. */
    featured_videos: { youtube_id: string }[];
    /** As fotografias das zonas de atuação (do consultor): fundos e separadores. */
    zone_photos: ZonePhoto[];
    brokerage: { legal_name: string | null; ami: string | null };
    network: { label: string | null; text: string | null; logo_url: string | null };
    legal_links: { complaints_book_url: string | null; ral: { name: string; url: string }[]; legal_info_text: string | null };
    data_controller: { legal_name: string | null; nif: string | null; address: string | null; email: string | null };
    privacy_policy_version: string | null;
    contact_retention_months: number;
    site_status: "rascunho" | "publicado";
    credit_partner: { legal_name: string; bp_registration: string; text: string | null; logo_url: string | null } | null;
    whatsapp: { number: string; messages: Record<"general" | "property" | "development" | "buy", WhatsappTexts> } | null;
    socials: Partial<Record<"instagram" | "facebook" | "linkedin" | "youtube" | "tiktok", string>>;
    parishes: { id: number; name: string; dicofre: string | null; municipality: string | null; market: Market | null }[];
    measurement: { ga4_id: string | null; meta_pixel_id: string | null };
    colors: { navy: string; blue: string; amber: string; cream: string; slate: string };
    sections: Record<string, boolean>;
    show_made_with: boolean;
    updated_at: string | null;
};

export type Image = { src: string; srcset: string; width: number | null; height: number | null; alt: string | null };

export type PropertyCard = {
    slug: string; draft: boolean; reference: string; title: string; kind: string; kind_label: string; listing: "venda" | "arrendamento";
    typology: string | null; bedrooms: number | null; bathrooms: number | null; area_m2: number | null; gross_area_m2: number | null;
    construction_state: string | null; price: number | null; price_on_request: boolean; energy_class: string | null; energy_precertificate: boolean;
    availability: "disponivel" | "reservado" | "vendido" | "arrendado"; featured: boolean;
    parish: { name: string; dicofre: string | null } | null; municipality: string | null;
    location: { lat: number; lng: number; radius_m: number; exact: false } | null;
    short_description: string | null; cover: Image | null; has_video: boolean; youtube_id: string | null;
    development: { slug: string; name: string } | null; published_at: string | null; updated_at: string | null;
};

export type PropertyDetail = PropertyCard & {
    description: string | null; images: Image[]; chave_nova: { url: string; reference: string | null } | null;
};

export type Facets = {
    kinds: Record<string, string>; typologies: string[]; construction_states: Record<string, string>;
    parishes: { dicofre: string; name: string; municipality: string | null }[]; price_min: number | null; price_max: number | null;
};

export type DevelopmentCard = {
    slug: string; draft: boolean; name: string; short_description: string | null; parish: { name: string; dicofre: string | null } | null; municipality: string | null;
    construction_phase: string; construction_phase_label: string; expected_completion: string | null; energy_class_expected: string | null;
    featured: boolean; price_from: number | null; units_available: number; cover: Image | null; has_video: boolean; youtube_id: string | null;
    location: { lat: number; lng: number; exact: true } | null;
};

export type DevelopmentDetail = DevelopmentCard & {
    description: string | null; payment_plan: string | null; images: Image[]; chave_nova: { url: string; reference: string | null } | null; units: PropertyCard[];
};

export type BlogCard = {
    title: string; subtitle: string | null; slug: string; banner: string | null; excerpt: string | null; category: string | null;
    category_slug: string | null; published_at: string | null; read_time: number | null; author: { name: string };
};

export type Page<T> = { items: T[]; page: number; per_page: number; total: number; last_page: number };

export type Site = {
    /** O host do site (o da XPLENDOR, depois do mapa de dev). */
    host: string;
    profile: Profile;
    /** Pré-visualização ativa (link assinado): faixa visível, noindex, sem medição. */
    preview: boolean;
    /** O token da pré-visualização, para os pedidos seguintes à API. */
    previewToken: string | null;
};
