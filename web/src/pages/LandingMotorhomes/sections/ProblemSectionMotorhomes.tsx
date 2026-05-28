import React from 'react';
import { Col, Row } from 'reactstrap';
import Section from '../../Landing/components/Section';

interface Problem {
    icon: string;
    text: string;
}

const PROBLEMS: Problem[] = [
    {
        icon: 'ri-filter-line',
        text: 'O seu site não deixa filtrar pelo que importa: número de camas, comprimento, casa de banho, equipamento de habitação. O comprador desiste.',
    },
    {
        icon: 'ri-image-line',
        text: 'As fichas não mostram o que faz uma autocaravana valer — interior, cozinha, camas, autonomia — ficam de fora ou mal apresentadas.',
    },
    {
        icon: 'ri-money-euro-circle-line',
        text: 'Dependência total dos marketplaces: paga ao Standvirtual, os leads não são seus, e compete com centenas de anúncios iguais.',
    },
];

const ProblemSectionMotorhomes: React.FC = () => (
    <Section id="problema" variant="alt">
        <div className="text-center mb-5">
            <p className="lp-label">O problema</p>
            <h2 className="lp-section-title">
                Porque é que o seu stand de autocaravanas não está a vender online
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

export default ProblemSectionMotorhomes;
