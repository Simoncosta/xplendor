import Image from "next/image";
import factsData from "@/data/facts-simple.json";
import Counter from "@/components/common/Counter";

export default function Facts() {
  return (
    <div className="mxd-section padding-default">
      <div className="mxd-container grid-container">
        {/* Block - Statistics Cards Start */}
        <div className="mxd-block">
          <div className="mxd-stats-simple">
            <div className="container-fluid p-0">
              <div className="row g-0 mxd-stats-simple">
                {factsData.map((fact) => (
                  <div
                    key={fact.id}
                    className="col-12 col-lg-3 mxd-stats-simple__item mxd-grid-item"
                  >
                    <div className="mxd-stats-simple__inner animate-card-4">
                      {/* counter */}
                      <div className="mxd-counter">
                        <p
                          id={fact.counterId}
                          className="mxd-stats-number mxd-stats-simple__counter"
                        >
                          <Counter max={fact.number} />
                          {fact.suffix}
                        </p>
                      </div>
                      {/* image */}
                      <Image
                        className="mxd-stats-simple__image"
                        alt="Icon"
                        src={fact.image}
                        width={fact.imageWidth}
                        height={fact.imageHeight}
                      />
                      {/* description */}
                      <p className="mxd-stats-simple__descr t-140 t-bright">
                        {fact.description.split("\n").map((line, index) => (
                          <span key={index}>
                            {line}
                            {index <
                              fact.description.split("\n").length - 1 && <br />}
                          </span>
                        ))}
                      </p>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
        {/* Block - Statistics Cards End */}
      </div>
    </div>
  );
}
