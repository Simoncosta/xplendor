export default function BlogSearch() {
  return (
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
  );
}
