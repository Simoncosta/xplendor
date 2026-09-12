import { useEffect, useMemo, useState } from "react";
import { useParams } from "react-router-dom";
import { Card, CardBody, Col, Container, Row, Spinner } from "reactstrap";
import CarPageNav from "./components/CarPageNav";
import CarAnalyticsHeader from "./components/CarAnalyticsHeader";
import CarDocumentsCard from "./components/CarDocumentsCard";
import { fetchCarSpecs } from "helpers/carSpecs_helper";
import { fmtDate, ipsClassBadge } from "./helpers/CarAnalyticsData";
import type { CarSpecs } from "types/api";

/**
 * DMS Fase 3 — tab "Documentos": o lar de tudo o que é imprimível desta viatura
 * (ficha A4 + documentos de venda). Disponível para todos os estados EXCETO
 * rascunho (draft). Não bloqueia por falta de venda/cliente — nesse caso os
 * campos vêm vazios e o utilizador preenche no modal.
 */
export default function CarDocumentsPage() {
    document.title = "Documentos | Xplendor";
    const { id } = useParams();

    const companyId = useMemo(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (!authUser) return 0;
        return Number(JSON.parse(authUser).company_id);
    }, []);

    const [specs, setSpecs] = useState<CarSpecs | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);

    useEffect(() => {
        if (!companyId || !id) return;
        setLoading(true);
        setError(false);
        fetchCarSpecs(companyId, Number(id))
            .then((res: any) => setSpecs(res?.data ?? null))
            .catch(() => setError(true))
            .finally(() => setLoading(false));
    }, [companyId, id]);

    const isDraft = specs?.status === "draft";

    // Mesmo adapter da Ficha para o cabeçalho do veículo (CarAnalyticsHeader
    // espera um objecto plano). Todas as tabs de /cars/:id/* mostram este topo.
    const carForHeader = specs
        ? {
            id: specs.id,
            brand: specs.brand,
            model: specs.model,
            version: specs.version,
            price_gross: specs.price.gross,
            promo_price_gross: specs.price.promo_gross,
            promo_discount_pct: specs.price.promo_discount_pct,
            license_plate: specs.identification.license_plate,
            created_at: specs.created_at,
        }
        : null;

    return (
        <div className="page-content">
            <Container fluid>
                {carForHeader && specs && (
                    <Row className="mb-2">
                        <Col>
                            <CarAnalyticsHeader
                                car={carForHeader}
                                ips={specs.header_meta.potential_score}
                                ai={specs.header_meta.analyses?.analysis ?? null}
                                aiMeta={specs.header_meta.analyses}
                                fmtDate={fmtDate}
                                ipsClassBadge={ipsClassBadge}
                            />
                        </Col>
                    </Row>
                )}

                <Row className="mb-3">
                    <Col>
                        <CarPageNav active="documents" />
                    </Col>
                </Row>

                {loading && (
                    <div className="d-flex justify-content-center align-items-center" style={{ minHeight: 160 }}>
                        <Spinner color="primary" />
                    </div>
                )}

                {!loading && (error || !specs) && (
                    <Card><CardBody className="text-center text-muted py-4">Não foi possível carregar a viatura.</CardBody></Card>
                )}

                {!loading && specs && isDraft && (
                    <Card>
                        <CardBody className="text-center text-muted py-5">
                            <i className="ri-draft-line display-6 d-block mb-2" />
                            <h6 className="mb-1">Documentos indisponíveis em rascunho</h6>
                            <p className="mb-0">Publica a viatura (deixa de ser rascunho) para gerar a ficha e os documentos de venda.</p>
                        </CardBody>
                    </Card>
                )}

                {!loading && specs && !isDraft && companyId > 0 && id && (
                    <CarDocumentsCard
                        companyId={companyId}
                        carId={Number(id)}
                        hasCustomer={Boolean(specs.sale?.customer_id)}
                        hasSale={Boolean(specs.sale)}
                    />
                )}
            </Container>
        </div>
    );
}
