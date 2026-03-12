export type BlogPostStatus = "draft" | "published";

export interface IBlogPost {
    id: number;
    title: string;
    subtitle?: string | null;
    slug: string;
    banner?: string | null;
    excerpt?: string | null;
    content: string;
    tags?: string[] | null;
    category?: string | null;
    status: BlogPostStatus;
    published_at?: string | null;
    read_time?: number | null;
    meta_title?: string | null;
    meta_description?: string | null;
    og_title?: string | null;
    og_description?: string | null;
    og_image?: string | null;
    user_id: number;
    company_id: number;
}