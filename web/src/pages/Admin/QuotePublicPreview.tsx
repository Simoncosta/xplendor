import { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import QuotePublicView from "pages/QuotePublic/QuotePublicView";
import type { QuotePublicData } from "common/models/quotePublic.model";
import { adminQuoteVersionPdfPath, getAdminQuotePublicPreview } from "helpers/laravel_helper";
import { openPdfGet } from "helpers/download_helper";
import "pages/QuotePublic/quote-public.css";

/**
 * A página pública de uma versão, vista pela equipa dentro da XPLENDOR: exatamente o que
 * o cliente vê, mas nunca conta como abertura e não permite responder.
 */
export default function QuotePublicPreview() {
    const { id, version } = useParams();
    const [data, setData] = useState<QuotePublicData | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        document.title = "Pré-visualização do orçamento | Xplendor";
        getAdminQuotePublicPreview(Number(id), Number(version))
            .then((r: any) => setData(r?.data ?? null))
            .catch(() => setFailed(true));
    }, [id, version]);

    const back = (
        <Link to={`/admin/quotes/${id}`} className="qp-btn" style={{ position: "fixed", top: 12, left: 12, zIndex: 25, minHeight: 38, padding: "0 12px", fontSize: 14 }}>
            ← Voltar ao orçamento
        </Link>
    );

    if (failed) return <div className="qp" data-bs-theme="light">{back}<div className="qp-paper"><div className="qp-loading">Não foi possível abrir a pré-visualização.</div></div></div>;
    if (!data) return <div className="qp" data-bs-theme="light"><div className="qp-paper"><div className="qp-loading">A carregar…</div></div></div>;

    return (
        <div style={{ background: "#eceef1", paddingTop: 44 }}>
            {back}
            <QuotePublicView data={data} onDownloadPdf={() => void openPdfGet(adminQuoteVersionPdfPath(Number(id), Number(version)))} />
        </div>
    );
}
