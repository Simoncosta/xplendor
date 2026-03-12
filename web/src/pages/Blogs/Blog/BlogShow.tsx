// React
import React, { useEffect, useState } from "react";
import { useDispatch, useSelector } from "react-redux";
import { useNavigate, useParams } from "react-router-dom";
import { createSelector } from "reselect";
// Components
import { Container } from "reactstrap";
// Slices
import { showBlog } from "slices/thunks";

export default function BlogShow() {
    const dispatch: any = useDispatch();
    const navigate = useNavigate();
    const { id } = useParams();

    document.title = "Visualizar Blog | Xplendor";

    // State
    const [companyId, setCompanyId] = useState<number>(0);

    const selectBlogState = (state: any) => state.Blog;

    const blogSelector = createSelector(selectBlogState, (state: any) => ({
        blog: state.blog,
        loadingShow: state.loadingShow,
    }));

    const { blog, loadingShow } = useSelector(blogSelector);

    // Effects
    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (authUser) {
            const obj = JSON.parse(authUser);
            setCompanyId(Number(obj.company_id));
            dispatch(showBlog({ companyId: obj.company_id, id: Number(id) }));
        }
    }, [dispatch, id]);

    if (loadingShow) return null;

    return (
        <React.Fragment>
            <div className="page-content">
                <Container fluid>
                    <div className="row justify-content-center">
                        <div className="col-xxl-10">
                            <div className="card">
                                <div className="card-body">
                                    <div className="text-center mb-4">
                                        <p className="text-success text-uppercase mb-2">{blog.category}</p>

                                        <h4 className="mb-2">{blog.title}</h4>
                                        <p className="text-muted mb-4">{blog.excerpt}</p>
                                        <div className="d-flex align-items-center justify-content-center flex-wrap gap-2">
                                            {blog.tags.map((tag: any, index: any) => (
                                                <span key={index} className="badge bg-primary-subtle text-primary">
                                                    {tag}
                                                </span>
                                            ))}
                                        </div>
                                    </div>
                                    <img src={process.env.REACT_APP_PUBLIC_URL + blog.banner} alt="" className="img-thumbnail" />

                                    <div className="row mt-4">
                                        <div
                                            className="col-lg-12"
                                            dangerouslySetInnerHTML={{ __html: blog.content ?? "" }}
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </Container>
            </div>
        </React.Fragment>
    )
}