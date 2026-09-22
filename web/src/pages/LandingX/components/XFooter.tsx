import React from 'react';
import { Link } from 'react-router-dom';
import logoLight from '../../../assets/images/logo-light.png';
import { CTA_WHATSAPP_URL } from '../../Landing/data/constants';

const XFooter: React.FC = () => (
    <footer className="x-footer">
        <div className="x-container">
            <div className="x-footer-grid">
                <div className="x-footer-brand">
                    <img src={logoLight} alt="XPLENDOR" />
                    <p>
                        A plataforma que gere o teu negócio de ponta a ponta.
                        Automotivo e Restauração, no mesmo lugar.
                    </p>
                </div>

                <div className="x-footer-col">
                    <h5>Produto</h5>
                    <a href="#ramos">Vertentes</a>
                    <a href="#capacidades">Capacidades</a>
                    <a href="#servicos">Serviços de agência</a>
                </div>

                <div className="x-footer-col">
                    <h5>Plataforma</h5>
                    <Link to="/login">Entrar</Link>
                    <Link to="/install">Instalar app</Link>
                    <a href={CTA_WHATSAPP_URL} target="_blank" rel="noopener noreferrer">
                        Falar connosco
                    </a>
                </div>

                <div className="x-footer-col">
                    <h5>Legal</h5>
                    <Link to="/privacy">Política de privacidade</Link>
                </div>
            </div>

            <div className="x-footer-bottom">
                <p>© {new Date().getFullYear()} XPLENDOR. Todos os direitos reservados.</p>
                <p>Feito em Portugal.</p>
            </div>
        </div>
    </footer>
);

export default XFooter;
