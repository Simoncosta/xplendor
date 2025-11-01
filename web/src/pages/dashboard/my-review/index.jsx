import MyReviews from "@/components/dashboard/MyReviews";
import Sidebar from "@/components/dashboard/Sidebar";
import Header4 from "@/components/headers/Header4";
import React from "react";

import MetaComponent from "@/components/common/MetaComponent";
const metadata = {
  title:
    "My Review || AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
  description: "AutoDeal - Car Dealer, Rental & Listing Reactjs Template",
};
export default function MyReviewPage() {
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
        <MyReviews />
      </div>
    </>
  );
}
