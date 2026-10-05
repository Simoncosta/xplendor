import LegalPage from "@/components/legal/LegalPage";
import JsonLd from "@/components/seo/JsonLd";
import { privacyEn } from "@/data/legal/privacy";
import { breadcrumbJsonLd, pageMetadata } from "@/data/seo";

export const metadata = pageMetadata("privacy");

export default function Page() {
  return (
    <>
      <JsonLd data={breadcrumbJsonLd("privacy")} />
      <LegalPage content={privacyEn} />
    </>
  );
}
