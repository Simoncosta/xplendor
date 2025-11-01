import Brands from "@/components/common/Brands";
import Footer1 from "@/components/footers/Footer1";
import Banner from "@/components/dealer/Banner";
import Brands2 from "@/components/dealer/Brands";
import Dealers from "@/components/dealer/Dealers";
import SearchSection from "@/components/dealer/SearchSection";
import Header2 from "@/components/headers/Header2";
import React from "react";

import MetaComponent from "@/components/common/MetaComponent";
const metadata = {
  title:
    "Dealer Listings || AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
  description: "AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
};
export default function DealerListingPage() {
  return (
    <>
      <MetaComponent meta={metadata} />
      <div className="header-fixed">
        <Header2 />
      </div>
      <Banner />
      <SearchSection />
      <Brands2 />
      <Dealers />
      <div className="mt-5 pt-5"></div>
      <Brands />
      <Footer1 />
    </>
  );
}
