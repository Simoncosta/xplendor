// React
import { useNavigate, useParams } from "react-router-dom";
import { useDispatch, useSelector } from "react-redux";
// Components
import BlogEditor from "./BlogEditor";
// Slices
import { BLOG_CREATE_DEFAULTS } from "slices/blogs/blog.defaults";
import { useEffect, useState } from "react";
import { showBlog, updateBlog } from "slices/thunks";
import { toast, ToastContainer } from "react-toastify";
import { createSelector } from "reselect";

export default function BlogUpdate() {
    const dispatch: any = useDispatch();
    const navigate = useNavigate();
    const { id } = useParams();

    document.title = "Editar Blog | Xplendor";

    // State
    const [companyId, setCompanyId] = useState<number>(0);

    const selectBlogState = (state: any) => state.Blog;

    const blogSelector = createSelector(selectBlogState, (state: any) => ({
        blog: state.blog,
        loading: state.loading,
    }));

    const { blog, loading } = useSelector(blogSelector);

    // Effects
    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (authUser) {
            const obj = JSON.parse(authUser);
            setCompanyId(Number(obj.company_id));
            dispatch(showBlog({ companyId: obj.company_id, id: Number(id) }));
        }
    }, [dispatch, id]);

    return (
        <>
            <ToastContainer />
            <BlogEditor
                data={blog ?? BLOG_CREATE_DEFAULTS}
                onSubmit={(values: any) => {
                    const formData = new FormData();

                    formData.append("title", values.title || "");
                    formData.append("subtitle", values.subtitle || "");
                    formData.append("slug", values.slug || "");
                    formData.append("excerpt", values.excerpt || "");
                    formData.append("content", values.content || "");
                    formData.append("category", values.category || "");
                    formData.append("status", values.status || "draft");

                    if (values.published_at) {
                        formData.append("published_at", values.published_at);
                    }

                    if (values.read_time !== null && values.read_time !== undefined) {
                        formData.append("read_time", String(values.read_time));
                    }

                    if (values.meta_title) {
                        formData.append("meta_title", values.meta_title);
                    }

                    if (values.meta_description) {
                        formData.append("meta_description", values.meta_description);
                    }

                    if (values.og_title) {
                        formData.append("og_title", values.og_title);
                    }

                    if (values.og_description) {
                        formData.append("og_description", values.og_description);
                    }

                    if (values.og_image) {
                        formData.append("og_image", values.og_image);
                    }

                    if (values.user_id) {
                        formData.append("user_id", String(values.user_id));
                    }

                    if (values.company_id) {
                        formData.append("company_id", String(values.company_id));
                    }

                    if (values.tags && values.tags.length > 0) {
                        values.tags.forEach((tag: any, index: any) => {
                            formData.append(`tags[${index}]`, tag);
                        });
                    }

                    if (values.banner instanceof File) {
                        formData.append("banner", values.banner);
                    } else if (typeof values.banner === "string" && values.banner) {
                        formData.append("banner", values.banner);
                    }

                    if (id && values.id) {
                        formData.append("id", String(values.id));
                    }

                    dispatch(updateBlog({ companyId: companyId, id: Number(id), formData: formData }));
                    toast("Blog atualizado com sucesso!", { position: "top-right", hideProgressBar: false, className: 'bg-success text-white' });
                }}
                onCancel={() => {
                    navigate(-1);
                }}
            />
        </>
    )
}