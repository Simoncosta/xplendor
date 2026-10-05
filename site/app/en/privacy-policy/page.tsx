import LegalPage from "@/components/legal/LegalPage";
import { privacyEn } from "@/data/legal/privacy";
import { Metadata } from "next";

export const metadata: Metadata = {
  title: "Privacy Policy | XPLENDOR",
  description: "How XPLENDOR handles personal data and the data received from Meta.",
  alternates: { languages: { pt: "/politica-de-privacidade/", en: "/en/privacy-policy/" } },
};

export default function Page() {
  return <LegalPage content={privacyEn} />;
}
