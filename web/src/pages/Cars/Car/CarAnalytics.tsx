// React
import { useEffect, useState } from "react";
import CountUp from "react-countup";
import { useDispatch, useSelector } from "react-redux";
import { Link, useNavigate, useParams } from "react-router-dom";
// Component
import { Card, CardBody, CardHeader, Col, Container, Progress, Row } from "reactstrap";
// Charts
import ReactApexChart from "react-apexcharts";
// Slices
import { createSelector } from "reselect";
import { analyticsCar } from "slices/cars/thunk";

// Legenda do KPI - necessário ir para a tela frontend
// Cores
// 	•	Views → azul
// 	•	Views 24h → info
// 	•	Views 7 dias → secondary
// 	•	Interações → verde se houver
// 	•	Leads → verde se houver
// 	•	Taxa de Interesse:
// 	•	0–2.9 → cinza
// 	•	3–9.9 → amarelo
// 	•	10+ → verde

// Badge na Taxa
// 	•	taxa alta → seta a subir
// 	•	taxa média → traço
// 	•	taxa baixa → seta a descer

export default function CarAnalytics() {
    document.title = "Análises do Carro | Xplendor";

    const dispatch: any = useDispatch();
    const navigate = useNavigate();
    const { id } = useParams();
    // State
    const [companyId, setCompanyId] = useState<number>(0);

    const selectCarState = (state: any) => state.Car;

    const carSelector = createSelector(selectCarState, (state: any) => ({
        carAnalytics: state.carAnalytics,
        loading: state.loading,
    }));

    const { carAnalytics, loading } = useSelector(carSelector);

    useEffect(() => {
        const authUser = sessionStorage.getItem("authUser");
        if (authUser) {
            const obj = JSON.parse(authUser);
            setCompanyId(Number(obj.company_id));
            dispatch(analyticsCar({ companyId: obj.company_id, id: Number(id) }));
        }
    }, [dispatch, id]);

    if (loading) return null;
    if (!carAnalytics) return null;

    // INIT METRICS
    const getInterestRateColor = (rate: number) => {
        if (rate >= 10) return "text-success";
        if (rate >= 3) return "text-warning";
        return "text-muted";
    };

    const getInterestRateBadge = (rate: number) => {
        if (rate >= 10) return "ri-arrow-up-line text-success";
        if (rate >= 3) return "ri-subtract-line text-warning";
        return "ri-arrow-down-line text-muted";
    };

    const kpiItems = [
        {
            id: 1,
            label: "Views",
            counter: carAnalytics.metrics.views,
            icon: "ri-eye-line",
            iconClass: "text-primary",
            badge: "",
            prefix: "",
            suffix: "",
            separator: ",",
            decimals: 0,
            valueClass: "text-body"
        },
        {
            id: 2,
            label: "Views (24h)",
            counter: carAnalytics.metrics.views_24h,
            icon: "ri-time-line",
            iconClass: carAnalytics.metrics.views_24h > 0 ? "text-info" : "text-muted",
            badge: "",
            prefix: "",
            suffix: "",
            separator: ",",
            decimals: 0,
            valueClass: "text-body"
        },
        {
            id: 3,
            label: "Views (7 dias)",
            counter: carAnalytics.metrics.views_7d,
            icon: "ri-calendar-line",
            iconClass: carAnalytics.metrics.views_7d > 0 ? "text-secondary" : "text-muted",
            badge: "",
            prefix: "",
            suffix: "",
            separator: ",",
            decimals: 0,
            valueClass: "text-body"
        },
        {
            id: 4,
            label: "Interações",
            counter: carAnalytics.metrics.interactions,
            icon: "ri-cursor-line",
            iconClass: carAnalytics.metrics.interactions > 0 ? "text-success" : "text-muted",
            badge: "",
            prefix: "",
            suffix: "",
            separator: ",",
            decimals: 0,
            valueClass: carAnalytics.metrics.interactions > 0 ? "text-success" : "text-body"
        },
        {
            id: 5,
            label: "Leads",
            counter: carAnalytics.metrics.leads,
            icon: "ri-user-follow-line",
            iconClass: carAnalytics.metrics.leads > 0 ? "text-success" : "text-muted",
            badge: "",
            prefix: "",
            suffix: "",
            separator: ",",
            decimals: 0,
            valueClass: carAnalytics.metrics.leads > 0 ? "text-success" : "text-body"
        },
        {
            id: 6,
            label: "Taxa de Interesse",
            counter: carAnalytics.metrics.interest_rate,
            icon: "ri-line-chart-line",
            iconClass: getInterestRateColor(carAnalytics.metrics.interest_rate),
            badge: getInterestRateBadge(carAnalytics.metrics.interest_rate),
            prefix: "",
            suffix: "%",
            separator: ",",
            decimals: 1,
            valueClass: getInterestRateColor(carAnalytics.metrics.interest_rate)
        }
    ];
    // END METRICS

    // INIT TRAFFIC SOURCES
    const channelLabels: any = {
        paid: "Tráfego Pago",
        direct: "Direto",
        organic_social: "Social Orgânico",
        organic_search: "Pesquisa Orgânica",
        referral: "Referência",
        email: "Email",
        utm: "Campanha UTM",
    };

    const channelColors: any = {
        paid: "#0d6efd",
        direct: "#6c757d",
        organic_social: "#20c997",
        organic_search: "#198754",
        referral: "#ffc107",
        email: "#6610f2",
        utm: "#fd7e14",
    };


    const normalizedTrafficSources = [...carAnalytics.traffic_sources]
        .map((item) => ({
            key: item.channel,
            label: channelLabels[item.channel] || item.channel || "Sem origem",
            total: Number(item.total || 0),
            color: channelColors[item.channel] || "#adb5bd",
        }))
        .sort((a, b) => b.total - a.total);

    const totalTraffic = normalizedTrafficSources.reduce(
        (sum: any, item: any) => sum + item.total,
        0
    );

    const series = normalizedTrafficSources.map((item: any) => item.total);

    const options = {
        chart: {
            type: "donut",
            toolbar: {
                show: false,
            },
        },
        labels: normalizedTrafficSources.map((item: any) => item.label),
        colors: normalizedTrafficSources.map((item: any) => item.color),
        legend: {
            show: false,
        },
        dataLabels: {
            enabled: true,
        },
        stroke: {
            width: 0,
        },
        plotOptions: {
            pie: {
                donut: {
                    size: "72%",
                    labels: {
                        show: true,
                        name: {
                            show: true,
                            offsetY: 18,
                        },
                        value: {
                            show: true,
                            fontSize: "22px",
                            fontWeight: 700,
                            offsetY: -10,
                            formatter: function (val: any) {
                                return parseInt(val, 10);
                            },
                        },
                        total: {
                            show: true,
                            label: "Views",
                            fontSize: "14px",
                            fontWeight: 500,
                            formatter: function () {
                                return totalTraffic;
                            },
                        },
                    },
                },
            },
        },
        tooltip: {
            y: {
                formatter: function (value: any) {
                    const percent = totalTraffic > 0
                        ? ((value / totalTraffic) * 100).toFixed(1)
                        : 0;

                    return `${value} views (${percent}%)`;
                },
            },
        },
        responsive: [
            {
                breakpoint: 768,
                options: {
                    chart: {
                        height: 280,
                    },
                },
            },
        ],
    };
    // END TRAFFIC SOURCES

    // INIT INTERACTIONS BREAKDOWN
    const interactionsBreakdown = carAnalytics.interactions_breakdown || [];

    const interactionLabels: any = {
        whatsapp_click: "WhatsApp",
        call_click: "Chamada",
        show_phone: "Mostrar Telefone",
        copy_phone: "Copiar Telefone",
        favorite: "Favorito",
        share: "Partilha",
        form_open: "Abrir Formulário",
        form_start: "Iniciar Formulário",
        location_view: "Ver Localização",
    };

    const interactionIcons: any = {
        whatsapp_click: "ri-whatsapp-line",
        call_click: "ri-phone-line",
        show_phone: "ri-smartphone-line",
        copy_phone: "ri-file-copy-line",
        favorite: "ri-heart-line",
        share: "ri-share-line",
        form_open: "ri-file-list-line",
        form_start: "ri-edit-line",
        location_view: "ri-map-pin-line",
    };

    const interactionColors: any = {
        whatsapp_click: "text-success",
        call_click: "text-info",
        show_phone: "text-warning",
        copy_phone: "text-secondary",
        favorite: "text-danger",
        share: "text-primary",
        form_open: "text-dark",
        form_start: "text-muted",
        location_view: "text-primary",
    };

    const normalizedInteractions = [...interactionsBreakdown]
        .map((item) => ({
            key: item.interaction_type,
            label: interactionLabels[item.interaction_type] || item.interaction_type,
            total: Number(item.total || 0),
            icon: interactionIcons[item.interaction_type] || "ri-cursor-line",
            colorClass: interactionColors[item.interaction_type] || "text-muted",
        }))
        .sort((a, b) => b.total - a.total);

    const totalInteractions = normalizedInteractions.reduce(
        (sum, item) => sum + item.total,
        0
    );
    // END INTERACTIONS BREAKDOWN

    // INIT INSIGHT AUTOMÁTICO
    const generateInsight = (metrics: any) => {
        if (!metrics || metrics.views === 0) {
            return {
                title: "Sem dados suficientes",
                text: "Este carro ainda não gerou tráfego suficiente para análise.",
                recommendation: "Reforce a divulgação do anúncio para começar a recolher dados úteis de mercado.",
                icon: "ri-search-eye-line",
                color: "text-muted",
                boxClass: "bg-light"
            };
        }

        if (metrics.views > 0 && metrics.interactions === 0) {
            return {
                title: "Tráfego sem intenção",
                text: "O carro está a receber visualizações, mas ainda não gerou interações de contacto.",
                recommendation: "Reveja preço, fotos e descrição. Se continuar sem interações, reforce a distribuição do anúncio.",
                icon: "ri-eye-line",
                color: "text-warning",
                boxClass: "bg-warning-subtle"
            };
        }

        if (metrics.interactions > 0 && metrics.leads === 0) {
            return {
                title: "Interesse do mercado",
                text: "Este carro está a gerar interesse com ações de contacto, mas ainda sem leads registadas.",
                recommendation: "Acompanhe os contactos via WhatsApp e chamada. Este carro já demonstra intenção comercial.",
                icon: "ri-cursor-line",
                color: "text-info",
                boxClass: "bg-info-subtle"
            };
        }

        if (metrics.leads > 0) {
            return {
                title: "Conversão registada",
                text: "Este carro já está a converter visitas em leads.",
                recommendation: "Mantenha a promoção ativa e acompanhe rapidamente os contactos para maximizar conversão.",
                icon: "ri-checkbox-circle-line",
                color: "text-success",
                boxClass: "bg-success-subtle"
            };
        }

        return {
            title: "Análise em curso",
            text: "Os dados deste carro estão a ser analisados.",
            recommendation: "Continue a acompanhar a evolução do anúncio.",
            icon: "ri-line-chart-line",
            color: "text-muted",
            boxClass: "bg-light"
        };
    };

    const insight = generateInsight(carAnalytics.metrics);
    // END INSIGHT AUTOMÁTICO

    // INIT TIMELINE
    const formatTimelineDate = (date: any) => {
        return new Date(date).toLocaleDateString("pt-PT", {
            day: "2-digit",
            month: "short",
            year: "numeric",
        });
    };

    const formatTimelineTime = (date: any) => {
        return new Date(date).toLocaleTimeString("pt-PT", {
            hour: "2-digit",
            minute: "2-digit",
        });
    };

    const getTimelineDescription = (item: any) => {
        if (item.type === "car_created") {
            return "O anúncio deste carro foi publicado na plataforma.";
        }

        if (item.type === "view_group") {
            const parts = [];

            if (item.count) {
                parts.push(
                    item.count === 1
                        ? "1 visualização registada"
                        : `${item.count} visualizações registadas`
                );
            }

            if (item.unique_visitors) {
                parts.push(
                    item.unique_visitors === 1
                        ? "1 visitante único"
                        : `${item.unique_visitors} visitantes únicos`
                );
            }

            return parts.length > 0
                ? parts.join(" • ")
                : "Foram registadas visualizações deste anúncio.";
        }

        if (item.type === "interaction") {
            return "Foi registada uma ação de intenção neste anúncio.";
        }

        if (item.type === "lead") {
            return "Foi gerada uma lead associada a este carro.";
        }

        return "Atividade registada neste anúncio.";
    };

    const getTimelineBadge = (item: any) => {
        if (item.type === "view_group" && item.count) {
            return item.count;
        }

        return null;
    };

    const getTimelineBadgeClass = (item: any) => {
        if (item.type === "view_group") return "bg-primary-subtle text-primary";
        if (item.type === "interaction") return `bg-${item.color}-subtle text-${item.color}`;
        if (item.type === "lead") return "bg-warning-subtle text-warning";
        if (item.type === "car_created") return "bg-success-subtle text-success";

        return "bg-light text-muted";
    };
    // END TIMELINE

    return (
        <div className="page-content">
            <Container fluid>
                <Row>
                    <Col lg={12}>
                        <Card>
                            <CardBody>
                                <Row className="gx-lg-5">
                                    <Col xl={12}>
                                        <div className="mt-xl-0 mt-5">
                                            <div className="d-flex">
                                                <div className="flex-grow-1">
                                                    <h4>{carAnalytics.car.brand.name} {carAnalytics.car.model.name}</h4>
                                                    <div className="hstack gap-3 flex-wrap">
                                                        <div className="text-muted">
                                                            Preço:{" "}
                                                            <span className="text-dark fw-medium">
                                                                €{new Intl.NumberFormat("pt-PT", {
                                                                    minimumFractionDigits: 2,
                                                                    maximumFractionDigits: 2
                                                                }).format(carAnalytics.car.price)}
                                                            </span>
                                                        </div>
                                                        <div className="vr"></div>
                                                        <div className="text-muted">
                                                            Publicado em:{" "}
                                                            <span className="text-dark fw-medium">
                                                                {new Date(carAnalytics.car.created_at).toLocaleDateString("pt-PT", {
                                                                    day: "2-digit",
                                                                    month: "2-digit",
                                                                    year: "numeric",
                                                                    hour: "2-digit",
                                                                    minute: "2-digit",
                                                                })}
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="flex-shrink-0">
                                                    <div>
                                                        <Link to={`/cars/${carAnalytics.car.id}`} className="btn btn-light">
                                                            <i className="ri-pencil-fill align-bottom"></i>
                                                        </Link>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </Col>
                                    <Col xl={12}>
                                        <Row className="row-cols-md-3 row-cols-1">
                                            {kpiItems.map((item, key) => (
                                                <Col
                                                    className={item.id === 6 ? "col-lg" : "col-lg border-end"}
                                                    key={key}
                                                >
                                                    <div className="mt-3 mt-md-0 py-4 px-3">
                                                        <h5 className="text-muted text-uppercase fs-13">
                                                            {item.label}
                                                            {item.badge ? (
                                                                <i className={"fs-18 float-end align-middle " + item.badge}></i>
                                                            ) : null}
                                                        </h5>
                                                        <div className="d-flex align-items-center">
                                                            <div className="flex-shrink-0">
                                                                <i className={"display-6 " + item.icon + " " + item.iconClass}></i>
                                                            </div>
                                                            <div className="flex-grow-1 ms-3">
                                                                <h2 className={"mb-0 " + item.valueClass}>
                                                                    <span className="counter-value">
                                                                        <CountUp
                                                                            start={0}
                                                                            prefix={item.prefix}
                                                                            suffix={item.suffix}
                                                                            separator={item.separator}
                                                                            end={item.counter}
                                                                            decimals={item.decimals}
                                                                            duration={1}
                                                                        />
                                                                    </span>
                                                                </h2>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </Col>
                                            ))}
                                        </Row>
                                    </Col>
                                </Row>
                            </CardBody>
                        </Card>
                    </Col>
                    <Col xl={6} xxl={3}>
                        <Card className="card-height-100">
                            <CardHeader className="align-items-center d-flex">
                                <h4 className="card-title mb-0 flex-grow-1">
                                    Distribuição do Tráfego
                                </h4>
                            </CardHeader>

                            <CardBody>
                                {normalizedTrafficSources.length === 0 ? (
                                    <div className="d-flex flex-column justify-content-center align-items-center text-center" style={{ minHeight: "333px" }}>
                                        <i className="ri-pie-chart-line fs-1 text-muted mb-3"></i>
                                        <h5 className="mb-2">Ainda sem dados de tráfego</h5>
                                        <p className="text-muted mb-0">
                                            Quando este carro começar a receber visitas, as origens de tráfego vão aparecer aqui.
                                        </p>
                                    </div>
                                ) : (
                                    <>
                                        <ReactApexChart
                                            dir="ltr"
                                            options={options}
                                            series={series}
                                            type="donut"
                                            height={333}
                                            className="apex-charts"
                                        />

                                        <div className="mt-4 pt-2 border-top">
                                            {normalizedTrafficSources.map((item: any, index: any) => {
                                                const percent = totalTraffic > 0
                                                    ? ((item.total / totalTraffic) * 100).toFixed(1)
                                                    : "0.0";

                                                return (
                                                    <div
                                                        key={`${item.key}-${index}`}
                                                        className="d-flex align-items-center justify-content-between mb-3"
                                                    >
                                                        <div className="d-flex align-items-center">
                                                            <span
                                                                className="rounded-circle me-2"
                                                                style={{
                                                                    width: "10px",
                                                                    height: "10px",
                                                                    backgroundColor: item.color,
                                                                    display: "inline-block",
                                                                }}
                                                            ></span>

                                                            <span className="text-muted">
                                                                {item.label}
                                                            </span>
                                                        </div>

                                                        <div className="text-end">
                                                            <span className="fw-semibold">{item.total}</span>
                                                            <span className="text-muted ms-2">({percent}%)</span>
                                                        </div>
                                                    </div>
                                                );
                                            })}
                                        </div>

                                        <div className="mt-3 p-3 bg-light rounded">
                                            <h6 className="mb-2">Leitura rápida</h6>
                                            <p className="text-muted mb-0">
                                                {normalizedTrafficSources[0]?.key === "paid" &&
                                                    "A maior parte do tráfego deste carro está a vir de campanhas pagas, o que indica boa distribuição através de anúncios."}

                                                {normalizedTrafficSources[0]?.key === "direct" &&
                                                    "A maior parte do tráfego deste carro está a chegar de forma direta, o que pode indicar retorno de visitantes ou partilhas do anúncio."}

                                                {normalizedTrafficSources[0]?.key === "organic_social" &&
                                                    "O tráfego deste carro está a ser impulsionado sobretudo por redes sociais orgânicas, um bom sinal de alcance natural."}

                                                {!["paid", "direct", "organic_social"].includes(normalizedTrafficSources[0]?.key) &&
                                                    "Este carro já está a receber tráfego de diferentes origens, o que ajuda a avaliar a qualidade da distribuição."}
                                            </p>
                                        </div>
                                    </>
                                )}
                            </CardBody>
                        </Card>
                    </Col>
                    <Col xl={6} xxl={3}>
                        <Card className="card-height-100">
                            <CardHeader className="align-items-center d-flex">
                                <h4 className="card-title mb-0 flex-grow-1">
                                    Interações
                                </h4>
                            </CardHeader>

                            <CardBody>
                                {normalizedInteractions.length === 0 ? (
                                    <div
                                        className="d-flex flex-column justify-content-center align-items-center text-center"
                                        style={{ minHeight: "333px" }}
                                    >
                                        <i className="ri-cursor-line fs-1 text-muted mb-3"></i>
                                        <h5 className="mb-2">Ainda sem interações</h5>
                                        <p className="text-muted mb-0">
                                            Este carro já pode estar a receber visitas, mas ainda não gerou ações de intenção como WhatsApp ou fazer chamada.
                                        </p>
                                    </div>
                                ) : (
                                    <>
                                        <div className="mb-4">
                                            <div className="d-flex align-items-center justify-content-between">
                                                <span className="text-muted">Total de interações</span>
                                                <span className="fw-semibold fs-5">{totalInteractions}</span>
                                            </div>
                                        </div>

                                        <div className="vstack gap-3">
                                            {normalizedInteractions.map((item, index) => {
                                                const percent = totalInteractions > 0
                                                    ? ((item.total / totalInteractions) * 100).toFixed(1)
                                                    : "0.0";

                                                return (
                                                    <div key={`${item.key}-${index}`}>
                                                        <div className="d-flex align-items-center justify-content-between mb-2">
                                                            <div className="d-flex align-items-center">
                                                                <i className={`${item.icon} ${item.colorClass} fs-18 me-2`}></i>
                                                                <span>{item.label}</span>
                                                            </div>

                                                            <div className="text-end">
                                                                <span className="fw-semibold">{item.total}</span>
                                                                <span className="text-muted ms-2">({percent}%)</span>
                                                            </div>
                                                        </div>

                                                        <div className="live-preview">
                                                            <Progress color="primary" value={percent}> {Number(percent)}% </Progress>
                                                        </div>
                                                        {/* <div className="progress progress-sm"> */}
                                                        {/* <div
                                                                className="progress-bar"
                                                                role="progressbar"
                                                                style={{ width: `${percent}%` }}
                                                                aria-valuenow={Number(percent)}
                                                                aria-valuemin="0"
                                                                aria-valuemax="100"
                                                            /> */}
                                                        {/* </div> */}
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </>
                                )}
                            </CardBody>
                        </Card>
                    </Col>
                    <Col xl={6} xxl={3}>
                        <Card className="card-height-100">
                            <CardHeader className="align-items-center d-flex">
                                <h4 className="card-title mb-0 flex-grow-1">
                                    Insight Automático
                                </h4>
                            </CardHeader>

                            <CardBody>
                                <div className="d-flex flex-column justify-content-between" style={{ minHeight: "333px" }}>
                                    <div className="text-center">
                                        <div className="mb-3">
                                            <i className={`${insight.icon} ${insight.color}`} style={{ fontSize: "48px" }}></i>
                                        </div>

                                        <h5 className="mb-2">
                                            {insight.title}
                                        </h5>

                                        <p className="text-muted mb-4">
                                            {insight.text}
                                        </p>
                                    </div>

                                    <div className={`${insight.boxClass} p-3 rounded mb-3`}>
                                        <h6 className="mb-2">Recomendação automática</h6>
                                        <p className="mb-0 text-muted">
                                            {insight.recommendation}
                                        </p>
                                    </div>

                                    <div className="bg-light p-3 rounded">
                                        <h6 className="mb-2">Resumo rápido</h6>

                                        <div className="small text-muted">
                                            <div className="d-flex justify-content-between">
                                                <span>Views</span>
                                                <span>{carAnalytics.metrics.views}</span>
                                            </div>

                                            <div className="d-flex justify-content-between">
                                                <span>Interações</span>
                                                <span>{carAnalytics.metrics.interactions}</span>
                                            </div>

                                            <div className="d-flex justify-content-between">
                                                <span>Leads</span>
                                                <span>{carAnalytics.metrics.leads}</span>
                                            </div>

                                            <div className="d-flex justify-content-between">
                                                <span>Taxa de Interesse</span>
                                                <span>{carAnalytics.metrics.interest_rate}%</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </CardBody>
                        </Card>
                    </Col>
                    <Col xl={6} xxl={3}>
                        <Card>
                            <CardHeader>
                                <h4 className="card-title mb-0">Timeline do Carro</h4>
                            </CardHeader>

                            <CardBody style={{ maxHeight: "630px", overflowY: "auto" }}>
                                {!carAnalytics?.timeline || carAnalytics.timeline.length === 0 ? (
                                    <div className="text-center py-5">
                                        <i className="ri-time-line fs-1 text-muted mb-3 d-block"></i>
                                        <h5 className="mb-2">Ainda sem histórico</h5>
                                        <p className="text-muted mb-0">
                                            Quando este carro começar a receber atividade, o histórico cronológico vai aparecer aqui.
                                        </p>
                                    </div>
                                ) : (
                                    <div className="timeline-2">
                                        <div className="timeline-continue">
                                            {carAnalytics.timeline.map((item: any, index: number) => {
                                                const badge = getTimelineBadge(item);

                                                return (
                                                    <Row className="timeline-right" key={index}>
                                                        <Col xs={12}>
                                                            <p className="timeline-date">
                                                                {formatTimelineDate(item.created_at)} às {formatTimelineTime(item.created_at)}
                                                            </p>
                                                        </Col>

                                                        <Col xs={12}>
                                                            <div className="timeline-box">
                                                                <div className="timeline-text">
                                                                    <div className="d-flex align-items-start">
                                                                        <div className="flex-shrink-0 avatar-sm">
                                                                            <div className={`avatar-title rounded-circle bg-${item.color}-subtle text-${item.color}`}>
                                                                                <i className={`${item.icon} fs-18`}></i>
                                                                            </div>
                                                                        </div>

                                                                        <div className="flex-grow-1 ms-3">
                                                                            <div className="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">
                                                                                <h5 className="fs-15 mb-0">
                                                                                    {item.label}
                                                                                </h5>

                                                                                {badge !== null && (
                                                                                    <span className={`badge ${getTimelineBadgeClass(item)}`}>
                                                                                        {badge}
                                                                                    </span>
                                                                                )}
                                                                            </div>

                                                                            <p className="text-muted mb-0">
                                                                                {getTimelineDescription(item)}
                                                                            </p>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </Col>
                                                    </Row>
                                                );
                                            })}
                                        </div>
                                    </div>
                                )}
                            </CardBody>
                        </Card>
                    </Col>
                </Row>
            </Container>
        </div>
    );
}