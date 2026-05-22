import React from 'react';
import { Col, Row } from 'reactstrap';
import Section from '../components/Section';
import FAQItem from '../components/FAQItem';
import { FAQS } from '../data/faqs';

const FAQSection: React.FC = () => (
    <Section id="faq" variant="alt">
        <Row className="justify-content-center">
            <Col lg={8}>
                <div className="text-center mb-5">
                    <p className="lp-label">Perguntas frequentes</p>
                    <h2 className="lp-section-title">As dúvidas mais comuns</h2>
                </div>

                <div role="list" aria-label="Perguntas frequentes">
                    {FAQS.map((entry, i) => (
                        <div role="listitem" key={i}>
                            <FAQItem entry={entry} />
                        </div>
                    ))}
                </div>
            </Col>
        </Row>
    </Section>
);

export default FAQSection;
