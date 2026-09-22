import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { CTA_WHATSAPP_URL } from '../../Landing/data/constants';

/*
  ⚠️ SCREENSHOT DA APP — o Simon põe a imagem aqui:
     web/public/landing-app-preview.png   (ex.: dashboard de restauração ou de carros)
  Enquanto o ficheiro não existir, o mockup mostra um estado neutro elegante
  (o onError troca para o placeholder). Assim que a imagem existir, aparece
  automaticamente enquadrada no mockup premium (sem mexer no código).
*/
const APP_PREVIEW_SRC = '/xplendor-dashboard.png';

const XHero: React.FC = () => {
    const [imgOk, setImgOk] = useState(true);

    return (
        <header className="x-hero" id="hero">
            <div className="x-container">
                <div className="x-hero-grid">
                    {/* ── Coluna esquerda: copy ── */}
                    <motion.div
                        initial={{ opacity: 0, y: 24 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.7, ease: [0.22, 1, 0.36, 1] }}
                    >
                        {/* Badge "rainbow border" — o toque premium subtil (referência Resend) */}
                        <span className="x-rainbow x-eyebrow">
                            <span>
                                <span className="x-dot" />
                                Uma plataforma. Dois ramos.
                            </span>
                        </span>

                        <h1 className="x-hero-title">
                            Gere o teu negócio,<br />
                            de ponta a ponta.
                        </h1>
                        <p className="x-hero-title-sub">Automotivo &amp; Restauração.</p>

                        <p className="x-hero-sub">
                            A XPLENDOR junta numa só plataforma a gestão de stands de
                            carros e autocaravanas e a operação de restaurantes. Stock,
                            CRM, faturação, reservas e marketing, tudo no mesmo lugar.
                        </p>

                        <div className="x-hero-ctas">
                            <a
                                href={CTA_WHATSAPP_URL}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="x-btn x-btn-primary"
                            >
                                Get started
                                <i className="ri-arrow-right-line" aria-hidden="true" />
                            </a>
                            <Link to="/login" className="x-btn x-btn-ghost">
                                Entrar na plataforma
                            </Link>
                        </div>

                        <div className="x-hero-ramos">
                            <span className="x-hero-ramo">
                                <span className="x-chip x-chip-auto" /> Automotivo: stands, carros e autocaravanas
                            </span>
                            <span className="x-hero-ramo">
                                <span className="x-chip x-chip-rest" /> Restauração: restaurantes com PingWin e CoverManager
                            </span>
                        </div>
                    </motion.div>

                    {/* ── Coluna direita: screenshot da app num mockup premium ── */}
                    <motion.div
                        initial={{ opacity: 0, scale: 0.95 }}
                        animate={{ opacity: 1, scale: 1 }}
                        transition={{ duration: 0.85, delay: 0.15, ease: [0.22, 1, 0.36, 1] }}
                    >
                        <div className="x-mockup">
                            <div className="x-mockup-bar" aria-hidden="true">
                                <span /><span /><span />
                            </div>
                            <div className="x-mockup-screen">
                                {imgOk ? (
                                    <img
                                        src={APP_PREVIEW_SRC}
                                        alt="Pré-visualização da plataforma XPLENDOR"
                                        onError={() => setImgOk(false)}
                                        loading="lazy"
                                    />
                                ) : (
                                    /* Estado neutro elegante enquanto não há screenshot. */
                                    <div className="x-mockup-empty" aria-hidden="true">
                                        <i className="ri-image-line" />
                                        <span>Pré-visualização da app</span>
                                    </div>
                                )}
                            </div>
                        </div>
                    </motion.div>
                </div>
            </div>
        </header>
    );
};

export default XHero;
