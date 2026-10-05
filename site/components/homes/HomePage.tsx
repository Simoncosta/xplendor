import Cta from "@/components/common/Cta";
import Footer2 from "@/components/footers/Footer2";

import About from "@/components/homes/home-1/About";
import Approch from "@/components/common/Approch";

// import Devider from "@/components/homes/home-1/Devider"; // escondido (ver abaixo)
import Hero from "@/components/homes/home-1/Hero";
import Marquee from "@/components/homes/home-1/Marquee";
import ServicesStack from "@/components/homes/home-1/ServicesStack";

// FASE 1 — secções escondidas por conterem provas falsas do template (números,
// portfólio, prémios, testemunhos, parceiros e artigos inventados). Ficam órfãs
// (código intacto), a reativar/preencher em fases seguintes com conteúdo real:
//   Facts, Projects, MarqueeSlider, Awards, Testimonials, MarqueeSection2,
//   Partners, Blogs.

export default function HomePage() {
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
