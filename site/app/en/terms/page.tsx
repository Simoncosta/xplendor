import LegalPage from "@/components/legal/LegalPage";
import { termsEn } from "@/data/legal/terms";
import { Metadata } from "next";

export const metadata: Metadata = {
  title: "Terms and Conditions | XPLENDOR",
  description: "Terms of use of the XPLENDOR website and Platform.",
  alternates: { languages: { pt: "/termos-e-condicoes/", en: "/en/terms/" } },
};

export default function Page() {
  return <LegalPage content={termsEn} />;
}
