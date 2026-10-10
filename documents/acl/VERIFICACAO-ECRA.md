# ACL: verificação no ecrã por perfil

Feita na noite de 10 de outubro de 2026, em dev, com utilizadores temporários (nomes neutros e emails `@exemplo.pt`), apagados no fim. Nas capturas, os nomes e os emails das pessoas reais de dev aparecem como "Pessoa (oculta)" e "email oculto".

## Quem é cada ator

- **Administrador, Marketing, Financeiro, Só leitura, Agência convidada:** pessoas da Yuko Tavern (dev), com os perfis criados a partir das sugestões (D13). A Yuko tem a Linha Editorial, a Análise de Marketing, o PingWin com todas as secções da restauração e o Suporte; não tem Finanças nem o Automóvel.
- **Membro da agência:** pessoa da XPLENDOR (agência), a trabalhar na PA Automóveis (cliente gerido, com o teto "Agência convidada (como hoje)").
- **Cliente gerido:** administrador da PA Automóveis (vê o teto da agência no cartão da agência gestora).

## O que cada perfil consegue abrir

"abre" quer dizer que a página abre; "não" quer dizer que a guarda da rota volta ao dashboard (e o backend recusa a API com 403). Uma página também fica fechada quando o módulo não está ativo na empresa (por exemplo, Despesas na Yuko, que não tem Finanças).

| Perfil | Colaboradores | Linha Editorial | Bússola | Lojas (restauração) | Faturas (restauração) | Despesas | Blog | Suporte | Tráfego do site | Botão do suporte |
|---|---|---|---|---|---|---|---|---|---|---|
| Administrador | abre | abre | abre | abre | abre | não | abre | abre | abre | sim |
| Marketing | abre | abre | abre | abre | abre | não | abre | abre | abre | sim |
| Financeiro | abre | abre | não | abre | abre | não | não | abre | abre | sim |
| Só leitura | não | abre | abre | não | não | não | abre | abre | abre | sim |
| Agência convidada | não | abre | abre | não | não | não | não | não | abre | não |
| Membro da agência | abre | abre | não | não | não | abre | abre | abre | abre | sim |
| Cliente gerido | abre | abre | não | não | não | abre | abre | abre | abre | sim |

Leitura:
- **Só leitura** (D9) vê a Linha Editorial, o blog, a Bússola, os resultados e o suporte; não vê os colaboradores nem o back-office da restauração.
- **Agência convidada** (D14) vê só a Linha Editorial, a Bússola e os resultados; não vê o suporte (o botão flutuante desaparece), a equipa, as finanças nem a restauração.
- **Financeiro** não vê o blog nem a Bússola (não estão na sugestão).
- O **membro da agência** e o **cliente gerido** veem as Despesas porque a PA Automóveis tem o módulo de Finanças; o teto "como hoje" deixa a agência trabalhar nelas.
- Nenhuma página tem scroll horizontal, no computador nem no telemóvel.

## Capturas (`documents/acl/capturas/`)

- `agencia-convidada-dashboard-computador-light.png`
- `agencia-convidada-telemovel-dark.png`
- `financeiro-dashboard-computador-light.png`
- `financeiro-telemovel-dark.png`
- `marketing-dashboard-computador-light.png`
- `marketing-telemovel-dark.png`
- `membro-agencia-dashboard-computador-light.png`
- `membro-agencia-telemovel-dark.png`
- `novo-perfil-areas-computador-dark.png`
- `novo-perfil-areas-computador-light.png`
- `novo-perfil-areas-telemovel-dark.png`
- `novo-perfil-rever-computador-light.png`
- `novo-perfil-sugestoes-computador-light.png`
- `perfis-computador-dark.png`
- `perfis-computador-light.png`
- `perfis-telemovel-dark.png`
- `perfis-telemovel-light.png`
- `so-leitura-dashboard-computador-light.png`
- `so-leitura-telemovel-dark.png`
- `teto-agencia-computador-dark.png`
- `teto-agencia-computador-light.png`
- `teto-agencia-telemovel-dark.png`
- `teto-agencia-telemovel-light.png`
