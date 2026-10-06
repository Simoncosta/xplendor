import { useEffect, useMemo, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { Spinner } from "reactstrap";
import ContentReviewView from "./ContentReviewView";
import type { ReviewPayload } from "common/models/contentReview.model";
import { getReviewLinkPreview } from "helpers/laravel_helper";
import "./content-review.css";

/**
 * "Ver como o cliente": a página do link de aprovação dentro da app, exatamente como o
 * cliente a vê, sem ações e sem contar como abertura.
 */
export default function ContentReviewPreview() {
    const { id } = useParams();
    const [data, setData] = useState<ReviewPayload | null>(null);
    const [failed, setFailed] = useState(false);
    const companyId = useMemo(() => { try { return Number(JSON.parse(sessionStorage.getItem("authUser") || "{}").company_id || 0); } catch { return 0; } }, []);

    useEffect(() => {
        document.title = "Ver como o cliente | Xplendor";
        getReviewLinkPreview(companyId, Number(id)).then((r: any) => setData(r.data)).catch(() => setFailed(true));
    }, [companyId, id]);

    return (
        <div className="page-content">
            <div className="mb-2 px-3">
                <Link to={`/editorial?aprovacoes=${id}`} className="btn btn-sm btn-light"><i className="ri-arrow-left-line me-1" />Voltar aos links de aprovação</Link>
            </div>
            {failed ? <p className="text-center text-muted py-5">Não foi possível abrir a pré-visualização.</p>
                : !data ? <div className="text-center py-5"><Spinner /></div>
                    : <ContentReviewView data={data} name="" onName={() => undefined} />}
        </div>
    );
}
