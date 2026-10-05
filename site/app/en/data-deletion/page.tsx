import LegalPage from "@/components/legal/LegalPage";
import { dataDeletionEn } from "@/data/legal/dataDeletion";
import { Metadata } from "next";

export const metadata: Metadata = {
  title: "Data Deletion | XPLENDOR",
  description: "How to request deletion of the data stored by XPLENDOR, including Meta data.",
  alternates: { languages: { pt: "/eliminacao-de-dados/", en: "/en/data-deletion/" } },
};

export default function Page() {
  return <LegalPage content={dataDeletionEn} />;
}
