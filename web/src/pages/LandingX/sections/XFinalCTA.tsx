import React from 'react';
import { Link } from 'react-router-dom';
import Reveal from '../components/Reveal';
import { CTA_WHATSAPP_URL } from '../../Landing/data/constants';

const XFinalCTA: React.FC = () => (
    <section className="x-section">
        <div className="x-container">
            <Reveal>
                <div className="x-final">
                    <h2>
                        Pronto para pôr tudo <span className="x-serif">no mesmo lugar?</span>
                    </h2>
                    <p>
                        Fala connosco e descobre como a XPLENDOR se adapta ao teu negócio,
                        seja automotivo ou restauração.
                    </p>
                    <div className="x-final-ctas">
                        <a
                            href={CTA_WHATSAPP_URL}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="x-btn x-btn-primary"
                        >
                            <i className="ri-whatsapp-line" aria-hidden="true" />
                            Falar connosco
                        </a>
                        <Link to="/login" className="x-btn x-btn-ghost">Entrar</Link>
                    </div>
                </div>
            </Reveal>
        </div>
    </section>
);

export default XFinalCTA;
