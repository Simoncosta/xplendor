import React from "react";
import BlogCategories from "./BlogCategories";
import FeaturedItems from "./FeaturedItems";
import NewsLetter from "./NewsLetter";
import PopulerTags from "./PopulerTags";
export default function BlogSidebar() {
  return (
    <aside className="side-bar side-bar-1 side-blog">
      <div className="inner-side-bar">
        <div className="widget-rent">
          <div className="flat-tabs style2">
            <div className="form-s2">
              <form onSubmit={(e) => e.preventDefault()}>
                <div className="wd-find-select">
                  <h3 className="widget-title title-search">Search blog</h3>
                  <div className="form-group-1 search-form form-style2 relative">
                    <i className="icon-autodeal-search" />
                    <input
                      type="search"
                      className="search-field"
                      placeholder="Search..."
                      defaultValue=""
                      name="s"
                      title="Search for"
                      required
                    />
                  </div>
                </div>
              </form>
              {/* End Job  Search Form*/}
            </div>
          </div>
        </div>
        <BlogCategories />
        <FeaturedItems />
        <NewsLetter />
        <PopulerTags />
      </div>
    </aside>
  );
}
