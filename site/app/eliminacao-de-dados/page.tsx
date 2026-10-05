import LegalPage from "@/components/legal/LegalPage";
import { dataDeletionPt } from "@/data/legal/dataDeletion";
import { Metadata } from "next";

export const metadata: Metadata = {
  title: "Eliminação de Dados | XPLENDOR",
  description: "Como pedir a eliminação dos dados guardados pela XPLENDOR, incluindo os dados da Meta.",
  alternates: { languages: { pt: "/eliminacao-de-dados/", en: "/en/data-deletion/" } },
};

export default function Page() {
  return <LegalPage content={dataDeletionPt} />;
}
