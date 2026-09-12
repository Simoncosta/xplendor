import { useEffect, useState } from "react";
import { Card, CardBody, CardHeader, Spinner } from "reactstrap";
import axios from "axios";

/**
 * DMS Pós-venda (Incremento 3) — LADO INTERNO: a avaliação que o cliente deixou
 * no relatório (estrelas + comentário). O stand vê o feedback — sobretudo os <4,
 * para saber o que melhorar. Autenticado, tenant-scoped pelo endpoint.
 */
interface Review { rating: number; comment: string | null; submitted_at?: string | null; }

export default function ClientReviewCard({ companyId, carId }: { companyId: number; carId: number }) {
    const [review, setReview] = useState<Review | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        let alive = true;
        setLoading(true);
        axios
            .get(`/companies/${companyId}/cars/${carId}/satisfaction-report/review`)
            .then((res: any) => { if (alive) setReview(res?.data?.review ?? null); })
            .catch(() => { if (alive) setReview(null); })
            .finally(() => { if (alive) setLoading(false); });
        return () => { alive = false; };
    }, [companyId, carId]);

    const low = review !== null && review.rating < 4;

    return (
        <Card className="mt-3 mb-0">
            <CardHeader>
                <h5 className="mb-0"><i className="ri-star-line me-1 text-primary" /> Avaliação do cliente</h5>
                <small className="text-muted">Avaliação deixada no relatório de pós-venda.</small>
            </CardHeader>
            <CardBody>
                {loading ? (
                    <div className="d-flex align-items-center gap-2 text-muted fs-13"><Spinner size="sm" /> A carregar…</div>
                ) : review === null ? (
                    <p className="text-muted fs-13 mb-0">O cliente ainda não avaliou.</p>
                ) : (
                    <>
                        <div className="d-flex align-items-center gap-1 mb-2" aria-label={`${review.rating} de 5`}>
                            {[1, 2, 3, 4, 5].map((n) => (
                                <i
                                    key={n}
                                    className={n <= review.rating ? "ri-star-fill" : "ri-star-line"}
                                    style={{ color: n <= review.rating ? "#f7b84b" : "#ced4da", fontSize: 20 }}
                                />
                            ))}
                            <span className="ms-2 fw-semibold">{review.rating}/5</span>
                            {low && <span className="badge bg-warning-subtle text-warning ms-2">A melhorar</span>}
                        </div>
                        {review.comment ? (
                            <p className="mb-0 fst-italic text-body">“{review.comment}”</p>
                        ) : (
                            <p className="mb-0 text-muted fs-13">Sem comentário.</p>
                        )}
                    </>
                )}
            </CardBody>
        </Card>
    );
}
