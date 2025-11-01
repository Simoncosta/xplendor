import { useEffect } from "react";

const SidebarToggleButton = () => {
  useEffect(() => {
    // Vanilla JS implementation of sidebar_mobie

    const btnSidebarMobieFilter = document.querySelector(
      ".btn-siderbar-mobie-filter"
    );
    const listingSidebar = document.querySelector(".listing-sidebar");
    const overlaySidebarMobie = document.querySelector(
      ".overlay-siderbar-mobie"
    );

    if (btnSidebarMobieFilter && listingSidebar && overlaySidebarMobie) {
      const toggleSidebar = () => {
        listingSidebar.classList.toggle("open");
        overlaySidebarMobie.classList.toggle("open");
      };

      btnSidebarMobieFilter.addEventListener("click", toggleSidebar);
      overlaySidebarMobie.addEventListener("click", toggleSidebar);

      // Cleanup event listeners when component unmounts
      return () => {
        btnSidebarMobieFilter.removeEventListener("click", toggleSidebar);
        overlaySidebarMobie.removeEventListener("click", toggleSidebar);
      };
    }

    return () => {
      btnSidebarMobieFilter.removeEventListener("click", toggleSidebar);
      overlaySidebarMobie.removeEventListener("click", toggleSidebar);
    };
  }, []);

  return (
    <div className="btn-siderbar-mobie-filter">
      <i className="icon-autodeal-list" />
    </div>
  );
};

export default SidebarToggleButton;
