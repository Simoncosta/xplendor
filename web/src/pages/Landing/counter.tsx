import React from 'react';
import { Col, Container, Row } from 'reactstrap';
import CountUp from "react-countup";

const Counter = () => {
    return (
        <React.Fragment>
            <section className="py-5 position-relative bg-light">
                <Container>
                    <Row className="text-center gy-4">

                        <Col lg={3} className="col-6">
                            <div>
                                <h2 className="mb-2">
                                    <span className="counter-value">
                                        <CountUp start={0} end={2} duration={3} />
                                    </span>
                                    +
                                </h2>
                                <div className="text-muted">Stands piloto</div>
                            </div>
                        </Col>

                        <Col lg={3} className="col-6">
                            <div>
                                <h2 className="mb-2">
                                    <span className="counter-value">
                                        <CountUp start={0} end={150} duration={3} />
                                    </span>
                                    +
                                </h2>
                                <div className="text-muted">Viaturas geridas</div>
                            </div>
                        </Col>

                        <Col lg={3} className="col-6">
                            <div>
                                <h2 className="mb-2">
                                    <span className="counter-value">
                                        <CountUp start={0} end={5000} duration={3} />
                                    </span>
                                    +
                                </h2>
                                <div className="text-muted">Visualizações de carros</div>
                            </div>
                        </Col>

                        <Col lg={3} className="col-6">
                            <div>
                                <h2 className="mb-2">
                                    <span className="counter-value">
                                        <CountUp start={0} end={120} duration={3} />
                                    </span>
                                    +
                                </h2>
                                <div className="text-muted">Leads gerados</div>
                            </div>
                        </Col>

                    </Row>
                </Container>
            </section>
        </React.Fragment>
    );
};

export default Counter;