"use client";

import { useEffect, useState } from "react";

/**
 * XPLENDOR — Mini-chat de WhatsApp (widget flutuante global, client-side).
 * Ao enviar, abre wa.me com a mensagem do visitante pre-preenchida (sem prefixo).
 * Sem backend: funciona numa landing estatica.
 */

const WHATSAPP_NUMBER = "351938963526"; // +351 938 963 526 (formato wa.me, sem +)
// "Visto nesta visita": marca-se quando o popup abre sozinho OU quando o utilizador
// interage (abre/fecha). Enquanto estiver marcado, o auto-open nao volta a acontecer.
const SEEN_KEY = "xp_chat_seen";

const isSeen = (): boolean => {
  try {
    return sessionStorage.getItem(SEEN_KEY) === "1";
  } catch {
    return false;
  }
};
const markSeen = (): void => {
  try {
    sessionStorage.setItem(SEEN_KEY, "1");
  } catch {
    /* sessionStorage pode falhar (modo privado, etc.) */
  }
};

export default function WhatsAppChat() {
  const [open, setOpen] = useState(false);
  const [message, setMessage] = useState("");
  const [avatarOk, setAvatarOk] = useState(true);

  // Auto-open educado: abre sozinho apos 5s, UMA vez por visita, e so se o
  // utilizador ainda nao tiver interagido com o chat nesta sessao.
  useEffect(() => {
    if (isSeen()) return; // ja abriu sozinho ou ja interagiu nesta visita
    const t = setTimeout(() => {
      if (!isSeen()) {
        setOpen(true);
        markSeen();
      }
    }, 5000);
    return () => clearTimeout(t);
  }, []);

  // Alterna o chat e regista a interacao (impede o auto-open futuro).
  const toggle = () => {
    markSeen();
    setOpen((v) => !v);
  };
  const close = () => {
    markSeen();
    setOpen(false);
  };

  const send = () => {
    const text = message.trim();
    if (!text) return;
    const url = `https://wa.me/${WHATSAPP_NUMBER}?text=${encodeURIComponent(
      text
    )}`;
    window.open(url, "_blank", "noopener,noreferrer");
    setMessage("");
  };

  const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "Enter") {
      e.preventDefault();
      send();
    }
  };

  return (
    <div className={`xp-chat ${open ? "is-open" : ""}`}>
      {/* Painel de conversa */}
      <div className="xp-chat__panel" role="dialog" aria-label="Chat com a XPLENDOR">
        {/* Cabecalho */}
        <div className="xp-chat__header">
          <div className="xp-chat__avatar-wrap">
            <div className="xp-chat__avatar">
              {avatarOk ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  src="/img/avatars/simon-costa.webp"
                  width={80}
                  height={100}
                  alt="Simon Costa"
                  onError={() => setAvatarOk(false)}
                />
              ) : (
                <span className="xp-chat__avatar-initials">SC</span>
              )}
            </div>
            <span className="xp-chat__online" />
          </div>
          <div className="xp-chat__id">
            <p className="xp-chat__name">Simon Costa</p>
            <p className="xp-chat__role">XPLENDOR</p>
          </div>
          <button
            type="button"
            className="xp-chat__close"
            aria-label="Fechar chat"
            onClick={close}
          >
            <i className="ph-bold ph-x" />
          </button>
        </div>

        {/* Mensagens automaticas */}
        <div className="xp-chat__body">
          <div className="xp-chat__msg">
            <div className="xp-chat__msg-avatar">
              {avatarOk ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img src="/img/avatars/simon-costa.webp" width={80} height={100} alt="" loading="lazy" />
              ) : (
                <span className="xp-chat__avatar-initials">SC</span>
              )}
            </div>
            <p className="xp-chat__bubble">Olá! 👋 Sou o Simon, da XPLENDOR.</p>
          </div>
          <div className="xp-chat__msg">
            <div className="xp-chat__msg-avatar">
              {avatarOk ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img src="/img/avatars/simon-costa.webp" width={80} height={100} alt="" loading="lazy" />
              ) : (
                <span className="xp-chat__avatar-initials">SC</span>
              )}
            </div>
            <p className="xp-chat__bubble">
              Em que posso ajudar o seu negócio? Escreva-me aqui e falamos pelo
              WhatsApp.
            </p>
          </div>
        </div>

        {/* Campo de envio */}
        <div className="xp-chat__input">
          <input
            type="text"
            value={message}
            onChange={(e) => setMessage(e.target.value)}
            onKeyDown={onKeyDown}
            placeholder="Escreva a sua mensagem..."
            aria-label="A sua mensagem"
          />
          <button
            type="button"
            className="xp-chat__send"
            aria-label="Enviar pelo WhatsApp"
            onClick={send}
            disabled={!message.trim()}
          >
            <i className="ph-bold ph-paper-plane-right" />
          </button>
        </div>
      </div>

      {/* Botao flutuante (abrir/fechar) */}
      <button
        type="button"
        className="xp-chat__toggle"
        aria-label={open ? "Fechar chat" : "Falar connosco no WhatsApp"}
        aria-expanded={open}
        onClick={toggle}
      >
        <i className={open ? "ph-bold ph-x" : "ph-fill ph-whatsapp-logo"} />
      </button>
    </div>
  );
}
