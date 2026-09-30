import Cta from "@/components/common/Cta";
import Footer2 from "@/components/footers/Footer2";

import Challages from "@/components/portfolios/Challages";
import DetailsHero from "@/components/portfolios/DetailsHero";
import Feedback from "@/components/portfolios/Feedback";
import NextPrevNavigation from "@/components/portfolios/NextPrevNavigation";
import ParallaxDivider2 from "@/components/portfolios/ParallaxDivider2";
import Solutions from "@/components/portfolios/Solutions";
import { Metadata } from "next";
export const metadata: Metadata = {
  title:
    "Project Details || Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
  description:
    "Rayo - Digital Agency & Personal Portfolio React Nextjs Template",
};
export default function ProjectDetailsPage() {
  return (
    <>
      <main
        id="mxd-page-content"
        className="mxd-page-content inner-page-content"
      >
        <DetailsHero />
        <ParallaxDivider2 />
        <div className="mxd-section mxd-project overflow-hidden">
          <div className="mxd-container grid-container">
            <Challages />
            <Solutions />
            <Feedback />
            <NextPrevNavigation />
          </div>
        </div>
        <Cta />
      </main>
      <Footer2 />
    </>
  );
}
