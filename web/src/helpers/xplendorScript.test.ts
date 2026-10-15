import fs from "fs";
import path from "path";

// O script de tracking dos sites (server/public/xplendor.js), corrido tal como o browser o recebe.
const SCRIPT = fs.readFileSync(path.resolve(__dirname, "../../../server/public/xplendor.js"), "utf8");

type Sent = { url: string; body: any; stubInPlace: boolean };

const loadScript = () => {
    // eslint-disable-next-line no-new-func
    new Function(SCRIPT)();
};

const w = window as any;

describe("xplendor.js", () => {
    let sent: Sent[];
    let stub: unknown = null;

    beforeEach(() => {
        sent = [];
        stub = null;
        localStorage.clear();
        sessionStorage.clear();
        delete w.xplendor;
        w.fetch = jest.fn((url: string, init: any) => {
            sent.push({ url, body: JSON.parse(init.body), stubInPlace: stub !== null && w.xplendor === stub });
            return Promise.resolve({ status: 201, text: () => Promise.resolve("") });
        });
    });

    it("processa a fila do stub por ordem antes de o substituir, e depois chama direto", () => {
        w.xplendor = function () {
            (w.xplendor.q = w.xplendor.q || []).push(arguments);
        };
        stub = w.xplendor;
        w.xplendor("init", { token: "t", api_base: "https://api.test", auto_page_view: false });
        w.xplendor("interaction", "whatsapp_click", { car_id: 7, page_context: "vehicle_gallery" });
        w.xplendor("interaction", "whatsapp_send", { car_id: null, page_context: "modal" });

        loadScript();

        expect(w.xplendor).not.toBe(stub);
        expect(sent.map((s) => s.body.type)).toEqual(["whatsapp_click", "whatsapp_send"]);
        expect(sent.map((s) => s.stubInPlace)).toEqual([true, true]); // a fila corre antes de o stub sair
        expect(sent[0].url).toBe("https://api.test/api/public/track?token=t");
        expect(w.xplendor.q).toEqual([]);

        w.xplendor("interaction", "call_click", { car_id: 7 });
        expect(sent.map((s) => s.body.type)).toEqual(["whatsapp_click", "whatsapp_send", "call_click"]);
        expect(sent[2].stubInPlace).toBe(false);
    });

    it("sem stub, arranca na mesma", () => {
        loadScript();
        w.xplendor("init", { token: "t", api_base: "https://api.test", auto_page_view: false });
        w.xplendor("interaction", "whatsapp_click", { car_id: 3 });

        expect(sent).toHaveLength(1);
        expect(sent[0].body.data.car_id).toBe(3);
    });

    it("car_id: null explícito é sem viatura; sem car_id infere a última viatura vista", () => {
        loadScript();
        w.xplendor("init", { token: "t", api_base: "https://api.test", auto_page_view: false });
        w.xplendor("event", "car_view", { car_id: 42 });
        w.xplendor("interaction", "whatsapp_click", { car_id: null, page_context: "vehicle_mobile_cta" });
        w.xplendor("interaction", "whatsapp_click", { page_context: "floating_button" });

        const [view, explicitNone, inferred] = sent.map((s) => s.body.data);
        expect(view.car_id).toBe(42);
        expect(explicitNone.car_id).toBeNull();
        expect(explicitNone.meta.car_id_source).toBe("explicit_none");
        expect(inferred.car_id).toBe(42);
        expect(inferred.meta.car_id_source).toBe("last_car_view");
    });
});
