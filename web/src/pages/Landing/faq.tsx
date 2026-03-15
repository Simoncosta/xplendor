import React, { useState } from 'react';
import { Col, Container, Row, Collapse } from 'reactstrap';

const whatsappNumber = "351938963526";
const whatsappUrl = `https://wa.me/${whatsappNumber}?text=${encodeURIComponent("Olá! Tenho uma dúvida sobre a Xplendor.")}`;

const faqGroups = [
    {
        icon: "ri-question-line",
        title: "Plataforma",
        faqs: [
            {
                q: "O que é o Índice de Potencial de Venda (IPS)?",
                a: "O IPS é um score de 0 a 100 que calculamos automaticamente para cada carro com base no preço vs. mercado, views, taxa de engajamento, dias em stock e histórico de vendas do segmento. Um score alto significa que o carro tem condições para vender rápido. Um score baixo alerta para o que precisa de ser ajustado.",
            },
            {
                q: "Preciso de ter um site para usar a Xplendor?",
                a: "Sim — a Xplendor funciona como uma camada de inteligência por cima do site que já tens. Instalas uma linha de código (X-TAG) e a plataforma começa a registar todos os dados automaticamente. Compatível com WordPress, sites custom, Webflow ou qualquer outra plataforma.",
            },
            {
                q: "A Xplendor substitui o Standvirtual ou o OLX?",
                a: "Não substitui — complementa. O Standvirtual traz tráfego, mas os dados ficam deles. A Xplendor dá-te dados do teu próprio tráfego: quem visitou, por onde chegou, se clicou no WhatsApp, se preencheu formulário. Com o tempo, podes reduzir a dependência dos marketplaces porque entendes melhor onde estão os teus compradores.",
            },
            {
                q: "Quanto tempo demora a configurar?",
                a: "5 minutos para instalar a X-TAG. Em 24 horas já tens os primeiros dados de views e canais. O IPS começa a calcular automaticamente após a instalação. Não precisas de formação nem de consultores.",
            },
        ],
    },
    {
        icon: "ri-shield-keyhole-line",
        title: "Dados e Preços",
        faqs: [
            {
                q: "Os dados do meu stand são meus?",
                a: "Sim, sempre. Os dados de views, leads e interações do teu site pertencem-te. A Xplendor é apenas a plataforma que os agrega e analisa — nunca vendemos nem partilhamos dados com terceiros.",
            },
            {
                q: "Posso cancelar quando quiser?",
                a: "Sim. Sem contratos anuais forçados, sem penalizações. Podes cancelar a qualquer momento a partir das definições da conta. O primeiro mês é sempre gratuito para experimentares sem risco.",
            },
            {
                q: "O que muda entre os planos?",
                a: "O Starter tem tracking básico e gestão de leads. O Growth adiciona o IPS, Analytics IA por viatura e alertas automáticos — é o plano que recomendamos para stands com stock activo. O Pro adiciona integração directa com Meta Ads e Google Ads, automação de conteúdo e XPLDR Intelligence (consultor IA).",
            },
            {
                q: "Consigo ver de onde vêm os compradores?",
                a: "Sim — essa é uma das funcionalidades centrais. A X-TAG identifica se o visitante veio do Meta Ads, Google, pesquisa orgânica, direto ou referral. Vês isso por carro individual e agregado para o stand inteiro.",
            },
        ],
    },
];

export default function Faqs() {
    const [open, setOpen] = useState<string>("0-0");

    const toggle = (key: string) => setOpen(open === key ? "" : key);

    return (
        <React.Fragment>
            <section className="section bg-light" id="faqs">
                <Container>
                    <Row className="justify-content-center">
                        <Col lg={7}>
                            <div className="text-center mb-5">
                                <h2 className="fw-bold mb-3">Perguntas frequentes</h2>
                                <p className="text-muted fs-15">
                                    Não encontras o que procuras?{" "}
                                    <a href={whatsappUrl} target="_blank" rel="noreferrer" className="text-success fw-medium">
                                        Fala connosco no WhatsApp
                                    </a>
                                </p>
                            </div>
                        </Col>
                    </Row>

                    <Row className="g-lg-5 g-4">
                        {faqGroups.map((group, gIdx) => (
                            <Col lg={6} key={gIdx}>
                                <div className="d-flex align-items-center mb-3 gap-2">
                                    <i className={`${group.icon} fs-22 text-success`}></i>
                                    <h5 className="fw-bold mb-0">{group.title}</h5>
                                </div>
                                <div className="accordion custom-accordionwithicon custom-accordion-border accordion-border-box">
                                    {group.faqs.map((faq, fIdx) => {
                                        const key = `${gIdx}-${fIdx}`;
                                        return (
                                            <div className="accordion-item" key={fIdx}>
                                                <h2 className="accordion-header">
                                                    <button
                                                        className={`accordion-button fw-semibold fs-14 ${open !== key ? "collapsed" : ""}`}
                                                        type="button"
                                                        onClick={() => toggle(key)}
                                                        style={{ cursor: "pointer" }}
                                                    >
                                                        {faq.q}
                                                    </button>
                                                </h2>
                                                <Collapse isOpen={open === key} className="accordion-collapse">
                                                    <div className="accordion-body text-muted fs-14" style={{ lineHeight: 1.7 }}>
                                                        {faq.a}
                                                    </div>
                                                </Collapse>
                                            </div>
                                        );
                                    })}
                                </div>
                            </Col>
                        ))}
                    </Row>
                </Container>
            </section>
        </React.Fragment>
    );
}