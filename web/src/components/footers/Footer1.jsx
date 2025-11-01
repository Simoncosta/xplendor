import React, { useEffect, useRef, useState } from "react";

import emailjs from "@emailjs/browser";
import { Link } from "react-router-dom";
import { footerData } from "@/data/footerLinks";
export default function Footer1() {
  const formRef = useRef();
  const [success, setSuccess] = useState(true);
  const [showMessage, setShowMessage] = useState(false);

  const handleShowMessage = () => {
    setShowMessage(true);
    setTimeout(() => {
      setShowMessage(false);
    }, 2000);
  };

  const sendMail = (e) => {
    e.preventDefault();
    emailjs
      .sendForm("service_noj8796", "template_fs3xchn", formRef.current, {
        publicKey: "iG4SCmR-YtJagQ4gV",
      })
      .then((res) => {
        if (res.status === 200) {
          setSuccess(true);
          handleShowMessage();

          formRef.current.reset();
        } else {
          setSuccess(false);
          handleShowMessage();
        }
      })
      .catch((err) => {
        console.log(err);
      });
  };
  useEffect(() => {
    const headings = document.querySelectorAll(".footer-heading-mobie");

    const toggleOpen = (event) => {
      const parent = event.target.closest(".footer-col-block");
      const content = parent.querySelector(".tf-collapse-content");

      if (parent.classList.contains("open")) {
        parent.classList.remove("open");
        content.style.height = "0px";
        content.style.padding = "0px 0px";
      } else {
        parent.classList.add("open");
        content.style.height = content.scrollHeight + 10 + "px";
        content.style.padding = "10px 0px";
      }
    };

    headings.forEach((heading) => {
      heading.addEventListener("click", toggleOpen);
    });

    // Clean up event listeners when the component unmounts
    return () => {
      headings.forEach((heading) => {
        heading.removeEventListener("click", toggleOpen);
      });
    };
  }, []); // Empty dependency array means this will run only once on mount
  return (
    <footer id="footer" className="clearfix home">
      <div className="container">
        <div className="footer-top">
          <div className="row">
            <div className="col-lg-3 col-6">
              <div className="box-footer-top flex-three">
                <div className="icon">
                  <svg
                    width={70}
                    height={70}
                    viewBox="0 0 70 70"
                    fill="none"
                    xmlns="http://www.w3.org/2000/svg"
                  >
                    <path
                      d="M39.6766 29.0873C39.9413 29.2638 40.2943 29.1756 40.6472 28.9991C40.9119 28.8226 41.0884 28.4697 41.0002 28.1167L40.1178 22.9108L43.9119 19.2932C44.1766 19.0285 44.2649 18.6756 44.1766 18.4108C44.0884 18.0579 43.8237 17.8814 43.4708 17.7932L38.2649 16.9991L35.9708 12.3226C35.7061 11.705 34.6472 11.705 34.3825 12.3226L32.0884 16.9991L26.8825 17.7932C26.5296 17.8814 26.2649 18.0579 26.1766 18.4108C26.0884 18.7638 26.1766 19.1167 26.4413 19.2932L30.2355 22.9108L29.3531 28.1167C29.2649 28.4697 29.4413 28.8226 29.7061 28.9991C29.9708 29.1756 30.3237 29.2638 30.6766 29.0873L35.3531 26.6167L39.6766 29.0873ZM31.1178 26.705L31.8237 22.8226C31.9119 22.5579 31.7355 22.205 31.559 22.0285L28.7355 19.2932L32.6178 18.7638C32.8825 18.7638 33.1472 18.4991 33.3237 18.3226L35.0884 14.7932L36.8531 18.3226C36.9413 18.5873 37.2061 18.7638 37.559 18.7638L41.4413 19.2932L38.6178 22.0285C38.4413 22.205 38.3531 22.5579 38.3531 22.8226L39.059 26.705L35.6178 24.852C35.5296 24.7638 35.3531 24.7638 35.1766 24.7638C35.0002 24.7638 34.9119 24.7638 34.7355 24.852L31.1178 26.705Z"
                      fill="currentColor"
                    />
                    <path
                      d="M59.6176 62.3534H49.0294V54.2358C49.0294 53.7063 48.6765 53.3534 48.1471 53.3534H44.0882V48.8534C44.0882 48.324 43.7353 47.9711 43.2059 47.9711H38.2647V38.0005C38.2647 37.9122 38.4412 37.7358 38.5294 37.7358C42.7647 36.5005 46.5588 33.0593 49.0294 28.1181C54.6765 25.1181 58.1176 19.2946 58.1176 12.8534V11.7063C58.1176 11.1769 57.7647 10.824 57.2353 10.824H52.4706C52.4706 9.41222 52.3824 7.55928 52.2941 6.67693C52.2059 6.23575 51.8529 5.88281 51.4118 5.88281H18.5C18.0588 5.88281 17.7059 6.23575 17.6176 6.67693C17.5294 7.55928 17.4412 9.41222 17.4412 10.824H12.5882C12.0588 10.824 11.7059 11.1769 11.7059 11.7063V12.9416C11.7059 19.471 15.1471 25.2946 20.7941 28.2063C23.3529 33.1475 27.0588 36.5005 31.2941 37.824C31.4706 37.824 31.5588 38.0005 31.5588 38.0887V48.0593H26.6176C26.0882 48.0593 25.7353 48.4122 25.7353 48.9416V53.4416H21.6765C21.1471 53.4416 20.7941 53.7946 20.7941 54.324V62.3534H10.3824C9.85294 62.3534 9.5 62.7063 9.5 63.2358C9.5 63.7652 9.85294 64.1181 10.3824 64.1181H59.6176C60.1471 64.1181 60.5 63.7652 60.5 63.2358C60.5 62.7063 60.0588 62.3534 59.6176 62.3534ZM56.5294 12.9416C56.5294 17.8828 54.2353 22.471 50.4412 25.2946C51.8529 21.5887 52.6471 17.2652 52.6471 12.5887H56.5294V12.9416ZM13.4706 12.9416V12.5887H17.3529C17.3529 17.1769 18.1471 21.5005 19.5588 25.2063C15.7647 22.3828 13.4706 17.8828 13.4706 12.9416ZM31.9118 36.0593C28.0294 34.9122 24.5882 31.7358 22.2941 27.1475C20.2647 23.0887 19.1176 18.0593 19.1176 12.5887C19.1176 11.3534 19.2059 8.97105 19.2941 7.64752H50.7059C50.7941 9.05928 50.8824 11.4416 50.8824 12.5887C50.8824 18.0593 49.8235 23.0887 47.7941 27.1475C45.5 31.7358 42.0588 34.9122 38.1765 36.0593C37.2059 36.324 36.5882 37.1181 36.5882 38.0005V47.9711H33.5V38.0005C33.4118 37.1181 32.7941 36.324 31.9118 36.0593ZM27.5882 49.824H42.3235V53.4416H27.5882V49.824ZM22.7353 62.3534V55.1181H47.3529V62.3534H22.7353Z"
                      fill="currentColor"
                    />
                  </svg>
                </div>
                <div className="content">
                  <h5 className="title">Top 1 Americas</h5>
                  <p>Largest Auto portal</p>
                </div>
              </div>
            </div>
            <div className="col-lg-3 col-6">
              <div className="box-footer-top flex-three">
                <div className="icon">
                  <svg
                    width={70}
                    height={70}
                    viewBox="0 0 70 70"
                    fill="none"
                    xmlns="http://www.w3.org/2000/svg"
                  >
                    <mask
                      id="mask0_4570_8679"
                      style={{ maskType: "luminance" }}
                      maskUnits="userSpaceOnUse"
                      x={5}
                      y={5}
                      width={60}
                      height={60}
                    >
                      <path
                        d="M64.3498 64.35V5.65018H5.65V64.35H64.3498Z"
                        fill="currentColor"
                        stroke="currentColor"
                        strokeWidth="1.3"
                      />
                    </mask>
                    <g mask="url(#mask0_4570_8679)">
                      <path
                        d="M47.433 39.4083L39.8609 52.5236C38.3694 55.1068 40.2337 58.3359 43.2167 58.3359H58.3608C61.3437 58.3359 63.2079 55.1068 61.7166 52.5236L54.1444 39.4083C52.6529 36.8252 48.9244 36.8252 47.433 39.4083Z"
                        stroke="currentColor"
                        strokeWidth="1.3"
                        strokeMiterlimit={10}
                        strokeLinecap="round"
                        strokeLinejoin="round"
                      />
                      <path
                        d="M50.7881 43.7964V50.0078"
                        stroke="currentColor"
                        strokeWidth="1.3"
                        strokeMiterlimit={10}
                        strokeLinecap="round"
                        strokeLinejoin="round"
                      />
                      <path
                        d="M50.7881 52.7964V53.0078"
                        stroke="currentColor"
                        strokeWidth="1.3"
                        strokeMiterlimit={10}
                        strokeLinecap="round"
                        strokeLinejoin="round"
                      />
                      <path
                        d="M32.2615 48.7305H42.0511L47.4323 39.4094C48.9241 36.8254 52.6531 36.8254 54.1448 39.4094L59.2858 48.3145C61.9354 47.3488 63.828 44.807 63.828 41.8235V18.5747C63.828 14.7591 60.7354 11.6665 56.9198 11.6665H13.0801C9.26444 11.6665 6.17188 14.7591 6.17188 18.5747V41.8235C6.17188 45.6379 9.26444 48.7305 13.0801 48.7305H21.8518"
                        stroke="currentColor"
                        strokeWidth="1.3"
                        strokeMiterlimit={10}
                        strokeLinecap="round"
                        strokeLinejoin="round"
                      />
                      <path
                        d="M40.7832 17.3556V28.0535C40.9016 28.0738 44.5325 28.0535 44.5325 28.0535"
                        stroke="currentColor"
                        strokeWidth="1.3"
                        strokeMiterlimit={10}
                        strokeLinecap="round"
                        strokeLinejoin="round"
                      />
                      <path
                        d="M50.3203 17.6839V27.8945"
                        stroke="currentColor"
                        strokeWidth="1.3"
                        strokeMiterlimit={10}
                        strokeLinecap="round"
                        strokeLinejoin="round"
                      />
                      <path
                        d="M57.459 22.8674C57.459 25.7302 55.8701 27.9956 53.3492 28.0395C52.5101 28.0543 50.3414 28.0625 50.3414 28.0625C50.3414 28.0625 50.3278 24.299 50.3278 22.8559C50.3278 21.6708 50.3203 17.6722 50.3203 17.6722H53.263C56.028 17.6722 57.459 20.0045 57.459 22.8674Z"
                        stroke="currentColor"
                        strokeWidth="1.3"
                        strokeMiterlimit={10}
                        strokeLinecap="round"
                        strokeLinejoin="round"
                      />
                      <path
                        d="M18.7797 18.4562C18.7797 18.4562 16.8584 16.8412 14.5928 17.5237C12.5127 18.1505 12.2217 20.5492 13.7267 21.5304C13.7267 21.5304 15.2034 22.1892 16.8415 22.7934C20.7844 24.2478 19.086 28.0625 15.9118 28.0625C14.3223 28.0625 12.9881 27.3664 12.1807 26.4753"
                        stroke="currentColor"
                        strokeWidth="1.3"
                        strokeMiterlimit={10}
                        strokeLinecap="round"
                        strokeLinejoin="round"
                      />
                      <path
                        d="M25.1875 33.9872V39.8141C25.1875 40.8064 25.6797 41.4745 26.5159 42.0087C26.9233 42.2689 27.4375 42.4543 28.0767 42.4551C28.6303 42.4559 29.093 42.3277 29.4756 42.135C30.45 41.6443 31.0715 40.8991 31.0715 39.8079V33.9872"
                        stroke="currentColor"
                        strokeWidth="1.3"
                        strokeMiterlimit={10}
                        strokeLinecap="round"
                        strokeLinejoin="round"
                      />
                      <path
                        d="M35.7559 33.9883H40.4322"
                        stroke="currentColor"
                        strokeWidth="1.3"
                        strokeMiterlimit={10}
                        strokeLinecap="round"
                        strokeLinejoin="round"
                      />
                      <path
                        d="M38.084 34.2576V42.4551"
                        stroke="currentColor"
                        strokeWidth="1.3"
                        strokeMiterlimit={10}
                        strokeLinecap="round"
                        strokeLinejoin="round"
                      />
                      <path
                        d="M35.0429 22.8692C35.0429 25.8258 32.646 28.2227 29.6894 28.2227C26.7328 28.2227 24.3359 25.8258 24.3359 22.8692C24.3359 19.9126 26.7328 17.5157 29.6894 17.5157C32.646 17.5157 35.0429 19.9126 35.0429 22.8692Z"
                        stroke="currentColor"
                        strokeWidth="1.3"
                        strokeMiterlimit={10}
                        strokeLinecap="round"
                        strokeLinejoin="round"
                      />
                      <path
                        d="M20.6485 38.2212C20.6485 40.5596 18.7529 42.4551 16.4145 42.4551C14.0763 42.4551 12.1807 40.5596 12.1807 38.2212C12.1807 35.8829 14.0763 33.9873 16.4145 33.9873C18.7529 33.9873 20.6485 35.8829 20.6485 38.2212Z"
                        stroke="currentColor"
                        strokeWidth="1.3"
                        strokeMiterlimit={10}
                        strokeLinecap="round"
                        strokeLinejoin="round"
                      />
                    </g>
                  </svg>
                </div>
                <div className="content">
                  <h5 className="title">Car Sold</h5>
                  <p>Every 5 minute</p>
                </div>
              </div>
            </div>
            <div className="col-lg-3 col-6">
              <div className="box-footer-top flex-three">
                <div className="icon">
                  <svg
                    width={70}
                    height={70}
                    viewBox="0 0 70 70"
                    fill="none"
                    xmlns="http://www.w3.org/2000/svg"
                  >
                    <path
                      d="M27.1865 46.1337H27.1866C27.7137 46.1337 28.2041 45.7996 28.3797 45.2731L36.666 20.4139C36.666 20.4139 36.666 20.4139 36.666 20.4139C36.8856 19.7548 36.5294 19.0425 35.8705 18.8228L27.1865 46.1337ZM27.1865 46.1337C27.0553 46.1337 26.9212 46.1129 26.7885 46.0687C26.1296 45.849 25.7735 45.1367 25.9931 44.4777C25.9931 44.4777 25.9931 44.4776 25.9931 44.4776L34.2794 19.6183M27.1865 46.1337L34.2794 19.6183M34.2794 19.6183C34.2794 19.6183 34.2794 19.6183 34.2794 19.6183C34.4991 18.9594 35.2115 18.6032 35.8704 18.8228L34.2794 19.6183ZM46.0466 32.4459C46.0466 35.0775 43.9048 37.2193 41.2732 37.2193C38.6415 37.2193 36.4997 35.0775 36.4997 32.4459C36.4997 29.8142 38.6415 27.6724 41.2732 27.6724C43.9048 27.6724 46.0466 29.8142 46.0466 32.4459ZM43.531 32.4459C43.531 31.2005 42.5186 30.1881 41.2732 30.1881C40.0278 30.1881 39.0154 31.2005 39.0154 32.4459C39.0154 33.6913 40.0278 34.7037 41.2732 34.7037C42.5186 34.7037 43.531 33.6913 43.531 32.4459ZM16.6123 32.4459C16.6123 29.8142 18.7541 27.6724 21.3857 27.6724C24.0175 27.6724 26.1592 29.8142 26.1592 32.4459C26.1592 35.0775 24.0174 37.2193 21.3857 37.2193C18.7541 37.2193 16.6123 35.0775 16.6123 32.4459ZM19.1279 32.4459C19.1279 33.6913 20.1403 34.7037 21.3857 34.7037C22.6313 34.7037 23.6436 33.6913 23.6436 32.4459C23.6436 31.2005 22.6311 30.1881 21.3857 30.1881C20.1403 30.1881 19.1279 31.2005 19.1279 32.4459Z"
                      fill="currentColor"
                      stroke="#24272C"
                    />
                    <path
                      d="M57.9944 18.7581L58.0237 18.5531L58.2629 16.8793C58.2905 16.686 58.2727 16.489 58.211 16.3037C58.1492 16.1185 58.0452 15.9501 57.9071 15.8121L58.2606 15.4585L57.9071 15.812L54.1782 12.0831L53.8246 11.7296L54.1782 11.376L57.907 7.64713L57.907 7.64709C58.3982 7.15601 58.3982 6.35957 57.907 5.86833C57.4159 5.37724 56.6194 5.3772 56.1281 5.86836L52.3994 9.59723L52.0458 9.95079L51.6923 9.59724L47.9635 5.86851L57.9944 18.7581ZM57.9944 18.7581L58.1187 18.9238M57.9944 18.7581L58.1187 18.9238M58.1187 18.9238L64.2484 27.0968C64.4117 27.3145 64.5 27.5794 64.5 27.8515V58.5547C64.5 61.8325 61.8325 64.5 58.5547 64.5H32.7734C29.4956 64.5 26.8281 61.8325 26.8281 58.5547V57.8419V57.5603L26.5872 57.4143C26.1804 57.1678 25.8048 56.8733 25.4684 56.537C25.4684 56.537 25.4684 56.537 25.4683 56.537L7.23837 38.3069L6.88481 38.6604L7.23836 38.3069C4.92055 35.9892 4.92055 32.2166 7.23836 29.8989L6.88747 29.548L7.23837 29.8989L28.7831 8.35423L28.7831 8.35421M58.1187 18.9238L28.7831 8.35421M28.7831 8.35421C28.9755 8.16176 29.2252 8.03692 29.4946 7.99842L46.8958 5.51256C46.8958 5.51256 46.8959 5.51255 46.8959 5.51255C46.896 5.51254 46.896 5.51253 46.8961 5.51252C47.0894 5.48506 47.2864 5.50291 47.4716 5.56465C47.6569 5.6264 47.8252 5.73036 47.9634 5.86833L28.7831 8.35421ZM30.4303 10.406L30.2653 10.4295L30.1475 10.5474L9.01716 31.6777C7.67955 33.0153 7.67955 35.1905 9.01716 36.5281L27.2472 54.7582L27.2472 54.7583C27.8943 55.4052 28.7572 55.7627 29.6724 55.7627C30.5876 55.7627 31.4504 55.4054 32.0976 54.7582L53.2278 33.6279L53.3456 33.5101L53.3692 33.3451L55.648 17.3938L55.6834 17.1463L55.5066 16.9695L52.3991 13.862L52.0456 13.5085L51.692 13.862L49.2061 16.3479L48.8526 16.7015L49.2061 17.055L50.4492 18.2981C50.9404 18.7893 50.9403 19.5857 50.4492 20.0768L50.4491 20.0769C50.2035 20.3226 49.8824 20.4453 49.5598 20.4453C49.2373 20.4453 48.9162 20.3226 48.6705 20.0768L43.6986 15.1049C43.2073 14.6137 43.2074 13.8173 43.6985 13.3262C44.1898 12.835 44.9863 12.8351 45.4774 13.3261L46.7204 14.5691L47.0739 14.9227L47.4275 14.5691L49.9134 12.0833L50.2669 11.7297L49.9134 11.3761L46.8059 8.26868L46.6291 8.09191L46.3816 8.12726L30.4303 10.406ZM29.8387 58.2712L29.3155 58.2857L29.3539 58.8076C29.4841 60.5812 30.9665 61.9843 32.7734 61.9843H58.5547C60.4462 61.9843 61.9843 60.4462 61.9843 58.5547V28.4375V28.2708L61.8843 28.1375L58.2154 23.2457L57.4912 22.2801L57.3205 23.475L55.7768 34.2808C55.7383 34.5502 55.6134 34.7999 55.421 34.9923L55.7745 35.3459L55.4209 34.9924L33.8763 56.537L34.2298 56.8906L33.8762 56.5371C32.7601 57.6533 31.3067 58.2305 29.8387 58.2712Z"
                      fill="currentColor"
                      stroke="#24272C"
                    />
                  </svg>
                </div>
                <div className="content">
                  <h5 className="title">Offers</h5>
                  <p>Stay updated pay less</p>
                </div>
              </div>
            </div>
            <div className="col-lg-3 col-6">
              <div className="box-footer-top flex-three">
                <div className="icon">
                  <svg
                    width={70}
                    height={70}
                    viewBox="0 0 70 70"
                    fill="none"
                    xmlns="http://www.w3.org/2000/svg"
                  >
                    <path
                      d="M36.0827 14.8407L36.0837 14.8396C36.7357 14.1411 37.1687 13.6191 37.1687 12.8281C37.1687 11.6395 36.1886 10.6594 35 10.6594C33.8114 10.6594 32.8312 11.6395 32.8312 12.8281C32.8312 13.2333 32.4989 13.5656 32.0938 13.5656C31.6886 13.5656 31.3563 13.2333 31.3563 12.8281C31.3563 10.8292 33.0011 9.18438 35 9.18438C36.9989 9.18438 38.6437 10.8292 38.6437 12.8281C38.6437 14.1527 37.954 14.9284 37.2807 15.6856C37.2359 15.7359 37.1913 15.7861 37.1469 15.8364C37.1248 15.8614 37.1028 15.8864 37.0807 15.9113C36.4023 16.6787 35.7375 17.4307 35.7375 18.8281C35.7375 19.2333 35.4052 19.5656 35 19.5656C34.5948 19.5656 34.2625 19.2333 34.2625 18.8281C34.2625 16.8004 35.2453 15.7246 36.0827 14.8407Z"
                      fill="currentColor"
                      stroke="#24272C"
                      strokeWidth="0.4"
                    />
                    <path
                      d="M35.8781 22.1094C35.8781 22.5943 35.485 22.9875 35 22.9875C34.515 22.9875 34.1219 22.5943 34.1219 22.1094C34.1219 21.6244 34.515 21.2313 35 21.2313C35.485 21.2313 35.8781 21.6244 35.8781 22.1094Z"
                      fill="currentColor"
                      stroke="#24272C"
                      strokeWidth="0.4"
                    />
                    <path
                      d="M53.4724 29.9694L53.4721 29.9695L53.4774 29.9801L64.721 52.4673C64.7677 52.6079 64.8 52.7133 64.8 52.8125C64.8 59.4052 59.4052 64.8 52.8125 64.8C46.2198 64.8 40.825 59.4052 40.825 52.8125C40.825 52.7133 40.8573 52.6079 40.904 52.4674L51.4913 31.3396L51.6364 31.05H51.3125H35.9375H35.7375V31.25V34.0625C35.7375 34.4677 35.4052 34.8 35 34.8C34.5948 34.8 34.2625 34.4677 34.2625 34.0625V31.25V31.05H34.0625H18.6875H18.3636L18.5087 31.3396L29.096 52.4674C29.1427 52.6079 29.175 52.7133 29.175 52.8125C29.175 59.4052 23.7802 64.8 17.1875 64.8C10.5948 64.8 5.2 59.4052 5.2 52.8125C5.2 52.7133 5.2323 52.6079 5.27901 52.4673L16.5226 29.9801L16.5229 29.9802L16.5276 29.9694C16.6315 29.7269 16.8785 29.575 17.1875 29.575H34.0625H34.2625V29.375V27.4531V27.2687L34.0787 27.2538C28.416 26.7934 23.95 22.0511 23.95 16.25C23.95 10.173 28.923 5.2 35 5.2C41.077 5.2 46.05 10.173 46.05 16.25C46.05 22.0048 41.5835 26.7934 35.9213 27.2538L35.7375 27.2687V27.4531V29.375V29.575H35.9375H52.8125C53.1215 29.575 53.3685 29.7269 53.4724 29.9694ZM6.92188 53.55H6.70322L6.72267 53.7678C7.20053 59.1199 11.6922 63.325 17.1875 63.325C22.6828 63.325 27.1745 59.1199 27.6523 53.7678L27.6718 53.55H27.4531H6.92188ZM26.9375 52.075H27.2615L27.1163 51.7854L17.3663 32.3323L17.1875 31.9755L17.0087 32.3323L7.2587 51.7854L7.11354 52.075H7.4375H26.9375ZM52.9913 32.3323L52.8125 31.9755L52.6337 32.3323L42.8837 51.7854L42.7385 52.075H43.0625H62.5625H62.8865L62.7413 51.7854L52.9913 32.3323ZM42.5469 53.55H42.3282L42.3477 53.7678C42.8255 59.1199 47.3172 63.325 52.8125 63.325C58.3078 63.325 62.7995 59.1199 63.2773 53.7678L63.2968 53.55H63.0781H42.5469ZM35 6.675C29.7333 6.675 25.425 10.9833 25.425 16.25C25.425 21.5167 29.7333 25.825 35 25.825C40.2667 25.825 44.575 21.5167 44.575 16.25C44.575 10.9833 40.2667 6.675 35 6.675Z"
                      fill="currentColor"
                      stroke="currentColor"
                      strokeWidth="0.4"
                    />
                  </svg>
                </div>
                <div className="content">
                  <h5 className="title">Compare</h5>
                  <p>Decode the right car</p>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div className="footer-main">
          <div className="row">
            {footerData.map((column, index) => (
              <div className="col-lg-3 col-sm-6 col-12" key={index}>
                <div className="widget widget-menu footer-col-block">
                  <div className="footer-heading-desktop">
                    <h4>{column.heading}</h4>
                  </div>
                  <div className="footer-heading-mobie ">
                    <h4>{column.heading}</h4>
                  </div>
                  <ul className="box-menu tf-collapse-content">
                    {column.menuItems.map((item, itemIndex) => (
                      <li key={itemIndex}>
                        <Link to={item.href}>{item.text}</Link>
                      </li>
                    ))}
                  </ul>
                </div>
              </div>
            ))}
            <div className="col-lg-3 col-sm-6 col-12">
              <div className="widget widget-menu widget-form footer-col-block">
                <div className="footer-heading-desktop">
                  <h4>Newsletter</h4>
                </div>
                <div className="footer-heading-mobie">
                  <h4>Newsletter</h4>
                </div>
                <div className="tf-collapse-content">
                  <div
                    className={`tfSubscribeMsg  footer-sub-element ${
                      showMessage ? "active" : ""
                    }`}
                  >
                    {success ? (
                      <p style={{ color: "rgb(52, 168, 83)" }}>
                        You have successfully subscribed.
                      </p>
                    ) : (
                      <p style={{ color: "red" }}>Something went wrong</p>
                    )}
                  </div>
                  <form
                    onSubmit={sendMail}
                    ref={formRef}
                    className="comment-form form-submit"
                    acceptCharset="utf-8"
                  >
                    <p className="font-2">
                      Stay on top of the latest car trends, tips, and tricks for
                      selling your car.
                    </p>
                    <div className="text-wrap clearfix">
                      <fieldset className="email-wrap style-text">
                        <input
                          type="email"
                          className="tb-my-input"
                          name="email"
                          placeholder="Your email address"
                          required
                        />
                      </fieldset>
                    </div>
                    <button
                      name="submit"
                      type="submit"
                      className="button btn-submit-comment btn-1 btn-8"
                    >
                      <span>Send</span>
                    </button>
                  </form>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div className="footer-bottom">
          <div className="row">
            <div className="col-lg-4 col-md-12">
              <div className="logo-footer style box-1">
                <Link to={`/`}>
                  <img
                    className="lazyload"
                    data-src="/assets/images/logo/logo-footer@2x.png"
                    alt="img"
                    width={225}
                    height={40}
                    src="/assets/images/logo/logo-footer@2x.png"
                  />
                </Link>
              </div>
            </div>
            <div className="col-lg-8 col-md-12">
              <div className="footer-bottom-right flex-six flex-wrap">
                <div className="title-bottom center">
                  © 2024 Auto Decar. All rights reserved
                </div>
                <div className="icon-social box-3 text-color-1">
                  <a href="#">
                    <i className="icon-autodeal-facebook" />
                  </a>
                  <a href="#">
                    <i className="icon-autodeal-linkedin" />
                  </a>
                  <a href="#">
                    <i className="icon-autodeal-twitter" />
                  </a>
                  <a href="#">
                    <i className="icon-autodeal-instagram" />
                  </a>
                  <a href="#">
                    <i className="icon-autodeal-youtube" />
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </footer>
  );
}
