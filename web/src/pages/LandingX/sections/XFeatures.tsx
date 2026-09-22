import React from 'react';
import Reveal from '../components/Reveal';
import { TRANSVERSAIS } from '../data/ramos';

const XFeatures: React.FC = () => (
    <section className="x-section" id="capacidades">
        <div className="x-container">
            <Reveal>
                <div className="x-section-head">
                    <p className="x-kicker">Comum aos dois ramos</p>
                    <h2 className="x-section-title">
                        Capacidades que <span className="x-serif">escalam contigo.</span>
                    </h2>
                    <p className="x-section-sub">
                        A base que qualquer empresa liga — e o sistema molda-se ao ramo.
                    </p>
                </div>
            </Reveal>

            <div className="x-cards">
                {TRANSVERSAIS.map((f, i) => (
                    <Reveal key={f.title} delay={0.04 * i}>
                        <div className="x-card">
                            <span className="x-card-ico">
                                <i className={f.icon} aria-hidden="true" />
                            </span>
                            <h4>{f.title}</h4>
                            <p>{f.desc}</p>
                        </div>
                    </Reveal>
                ))}
            </div>
        </div>
    </section>
);

export default XFeatures;
