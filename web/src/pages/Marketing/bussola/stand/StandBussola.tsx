import React, { useCallback, useEffect, useState } from "react";
import { Badge, Card, CardBody, Col, Container, Row, Spinner } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import { getBussolaStand } from "helpers/laravel_helper";
import { getWorkingCompanyId } from "helpers/workingCompany";
import type { StandCompassData, StandType } from "common/models/standCompass.model";
import StandTop from "./StandTop";
import StandPlays, { TypeSelector } from "./StandPlays";
import { AdsCard, AgingCard, DemandCard, FooterCard, ForgottenCard, PriceCard } from "./StandBlocks";

/**
 * Bússola, fornecedor do stand (veículos: carros e autocaravanas): o topo, as 3 jogadas da
 * semana e os blocos, a partir do stock, dos preços face ao mercado, dos anúncios e da procura
 * já guardados. Nunca mostra dados pessoais dos contactos, só contagens.
 */
export default function StandBussola({ providerSwitch }: { providerSwitch?: React.ReactNode }) {
    document.title = "Bússola | Xplendor";
    const companyId = getWorkingCompanyId();
    const [type, setType] = useState<StandType>("all");
    const [data, setData] = useState<StandCompassData | null>(null);
    const [loading, setLoading] = useState(true);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const r: any = await getBussolaStand(companyId, type);
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
                <PageHeader
                    title="Bússola"
                    breadcrumbs={[{ label: "Marketing" }]}
                    info={<>
                        <p className="mb-1">O que fazer esta semana no stand, a partir do stock, dos preços face ao mercado, dos anúncios e da procura. Os números descrevem o que aconteceu; não dizem porquê.</p>
                        <p className="mb-1"><Badge color="success-subtle" className="text-success fw-normal me-1">Confiança alta</Badge>factos ou muitos comparáveis. <Badge color="info-subtle" className="text-info fw-normal me-1">Confiança média</Badge>menos comparáveis ou menos vistas. Os sinais de confiança baixa não aparecem.</p>
                        <p className="mb-0">Recalcula-se todas as noites e quando o stock, os preços ou os anúncios mudam.</p>
                    </>}
                    filters={<>{providerSwitch}{data?.enabled ? <TypeSelector data={data} onType={setType} /> : null}</>}
                />
                {!data && loading && <div className="text-center py-5"><Spinner color="primary" /></div>}
                {data && !data.enabled && (
                    <Card><CardBody className="text-muted">A Bússola do stand está desligada nesta empresa. Quando a equipa XPLENDOR a ligar, aparece aqui.</CardBody></Card>
                )}
                {data && data.enabled && (
                    <div className={loading ? "opacity-50" : undefined} data-testid="stand-bussola">
                        <StandTop data={data} />
                        <StandPlays companyId={companyId} data={data} onChanged={load} />
                        {data.blocks && (
                            <>
                                <AgingCard block={data.blocks.aging} />
                                <Row className="g-3 mb-3">
                                    <Col xl={6}><PriceCard block={data.blocks.price} /></Col>
                                    <Col xl={6}><AdsCard block={data.blocks.ads} /></Col>
                                </Row>
                                <Row className="g-3 mb-3">
                                    <Col xl={6}><DemandCard block={data.blocks.demand} /></Col>
                                    <Col xl={6}><ForgottenCard block={data.blocks.forgotten} /></Col>
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
