import LegalPage from "@/components/legal/LegalPage";
import { privacyPt } from "@/data/legal/privacy";
import { Metadata } from "next";

export const metadata: Metadata = {
  title: "Política de Privacidade | XPLENDOR",
  description: "Como a XPLENDOR trata os dados pessoais e os dados recebidos da Meta.",
  alternates: { languages: { pt: "/politica-de-privacidade/", en: "/en/privacy-policy/" } },
};

export default function Page() {
  return <LegalPage content={privacyPt} />;
}
