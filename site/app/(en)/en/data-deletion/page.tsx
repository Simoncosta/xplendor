import LegalPage from "@/components/legal/LegalPage";
import JsonLd from "@/components/seo/JsonLd";
import { dataDeletionEn } from "@/data/legal/dataDeletion";
import { breadcrumbJsonLd, pageMetadata } from "@/data/seo";

export const metadata = pageMetadata("deletion");

export default function Page() {
  return (
    <>
      <JsonLd data={breadcrumbJsonLd("deletion")} />
      <LegalPage content={dataDeletionEn} />
    </>
  );
}
