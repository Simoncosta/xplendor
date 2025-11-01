import "rc-slider/assets/index.css";
import "./styles/style.scss";
import "swiper/css/effect-fade";
import "swiper/css/grid";
import "photoswipe/style.css";
import { useEffect } from "react";
import BackToTop from "@/components/common/BacktoTop";

import Login from "@/components/modals/Login";
import SignUp from "@/components/modals/SignUp";
import { Route, Routes, useLocation } from "react-router-dom";
import WOW from "./utlis/wow";
import ScrollTopBehaviour from "./components/common/ScrollToTopBehaviour";
import HomePage1 from "./pages/page";
import HomePage2 from "./pages/homes/home02";
import HomePage3 from "./pages/homes/home03";
import HomePage4 from "./pages/homes/home04";
import HomePage5 from "./pages/homes/home05";
import HomePage6 from "./pages/homes/home06";
import HomePage7 from "./pages/homes/home07";
import HomePage8 from "./pages/homes/home08";
import HomePage10 from "./pages/homes/home10";
import HomePage9 from "./pages/homes/home09";
import ListingListPage from "./pages/car-listings/listing-list";
import ListingGridPage from "./pages/car-listings/listing-grid";
import ListingGridPage2 from "./pages/car-listings/listing-grid2";
import ListingGridMapPage from "./pages/car-listings/listing-grid-map";
import ListingListMapPage from "./pages/car-listings/listing-list-map";
import BlogListingDetailsPage1 from "./pages/car-details/listing-detail-v1";
import BlogListingDetailsPage2 from "./pages/car-details/listing-detail-v2";
import BlogListingDetailsPage3 from "./pages/car-details/listing-detail-v3";
import BlogListingDetailsPage4 from "./pages/car-details/listing-detail-v4";
import BlogListingDetailsPage5 from "./pages/car-details/listing-detail-v5";
import AboutUsPage from "./pages/other-pages/about-us";
import FaqPage from "./pages/other-pages/faq";
import PricingPage from "./pages/other-pages/pricing";
import ComparePage from "./pages/other-pages/compare";
import DealerListingPage from "./pages/dealer/dealer-listing";
import DealerDetailPage from "./pages/dealer/dealer-detail";
import SaleAgentsPage from "./pages/agents/sale-agents";
import SaleAgentDetailsPage from "./pages/agents/sale-agents-detail";
import BlogPage from "./pages/blogs/blog";
import BlogGridPage from "./pages/blogs/blog-grid";
import BlogDetailsPage from "./pages/blogs/blog-detail";
import DashboardPage from "./pages/dashboard/dashboard";
import MyListingPage from "./pages/dashboard/my-listing";
import MyProfilePage from "./pages/dashboard/my-profile";
import MessagePage from "./pages/dashboard/message";
import MyReviewPage from "./pages/dashboard/my-review";
import MyFavoritePage from "./pages/dashboard/my-favorite";
import ChangePassPage from "./pages/dashboard/change-password";
import ContactPage from "./pages/other-pages/contact";
import AddListingPage from "./pages/dashboard/add-listing";
function App() {
  useEffect(() => {
    if (typeof window !== "undefined") {
      // Import the script only on the client side
      import("bootstrap/dist/js/bootstrap.esm").then(() => {
        // Module is imported, you can access any exported functionality if
      });
    }
  }, []);
  const { pathname } = useLocation();
  useEffect(() => {
    const nav = document.querySelector(".header-lower");
    if (nav) {
      const headerHeight = nav.offsetHeight;

      // Create a placeholder div to maintain layout when nav is fixed
      const injectSpace = document.createElement("div");
      injectSpace.style.height = `${headerHeight}px`;
      injectSpace.classList.add("header-lower-after-div");
      nav.after(injectSpace);
      injectSpace.style.display = "none";
    }
    const handleScroll = () => {
      const nav = document.querySelector(".header-lower");

      if (document.querySelector(".header-fixed")) {
        const afterDiv = document.querySelector(".header-lower-after-div");
        if (nav && afterDiv) {
          if (window.scrollY > 200) {
            nav.classList.add("is-fixed");
            afterDiv.style.display = "block";
          } else {
            nav.classList.remove("is-fixed");
            afterDiv.style.display = "none";
          }

          if (window.scrollY > 300) {
            nav.classList.add("is-small");
          } else {
            nav.classList.remove("is-small");
          }
        }
      }
    };

    // Add scroll event listener
    window.addEventListener("scroll", handleScroll);

    // Cleanup event listener on component unmount
    return () => {
      window.removeEventListener("scroll", handleScroll);
    };
  }, [pathname]);
  useEffect(() => {
    const wow = new WOW({
      mobile: false,
      live: false,
    });
    wow.init();
  }, [pathname]);
  return (
    <>
      <div id="wrapper">
        <div id="pagee" className="clearfix">
          <Routes>
            <Route path="/">
              <Route index element={<HomePage1 />} />
              <Route path="home02" element={<HomePage2 />} />
              <Route path="home03" element={<HomePage3 />} />
              <Route path="home04" element={<HomePage4 />} />
              <Route path="home05" element={<HomePage5 />} />
              <Route path="home06" element={<HomePage6 />} />
              <Route path="home07" element={<HomePage7 />} />
              <Route path="home08" element={<HomePage8 />} />
              <Route path="home09" element={<HomePage9 />} />
              <Route path="home10" element={<HomePage10 />} />
              <Route path="listing-list" element={<ListingListPage />} />
              <Route path="listing-grid" element={<ListingGridPage />} />
              <Route path="listing-grid2" element={<ListingGridPage2 />} />
              <Route path="listing-grid-map" element={<ListingGridMapPage />} />
              <Route path="listing-list-map" element={<ListingListMapPage />} />
              <Route
                path="listing-detail-v1/:id"
                element={<BlogListingDetailsPage1 />}
              />
              <Route
                path="listing-detail-v2/:id"
                element={<BlogListingDetailsPage2 />}
              />
              <Route
                path="listing-detail-v3/:id"
                element={<BlogListingDetailsPage3 />}
              />
              <Route
                path="listing-detail-v4/:id"
                element={<BlogListingDetailsPage4 />}
              />
              <Route
                path="listing-detail-v5/:id"
                element={<BlogListingDetailsPage5 />}
              />
              <Route path="about-us" element={<AboutUsPage />} />
              <Route path="faq" element={<FaqPage />} />
              <Route path="pricing" element={<PricingPage />} />
              <Route path="compare" element={<ComparePage />} />
              <Route path="dealer-listing" element={<DealerListingPage />} />
              <Route path="dealer-detail/:id" element={<DealerDetailPage />} />
              <Route path="sale-agents" element={<SaleAgentsPage />} />
              <Route
                path="sale-agents-detail/:id"
                element={<SaleAgentDetailsPage />}
              />
              <Route path="blog" element={<BlogPage />} />
              <Route path="blog-grid" element={<BlogGridPage />} />
              <Route path="blog-detail/:id" element={<BlogDetailsPage />} />

              <Route path="dashboard" element={<DashboardPage />} />
              <Route path="my-listing" element={<MyListingPage />} />
              <Route path="my-favorite" element={<MyFavoritePage />} />
              <Route path="message" element={<MessagePage />} />
              <Route path="my-review" element={<MyReviewPage />} />
              <Route path="my-profile" element={<MyProfilePage />} />
              <Route path="change-password" element={<ChangePassPage />} />
              <Route path="add-listing" element={<AddListingPage />} />
              <Route path="contact" element={<ContactPage />} />
            </Route>
          </Routes>
        </div>
      </div>
      <Login />
      <SignUp />
      <BackToTop />
      <ScrollTopBehaviour />
    </>
  );
}

export default App;
