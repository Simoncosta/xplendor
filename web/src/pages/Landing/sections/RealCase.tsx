import React from 'react';
import { Col, Container, Row } from 'reactstrap';
import { Reveal } from '../lib/motion';
import ShotPlaceholder from '../components/ShotPlaceholder';

/**
 * CASO REAL — demonstração HONESTA. O stand do Paulo (PA Automóveis) é um stand
 * real a usar a plataforma no dia-a-dia. Apresentado como "o produto a funcionar
 * num stand real" — NÃO como testemunho de cliente pagante (o Paulo é sócio/stand
 * informal). Sem aspas de recomendação, sem números de impacto inventados.
 */
const RealCase: React.FC = () => (
    <section id="caso-real" className="lp-section2">
        <Container>
            <Reveal className="lp-section2-head">
                <span className="lp-kicker">Caso real</span>
                <h2 className="lp-h2">A plataforma a funcionar num stand a sério</h2>
                <p className="lp-lead">
                    Isto não é uma demo montada. É a XPLENDOR em uso real no dia-a-dia da
                    PA Automóveis — o stock verdadeiro, os leads verdadeiros, o fluxo verdadeiro.
                </p>
            </Reveal>

            <Row className="align-items-center g-4 g-lg-5">
                <Col lg={7}>
                    <Reveal>
                        <ShotPlaceholder
                            label="Ecrã real da PA Automóveis na plataforma (stock/leads reais)"
                            src={(process.env.PUBLIC_URL || '') + '/xplendor-ficha.png'}
                            alt="Ecrã real da PA Automóveis na plataforma XPLENDOR"
                            ratio="16 / 10"
                        />
                    </Reveal>
                </Col>
                <Col lg={5}>
                    <Reveal className="lp-realcase-copy">
                        <div className="lp-realcase-badge">
                            <i className="ri-store-2-line" aria-hidden="true" />
                            PA Automóveis
                        </div>
                        <p className="lp-realcase-text">
                            Usamos a plataforma com um stand real para a construir com os pés
                            assentes na terra — cada funcionalidade nasce de uma necessidade
                            concreta do negócio, não de uma suposição.
                        </p>
                        <p className="lp-realcase-note">
                            <i className="ri-information-line" aria-hidden="true" />
                            Mostramos o produto em uso, não uma prova social que ainda não temos.
                            Quando houver stands clientes a partilhar resultados, é aqui que entram.
                        </p>
                    </Reveal>
                </Col>
            </Row>
        </Container>
    </section>
);

export default RealCase;
