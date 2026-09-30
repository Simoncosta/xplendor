import Blogs from "@/components/common/Blogs";
import Cta from "@/components/common/Cta";
import Footer2 from "@/components/footers/Footer2";
import About from "@/components/other-pages/team/About";
import Hero from "@/components/other-pages/team/Hero";
import MarqueeSlider from "@/components/other-pages/team/MarqueeSlider";
import Teammembers from "@/components/other-pages/team/Teammembers";
import { Metadata } from "next";
export const metadata: Metadata = {
  title:
    "Team || Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
  description:
    "Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
};
export default function TeamPage() {
  return (
    <>
      <main
        id="mxd-page-content"
        className="mxd-page-content inner-page-content"
      >
        <Hero />
        <MarqueeSlider />
        <Teammembers />
        <About />
        <Blogs />
        <Cta />
      </main>
      <Footer2 />
    </>
  );
}
