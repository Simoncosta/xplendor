import React from "react";
import { Link } from "react-router-dom";
import { Row } from "reactstrap";

interface XPaginationProps {
    currentPage: number;
    lastPage: number;
    total: number;
    perPage: number;
    from: number;
    to: number;
    onPageChange: (page: number) => void;
}

const Pagination = ({
    currentPage,
    lastPage,
    total,
    perPage,
    from,
    to,
    onPageChange,
}: XPaginationProps) => {
    const handleClick = (page: number) => {
        if (page < 1 || page > lastPage || page === currentPage) return;
        onPageChange(page);
    };

    const handlePrevPage = () => {
        if (currentPage > 1) {
            onPageChange(currentPage - 1);
        }
    };

    const handleNextPage = () => {
        if (currentPage < lastPage) {
            onPageChange(currentPage + 1);
        }
    };

    const pageNumbers: number[] = [];

    const maxVisiblePages = 5;
    let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
    let endPage = startPage + maxVisiblePages - 1;

    if (endPage > lastPage) {
        endPage = lastPage;
        startPage = Math.max(1, endPage - maxVisiblePages + 1);
    }

    for (let i = startPage; i <= endPage; i++) {
        pageNumbers.push(i);
    }

    return (
        <Row className="g-0 text-center text-sm-start align-items-center mb-4">
            <div className="col-sm-6">
                <div>
                    <p className="mb-sm-0 text-muted">
                        Mostrando{" "}
                        <span className="fw-semibold">{from}</span> até{" "}
                        <span className="fw-semibold">{to}</span> de{" "}
                        <span className="fw-semibold text-decoration-underline">{total}</span> resultados
                    </p>
                </div>
            </div>

            <div className="col-sm-6">
                <div className="d-flex justify-content-center justify-content-sm-end mt-3 mt-sm-0">
                    <ul className="pagination pagination-separated pagination-md mb-0">
                        <li className={`page-item ${currentPage <= 1 ? "disabled" : ""}`}>
                            <Link
                                to="#"
                                className="page-link"
                                onClick={(e) => {
                                    e.preventDefault();
                                    handlePrevPage();
                                }}
                            >
                                Anterior
                            </Link>
                        </li>

                        {startPage > 1 && (
                            <>
                                <li className="page-item">
                                    <Link
                                        to="#"
                                        className="page-link"
                                        onClick={(e) => {
                                            e.preventDefault();
                                            handleClick(1);
                                        }}
                                    >
                                        1
                                    </Link>
                                </li>
                                {startPage > 2 && (
                                    <li className="page-item disabled">
                                        <span className="page-link">...</span>
                                    </li>
                                )}
                            </>
                        )}

                        {pageNumbers.map((page) => (
                            <li key={page} className="page-item">
                                <Link
                                    to="#"
                                    className={currentPage === page ? "page-link active" : "page-link"}
                                    onClick={(e) => {
                                        e.preventDefault();
                                        handleClick(page);
                                    }}
                                >
                                    {page}
                                </Link>
                            </li>
                        ))}

                        {endPage < lastPage && (
                            <>
                                {endPage < lastPage - 1 && (
                                    <li className="page-item disabled">
                                        <span className="page-link">...</span>
                                    </li>
                                )}
                                <li className="page-item">
                                    <Link
                                        to="#"
                                        className="page-link"
                                        onClick={(e) => {
                                            e.preventDefault();
                                            handleClick(lastPage);
                                        }}
                                    >
                                        {lastPage}
                                    </Link>
                                </li>
                            </>
                        )}

                        <li className={`page-item ${currentPage >= lastPage ? "disabled" : ""}`}>
                            <Link
                                to="#"
                                className="page-link"
                                onClick={(e) => {
                                    e.preventDefault();
                                    handleNextPage();
                                }}
                            >
                                Próximo
                            </Link>
                        </li>
                    </ul>
                </div>
            </div>
        </Row>
    );
};

export default Pagination;