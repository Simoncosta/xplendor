import React from "react";

export default function Messages() {
  return (
    <div className="container">
      <div className="row">
        <div className="col-md-12">
          <div className="content-area">
            <main id="main" className="main-content">
              <div className="tfcl-dashboard">
                <h1 className="admin-title mb-3">Message</h1>
                <div className="tfcl-message">
                  <div className="content-left">
                    <div className="message-user">
                      <div className="message-header">
                        <div className="form-search">
                          <input
                            type="text"
                            className="form-control"
                            name="listing_title"
                            placeholder="Search..."
                            defaultValue=""
                          />
                          <svg
                            xmlns="http://www.w3.org/2000/svg"
                            width={16}
                            height={16}
                            viewBox="0 0 16 16"
                            fill="none"
                          >
                            <path
                              d="M14.7506 14.7506L10.8528 10.8528M10.8528 10.8528C11.9078 9.7979 12.5004 8.36711 12.5004 6.87521C12.5004 5.38331 11.9078 3.95252 10.8528 2.89759C9.7979 1.84265 8.36711 1.25 6.87521 1.25C5.38331 1.25 3.95252 1.84265 2.89759 2.89759C1.84265 3.95252 1.25 5.38331 1.25 6.87521C1.25 8.36711 1.84265 9.7979 2.89759 10.8528C3.95252 11.9078 5.38331 12.5004 6.87521 12.5004C8.36711 12.5004 9.7979 11.9078 10.8528 10.8528Z"
                              stroke="#B6B6B6"
                              strokeWidth="1.5"
                              strokeLinecap="round"
                              strokeLinejoin="round"
                            />
                          </svg>
                        </div>
                      </div>
                    </div>
                    <ul className="list-user-chat">
                      <li>
                        <div className="user-item">
                          <div className="avatar">
                            <img
                              alt="avatar"
                              src="/assets/images/dashboard/chat-avt4.jpg"
                              width={60}
                              height={60}
                            />
                          </div>
                          <div className="content">
                            <div className="inner">
                              <div className="name">Leslie Alexander</div>
                              <span className="date">August 13, 2023</span>
                            </div>
                            <p>Lorem ipsum dolor sit amet, consectetur...</p>
                          </div>
                        </div>
                      </li>
                      <li className="active">
                        <div className="user-item">
                          <div className="avatar">
                            <img
                              alt="avatar"
                              src="/assets/images/dashboard/chat-avt3.jpg"
                              width={60}
                              height={60}
                            />
                          </div>
                          <div className="content">
                            <div className="inner">
                              <div className="name">Arlene McCoy</div>
                              <span className="date">August 13, 2023</span>
                            </div>
                            <p>
                              Proin at massa suscipit sem iaculis dignissim...
                            </p>
                          </div>
                        </div>
                      </li>
                      <li>
                        <div className="user-item">
                          <div className="avatar">
                            <img
                              alt="avatar"
                              src="/assets/images/dashboard/chat-avt2.jpg"
                              width={60}
                              height={60}
                            />
                          </div>
                          <div className="content">
                            <div className="inner">
                              <div className="name">Kristin Watson</div>
                              <span className="date">August 13, 2023</span>
                            </div>
                            <p>Sed imperdiet tortor sed magna tempus, in...</p>
                          </div>
                        </div>
                      </li>
                      <li>
                        <div className="user-item">
                          <div className="avatar">
                            <img
                              alt="avatar"
                              src="/assets/images/dashboard/chat-avt1.jpg"
                              width={60}
                              height={60}
                            />
                          </div>
                          <div className="content">
                            <div className="inner">
                              <div className="name">Cody Fisher</div>
                              <span className="date">August 13, 2023</span>
                            </div>
                            <p>
                              Proin a augue ut leo elementum tincidunt et...
                            </p>
                          </div>
                        </div>
                      </li>
                      <li>
                        <div className="user-item">
                          <div className="avatar">
                            <img
                              alt="avatar"
                              src="/assets/images/dashboard/chat-avt7.jpg"
                              width={60}
                              height={60}
                            />
                          </div>
                          <div className="content">
                            <div className="inner">
                              <div className="name">Arlene McCoy</div>
                              <span className="date">August 13, 2023</span>
                            </div>
                            <p>
                              Fusce tempor urna dictum ante porta pretium...
                            </p>
                          </div>
                        </div>
                      </li>
                      <li>
                        <div className="user-item">
                          <div className="avatar">
                            <img
                              alt="avatar"
                              src="/assets/images/dashboard/chat-avt6.jpg"
                              width={60}
                              height={60}
                            />
                          </div>
                          <div className="content">
                            <div className="inner">
                              <div className="name">Bessie Cooper</div>
                              <span className="date">August 13, 2023</span>
                            </div>
                            <p>
                              Mauris facilisis ipsum elementum nulla iaculis...
                            </p>
                          </div>
                        </div>
                      </li>
                      <li>
                        <div className="user-item">
                          <div className="avatar">
                            <img
                              alt="avatar"
                              src="/assets/images/dashboard/chat-avt5.jpg"
                              width={60}
                              height={60}
                            />
                          </div>
                          <div className="content">
                            <div className="inner">
                              <div className="name">Savannah Nguyen</div>
                              <span className="date">August 13, 2023</span>
                            </div>
                            <p>Lorem ipsum dolor sit amet, consectetur...L</p>
                          </div>
                        </div>
                      </li>
                    </ul>
                  </div>
                  <div className="content-right">
                    <div className="header-inner-chat">
                      <div className="user-infor">
                        <div className="avatar">
                          <img
                            alt="avatar"
                            src="/assets/images/dashboard/chat-avt3.jpg"
                            width={60}
                            height={60}
                          />
                        </div>
                        <div className="content">
                          <div className="inner">
                            <div className="name">Arlene McCoy</div>
                            <span className="nofi">Online</span>
                          </div>
                        </div>
                      </div>
                    </div>
                    <div className="content-inner-chat">
                      <div className="current-user-chat">
                        <div className="chat-text">
                          <p>
                            Dear Mr Williamson, <br />
                            I would like to send you the quotation for the
                            Toyota Fortuner
                            <br />
                            Srp:
                            <br />
                            Downpayment:
                            <br />
                            Monthly payment: <br />
                            If you have more questions, dont hesitate to contact
                            me via this number: 09012345678.
                            <br />
                            Im happy to assist!
                          </p>
                          <div className="date-pushlish mb-3">
                            2:55 PM - Aug 17, 2024
                          </div>
                          <div className="attrach">
                            <img
                              alt="file"
                              src="/assets/images/dashboard/attrach1.png"
                              width={60}
                              height={60}
                            />
                            <img
                              alt="file"
                              src="/assets/images/dashboard/attrach2.png"
                              width={60}
                              height={60}
                            />
                          </div>
                          <div className="date-pushlish mb-3">
                            2:55 PM - Aug 17, 2024
                          </div>
                        </div>
                      </div>
                      <div className="client-chat mb-3">
                        <div className="client-inner">
                          <div className="avatar">
                            <img
                              alt="avatar"
                              src="/assets/images/dashboard/chat-avt3.jpg"
                              width={60}
                              height={60}
                            />
                          </div>
                          <div className="content">
                            <p>Yes</p>
                            <div className="date-pushlish">
                              2:55 PM - Aug 17, 2024
                            </div>
                          </div>
                        </div>
                      </div>
                      <div className="current-user-chat mb-3">
                        <div className="chat-text">
                          <p>
                            Dear Mr Williamson,
                            <br />
                            Thank you for inquiring Toyota Fortuner. My name is
                            Adam, Sales Consultant at Toyota Cubao.
                            <br />
                            If you are still interested in Toyota Fortuner,
                            kindly answer “Yes” for the quotation. For any
                            <br />
                            further assistance, please do not hesitate to
                            contact me via 09012345678 or
                            <br />
                            dvg.ap.ngantt1@gmail.com.
                            <br />
                            Have a great day ahead!
                          </p>
                          <div className="date-pushlish mb-3">
                            2:55 PM - Aug 17, 2024
                          </div>
                        </div>
                      </div>
                      <div className="controller-chat">
                        <div className="form-message">
                          <input
                            type="text"
                            className="form-control"
                            name="listing_title"
                            placeholder="Aa"
                            defaultValue=""
                          />
                          <a href="#">Send</a>
                        </div>
                        <div className="controll">
                          <a href="#" className="file">
                            <svg
                              xmlns="http://www.w3.org/2000/svg"
                              width={20}
                              height={21}
                              viewBox="0 0 20 21"
                              fill="none"
                            >
                              <path
                                d="M16.375 11.239L8.68203 18.932C7.83811 19.7759 6.69351 20.25 5.50003 20.25C4.30655 20.25 3.16195 19.7759 2.31803 18.932C1.47411 18.0881 1 16.9435 1 15.75C1 14.5565 1.47411 13.4119 2.31803 12.568L13.258 1.62801C13.5367 1.34948 13.8675 1.12856 14.2315 0.977866C14.5956 0.827175 14.9857 0.749663 15.3797 0.749756C15.7737 0.749849 16.1639 0.827545 16.5278 0.978407C16.8918 1.12927 17.2225 1.35035 17.501 1.62901C17.7796 1.90768 18.0005 2.23847 18.1512 2.60252C18.3019 2.96656 18.3794 3.35672 18.3793 3.75072C18.3792 4.14472 18.3015 4.53484 18.1506 4.89881C17.9998 5.26278 17.7787 5.59348 17.5 5.87201L6.55203 16.82C6.26801 17.0923 5.88839 17.2411 5.49497 17.2361C5.10156 17.2311 4.72585 17.0717 4.44883 16.7924C4.1718 16.513 4.01564 16.1359 4.01399 15.7425C4.01234 15.349 4.16535 14.9707 4.44003 14.689L12.25 6.87901M6.56103 16.81L6.55103 16.82"
                                stroke="#B6B6B6"
                                strokeWidth="1.5"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                              />
                            </svg>
                          </a>
                          <a href="#" className="image">
                            <svg
                              xmlns="http://www.w3.org/2000/svg"
                              width={22}
                              height={17}
                              viewBox="0 0 22 17"
                              fill="none"
                            >
                              <path
                                d="M1.25 12.25L6.409 7.091C6.61793 6.88206 6.86597 6.71633 7.13896 6.60325C7.41194 6.49018 7.70452 6.43198 8 6.43198C8.29548 6.43198 8.58806 6.49018 8.86104 6.60325C9.13403 6.71633 9.38207 6.88206 9.591 7.091L14.75 12.25M13.25 10.75L14.659 9.341C14.8679 9.13206 15.116 8.96633 15.389 8.85325C15.6619 8.74018 15.9545 8.68198 16.25 8.68198C16.5455 8.68198 16.8381 8.74018 17.111 8.85325C17.384 8.96633 17.6321 9.13206 17.841 9.341L20.75 12.25M2.75 16H19.25C19.6478 16 20.0294 15.842 20.3107 15.5607C20.592 15.2794 20.75 14.8978 20.75 14.5V2.5C20.75 2.10218 20.592 1.72064 20.3107 1.43934C20.0294 1.15804 19.6478 1 19.25 1H2.75C2.35218 1 1.97064 1.15804 1.68934 1.43934C1.40804 1.72064 1.25 2.10218 1.25 2.5V14.5C1.25 14.8978 1.40804 15.2794 1.68934 15.5607C1.97064 15.842 2.35218 16 2.75 16ZM13.25 4.75H13.258V4.758H13.25V4.75ZM13.625 4.75C13.625 4.84946 13.5855 4.94484 13.5152 5.01517C13.4448 5.08549 13.3495 5.125 13.25 5.125C13.1505 5.125 13.0552 5.08549 12.9848 5.01517C12.9145 4.94484 12.875 4.84946 12.875 4.75C12.875 4.65054 12.9145 4.55516 12.9848 4.48484C13.0552 4.41451 13.1505 4.375 13.25 4.375C13.3495 4.375 13.4448 4.41451 13.5152 4.48484C13.5855 4.55516 13.625 4.65054 13.625 4.75Z"
                                stroke="#B6B6B6"
                                strokeWidth="1.5"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                              />
                            </svg>
                          </a>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </main>
          </div>
        </div>
      </div>
    </div>
  );
}
