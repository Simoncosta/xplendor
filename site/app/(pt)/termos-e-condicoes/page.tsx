import LegalPage from "@/components/legal/LegalPage";
import JsonLd from "@/components/seo/JsonLd";
import { termsPt } from "@/data/legal/terms";
import { breadcrumbJsonLd, pageMetadata } from "@/data/seo";

export const metadata = pageMetadata("termos");

export default function Page() {
  return (
    <>
      <JsonLd data={breadcrumbJsonLd("termos")} />
      <LegalPage content={termsPt} />
    </>
  );
}
