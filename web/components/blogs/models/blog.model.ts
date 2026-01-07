export interface IBlogs {
    id: number | undefined;
    title: string;
    subtitle?: string;
    slug?: string;
    banner?: string;
    excerpt?: string;
    content: string;
    tags?: string[];
    category?: string;
    status: 'draft' | 'published';
    published_at?: string;
    read_time?: number;
    meta_title?: string;
    meta_description?: string;
    og_title?: string;
    og_description?: string;
    og_image?: string;
}