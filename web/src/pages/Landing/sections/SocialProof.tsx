import React from 'react';
import Section from '../components/Section';

/*
 * Casos de sucesso — a activar quando tivermos resultados publicáveis.
 * Estrutura preparada abaixo em comentário; descomentar e preencher.
 */

const SocialProof: React.FC = () => (
    <Section>
        <div className="text-center mb-5">
            <p className="lp-label">Clientes</p>
            <h2 className="lp-section-title">Clientes que confiam em nós</h2>
        </div>

        <div className="d-flex justify-content-center gap-5 flex-wrap">
            <div className="lp-logo-placeholder" role="img" aria-label="Cliente XPLENDOR" />
            <div className="lp-logo-placeholder" role="img" aria-label="Cliente XPLENDOR" />
        </div>

        {/*
        <Row className="g-4 mt-5">
            <Col md={4}>
                <div className="lp-case-card">
                    <p className="lp-case-result">+142 leads em 90 dias</p>
                    <p className="lp-case-detail">Stand no Porto, stock misto. Facebook Ads + site próprio.</p>
                    <p className="lp-case-name">Stand X — Porto</p>
                </div>
            </Col>
            <Col md={4}>
                <div className="lp-case-card">
                    <p className="lp-case-result">Custo por lead: €8,40</p>
                    <p className="lp-case-detail">Stand de autocaravanas. Google Ads + criativos mensais.</p>
                    <p className="lp-case-name">Stand Y — Lisboa</p>
                </div>
            </Col>
            <Col md={4}>
                <div className="lp-case-card">
                    <p className="lp-case-result">-60% dependência dos marketplaces</p>
                    <p className="lp-case-detail">12 meses de trabalho. Site próprio com tracking por viatura.</p>
                    <p className="lp-case-name">Stand Z — Braga</p>
                </div>
            </Col>
        </Row>
        */}
    </Section>
);

export default SocialProof;
