import React from 'react';
import { Col, Row } from 'reactstrap';
import Section from '../../Landing/components/Section';
import { Differentiator } from '../../Landing/data/differentiators';

// Diferenciadores específicos do nicho de autocaravanas — reforçam o que os
// marketplaces não oferecem (filtros de habitação, análise de mercado própria).
const DIFFERENTIATORS_MOTORHOMES: Differentiator[] = [
    {
        icon: 'ri-filter-3-line',
        title: 'Filtros de habitação',
        description:
            'Camas, comprimento, casa de banho, cozinha, autonomia — o comprador filtra pelo que importa numa autocaravana. Os marketplaces não têm isto.',
    },
    {
        icon: 'ri-bar-chart-2-line',
        title: 'Análise de mercado de autocaravanas',
        description:
            'Monitorizamos diariamente autocaravanas comparáveis no mercado português. Cada viatura do seu stock sabe se está abaixo, dentro ou acima do mercado.',
    },
    {
        icon: 'ri-home-gear-line',
        title: 'Fichas que mostram a célula',
        description:
            'Interior, equipamento de habitação, camas e layout bem apresentados — o que faz uma autocaravana valer, em vez de ficar de fora.',
    },
    {
        icon: 'ri-handshake-line',
        title: 'Sem letra pequena',
        description:
            'Compromisso semestral com pré-aviso de 30 dias para sair. Sem renovações automáticas anuais nem cláusulas surpresa.',
    },
];

const DifferentiatorsMotorhomes: React.FC = () => (
    <Section variant="accent">
        <div className="text-center mb-5">
            <p className="lp-label">Porquê a XPLENDOR</p>
            <h2 className="lp-section-title">
                O que nos distingue de uma agência genérica
            </h2>
        </div>

        <Row className="g-4">
            {DIFFERENTIATORS_MOTORHOMES.map((d, i) => (
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

export default DifferentiatorsMotorhomes;
