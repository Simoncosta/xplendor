import React from 'react';
import { Col, Row } from 'reactstrap';
import Section from '../components/Section';

interface Problem {
    icon: string;
    text: string;
}

const PROBLEMS: Problem[] = [
    {
        icon: 'ri-money-euro-circle-line',
        text: 'Paga muito aos marketplaces e não sabe quanto cada lead lhe custa.',
    },
    {
        icon: 'ri-global-line',
        text: 'Tem site mas não traz visitas nem contactos.',
    },
    {
        icon: 'ri-time-line',
        text: 'Não tem tempo para gerir Facebook, Google, conteúdos e criativos.',
    },
];

const ProblemSection: React.FC = () => (
    <Section id="problema" variant="alt">
        <div className="text-center mb-5">
            <p className="lp-label">O problema</p>
            <h2 className="lp-section-title">
                Porque é que o marketing digital do seu stand não está a funcionar
            </h2>
        </div>

        <Row className="g-4 justify-content-center">
            {PROBLEMS.map((problem, i) => (
                <Col md={4} key={i}>
                    <div className="lp-problem-card">
                        <div className="lp-problem-icon" aria-hidden="true">
                            <i className={problem.icon} />
                        </div>
                        <p>{problem.text}</p>
                    </div>
                </Col>
            ))}
        </Row>
    </Section>
);

export default ProblemSection;
