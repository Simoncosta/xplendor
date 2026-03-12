// React
import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useDispatch } from "react-redux";
import { toast, ToastContainer } from "react-toastify";
// Components
import BlogEditor from "./BlogEditor";
// Slices
import { BLOG_CREATE_DEFAULTS } from "slices/blogs/blog.defaults";
import { createBlog } from "slices/thunks";

export default function BlogCreate() {
    const navigate = useNavigate();
    const dispatch: any = useDispatch();

    document.title = "Novo Blog | Xplendor";

    // States
    const [companyId, setCompanyId] = useState(0);

    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (authUser) {
            const obj = JSON.parse(authUser);
            setCompanyId(Number(obj.company_id));
        }
    }, [dispatch]);

    return (
        <>
            <ToastContainer />
            <BlogEditor
                data={BLOG_CREATE_DEFAULTS}
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

                    dispatch(createBlog({ companyId, formData: formData }));
                    toast("Carro criado com sucesso!", { position: "top-right", hideProgressBar: false, className: "bg-success text-white" });
                    navigate(-1);
                }}
                onCancel={() => {
                    navigate(-1);
                }}
            />
        </>
    )
}