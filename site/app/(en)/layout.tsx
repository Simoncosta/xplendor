import RootDocument from "@/components/layout/RootDocument";
import { SITE_URL } from "@/data/seo";
import { Metadata } from "next";

// Layout raiz das páginas em inglês (lang en).
export const metadata: Metadata = {
  metadataBase: new URL(SITE_URL),
  title: "XPLENDOR",
  description: "Marketing and technology, together.",
};

export default function EnLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <RootDocument lang="en">{children}</RootDocument>;
}
