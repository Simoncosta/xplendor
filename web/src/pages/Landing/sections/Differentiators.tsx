import React from 'react';
import { Col, Row } from 'reactstrap';
import Section from '../components/Section';
import { DIFFERENTIATORS } from '../data/differentiators';

const Differentiators: React.FC = () => (
    <Section variant="accent">
        <div className="text-center mb-5">
            <p className="lp-label">Porquê a XPLENDOR</p>
            <h2 className="lp-section-title">
                O que nos distingue de uma agência genérica
            </h2>
        </div>

        <Row className="g-4">
            {DIFFERENTIATORS.map((d, i) => (
                <Col md={6} lg={3} key={i}>
                    <div className="lp-diff-card">
                        <div className="lp-diff-icon" aria-hidden="true">
                            <i className={d.icon} />
                        </div>
                        <h3>{d.title}</h3>
                        <p>{d.description}</p>
                    </div>
                </Col>
            ))}
        </Row>
    </Section>
);

export default Differentiators;
