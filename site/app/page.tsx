import HomeMainPage from "./(homes)/home-main/page";
import { Metadata } from "next";

export const metadata: Metadata = {
  title: "XPLENDOR | Marketing e tecnologia, juntos.",
  description:
    "A XPLENDOR reúne os especialistas que fazem tudo funcionar de verdade: plataforma própria, social media, tráfego pago e consultoria de tecnologia.",
};

// A raiz (/) passa a servir a home-main (a nossa landing). A antiga PreviewPage
// (catálogo de demonstração do template) fica órfã, acessível só em /preview.
export default function Home() {
  return <HomeMainPage />;
}
