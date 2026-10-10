import { profileSubtitle } from "./ProfileDropdown";

jest.mock("react-router-dom", () => ({ Link: ({ children }: any) => children }), { virtual: true });
jest.mock("react-redux", () => ({ useSelector: () => null }));
jest.mock("contexts/ModulesContext", () => ({ useAccess: () => ({ profileName: null, loading: false }) }));

const login = (user: object) => sessionStorage.setItem("authUser", JSON.stringify(user));

describe("Cabeçalho: o perfil por baixo do nome", () => {
    afterEach(() => sessionStorage.clear());

    it("mostra o nome do perfil da pessoa", () => {
        login({ id: 2, role: "user" });
        expect(profileSubtitle("Marketing", false)).toBe("Marketing");
        login({ id: 3, role: "admin" });
        expect(profileSubtitle("Administrador", false)).toBe("Administrador");
    });

    it("o root é a Equipa XPLENDOR, exceto numa sessão como cliente", () => {
        login({ id: 1, role: "root" });
        expect(profileSubtitle(null, true)).toBe("Equipa XPLENDOR");
        login({ id: 1, role: "user", impersonating: true });
        expect(profileSubtitle("Marketing", false)).toBe("Marketing");
    });

    it("enquanto carrega não mostra nada; sem perfil, o papel", () => {
        login({ id: 2, role: "user" });
        expect(profileSubtitle(null, true)).toBe("");
        expect(profileSubtitle(null, false)).toBe("Utilizador");
        login({ id: 3, role: "admin" });
        expect(profileSubtitle(null, false)).toBe("Administrador");
    });
});
