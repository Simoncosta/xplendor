import AnimatedButton from "../animation/AnimatedButton";

export default function DetailsHero() {
  return (
    <div className="mxd-section mxd-section-inner-headline padding-default">
      <div className="mxd-container grid-container">
        {/* Block - Inner Page Headline Start */}
        <div className="mxd-block loading-wrap">
          <div className="container-fluid px-0">
            <div className="row gx-0">
              {/* Inner Headline Name Start */}
              <div className="col-12 col-xl-2 mxd-grid-item no-margin">
                <div className="mxd-block__name name-project-link loading__fade">
                  <AnimatedButton
                    text="Project Page"
                    as={"a"}
                    className="btn btn-anim btn-line-small btn-muted slide-right-up"
                    href="#"
                  >
                    <i className="ph ph-arrow-up-right" />
                  </AnimatedButton>
                </div>
              </div>
              {/* Inner Headline Name Start */}
              {/* Inner Headline Content Start */}
              <div className="col-12 col-xl-10 mxd-grid-item no-margin">
                <div className="mxd-block__content">
                  <div className="mxd-block__inner-headline loading__item">
                    <h1 className="inner-headline__title">Studio template</h1>
                  </div>
                </div>
              </div>
              {/* Inner Headline Content End */}
              {/* Inner Headline Aside Start */}
              <div className="col-12" />
              {/* Inner Headline Aside End */}
            </div>
            <div className="row g-0">
              <div className="col-12 col-xl-2" />
              {/* Inner Headline Paragraph & Data Start */}
              <div className="col-12 col-xl-6 mxd-grid-item no-margin">
                <div className="inner-headline__paragraph loading__item">
                  <p>
                    Inspiring ideas, creative insights, and the latest in design
                    and tech. Fueling innovation for your digital journey.
                  </p>
                </div>
                <div className="inner-headline__data">
                  <div className="mxd-data-list">
                    <div className="container-fluid p-0">
                      <div className="row g-0">
                        <div className="col-12 col-md-6 col-xl-5 mxd-data-list__column loading__item">
                          <div className="mxd-data-list__item">
                            <p className="mxd-data-list__name">Client</p>
                            <p className="mxd-data-list__content">Mix Design</p>
                          </div>
                          <div className="mxd-data-list__item">
                            <p className="mxd-data-list__name">Services</p>
                            <p className="mxd-data-list__content">
                              Web development
                            </p>
                          </div>
                        </div>
                        <div className="col-12 col-md-6 col-xl-5 mxd-data-list__column loading__item">
                          <div className="mxd-data-list__item">
                            <p className="mxd-data-list__name">Industries</p>
                            <p className="mxd-data-list__content">
                              HTML template
                            </p>
                          </div>
                          <div className="mxd-data-list__item">
                            <p className="mxd-data-list__name">Date</p>
                            <p className="mxd-data-list__content">
                              January 2025
                            </p>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              {/* Inner Headline Paragraph & Data End */}
              {/* Inner Headline Tags Start */}
              <div className="col-12 col-xl-4 mxd-grid-item no-margin">
                <div className="inner-headline__tags loading__fade">
                  <span className="tag tag-default tag-outline-medium">
                    Web design
                  </span>
                  <span className="tag tag-default tag-outline-medium">
                    UI/UX design
                  </span>
                  <span className="tag tag-default tag-outline-medium">
                    Development
                  </span>
                  <span className="tag tag-default tag-outline-medium">
                    Branding
                  </span>
                </div>
              </div>
            </div>
            {/* Inner Headline Tags End */}
          </div>
        </div>
        {/* Block - Inner Page Headline End */}
      </div>
    </div>
  );
}
