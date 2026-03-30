"""
Corre este ficheiro para ver a estrutura real do __NEXT_DATA__ do Standvirtual.
Uso: python3 debug.py
"""

import json
import requests
from bs4 import BeautifulSoup

URL = "https://www.standvirtual.com/carros"

headers = {
    "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
    "Accept-Language": "pt-PT,pt;q=0.9",
}

print(f"A fazer fetch de {URL} ...")
response = requests.get(URL, headers=headers, timeout=15)
print(f"Status: {response.status_code}")

soup = BeautifulSoup(response.text, "lxml")
script = soup.find("script", {"id": "__NEXT_DATA__"})

if not script:
    print("\n❌ __NEXT_DATA__ NÃO encontrado na página.")
    print("A página pode estar a bloquear o pedido ou mudou de estrutura.")

    # Mostra todos os scripts para diagnóstico
    print("\nScripts encontrados na página:")
    for s in soup.find_all("script")[:10]:
        attrs = dict(s.attrs)
        preview = (s.string or "")[:100]
        print(f"  attrs={attrs} | preview={preview!r}")
else:
    data = json.loads(script.string)

    # Guarda o JSON completo para inspeção
    with open("next_data_raw.json", "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    print("\n✅ __NEXT_DATA__ encontrado!")
    print("Ficheiro guardado: next_data_raw.json")

    # Mostra apenas as keys de topo para orientação
    print("\nEstrutura de topo:")
    def show_keys(d, prefix="", depth=0):
        if depth > 3:
            return
        if isinstance(d, dict):
            for k, v in d.items():
                type_info = type(v).__name__
                if isinstance(v, list):
                    type_info = f"list[{len(v)}]"
                elif isinstance(v, dict):
                    type_info = f"dict[{len(v)} keys]"
                print(f"{'  ' * depth}{prefix}{k}: {type_info}")
                if isinstance(v, (dict, list)) and depth < 3:
                    show_keys(v, depth=depth+1)
        elif isinstance(d, list) and d:
            show_keys(d[0], prefix="[0].", depth=depth)

    show_keys(data)