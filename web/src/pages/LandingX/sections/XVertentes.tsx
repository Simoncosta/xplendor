import React, { useState } from 'react';
import { AnimatePresence, motion, useReducedMotion } from 'framer-motion';
import Reveal from '../components/Reveal';
import { VERTENTES } from '../data/vertentes';

/** Vertentes clicáveis (Autocaravanas / Carros / Restauração). Uma selecionada de
 *  cada vez com linha animada a rodar; o detalhe expande com o conteúdo a fundo.
 *  Comunica que a XPLENDOR é um sistema de ponta a ponta, não features soltas. */
const XVertentes: React.FC = () => {
    const [active, setActive] = useState<string>(VERTENTES[0].key);
    const reduce = useReducedMotion();
    const current = VERTENTES.find((v) => v.key === active) ?? VERTENTES[0];

    return (
        <section className="x-section" id="ramos">
            <div className="x-container">
                <Reveal>
                    <div className="x-section-head">
                        <p className="x-kicker">Um sistema, de ponta a ponta</p>
                        <h2 className="x-section-title">
                            Feito para o teu <span className="x-serif">negócio.</span>
                        </h2>
                        <p className="x-section-sub">
                            Escolhe a tua vertente e vê como a XPLENDOR trata de tudo,
                            da captação ao pós-venda.
                        </p>
                    </div>
                </Reveal>

                {/* Abas clicáveis — a selecionada tem a linha animada a rodar */}
                <div className="x-vtabs" role="tablist" aria-label="Vertentes da XPLENDOR">
                    {VERTENTES.map((v) => (
                        <button
                            key={v.key}
                            type="button"
                            role="tab"
                            id={`vtab-${v.key}`}
                            aria-selected={active === v.key}
                            aria-controls="vdetail"
                            className={`x-vtab${active === v.key ? ' active' : ''}`}
                            style={{ ['--v-accent' as any]: v.accent }}
                            onClick={() => setActive(v.key)}
                        >
                            <span className="x-vtab-ico"><i className={v.icon} aria-hidden="true" /></span>
                            <span className="x-vtab-txt">
                                <strong>{v.label}</strong>
                                <span>{v.tagline}</span>
                            </span>
                        </button>
                    ))}
                </div>

                {/* Detalhe aprofundado — troca com transição suave */}
                <div className="x-vdetail-wrap">
                    <AnimatePresence mode="wait">
                        <motion.div
                            key={current.key}
                            id="vdetail"
                            role="tabpanel"
                            aria-labelledby={`vtab-${current.key}`}
                            className="x-vdetail"
                            style={{ ['--v-accent' as any]: current.accent }}
                            initial={reduce ? false : { opacity: 0, y: 14 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={reduce ? { opacity: 0 } : { opacity: 0, y: -10 }}
                            transition={{ duration: 0.35, ease: [0.22, 1, 0.36, 1] }}
                        >
                            <div className="x-vdetail-head">
                                <p className="x-vdor">{current.dor}</p>
                                <p className="x-vlead">{current.lead}</p>
                            </div>

                            <div className="x-vfeats">
                                {current.features.map((f) => (
                                    <div className="x-feat" key={f.title}>
                                        <i className={f.icon} aria-hidden="true" />
                                        <span className="x-feat-txt">
                                            <strong>{f.title}</strong>
                                            <span>{f.desc}</span>
                                        </span>
                                    </div>
                                ))}
                            </div>

                            <p className="x-vnote">
                                <i className="ri-shining-2-line" aria-hidden="true" />
                                {current.note}
                            </p>
                        </motion.div>
                    </AnimatePresence>
                </div>
            </div>
        </section>
    );
};

export default XVertentes;
