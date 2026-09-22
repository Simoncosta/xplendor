import React from 'react';
import { CTA_WHATSAPP_URL } from '../../Landing/data/constants';

/** Botão WhatsApp flutuante — fixo no canto inferior direito, presente em toda
 *  a landing. Via de contacto destacada (o Simon orça por especificidade). */
const XWhatsApp: React.FC = () => (
    <a
        href={CTA_WHATSAPP_URL}
        target="_blank"
        rel="noopener noreferrer"
        className="x-whatsapp"
        aria-label="Falar connosco no WhatsApp"
    >
        <i className="ri-whatsapp-line" aria-hidden="true" />
    </a>
);

export default XWhatsApp;
