import LegalPage from "@/components/legal/LegalPage";
import JsonLd from "@/components/seo/JsonLd";
import { privacyPt } from "@/data/legal/privacy";
import { breadcrumbJsonLd, pageMetadata } from "@/data/seo";

export const metadata = pageMetadata("privacidade");

export default function Page() {
  return (
    <>
      <JsonLd data={breadcrumbJsonLd("privacidade")} />
      <LegalPage content={privacyPt} />
    </>
  );
}
