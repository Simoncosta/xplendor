import React from "react";
import { Col } from "reactstrap";
import { Carousel } from "react-responsive-carousel";
import "react-responsive-carousel/lib/styles/carousel.min.css";
import { Link } from "react-router-dom";

// Import Images
import logoLight from "../../assets/images/logo-light.png";

const AuthSlider = () => {
    return (
        <React.Fragment>
            <Col lg={6}>
                <div className="p-lg-5 p-4 auth-one-bg h-100">
                    <div className="bg-overlay"></div>
                    <div className="position-relative h-100 d-flex flex-column">
                        <div className="mb-4">
                            <Link to="/dashboard" className="d-block">
                                <img src={logoLight} alt="" height="18" />
                            </Link>
                        </div>
                        <div className="mt-auto">
                            <div className="mb-3">
                                <i className="ri-double-quotes-l display-4 text-success"></i>
                            </div>

                            <Carousel showThumbs={false} autoPlay={true} showArrows={false} showStatus={false} infiniteLoop={true} className="slide"
                            // id="qoutescarouselIndicators"
                            >
                                <div className="carousel-inner text-center text-white pb-5">
                                    <div className="item">
                                        <p className="fs-15 fst-italic">
                                            " Desde que usamos a Xplendor, deixámos de depender de marketplaces.
                                            Agora controlamos o tráfego, as leads e as vendas diretamente no nosso site. "
                                        </p>
                                    </div>
                                </div>

                                <div className="carousel-inner text-center text-white pb-5">
                                    <div className="item">
                                        <p className="fs-15 fst-italic">
                                            " A Xplendor transformou o nosso stock num verdadeiro motor de vendas.
                                            Cada viatura passa a ter estratégia, conteúdo e campanhas próprias. "
                                        </p>
                                    </div>
                                </div>

                                <div className="carousel-inner text-center text-white pb-5">
                                    <div className="item">
                                        <p className="fs-15 fst-italic">
                                            " Pela primeira vez conseguimos medir exatamente quanto cada carro nos gera
                                            em visitas, leads e vendas. Isto muda completamente a forma de gerir o stock. "
                                        </p>
                                    </div>
                                </div>
                            </Carousel>

                        </div>
                    </div>
                </div>
            </Col>
        </React.Fragment >
    );
};

export default AuthSlider;