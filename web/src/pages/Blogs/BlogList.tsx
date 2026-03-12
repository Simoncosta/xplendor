// React
import React, { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
// Components
import Pagination from 'Components/Common/Pagination'
import { Col, Container, Row } from 'reactstrap'
// Redux
import { useDispatch, useSelector } from 'react-redux'
import { getBlogsPaginate } from 'slices/thunks'

const BlogList = () => {
    const dispatch: any = useDispatch();
    document.title = "Blogs | Xplendor";

    // Redux direto, sem reselect desnecessário
    const { blogs, meta, loading } = useSelector(
        (state: any) => state.Blog
    );

    // Paginação controlada no pai (server-side)
    const [pagination, setPagination] = useState({
        pageIndex: 0,
        pageSize: 10,
    });

    // Fetch sempre que mudar página ou tamanho
    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (authUser) {
            const obj = JSON.parse(authUser);
            dispatch(
                getBlogsPaginate({
                    page: pagination.pageIndex + 1,
                    perPage: pagination.pageSize,
                    companyId: obj.company_id,
                })
            );
        }
    }, [dispatch, pagination.pageIndex, pagination.pageSize]);

    return (
        <React.Fragment>
            <div className="page-content">
                <Container fluid>

                    <Row className="g-4 mb-3">
                        <div className="col-sm">
                            <div className="d-flex justify-content-sm-init gap-2">
                                <div className="search-box">
                                    <input type="text" className="form-control" placeholder="Buscar..." />
                                    <i className="ri-search-line search-icon"></i>
                                </div>

                                <select className="form-control w-md" style={{ width: "152px" }} defaultValue="Yesterday">
                                    <option value="All">All</option>
                                    <option value="Today">Today</option>
                                    <option value="Yesterday">Yesterday</option>
                                    <option value="Last 7 Days">Last 7 Days</option>
                                    <option value="Last 30 Days">Last 30 Days</option>
                                    <option value="This Month">This Month</option>
                                    <option value="Last Year">Last Year</option>
                                </select>
                            </div>
                        </div>
                        <div className="col-sm-auto">
                            <div>
                                <Link to="/blogs/create" className="btn btn-outline-success">
                                    <i className="ri-add-line align-bottom"></i>
                                </Link>
                            </div>
                        </div>
                    </Row>

                    <Row>
                        {blogs && blogs.map((item: any, idx: any) => (
                            <Col xxl={3} lg={6} key={idx}>
                                <div className="card overflow-hidden blog-grid-card">
                                    <div className="position-relative overflow-hidden">
                                        <img src={process.env.REACT_APP_PUBLIC_URL + item.banner} alt="" className="blog-img object-fit-cover" />
                                    </div>
                                    <div className="card-body">
                                        <h5 className="card-title">
                                            <Link to="/pages-blog-overview" className="text-reset">{item.title}</Link>
                                        </h5>
                                        <p className="text-muted mb-2">{item.excerpt}</p>

                                        <div className='d-flex align-items-center justify-content-between flex-wrap gap-2'>
                                            <Link to={`/blogs/${item.id}/show`} className="link link-primary text-decoration-underline link-offset-1">
                                                Ler Post
                                                <i className="ri-arrow-right-up-line"></i>
                                            </Link>
                                            <Link to={`/blogs/${item.id}`} className="link link-primary text-decoration-underline link-offset-1">
                                                Editar
                                                <i className="ri-arrow-right-up-line"></i>
                                            </Link>
                                        </div>
                                    </div>
                                </div>
                            </Col>
                        ))}
                    </Row>

                    <Pagination
                        currentPage={meta?.current_page ?? 1}
                        lastPage={meta?.last_page ?? 1}
                        total={meta?.total ?? 0}
                        perPage={meta?.per_page ?? pagination.pageSize}
                        from={meta?.from ?? 0}
                        to={meta?.to ?? 0}
                        onPageChange={(page) => {
                            setPagination((prev) => ({
                                ...prev,
                                pageIndex: page - 1,
                            }));
                        }}
                    />

                </Container>
            </div>
        </React.Fragment>
    )
}

export default BlogList