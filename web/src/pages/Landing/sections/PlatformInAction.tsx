import React from 'react';
import { Col, Container, Row } from 'reactstrap';
import { Reveal } from '../lib/motion';
import ShotPlaceholder from '../components/ShotPlaceholder';

interface Feature {
    kicker: string;
    title: string;
    text: string;
    points: string[];
    shot: string;
    src: string;
}

const asset = (name: string) => (process.env.PUBLIC_URL || '') + '/' + name;

const FEATURES: Feature[] = [
    {
        kicker: 'Dashboard',
        title: 'Todo o stand num ecrã',
        text: 'Stock, leads, campanhas e performance de cada viatura — em tempo real, sem folhas de cálculo.',
        points: ['Custo por lead por viatura', 'Estado do stock e dias parado', 'Sinais de mercado'],
        shot: 'Dashboard principal (stock + leads + performance)',
        src: asset('xplendor-stock.png'),
    },
    {
        kicker: 'Pós-venda',
        title: 'Um relatório de satisfação que impressiona',
        text: 'Depois da venda, o cliente recebe um relatório bonito e profissional — a sua marca a brilhar após a entrega.',
        points: ['Link público partilhável', 'Imagem de marca do stand', 'Recolha de avaliação'],
        shot: 'Relatório público de pós-venda / satisfação',
        src: asset('xplendor-pos-venda.png'),
    },
    {
        kicker: 'Documentos',
        title: 'Documentos de venda gerados em segundos',
        text: 'Modelos preenchidos com os dados da viatura e do cliente — sem copiar e colar, sem erros.',
        points: ['Modelos personalizáveis', 'Preenchimento automático', 'Pronto a imprimir'],
        shot: 'Geração de documentos de venda',
        src: asset('xplendor-documentos.png'),
    },
    {
        kicker: 'Gestão comercial',
        title: 'Leads e ações sem nada cair no esquecimento',
        text: 'Cada lead com origem e histórico; alertas do que precisa de decisão hoje. O comercial sabe sempre o próximo passo.',
        points: ['Origem de cada lead', 'Centro de ações e alertas', 'Candidatas a promoção'],
        shot: 'Gestão de leads e centro de ações',
        src: asset('xplendor-leads.png'),
    },
];

/**
 * A PLATAFORMA EM AÇÃO — o coração: MOSTRA o produto. Linhas alternadas
 * (imagem ↔ texto) com placeholders claramente marcados para as capturas reais.
 */
const PlatformInAction: React.FC = () => (
    <section id="plataforma" className="lp-section2 lp-section2-alt">
        <Container>
            <Reveal className="lp-section2-head">
                <span className="lp-kicker">A plataforma em ação</span>
                <h2 className="lp-h2">Veja o produto a trabalhar</h2>
                <p className="lp-lead">
                    Não é uma folha de cálculo com outra cara. É software pensado para stands —
                    do primeiro clique do anúncio até ao pós-venda.
                </p>
            </Reveal>

            <div className="lp-feature-rows">
                {FEATURES.map((f, i) => {
                    const imageFirst = i % 2 === 1; // alterna o ritmo
                    const media = (
                        <Col lg={6}>
                            <Reveal delay={0.05}>
                                <ShotPlaceholder label={f.shot} src={f.src} alt={f.shot} ratio="16 / 10" />
                            </Reveal>
                        </Col>
                    );
                    const copy = (
                        <Col lg={6}>
                            <Reveal className="lp-feature-copy">
                                <span className="lp-kicker">{f.kicker}</span>
                                <h3 className="lp-h3">{f.title}</h3>
                                <p className="lp-feature-text">{f.text}</p>
                                <ul className="lp-feature-points">
                                    {f.points.map((p) => (
                                        <li key={p}>
                                            <i className="ri-checkbox-circle-fill" aria-hidden="true" />
                                            {p}
                                        </li>
                                    ))}
                                </ul>
                            </Reveal>
                        </Col>
                    );

                    return (
                        <Row key={f.title} className="align-items-center g-4 g-lg-5 lp-feature-row">
                            {imageFirst ? (<>{media}{copy}</>) : (<>{copy}{media}</>)}
                        </Row>
                    );
                })}
            </div>
        </Container>
    </section>
);

export default PlatformInAction;
