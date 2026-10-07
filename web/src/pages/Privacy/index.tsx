import { useEffect } from "react";
import { PRIVACY_URL, PRIVACY_URL_EN } from "common/legal";

/**
 * A política de privacidade vive no site (xplendor.tech). Esta página antiga da app
 * (/app/privacy) só redireciona para lá, para os links e marcadores antigos não abrirem
 * uma versão desatualizada. Com ?lang=en vai para a versão inglesa.
 */
const PrivacyPolicy = () => {
    const target = new URLSearchParams(window.location.search).get("lang") === "en" ? PRIVACY_URL_EN : PRIVACY_URL;

    useEffect(() => {
        document.title = "Política de Privacidade | XPLENDOR";
        window.location.replace(target);
    }, [target]);

    return (
        <p style={{ padding: 24, fontFamily: "sans-serif" }}>
            A abrir a <a href={target}>Política de Privacidade</a>…
        </p>
    );
};

export default PrivacyPolicy;
