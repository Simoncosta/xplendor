import LegalPage from "@/components/legal/LegalPage";
import { termsPt } from "@/data/legal/terms";
import { Metadata } from "next";

export const metadata: Metadata = {
  title: "Termos e Condições | XPLENDOR",
  description: "Condições de utilização do site e da Plataforma XPLENDOR.",
  alternates: { languages: { pt: "/termos-e-condicoes/", en: "/en/terms/" } },
};

export default function Page() {
  return <LegalPage content={termsPt} />;
}
