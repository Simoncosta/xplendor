import { APIClient } from "./api_helper";
import * as url from "./url_helper";

const api = new APIClient();

export const fetchCarSpecs = (companyId: number, carId: number) =>
    api.get(url.GET_COMPANIES + `/${companyId}` + url.GET_CARS + `/${carId}` + url.GET_CAR_SPECS);
