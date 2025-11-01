import Brands from "@/components/common/Brands";
import Footer1 from "@/components/footers/Footer1";
import Header2 from "@/components/headers/Header2";
import Topbar from "@/components/headers/Topbar";
import Agents from "@/components/common/Agents";
import Blogs from "@/components/common/Blogs3";
import CarReview from "@/components/homes/home-3/CarReview";
import Cars from "@/components/homes/home-3/Cars";
import Categories from "@/components/homes/home-3/Categories";
import Compare from "@/components/homes/home-3/Compare";
import DownloadApp from "@/components/common/DownloadApp";
import Features from "@/components/homes/home-3/Features";
import Filtering from "@/components/homes/home-3/Filtering";
import Hero from "@/components/homes/home-3/Hero";
import LoanCalculator from "@/components/homes/home-3/LoanCalculator";
import Testimonials from "@/components/homes/home-3/Testimonials";
import React from "react";

import MetaComponent from "@/components/common/MetaComponent";
const metadata = {
  title: "Home 03 || AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
  description: "AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
};
export default function HomePage3() {
  return (
    <>
      <MetaComponent meta={metadata} />
      <Topbar />
      <div className="header-fixed">
        <Header2 />
      </div>
      <Hero />
      <Filtering />
      <Categories />
      <Cars />
      <Features />
      <LoanCalculator />
      <Agents />
      <DownloadApp />
      <Testimonials />
      <Compare />
      <CarReview />
      <Blogs />
      <Brands />
      <Footer1 />
    </>
  );
}
