import React from 'react';
import { Link } from 'react-router-dom';
import { Col, Container, Row } from 'reactstrap';
import { motion, useReducedMotion } from 'framer-motion';
import CTAButton from '../components/CTAButton';
import ShotPlaceholder from '../components/ShotPlaceholder';
import { CTA_WHATSAPP_URL } from '../data/constants';

/**
 * HERÓI — frase grande e direta + subtítulo curto + 2 CTAs. Entrada animada
 * subtil (stagger). O visual à direita mostra já o produto (placeholder de
 * captura real do dashboard), numa moldura de browser.
 */
const HeroNew: React.FC = () => {
    const reduce = useReducedMotion();

    const item = reduce
        ? {}
        : {
              initial: { opacity: 0, y: 24 },
              animate: { opacity: 1, y: 0 },
          };

    return (
        <section id="hero" className="lp-hero2">
            <div className="lp-hero2-glow" aria-hidden="true" />
            <Container>
                <Row className="align-items-center g-5">
                    <Col lg={6}>
                        <motion.span
                            className="lp-hero2-eyebrow"
                            {...item}
                            transition={{ duration: 0.5, ease: [0.22, 1, 0.36, 1] }}
                        >
                            Agência + plataforma para stands automóveis
                        </motion.span>

                        <motion.h1
                            className="lp-hero2-title"
                            {...item}
                            transition={{ duration: 0.6, delay: 0.08, ease: [0.22, 1, 0.36, 1] }}
                        >
                            Venda mais viaturas com marketing e uma plataforma
                            feitos <span className="lp-hero2-hl">para o seu stand</span>.
                        </motion.h1>

                        <motion.p
                            className="lp-hero2-sub"
                            {...item}
                            transition={{ duration: 0.6, delay: 0.16, ease: [0.22, 1, 0.36, 1] }}
                        >
                            Tratamos das campanhas, das redes sociais e do site. E dá-mos-lhe a
                            plataforma XPLENDOR para gerir stock, leads e vendas — tudo num sítio,
                            com números a sério.
                        </motion.p>

                        <motion.div
                            className="lp-hero2-cta"
                            {...item}
                            transition={{ duration: 0.6, delay: 0.24, ease: [0.22, 1, 0.36, 1] }}
                        >
                            <CTAButton href={CTA_WHATSAPP_URL}>
                                <i className="ri-whatsapp-line" aria-hidden="true" />
                                Falar connosco
                            </CTAButton>
                            <Link to="/login" className="lp-cta-btn lp-cta-btn-outline">
                                <i className="ri-play-circle-line" aria-hidden="true" />
                                Ver a plataforma
                            </Link>
                        </motion.div>
                    </Col>

                    <Col lg={6}>
                        <motion.div
                            initial={reduce ? undefined : { opacity: 0, y: 30, scale: 0.98 }}
                            animate={reduce ? undefined : { opacity: 1, y: 0, scale: 1 }}
                            transition={{ duration: 0.8, delay: 0.2, ease: [0.22, 1, 0.36, 1] }}
                        >
                            <ShotPlaceholder
                                label="Dashboard da plataforma (visão geral de stock, leads e performance)"
                                src={(process.env.PUBLIC_URL || '') + '/xplendor-dashboard.png'}
                                alt="Dashboard da plataforma XPLENDOR — visão geral de stock, leads e performance"
                                ratio="16 / 10"
                            />
                        </motion.div>
                    </Col>
                </Row>
            </Container>
        </section>
    );
};

export default HeroNew;
