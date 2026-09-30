"use client";
export default function SearchForm() {
  return (
    <div className="widget-search__form">
      <form className="form search-form" onSubmit={(e) => e.preventDefault()}>
        <input id="search" type="search" name="search" placeholder="Search" />
        <button
          className="btn btn-form no-scale btn-absolute-right btn-muted"
          type="submit"
        >
          <i className="ph ph-magnifying-glass" />
        </button>
      </form>
    </div>
  );
}
