import React from 'react';
import { Col, Row } from 'reactstrap';
import Section from '../components/Section';

interface Step {
    number: string;
    title: string;
    description: string;
}

const STEPS: Step[] = [
    {
        number: '1',
        title: 'Ligamos o seu stock à nossa plataforma',
        description:
            'Importamos o catálogo de viaturas e configuramos o tracking de visitantes e leads por viatura, canal e campanha.',
    },
    {
        number: '2',
        title: 'Criamos site, criativos e campanhas',
        description:
            'Desenvolvemos ou optimizamos o site do stand e lançamos campanhas nos canais certos para o seu tipo de stock.',
    },
    {
        number: '3',
        title: 'Entregamos leads e relatórios mensais',
        description:
            'Todos os meses recebe os números: custo por lead, viaturas com melhor performance e o que vamos ajustar.',
    },
];

const HowItWorks: React.FC = () => (
    <Section id="como-funciona">
        <div className="text-center mb-5">
            <p className="lp-label">Como funciona</p>
            <h2 className="lp-section-title">
                3 passos para o seu stand gerar leads em vez de os comprar
            </h2>
        </div>

        <Row className="justify-content-center">
            <Col lg={7}>
                <ol className="lp-steps" aria-label="Passos do processo">
                    {STEPS.map((step) => (
                        <li key={step.number} className="lp-step">
                            <div className="lp-step-number" aria-hidden="true">
                                {step.number}
                            </div>
                            <div className="lp-step-content">
                                <h3>{step.title}</h3>
                                <p>{step.description}</p>
                            </div>
                        </li>
                    ))}
                </ol>
            </Col>
        </Row>
    </Section>
);

export default HowItWorks;
