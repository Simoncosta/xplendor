import { toggleItems } from "@/data/faqs";
import { useEffect, useRef, useState } from "react";

export default function Accordion({
  faqData = toggleItems,
  parentClass = "flat-toggle style-2",
}) {
  const parentRefs = useRef([]);
  const questionRefs = useRef([]);
  const answerRefs = useRef([]);
  const [currentIndex, setCurrentIndex] = useState(0);
  useEffect(() => {
    questionRefs.current.forEach((el) => {
      el.classList.remove("active");
    });
    parentRefs.current.forEach((el) => {
      el.classList.remove("active");
    });
    answerRefs.current.forEach((el) => {
      el.style.height = "0px";
      el.style.overflow = "hidden";
      el.style.transition = "all 0.5s ease-in-out";
      //   el.style.marginTop = "0px";
    });
    if (currentIndex !== -1) {
      questionRefs.current[currentIndex].classList.add("active");
      parentRefs.current[currentIndex].classList.add("active");
      const element = answerRefs.current[currentIndex];
      element.style.height = element.scrollHeight + "px";
      element.style.overflow = "hidden";
      element.style.transition = "all 0.5s ease-in-out";
      //   element.style.marginTop = "20px";
    }
  }, [currentIndex]);

  return (
    <>
      {faqData.map((item, index) => (
        <div
          ref={(el) => (parentRefs.current[index] = el)}
          className={`${currentIndex == index ? "activ" : ""} ${parentClass} `}
          onClick={() => {
            setCurrentIndex((pre) => (pre == index ? -1 : index));
          }}
          key={index}
        >
          <div
            className="toggle-title flex align-center"
            role="button"
            aria-disabled="false"
            ref={(el) => (questionRefs.current[index] = el)}
          >
            <h5 className="fw-6">{item.title}</h5>
            <div className="btn-toggle" />
          </div>
          <div
            className="toggle-content section-desc"
            ref={(el) => (answerRefs.current[index] = el)}
          >
            <p className="texts">{item.content}</p>
          </div>
        </div>
      ))}
    </>
  );
}
