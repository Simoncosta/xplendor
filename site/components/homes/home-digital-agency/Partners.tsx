import Image from "next/image";

export default function Partners() {
  return (
    <div className="mxd-section padding-pre-title">
      <div className="mxd-container grid-container">
        {/* Block - Partners Cards Start */}
        <div className="mxd-block">
          <div className="mxd-partners-cards">
            <div className="container-fluid p-0">
              <div className="row g-0">
                {/* item */}
                <div className="col-12 col-md-6 col-xl-3 mxd-partners-cards__item mxd-grid-item animate-card-4">
                  <a className="mxd-partners-cards__inner" href="#">
                    <div className="mxd-partners-cards__logo">
                      <Image
                        alt="Partner Logo"
                        src="/img/brands/mozilla.svg"
                        width={984}
                        height={235}
                      />
                    </div>
                  </a>
                </div>
                {/* item */}
                <div className="col-12 col-md-6 col-xl-3 mxd-partners-cards__item mxd-grid-item animate-card-4">
                  <a className="mxd-partners-cards__inner" href="#">
                    <div className="mxd-partners-cards__logo">
                      <Image
                        alt="Partner Logo"
                        src="/img/brands/envato.svg"
                        width={700}
                        height={169}
                      />
                    </div>
                  </a>
                </div>
                {/* item */}
                <div className="col-12 col-md-6 col-xl-3 mxd-partners-cards__item mxd-grid-item animate-card-4">
                  <a className="mxd-partners-cards__inner" href="#">
                    <div className="mxd-partners-cards__logo">
                      <Image
                        alt="Partner Logo"
                        src="/img/brands/behance.svg"
                        width={2499}
                        height={464}
                      />
                    </div>
                  </a>
                </div>
                {/* item */}
                <div className="col-12 col-md-6 col-xl-3 mxd-partners-cards__item mxd-grid-item animate-card-4">
                  <a className="mxd-partners-cards__inner" href="#">
                    <div className="mxd-partners-cards__logo">
                      <Image
                        alt="Partner Logo"
                        src="/img/brands/dribbble.svg"
                        width={917}
                        height={258}
                      />
                    </div>
                  </a>
                </div>
                {/* item */}
                <div className="col-12 col-md-6 col-xl-3 mxd-partners-cards__item mxd-grid-item animate-card-4">
                  <a className="mxd-partners-cards__inner" href="#">
                    <div className="mxd-partners-cards__logo">
                      <Image
                        alt="Partner Logo"
                        src="/img/brands/codeninja.svg"
                        width={223}
                        height={34}
                      />
                    </div>
                  </a>
                </div>
                {/* item */}
                <div className="col-12 col-md-6 col-xl-3 mxd-partners-cards__item mxd-grid-item animate-card-4">
                  <a className="mxd-partners-cards__inner" href="#">
                    <div className="mxd-partners-cards__logo">
                      <Image
                        alt="Partner Logo"
                        src="/img/brands/udemy.svg"
                        width={631}
                        height={237}
                      />
                    </div>
                  </a>
                </div>
                {/* item */}
                <div className="col-12 col-md-6 col-xl-3 mxd-partners-cards__item mxd-grid-item animate-card-4">
                  <a className="mxd-partners-cards__inner" href="#">
                    <div className="mxd-partners-cards__logo">
                      <Image
                        alt="Partner Logo"
                        src="/img/brands/angular.svg"
                        width={700}
                        height={169}
                      />
                    </div>
                  </a>
                </div>
                {/* item */}
                <div className="col-12 col-md-6 col-xl-3 mxd-partners-cards__item mxd-grid-item animate-card-4">
                  <a className="mxd-partners-cards__inner" href="#">
                    <div className="mxd-partners-cards__logo">
                      <Image
                        alt="Partner Logo"
                        src="/img/brands/ghostgaming.svg"
                        width={2854}
                        height={1199}
                      />
                    </div>
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
        {/* Block - Partners Cards End */}
      </div>
    </div>
  );
}
