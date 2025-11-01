import AddListing from "@/components/dashboard/AddListing";
import Sidebar from "@/components/dashboard/Sidebar";
import Header4 from "@/components/headers/Header4";
import React from "react";

import MetaComponent from "@/components/common/MetaComponent";
const metadata = {
  title:
    "Add Listing || AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
  description: "AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
};
export default function AddListingPage() {
  return (
    <>
      <MetaComponent meta={metadata} />
      <Sidebar />
      <div id="wrapper-dashboard">
        <div id="pagee" className="clearfix">
          <Header4 />
        </div>
        <div id="themesflat-content"></div>
        <div className="dashboard-toggle">Show DashBoard</div>
        <AddListing />
      </div>
    </>
  );
}
