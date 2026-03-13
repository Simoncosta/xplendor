import { combineReducers } from "redux";

import BlogReducer from "./blogs/reducer";
import CarReducer from "./cars/reducer";
import CarAiAnalysesReducer from "./car-ai-analises/reducer";
import CarBrandReducer from "./car-brands/reducer";
import CarModelsReducer from "./car-models/reducer";
import CarmineReducer from "./carmine/reducer";
import CompanyReducer from "./companies/reducer";
import DashboardReducer from "./dashboards/reducer";
import DistrictReducer from "./districts/reducer";
import MunicipalityReducer from "./municipalities/reducer";
import ParishReducer from "./parishes/reducer";
import RegisterInviteReducer from "./auth/register/reducer";
import UserReducer from "./users/reducer";

// Front
import LayoutReducer from "./layouts/reducer";

// Authentication
import LoginReducer from "./auth/login/reducer";
import AccountReducer from "./auth/register/reducer";
import ProfileReducer from "./auth/profile/reducer";

const rootReducer = combineReducers({
    Blog: BlogReducer,
    Car: CarReducer,
    CarAiAnalyses: CarAiAnalysesReducer,
    Carmine: CarmineReducer,
    CarBrand: CarBrandReducer,
    CarModel: CarModelsReducer,
    Company: CompanyReducer,
    Dashboard: DashboardReducer,
    District: DistrictReducer,
    Municipality: MunicipalityReducer,
    Parish: ParishReducer,
    User: UserReducer,

    RegisterInvite: RegisterInviteReducer,
    Layout: LayoutReducer,
    Login: LoginReducer,
    Account: AccountReducer,
    Profile: ProfileReducer,
});

export default rootReducer;