import LegalPage from "@/components/legal/LegalPage";
import JsonLd from "@/components/seo/JsonLd";
import { termsEn } from "@/data/legal/terms";
import { breadcrumbJsonLd, pageMetadata } from "@/data/seo";

export const metadata = pageMetadata("terms");

export default function Page() {
  return (
    <>
      <JsonLd data={breadcrumbJsonLd("terms")} />
      <LegalPage content={termsEn} />
    </>
  );
}
