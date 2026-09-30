import Cta from "@/components/common/Cta";
import Footer2 from "@/components/footers/Footer2";

import MarqueeSlider from "@/components/portfolios/MarqueeSlider";
import PortfolioList from "@/components/portfolios/PortfolioList";
import Portfolios1 from "@/components/portfolios/Portfolios1";
import Testimonials from "@/components/common/Testimonials";
import { Metadata } from "next";
export const metadata: Metadata = {
  title:
    "Work Simple || Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
  description:
    "Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
};
export default function WorksSimplePage() {
  return (
    <>
      <main
        id="mxd-page-content"
        className="mxd-page-content inner-page-content"
      >
        <Portfolios1 />
        <PortfolioList />
        <MarqueeSlider />
        <Testimonials />
        <Cta />
      </main>
      <Footer2 />
    </>
  );
}
