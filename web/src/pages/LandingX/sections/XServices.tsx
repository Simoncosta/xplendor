import React from 'react';
import Reveal from '../components/Reveal';
import { SERVICOS } from '../data/ramos';

/** Serviços de agência — além da plataforma, a XPLENDOR trata da presença digital. */
const XServices: React.FC = () => (
    <section className="x-section" id="servicos">
        <div className="x-container">
            <Reveal>
                <div className="x-section-head">
                    <p className="x-kicker">Mais do que software</p>
                    <h2 className="x-section-title">
                        Também somos a tua <span className="x-serif">agência.</span>
                    </h2>
                    <p className="x-section-sub">
                        Além da plataforma, tratamos da tua presença digital de ponta a ponta.
                    </p>
                </div>
            </Reveal>

            <div className="x-cards x-cards-4">
                {SERVICOS.map((s, i) => (
                    <Reveal key={s.title} delay={0.04 * i}>
                        <div className="x-card">
                            <span className="x-card-ico">
                                <i className={s.icon} aria-hidden="true" />
                            </span>
                            <h4>{s.title}</h4>
                            <p>{s.desc}</p>
                        </div>
                    </Reveal>
                ))}
            </div>
        </div>
    </section>
);

export default XServices;
