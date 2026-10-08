import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { Card, CardBody, Col, Container, Row, Spinner } from "reactstrap";
import { toast, ToastContainer } from "react-toastify";
import PageHeader from "Components/Common/PageHeader";
import { getBussola, ignoreRestaurantSignal } from "helpers/laravel_helper";
import { getWorkingCompanyId } from "helpers/workingCompany";
import type { CompassData } from "common/models/bussola.model";
import CreatePostModal from "../CreatePostModal";
import BussolaSummary from "./BussolaSummary";
import { BussolaFooter, ChangesCard, ChannelsCard, DaysCard, DecideCard, ForgottenCard, StarsCard } from "./BussolaBlocks";

/**
 * Bússola (antes "O que publicar e quando"): o topo da semana, as 3 jogadas e os blocos
 * (dias para encher, estrelas, a ganhar e a perder força, quando o cliente decide, por onde
 * chegam, esquecidos), a partir das vendas (PingWin) e das reservas (CoverManager).
 */
export default function BussolaPage() {
    document.title = "Bússola | Xplendor";
    const companyId = getWorkingCompanyId();
    const [locationId, setLocationId] = useState(0);
    const [data, setData] = useState<CompassData | null>(null);
    const [loading, setLoading] = useState(true);
    const [forgotten, setForgotten] = useState<any>(null);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const r: any = await getBussola(companyId, locationId || null);
            setData(r?.data ?? null);
        } catch (e: any) {
            toast.error(e?.message ?? "Não foi possível carregar a Bússola.");
        } finally {
            setLoading(false);
        }
    }, [companyId, locationId]);
    useEffect(() => { void load(); }, [load]);

    const ignore = async (key: string) => {
        try {
            await ignoreRestaurantSignal(companyId, key);
            toast.success("Artigo escondido durante 4 semanas.");
            void load();
        } catch (e: any) { toast.error(e?.message ?? "Não foi possível ignorar."); }
    };

    return (
        <div className="page-content">
            <ToastContainer />
            <Container fluid>
                <PageHeader title="Bússola" breadcrumbs={[{ label: "Marketing" }]}
                    description="O que publicar e quando, a partir das vendas e das reservas do restaurante." />
                {!data && loading && <div className="text-center py-5"><Spinner color="primary" /></div>}
                {data && !data.enabled && (
                    <Card><CardBody className="text-muted">A leitura das vendas por artigo está desligada nesta empresa. Quando for ligada, a Bússola aparece aqui.</CardBody></Card>
                )}
                {data && data.enabled && (
                    <>
                        {data.categories_pending > 0 && (
                            <div className="alert alert-warning fs-13" role="alert">
                                <i className="ri-error-warning-line me-1" aria-hidden />Há {data.categories_pending} {data.categories_pending === 1 ? "família" : "famílias"} de artigos por confirmar: os rankings por categoria e os grupos de artigos ficam completos quando forem confirmadas. <Link to="/restauracao/categorias">Confirmar as categorias</Link>
                            </div>
                        )}
                        <div className={loading ? "opacity-50" : undefined}>
                            <BussolaSummary companyId={companyId} data={data} onLocation={setLocationId} onChanged={load} />
                            {data.blocks && (
                                <>
                                    <DaysCard companyId={companyId} block={data.blocks.days} key={`days-${data.location_id}`} />
                                    <StarsCard block={data.blocks.stars} />
                                    <ChangesCard block={data.blocks.changes} />
                                    <Row className="g-3 mb-3">
                                        <Col xl={6}><DecideCard block={data.blocks.decide} /></Col>
                                        <Col xl={6}><ChannelsCard block={data.blocks.channels} /></Col>
                                    </Row>
                                    <ForgottenCard block={data.blocks.forgotten} canAct={!!data.can_act} canActReason={data.can_act_reason}
                                        onCreate={(it) => setForgotten({ key: it.key, theme: it.theme ?? `Voltar a mostrar: ${it.name}`, title: it.name, suggested_date: it.date })}
                                        onIgnore={ignore} />
                                    <BussolaFooter computedAt={data.computed_at} />
                                </>
                            )}
                        </div>
                    </>
                )}
            </Container>
            <CreatePostModal companyId={companyId} signal={forgotten} formats={data?.formats ?? ["Imagem única"]} networks={["instagram", "facebook"]} specialDays={{}}
                onClose={() => setForgotten(null)} onCreated={(msg) => { setForgotten(null); toast.success(msg); void load(); }} />
        </div>
    );
}
