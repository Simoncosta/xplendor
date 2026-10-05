import LegalPage from "@/components/legal/LegalPage";
import JsonLd from "@/components/seo/JsonLd";
import { dataDeletionPt } from "@/data/legal/dataDeletion";
import { breadcrumbJsonLd, pageMetadata } from "@/data/seo";

export const metadata = pageMetadata("eliminacao");

export default function Page() {
  return (
    <>
      <JsonLd data={breadcrumbJsonLd("eliminacao")} />
      <LegalPage content={dataDeletionPt} />
    </>
  );
}
