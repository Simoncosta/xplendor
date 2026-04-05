import React, { useEffect, useState } from "react";
import { Card, CardBody, CardHeader, Col, Container, Row } from "reactstrap";
import ExecutionHistory from "./ExecutionHistory";
import ScraperForm from "./ScraperForm";

const ScraperRunner: React.FC = () => {
    const [companyId, setCompanyId] = useState<number | null>(null);
    const [refreshKey, setRefreshKey] = useState(0);

    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (authUser) {
            const obj = JSON.parse(authUser);
            setCompanyId(Number(obj.company_id));
        }
    }, []);

    const handleRunComplete = () => {
        setRefreshKey((k) => k + 1);
    };

    if (!companyId) return null;

    return (
        <div className="page-content">
            <Container fluid>
                <Row className="mb-3">
                    <Col>
                        <h4 className="mb-1">Scraper Runner</h4>
                        <p className="text-muted mb-0 fs-13">
                            <i className="ri-lock-line me-1" />
                            Ferramenta interna — apenas para uso da equipa Xplendor
                        </p>
                    </Col>
                </Row>

                <Row className="g-4">
                    <Col xs={12}>
                        <Card>
                            <CardHeader className="border-bottom-dashed">
                                <h5 className="card-title mb-0">
                                    <i className="ri-settings-4-line me-2 text-primary" />
                                    Configurar execução
                                </h5>
                            </CardHeader>
                            <CardBody>
                                <ScraperForm
                                    companyId={companyId}
                                    onRunComplete={handleRunComplete}
                                />
                            </CardBody>
                        </Card>
                    </Col>

                    <Col xs={12}>
                        <Card>
                            <CardHeader className="border-bottom-dashed">
                                <h5 className="card-title mb-0">
                                    <i className="ri-history-line me-2 text-primary" />
                                    Histórico de execuções
                                </h5>
                            </CardHeader>
                            <CardBody className="p-0">
                                <ExecutionHistory
                                    companyId={companyId}
                                    refreshKey={refreshKey}
                                />
                            </CardBody>
                        </Card>
                    </Col>
                </Row>
            </Container>
        </div>
    );
};

export default ScraperRunner;
