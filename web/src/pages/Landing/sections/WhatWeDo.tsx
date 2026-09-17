import React from 'react';
import { Container } from 'reactstrap';
import { Reveal, RevealGroup, RevealItem } from '../lib/motion';

interface Offer {
    icon: string;
    title: string;
    text: string;
    tag: 'Serviço' | 'Plataforma';
}

const OFFERS: Offer[] = [
    {
        icon: 'ri-megaphone-line',
        title: 'Tráfego pago',
        text: 'Campanhas no Facebook, Instagram e Google geridas de raiz — criativos, segmentação e otimização por custo de lead.',
        tag: 'Serviço',
    },
    {
        icon: 'ri-instagram-line',
        title: 'Redes sociais',
        text: 'Presença consistente e profissional nas redes do stand: conteúdo, publicações e gestão contínua.',
        tag: 'Serviço',
    },
    {
        icon: 'ri-global-line',
        title: 'Sites de stand',
        text: 'Site próprio otimizado para SEO, com fichas de viatura e tracking — menos dependência dos marketplaces.',
        tag: 'Serviço',
    },
    {
        icon: 'ri-dashboard-3-line',
        title: 'Plataforma XPLENDOR',
        text: 'O software para gerir o stand: stock, leads, vendas, pós-venda e documentos. Um produto com preço próprio.',
        tag: 'Plataforma',
    },
];

/**
 * O QUE FAZEMOS — mostra AMBOS: os serviços de agência E a plataforma como
 * produto (com etiqueta a distinguir). Resolve à partida a ambiguidade do modelo.
 */
const WhatWeDo: React.FC = () => (
    <section id="o-que-fazemos" className="lp-section2">
        <Container>
            <Reveal className="lp-section2-head">
                <span className="lp-kicker">O que fazemos</span>
                <h2 className="lp-h2">Marketing feito por nós. Gestão feita por si, com a nossa plataforma.</h2>
                <p className="lp-lead">
                    Somos agência e software. Pode contratar só os serviços, só a plataforma, ou os dois —
                    a plataforma tem o seu próprio valor e preço, não é um extra dos serviços.
                </p>
            </Reveal>

            <RevealGroup className="lp-offer-grid">
                {OFFERS.map((o) => (
                    <RevealItem key={o.title} className={`lp-offer-card${o.tag === 'Plataforma' ? ' is-platform' : ''}`}>
                        <span className="lp-offer-icon"><i className={o.icon} aria-hidden="true" /></span>
                        <span className={`lp-offer-tag lp-offer-tag-${o.tag === 'Plataforma' ? 'platform' : 'service'}`}>
                            {o.tag}
                        </span>
                        <h3 className="lp-offer-title">{o.title}</h3>
                        <p className="lp-offer-text">{o.text}</p>
                    </RevealItem>
                ))}
            </RevealGroup>
        </Container>
    </section>
);

export default WhatWeDo;
