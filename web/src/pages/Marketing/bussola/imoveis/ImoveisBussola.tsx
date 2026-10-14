import React, { useCallback, useEffect, useState } from "react";
import { Badge, Card, CardBody, Col, Container, Row, Spinner } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import { getBussolaImoveis } from "helpers/laravel_helper";
import { getWorkingCompanyId } from "helpers/workingCompany";
import type { ImoveisCompassData, ImoveisType } from "common/models/imoveisCompass.model";
import StandTop from "../stand/StandTop";
import { FooterCard } from "../stand/StandBlocks";
import ImoveisPlays, { ImoveisTypeSelector } from "./ImoveisPlays";
import { ImoveisAgingCard, ImoveisClosedCard, ImoveisInterestCard, ImoveisPriceCard, ImoveisZonesCard } from "./ImoveisBlocks";

/**
 * Bússola, fornecedor do imobiliário (Bússola Master, R3): o topo, as 3 jogadas da semana e os
 * blocos, a partir da carteira, da Linha Editorial, da referência oficial do INE e do calendário
 * (o BCE). Nunca sugere um preço; sem dados de proprietários, compradores nem inquilinos.
 */
export default function ImoveisBussola({ providerSwitch, radar }: { providerSwitch?: React.ReactNode; radar?: React.ReactNode }) {
    document.title = "Bússola | Xplendor";
    const companyId = getWorkingCompanyId();
    const [type, setType] = useState<ImoveisType>("all");
    const [data, setData] = useState<ImoveisCompassData | null>(null);
    const [loading, setLoading] = useState(true);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const r: any = await getBussolaImoveis(companyId, type);
            setData(r?.data ?? null);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível carregar a Bússola.");
        } finally {
            setLoading(false);
        }
    }, [companyId, type]);
    useEffect(() => { void load(); }, [load]);

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Bússola" breadcrumbs={[{ label: "Marketing" }]}
                    info={<>
                        <p className="mb-1">O que fazer esta semana na carteira de imóveis, a partir dos dias em carteira, da referência oficial do INE, das publicações e do calendário. Os números descrevem o que aconteceu; não dizem porquê. Nunca se sugere um preço.</p>
                        <p className="mb-0"><Badge color="success-subtle" className="text-success fw-normal me-1">Confiança alta</Badge>factos da carteira. <Badge color="info-subtle" className="text-info fw-normal me-1">Confiança média</Badge>a referência do INE é de toda a freguesia.</p>
                    </>}
                    filters={<>{providerSwitch}{data?.enabled ? <ImoveisTypeSelector data={data} onType={setType} /> : null}</>}
                />
                {!data && loading && <div className="text-center py-5"><Spinner color="primary" /></div>}
                {data && !data.enabled && (
                    <>
                        <Card><CardBody className="text-muted">A Bússola do imobiliário está desligada nesta empresa. Quando o módulo dos imóveis for ligado, aparece aqui.</CardBody></Card>
                        {radar}
                    </>
                )}
                {data && data.enabled && (
                    <div className={loading ? "opacity-50" : undefined} data-testid="imoveis-bussola">
                        <StandTop data={data as any} />
                        <ImoveisPlays companyId={companyId} data={data} onChanged={load} />
                        {radar}
                        {data.blocks && (
                            <>
                                <ImoveisAgingCard block={data.blocks.aging} />
                                <Row className="g-3 mb-3">
                                    <Col xl={6}><ImoveisPriceCard block={data.blocks.price} /></Col>
                                    <Col xl={6}><ImoveisZonesCard block={data.blocks.zones} /></Col>
                                </Row>
                                <Row className="g-3 mb-3">
                                    <Col xl={6}><ImoveisInterestCard block={data.blocks.interest} /></Col>
                                    <Col xl={6}><ImoveisClosedCard block={data.blocks.closed} forgotten={data.blocks.forgotten} /></Col>
                                </Row>
                                <FooterCard lines={data.blocks.footer} />
                            </>
                        )}
                    </div>
                )}
            </Container>
        </div>
    );
}
