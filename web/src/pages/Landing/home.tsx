import React from 'react';
import { Col, Container, Row } from 'reactstrap';
import { Link } from 'react-router-dom';

// Import Swiper styles
import "swiper/css";
import "swiper/css/effect-fade";
import "swiper/css/pagination";

import { EffectFade, Autoplay } from "swiper/modules";
import { Swiper, SwiperSlide } from "swiper/react";


// Import Images
import imgpattern from "../../assets/images/landing/img-pattern.png";

import defaultDemo from "../../assets/images/demos/default.png";
import defaultDemoTwo from "../../assets/images/demos/default-2.png";
import cars from "../../assets/images/demos/cars.png";
import carsMetrics from "../../assets/images/demos/cars-metrics.png";
import carsAi from "../../assets/images/demos/cars-ai.png";
import carsAiTwo from "../../assets/images/demos/cars-ai-2.png";


const Home = () => {

    const whatsappNumber = "351938963526";
    const whatsappMessage = encodeURIComponent(
        "Olá! Vi a Xplendor e gostava de saber como pode ajudar o meu stand."
    );
    const whatsappUrl = `https://wa.me/${whatsappNumber}?text=${whatsappMessage}`;

    return (
        <React.Fragment>
            <section className="section pb-0 hero-section" id="hero">
                <div className="bg-overlay bg-overlay-pattern"></div>
                <Container>
                    <Row className="justify-content-center">
                        <Col lg={8} sm={10}>
                            <div className="text-center mt-lg-5 pt-5">
                                {/* Pill de credibilidade */}
                                <div className="d-inline-flex align-items-center gap-2 badge bg-primary-subtle text-primary rounded-pill px-3 py-2 mb-3 fs-13">
                                    <span className="bg-primary rounded-circle" style={{ width: 8, height: 8, display: "inline-block" }}></span>
                                    Plataforma utilizada por stands em Portugal
                                </div>

                                <h1 className="display-6 fw-bold mb-3 lh-base">
                                    Sabe exactamente{" "}
                                    <span className="text-primary">qual carro vai vender</span>{" "}
                                    esta semana
                                </h1>
                                <p className="lead text-muted lh-base">
                                    A Xplendor analisa o comportamento dos compradores no teu site
                                    e diz-te quais carros têm procura real, quais estão parados
                                    e onde estás a perder leads — tudo automático.
                                </p>

                                {/* Três micro-provas */}
                                <div className="d-flex justify-content-center gap-4 mt-3 mb-4 flex-wrap">
                                    {[
                                        { icon: "ri-eye-line", text: "Views por carro em tempo real" },
                                        { icon: "ri-award-line", text: "Índice de Potencial de Venda" },
                                        { icon: "ri-whatsapp-line", text: "Leads por canal identificadas" },
                                    ].map((item, idx) => (
                                        <div key={idx} className="d-flex align-items-center gap-2 text-muted fs-13">
                                            <i className={`${item.icon} text-primary fs-16`}></i>
                                            {item.text}
                                        </div>
                                    ))}
                                </div>

                                {/* CTAs */}
                                <div className="d-flex gap-2 justify-content-center mt-4 flex-wrap">
                                    <a
                                        href={whatsappUrl}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="btn btn-success btn-lg"
                                    >
                                        <i className="ri-whatsapp-line me-2 fs-16"></i>
                                        Falar connosco no WhatsApp
                                    </a>
                                    <a href="#platform" className="btn btn-outline-primary btn-lg">
                                        Ver como funciona
                                        <i className="ri-arrow-down-line ms-2"></i>
                                    </a>
                                </div>

                                <p className="text-muted mt-2" style={{ fontSize: 12 }}>
                                    Sem compromisso · Respondemos em minutos
                                </p>
                            </div>

                            <div className='mt-4 mt-sm-5 pt-sm-5 mb-sm-n5 demo-carousel'>
                                <div className="demo-img-patten-top d-none d-sm-block">
                                    <img src={imgpattern} className="d-block img-fluid" alt="..." />
                                </div>
                                <div className="demo-img-patten-bottom d-none d-sm-block">
                                    <img src={imgpattern} className="d-block img-fluid" alt="..." />
                                </div>
                                <Swiper
                                    spaceBetween={30}
                                    effect={"fade"}
                                    loop={true}
                                    pagination={{
                                        clickable: true,
                                    }}
                                    autoplay={{ delay: 2000, disableOnInteraction: false }}
                                    modules={[EffectFade, Autoplay]}
                                    className="mySwiper" >

                                    <SwiperSlide className="carousel-inner shadow-lg p-2 bg-white rounded">
                                        <img src={defaultDemo} className="d-block w-100" alt="..." />
                                    </SwiperSlide>
                                    <SwiperSlide className="carousel-inner shadow-lg p-2 bg-white rounded">
                                        <img src={defaultDemoTwo} className="d-block w-100" alt="..." />
                                    </SwiperSlide>
                                    <SwiperSlide className="carousel-inner shadow-lg p-2 bg-white rounded">
                                        <img src={cars} className="d-block w-100" alt="..." />
                                    </SwiperSlide>
                                    <SwiperSlide className="carousel-inner shadow-lg p-2 bg-white rounded">
                                        <img src={carsMetrics} className="d-block w-100" alt="..." />
                                    </SwiperSlide>
                                    <SwiperSlide className="carousel-inner shadow-lg p-2 bg-white rounded">
                                        <img src={carsAi} className="d-block w-100" alt="..." />
                                    </SwiperSlide>
                                    <SwiperSlide className="carousel-inner shadow-lg p-2 bg-white rounded">
                                        <img src={carsAiTwo} className="d-block w-100" alt="..." />
                                    </SwiperSlide>
                                </Swiper>
                            </div>
                        </Col>
                    </Row>
                </Container>

                <div className="position-absolute start-0 end-0 bottom-0 hero-shape-svg">
                    <svg xmlns="http://www.w3.org/2000/svg" version="1.1"
                        // xmlns:xlink="http://www.w3.org/1999/xlink"
                        viewBox="0 0 1440 120">
                        <g mask="url(&quot;#SvgjsMask1003&quot;)" fill="none">
                            <path d="M 0,118 C 288,98.6 1152,40.4 1440,21L1440 140L0 140z">
                            </path>
                        </g>
                    </svg>
                </div>

            </section>
        </React.Fragment>
    );
};

export default Home;