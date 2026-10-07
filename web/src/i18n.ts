import i18n from "i18next";
import detector from "i18next-browser-languagedetector";
import { initReactI18next } from "react-i18next";

import translationPt from "./locales/pt.json";
import translationENG from "./locales/en.json";


// the translations
const resources = {
    pt: {
        translation: translationPt,
    },
    // en: {
    //   translation: translationENG,
    // },
};

// O armazenamento do browser pode estar bloqueado (modo privado): nesse caso, português.
let language: string | null = null;
try {
    language = localStorage.getItem("I18N_LANGUAGE");
    if (!language) localStorage.setItem("I18N_LANGUAGE", "pt");
} catch {
    language = null;
}

i18n
    .use(detector)
    .use(initReactI18next) // passes i18n down to react-i18next
    .init({
        resources,
        lng: language || "pt",
        fallbackLng: "pt", // use en if detected lng is not available

        keySeparator: false, // we do not use keys in form messages.welcome

        interpolation: {
            escapeValue: false, // react already safes from xss
        },
    });

export default i18n;
