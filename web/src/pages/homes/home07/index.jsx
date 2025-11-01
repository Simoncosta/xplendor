import CarBrands2 from "@/components/common/CarBrands2";
import Cta from "@/components/common/Cta";
import Trending from "@/components/common/Trending";
import Header1 from "@/components/headers/Header1";
import CarReview from "@/components/common/CarReview";

import CarBrands from "@/components/homes/home-7/CarBrands";
import Cars from "@/components/homes/home-7/Cars";
import Cars2 from "@/components/homes/home-7/Cars2";
import Filter from "@/components/homes/home-7/Filter";
import Hero from "@/components/homes/home-7/Hero";
import LoanCalculator from "@/components/homes/home-7/LoanCalculator";
import React from "react";
import Testimonials from "@/components/homes/home-7/Testimonials";
import Blogs from "@/components/common/Blogs";
import DownloadApp from "@/components/common/DownloadApp";
import Brands from "@/components/common/Brands";
import Footer1 from "@/components/footers/Footer1";
import Banner2 from "@/components/homes/home-6/Banner2";

import MetaComponent from "@/components/common/MetaComponent";
const metadata = {
  title: "Home 07 || AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
  description: "AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
};
export default function HomePage7() {
  return (
    <>
      <MetaComponent meta={metadata} />
      <div className="header-fixed">
        <Header1 />
      </div>
      <Hero />
      <Filter />
      <CarBrands />
      <Cars />
      <Cars2 />
      <LoanCalculator />
      <Trending />
      <Cta />
      <Banner2 />
      <CarBrands2 />
      <CarReview />
      <div className="mt-5 pt-5"></div>
      <Testimonials />
      <Blogs />
      <DownloadApp />
      <div className="mt-5 pt-5"></div>
      <Brands />
      <Footer1 />
    </>
  );
}
