import Agents from "@/components/common/Agents";
import Brands from "@/components/common/Brands";
import Cars from "@/components/common/Cars";
import Categories from "@/components/common/Categories";
import Categories2 from "@/components/common/Categories2";
import Cta from "@/components/common/Cta";
import Header1 from "@/components/headers/Header1";
import CarCompare from "@/components/common/CarCompare";
import Features from "@/components/common/Features";
import Filter from "@/components/homes/home-8/Filter";
import Hero from "@/components/homes/home-8/Hero";
import React from "react";
import Trending from "@/components/common/Trending";
import DownloadApp from "@/components/common/DownloadApp";
import Footer1 from "@/components/footers/Footer1";

import MetaComponent from "@/components/common/MetaComponent";
const metadata = {
  title: "Home 08 || AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
  description: "AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
};
export default function HomePage8() {
  return (
    <>
      <MetaComponent meta={metadata} />
      <div className="header-fixed">
        <Header1 />
      </div>
      <Hero />
      <Filter />
      <Categories />
      <Cars parentClass="tf-section3" />
      <Cta />
      <Categories2 parentClass="tf-section3 mb-5" />
      <Features />
      <Brands />
      <Agents parentClass="tf-section3" />
      <CarCompare parentClass="tf-section3" />
      <Trending parentClass="tf-section3 mb-0 pb-4" />
      <DownloadApp parentClass="tf-section-banner " />
      <div className="mt-5 pt-5"></div>
      <Footer1 />
    </>
  );
}
