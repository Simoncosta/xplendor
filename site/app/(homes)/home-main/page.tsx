import Cta from "@/components/common/Cta";
import Footer2 from "@/components/footers/Footer2";

import About from "@/components/homes/home-1/About";
import Approch from "@/components/common/Approch";

// import Devider from "@/components/homes/home-1/Devider"; // escondido (ver abaixo)
import Hero from "@/components/homes/home-1/Hero";
import Marquee from "@/components/homes/home-1/Marquee";
import ServicesStack from "@/components/homes/home-1/ServicesStack";
import { Metadata } from "next";

// FASE 1 — secções escondidas por conterem provas falsas do template (números,
// portfólio, prémios, testemunhos, parceiros e artigos inventados). Ficam órfãs
// (código intacto), a reativar/preencher em fases seguintes com conteúdo real:
//   Facts, Projects, MarqueeSlider, Awards, Testimonials, MarqueeSection2,
//   Partners, Blogs.

export const metadata: Metadata = {
  title: "XPLENDOR | Marketing e tecnologia, juntos.",
  description:
    "A XPLENDOR reúne os especialistas que fazem tudo funcionar de verdade: plataforma própria, social media, tráfego pago e consultoria de tecnologia.",
};
export default function HomeMainPage() {
  return (
    <>
      <main id="mxd-page-content" className="mxd-page-content">
        <Hero />
        {/* Devider escondido (faixa decorativa do template). A reativar/transformar
            em montra da plataforma mais tarde. O About vem logo a seguir ao Hero. */}
        {/* <Devider /> */}
        <About />
        <Marquee />
        <ServicesStack />
        <Approch />
        <Cta />
      </main>
      <Footer2 />
    </>
  );
}
