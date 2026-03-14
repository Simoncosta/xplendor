import { IBlogPost } from "common/models/blog.model";

export const BLOG_CREATE_DEFAULTS: IBlogPost = {
    id: 0,
    title: "",
    subtitle: null,
    slug: "",
    banner: null,
    excerpt: null,
    content: "",
    tags: [],
    category: null,
    status: "draft",
    published_at: null,
    read_time: null,
    meta_title: null,
    meta_description: null,
    og_title: null,
    og_description: null,
    og_image: null,
    user_id: 0,
    company_id: 0,
};