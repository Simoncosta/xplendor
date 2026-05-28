import React, { useEffect } from 'react';
import { Container } from 'reactstrap';
import '../Landing/landing.css';

import LandingNav from '../Landing/components/LandingNav';
import LandingFooter from '../Landing/components/LandingFooter';

const LAST_UPDATED = '28 de Maio de 2026';

const PrivacyPolicy: React.FC = () => {
    useEffect(() => {
        document.title = 'Política de Privacidade — XPLENDOR';
    }, []);

    return (
        <div className="xplndor-landing">
            <LandingNav />
            <main>
                <section className="lp-section">
                    <Container>
                        <article className="lp-legal">
                            <p className="lp-label">Legal</p>
                            <h1 className="lp-legal-title">Política de Privacidade</h1>
                            <p className="lp-legal-meta">
                                Última actualização: {LAST_UPDATED}
                            </p>

                            <p>
                                Esta política explica que dados pessoais recolhemos, com que
                                finalidade, e quais os direitos de quem nos contacta ou visita o
                                nosso site. É um documento informativo, redigido de forma clara e
                                honesta.
                            </p>

                            <h2>1. Identificação do responsável</h2>
                            <p>
                                O responsável pelo tratamento dos dados é a <strong>XPLENDOR</strong>,
                                agência de marketing digital para stands de automóveis em Portugal.
                            </p>
                            <ul>
                                <li>NIPC: 517343355</li>
                                <li>Morada: Rua Camilo de Oliveira, 101, Rio Tinto, Gondomar</li>
                                <li>
                                    Email: <a href="mailto:simoncosta@xplendor.tech">simoncosta@xplendor.tech</a>
                                </li>
                                <li>
                                    Telefone / WhatsApp:{' '}
                                    <a href="https://wa.me/351938963526" target="_blank" rel="noopener noreferrer">
                                        +351 938 963 526
                                    </a>
                                </li>
                                <li>
                                    Website:{' '}
                                    <a href="https://xplendor.tech" target="_blank" rel="noopener noreferrer">
                                        https://xplendor.tech
                                    </a>
                                </li>
                            </ul>

                            <h2>2. Que dados recolhemos</h2>
                            <ul>
                                <li>
                                    <strong>Dados de contacto</strong> fornecidos voluntariamente
                                    (nome, telefone, email) quando nos contacta via WhatsApp ou
                                    formulário.
                                </li>
                                <li>
                                    <strong>Dados de navegação</strong> recolhidos por cookies e
                                    ferramentas de análise: páginas visitadas, origem do tráfego e
                                    tipo de dispositivo.
                                </li>
                            </ul>

                            <h2>3. Finalidade e base legal</h2>
                            <ul>
                                <li>
                                    Responder a pedidos de contacto e preparar uma eventual relação
                                    comercial (diligências pré-contratuais / interesse legítimo).
                                </li>
                                <li>
                                    Analisar e melhorar o site e as campanhas de marketing
                                    (consentimento, dado através do banner de cookies).
                                </li>
                            </ul>

                            <h2>4. Cookies e tecnologias de análise</h2>
                            <ul>
                                <li>
                                    <strong>Google Analytics 4</strong> — medição de tráfego, com
                                    Google Consent Mode. Só fica activo após o seu consentimento.
                                </li>
                                <li>
                                    <strong>Meta Pixel</strong> — medição e optimização de campanhas
                                    no Facebook e Instagram, presente apenas na nossa landing page e
                                    só após o seu consentimento.
                                </li>
                                <li>
                                    Pode aceitar ou rejeitar estas tecnologias no banner de cookies e
                                    alterar a sua escolha a qualquer momento (limpar os cookies do
                                    site repõe o banner).
                                </li>
                                <li>
                                    A Google e a Meta podem transferir dados para fora da União
                                    Europeia, ao abrigo dos respectivos mecanismos legais de
                                    transferência.
                                </li>
                            </ul>

                            <h2>5. Conservação dos dados</h2>
                            <p>
                                Conservamos os dados apenas enquanto forem necessários à relação
                                comercial ou pelo prazo legalmente exigido, eliminando-os quando deixem
                                de ser necessários.
                            </p>

                            <h2>6. Direitos do titular dos dados</h2>
                            <p>
                                Pode exercer os seguintes direitos sobre os seus dados pessoais:
                                acesso, rectificação, apagamento, oposição, portabilidade e retirada
                                de consentimento. Para isso, contacte-nos através de{' '}
                                <a href="mailto:simoncosta@xplendor.tech">simoncosta@xplendor.tech</a>.
                            </p>
                            <p>
                                Tem ainda o direito de apresentar reclamação à autoridade de controlo
                                competente em Portugal — a <strong>CNPD</strong> (Comissão Nacional de
                                Protecção de Dados).
                            </p>

                            <h2>7. Partilha com terceiros</h2>
                            <p>
                                Partilhamos dados apenas com os prestadores de serviços descritos
                                acima (Google e Meta), na medida do necessário às finalidades
                                indicadas. <strong>Não vendemos os seus dados.</strong>
                            </p>

                            <h2>8. Contacto</h2>
                            <p>
                                Para qualquer questão relacionada com privacidade ou com esta
                                política, contacte-nos através de{' '}
                                <a href="mailto:simoncosta@xplendor.tech">simoncosta@xplendor.tech</a>.
                            </p>
                        </article>
                    </Container>
                </section>
            </main>
            <LandingFooter />
        </div>
    );
};

export default PrivacyPolicy;
