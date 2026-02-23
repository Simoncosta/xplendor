import React, { useState, useMemo, useEffect } from "react";
import { Link } from "react-router-dom";
import {
  Button,
  Card,
  CardBody,
  Col,
  Container,
  DropdownItem,
  DropdownMenu,
  DropdownToggle,
  Form,
  Input,
  Row,
  UncontrolledDropdown,
} from "reactstrap";
import Select from "react-select";
import Flatpickr from "react-flatpickr";
import { jobGrid } from "../../../../common/data/appsJobs";
import BreadCrumb from "../../../../Components/Common/BreadCrumb";
import Pagination from "../../../../Components/Common/Pagination";

const JobGrid = () => {
  document.title = "Job Grid | Velzon -  Admin & Dashboard Template";
  const sortbyname = [
    {
      options: [
        { label: "All", value: "All" },
        { label: "Today", value: "Today" },
        { label: "Yesterday", value: "Yesterday" },
        { label: "Last 7 Days", value: "Last 7 Days" },
        { label: "Last 30 Days", value: "Last 30 Days" },
        { label: "Thise Month", value: "Thise Month" },
        { label: "Last Year", value: "Last Year" },
      ],
    },
  ];
  const option = [
    {
      options: [
        { label: "All", value: "All" },
        { label: "Active", value: "Active" },
        { label: "New", value: "New" },
        { label: "Close", value: "Close" },
      ],
    },
  ];

  const [jobGridData, setJobGridData] = useState<any>();
  const [currentPage, setCurrentPage] = useState<any>(1);
  //pagination
  const perPageData = 7;
  const indexOfLast = currentPage * perPageData;
  const indexOfFirst = indexOfLast - perPageData;
  const currentdata = useMemo(() => jobGrid?.slice(indexOfFirst, indexOfLast), [indexOfFirst, indexOfLast])
  useEffect(() => {
    setJobGridData(currentdata)
  }, [currentdata]);

  return (
    <React.Fragment>
      <div className="page-content">
        <Container fluid>
          <BreadCrumb title="Job Grid List" pageTitle="Jobs" />

          <Row>
            <Col lg={12}>
              <Card>
                <CardBody className="bg-light-subtle">
                  <Form>
                    <Row className="g-3">
                      <Col xxl={4} sm={12}>
                        <div className="search-box">
                          <Input
                            type="text"
                            className="form-control search bg-light border-light"
                            id="searchJob"
                            autoComplete="off"
                            placeholder="Search for jobs or companies..."
                          />
                          <i className="ri-search-line search-icon"></i>
                        </div>
                      </Col>

                      <Col xxl={3} sm={4}>
                        <Flatpickr
                          // className="form-control"
                          id="datepicker-publish-input"
                          placeholder="Select a date"
                          options={{
                            altInput: true,
                            altFormat: "F j, Y",
                            mode: "multiple",
                            dateFormat: "d.m.y",
                          }}
                        />
                      </Col>

                      <Col xxl={2} sm={4}>
                        <div className="input-light">
                          <Select
                            className="js-example-basic-single mb-0"
                            options={sortbyname}
                          ></Select>
                        </div>
                      </Col>

                      <Col xxl={2} sm={4}>
                        <div className="input-light">
                          <Select
                            className="js-example-basic-single mb-0"
                            options={option}
                          ></Select>
                        </div>
                      </Col>

                      <Col xxl={1} sm={4}>
                        <Button
                          type="button"
                          color="primary"
                          className="btn y w-100"
                        //   onclick="filterData();"
                        >
                          <i className="ri-equalizer-fill me-1 align-bottom"></i>{" "}
                          Filters
                        </Button>
                      </Col>
                    </Row>
                  </Form>
                </CardBody>
              </Card>
            </Col>
          </Row>

          <Row>
            <Col lg={12}>
              <div className="d-flex align-items-center mb-4">
                <div className="flex-grow-1">
                  <p className="text-muted fs-14 mb-0">
                    Result: <span id="total-result"></span>
                  </p>
                </div>
                <div className="flex-shrink-0">
                  <UncontrolledDropdown className="dropdown">
                    <DropdownToggle
                      className="btn text-muted fs-14 dropdown-toggle"
                      to="#"
                      role="button"
                      tag="button"
                      type="button"
                    >
                      All View
                    </DropdownToggle>
                    <DropdownMenu aria-labelledby="dropdownMenuLink">
                      <li>
                        <DropdownItem href="#">Action</DropdownItem>
                      </li>
                      <li>
                        <DropdownItem href="#">Another action</DropdownItem>
                      </li>
                      <li>
                        <DropdownItem href="#">
                          Something else here
                        </DropdownItem>
                      </li>
                    </DropdownMenu>
                  </UncontrolledDropdown>
                </div>
              </div>
            </Col>
          </Row>

          <Row id="job-list">
            <Col lg={3} md={6} id="job-widget">
              <Card className="card-height-100 bg-info bg-job">
                <CardBody className="p-5">
                  <h2 className="lh-base text-white">
                    Velzon invites young professionals for an intership!
                  </h2>
                  <p className="text-white text-opacity-75 mb-0 fs-14">
                    Don't miss your opportunity to improve your skills!
                  </p>
                  <div className="mt-5 pt-2">
                    <Button type="button" className="btn btn-light w-100">
                      View More{" "}
                      <i className="ri-arrow-right-line align-bottom"></i>
                    </Button>
                  </div>
                </CardBody>
              </Card>
            </Col>
            {
              (jobGridData || []).map((item: any, key: any) => (
                <Col lg={3} md={6} key={key}>
                  <Card>
                    <CardBody>
                      <button
                        type="button"
                        className="btn btn-icon btn-soft-primary float-end"
                        data-bs-toggle="button"
                        aria-pressed="true"
                      >
                        <i className="mdi mdi-cards-heart fs-16"></i>
                      </button>
                      <div className="avatar-sm mb-4">
                        <div className="avatar-title bg-light rounded">
                          <img
                            src={item.companyLogo}
                            alt=""
                            className="avatar-xxs"
                          />
                        </div>
                      </div>
                      <Link to="#!">
                        <h5>{item.jobTitle}</h5>
                      </Link>
                      <p className="text-muted">{item.companyName} </p>
                      <div className="d-flex gap-4 mb-3">
                        <div>
                          <i className="ri-map-pin-2-line text-primary me-1 align-bottom"></i>{" "}
                          {item.location}
                        </div>
                        <div>
                          <i className="ri-time-line text-primary me-1 align-bottom"></i>{" "}
                          {item.postDate}
                        </div>
                      </div>
                      <p className="text-muted">{item.description}</p>
                      <div className="hstack gap-2">
                        {item.requirement.map((subItem:any, key:any) => (
                          <React.Fragment key={key}>
                            {
                              subItem === "Full Time" ?
                                <span className="badge bg-success-subtle text-success">{subItem}</span>
                                :
                                subItem === " Freelance" ?
                                  <span className="badge bg-primary-subtle text-primary">{subItem}</span>
                                  :
                                  <span className="badge bg-danger-subtle text-danger">{subItem}</span>
                            }
                          </React.Fragment>
                        ))}
                      </div>
                      <div className="mt-4 hstack gap-2">
                        <Link to="#!" className="btn btn-soft-primary w-100">
                          Apply Job
                        </Link>
                        <Link
                          to="/apps-job-details"
                          className="btn btn-soft-success w-100"
                        >
                          Overview
                        </Link>
                      </div>
                    </CardBody>
                  </Card>
                </Col>
              ))}
          </Row>

          <Pagination
            perPageData={perPageData}
            data={jobGrid}
            currentPage={currentPage}
            setCurrentPage={setCurrentPage}
            className="pagination pagination-separated pagination-md justify-content-center justify-content-sm-start mb-0"
          />
        </Container>
      </div>
    </React.Fragment>
  );
};

export default JobGrid;
