import RootDocument from "@/components/layout/RootDocument";
import { SITE_URL } from "@/data/seo";
import { Metadata } from "next";

// Layout raiz das páginas em português (lang pt-PT).
export const metadata: Metadata = {
  metadataBase: new URL(SITE_URL),
  title: "XPLENDOR",
  description: "Marketing e tecnologia, juntos.",
};

export default function PtLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <RootDocument lang="pt-PT">{children}</RootDocument>;
}
