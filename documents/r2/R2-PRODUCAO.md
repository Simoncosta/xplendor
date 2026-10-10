# R2 em produção: Cloudflare, .env, deploy e migração dos ficheiros

Escrito na noite de 10 de outubro de 2026. Nada disto foi feito em produção nem na Cloudflare. O inventário está em `documents/r2/INVENTARIO.md`.

## 1. O que muda no código

- Disco novo `r2` (`server/config/filesystems.php`), do tipo S3, **bucket privado**.
- `MEDIA_DISK` (Linha Editorial e fotos das contas) e `PRIVATE_FILES_DISK` (cobranças, comprovativos, faturas do OCR e, desde o pré-deploy, as faturas dos tickets e as fotografias dos relatórios de satisfação) escolhem o disco. **Por omissão, tudo continua nos discos locais**: o deploy não muda nada até se mudar o `.env`.
- Os ficheiros saem sempre por endereços assinados de curta duração, gerados pelo backend:
  - **Media:** os ecrãs recebem o URL assinado da aplicação (30 minutos), gerado atrás do tenant e do ACL. No R2, esse URL redireciona para um endereço assinado do R2 de **5 minutos**. O R2 serve por partes (Range), por isso o vídeo continua a avançar sem descarregar tudo.
  - **Cobranças e OCR:** continuam a sair em bytes pelo backend, atrás do tenant e do ACL, também no R2.
- **Envio em partes:** as partes ficam sempre no disco local; no fim, o original vai para o R2 em stream.
- **O worker** (ffmpeg, miniaturas, poster) trabalha numa cópia temporária local, apagada no fim, mesmo se o processamento falhar.
- **Retenção** (30 dias e 12 meses), **quota** por empresa (soma na base de dados) e **apagamento aos 90 dias:** funcionam nos dois discos. O apagamento de uma empresa apaga no R2 e na cópia local.
- **O aviso de "disco cheio"** só conta para o disco local (o R2 não enche).

## 2. Na Cloudflare (amanhã)

Os nomes dos menus podem variar: confirmar no painel.

**a) Criar o bucket**
1. Painel da Cloudflare › **R2 Object Storage** › **Create bucket**.
2. Nome: `xplendor-media`.
3. Localização: recomendo a **jurisdição da União Europeia** (os ficheiros têm dados de clientes). Com a jurisdição da UE, o endereço S3 é `https://<ID_DA_CONTA>.eu.r2.cloudflarestorage.com`; sem ela, é `https://<ID_DA_CONTA>.r2.cloudflarestorage.com`. Confirmar o endereço no separador **Settings** do bucket ("S3 API").
4. **Acesso público: desligado.** Não ligar o subdomínio `r2.dev` nem um domínio próprio. O bucket nunca é público.

**b) Uma chave só para este bucket**
1. R2 › **Manage R2 API Tokens** (ou "API tokens" na página do R2) › **Create API token**.
2. Permissões: **Object Read & Write**.
3. Âmbito: **Apply to specific buckets only** › `xplendor-media`.
4. Validade: sem fim, ou uma data com lembrete para a renovar.
5. Guardar o **Access Key ID** e o **Secret Access Key** (o segredo só aparece uma vez).

**c) CORS (só se for preciso)**
As etiquetas `<img>` e `<video>` não precisam de CORS. Se algum ecrã vier a ler um ficheiro com `fetch` (por exemplo, para descarregar), acrescentar no bucket uma regra CORS com `GET` e `HEAD` para a origem da aplicação (`https://app.xplendor.pt` ou a que estiver em uso).

## 3. Variáveis do `.env` de produção

```
# R2 (Cloudflare)
R2_ACCESS_KEY_ID=<Access Key ID>
R2_SECRET_ACCESS_KEY=<Secret Access Key>
R2_BUCKET=xplendor-media
R2_ENDPOINT=https://<ID_DA_CONTA>.eu.r2.cloudflarestorage.com
R2_PUBLIC_ENDPOINT=
R2_REGION=auto
R2_PATH_STYLE=true
MEDIA_EXTERNAL_FETCH_TTL=3600

# Discos: ficam locais até ao passo 4.5 abaixo
MEDIA_DISK=media
PRIVATE_FILES_DISK=local
```

- `R2_PUBLIC_ENDPOINT` vazio = o mesmo que `R2_ENDPOINT` (só em dev é diferente, por causa do MinIO dentro do Docker).
- `OCR_INVOICE_DISK` não é preciso: segue `PRIVATE_FILES_DISK`.

## 4. Deploy e migração dos ficheiros (por esta ordem)

1. **Deploy normal**, como habitualmente (`deploy.sh`). Entram três migrações aditivas:
   - `2026_12_20_100000_create_permission_profiles` (ACL);
   - `2026_12_21_100000_add_profile_suggestions` (ACL);
   - `2026_12_22_100000_create_storage_migration_items` (R2).
2. **Dependências PHP novas** (o `deploy.sh` não corre o Composer; o `vendor` é um volume):
   ```
   docker exec xplendor-php composer install --no-dev --optimize-autoloader
   docker exec xplendor-php php artisan config:cache
   docker exec xplendor-php php artisan queue:restart
   ```
3. **Acrescentar as variáveis do R2** ao `.env` (com os discos ainda locais) e testar a ligação:
   ```
   docker exec xplendor-php php artisan config:cache
   docker exec xplendor-php php artisan tinker --execute='Storage::disk("r2")->put("teste/ligacao.txt","ok"); echo Storage::disk("r2")->get("teste/ligacao.txt"), PHP_EOL; Storage::disk("r2")->delete("teste/ligacao.txt");'
   ```
   Deve escrever `ok`.
4. **Migração:**
   1. Simulação (não escreve nada):
      ```
      docker exec xplendor-php php artisan storage:migrate-to-r2
      ```
      Mostra quantos ficheiros e quantos MB.
   2. Cópia, com confirmação de cada ficheiro (tamanho e SHA-256), retomável:
      ```
      docker exec xplendor-php php artisan storage:migrate-to-r2 --execute
      ```
   3. Repetir até "Falhas" dar 0 (o que já está confirmado não volta a ser copiado). Para ir por partes: `--only=media`, `--only=cobranca,ocr`, `--only=fatura_ticket,foto_relatorio`, `--limit=500`. As faturas dos tickets e as fotografias dos relatórios só entram depois do `files:make-private --execute` (as que ainda têm o caminho público `/storage/...` ficam de fora).
   4. Os media da Linha Editorial passam a ler do R2 sozinhos, asset a asset, quando todos os ficheiros do asset estão confirmados.
   5. Mudar o `.env` para `MEDIA_DISK=r2` e `PRIVATE_FILES_DISK=r2`, e depois:
      ```
      docker exec xplendor-php php artisan config:cache
      docker exec xplendor-php php artisan queue:restart
      ```
   6. Correr outra vez `storage:migrate-to-r2 --execute`, para os ficheiros escritos entre os passos 4.2 e 4.5. Os que já foram escritos diretamente no R2 contam como "já estavam" (não "em falta").
5. **Confirmar no ecrã:** uma publicação com imagem e uma com vídeo (o vídeo avança), enviar uma imagem nova, abrir uma fatura do OCR, o PDF de uma cobrança, a fatura de um ticket pago e as fotografias de um relatório de satisfação.
6. **Uns dias depois**, com tudo a funcionar, apagar as cópias locais (comando à parte; só apaga o que já é lido do R2):
   ```
   docker exec xplendor-php php artisan storage:purge-local
   docker exec xplendor-php php artisan storage:purge-local --execute
   ```
   O primeiro só simula e mostra os MB a libertar.

**Para voltar atrás**, antes do passo 6:
1. Repor `MEDIA_DISK=media` e `PRIVATE_FILES_DISK=local`, depois `config:cache` e `queue:restart`.
2. Pôr os media outra vez no disco local (as cópias locais continuam lá):
   ```
   docker exec xplendor-php php artisan tinker --execute='App\Models\MediaAsset::where("disk","r2")->update(["disk"=>"media"]);'
   ```
Os ficheiros enviados já com o R2 ficam só no R2: copiá-los para o local antes de voltar atrás.

## 5. Para a F2 da Meta: um endereço assinado de 1 hora chega?

O que a documentação da Meta diz ([Content Publishing](https://developers.facebook.com/docs/instagram-platform/content-publishing)):
- "We cURL media used in publishing attempts", e por isso "the media must be hosted on a publicly accessible server at the time of the attempt".
- Os contentores que não são publicados em 24 horas expiram (`EXPIRED`).
- Para os vídeos, recomenda consultar o estado do contentor uma vez por minuto, durante no máximo 5 minutos.
- A documentação **não publica** um prazo para a Meta ir buscar o ficheiro.

**Conclusão:** chega, se o endereço for gerado no momento de criar o contentor e a publicação for feita logo a seguir. A Meta vai buscar o ficheiro durante a criação e o processamento, que a própria documentação conta em minutos. Um endereço gerado horas antes não serve.

**O método está pronto:** `MediaAsset::externalUrl('original')`.
- No R2, devolve um endereço assinado do R2 válido por `MEDIA_EXTERNAL_FETCH_TTL` segundos (3600 por omissão; o R2 aceita até 7 dias).
- No disco local, devolve o URL assinado absoluto da aplicação.
- Quem o chamar tem de ter verificado a empresa e a permissão antes, e deve gerá-lo imediatamente antes do pedido à Meta.
- Se o contentor falhar ou expirar, gera-se um endereço novo.

Fontes: [Content Publishing, Meta](https://developers.facebook.com/docs/instagram-platform/content-publishing); [Presigned URLs, Cloudflare R2](https://developers.cloudflare.com/r2/data-access/s3-api/presigned-urls/).

## 6. Dev

- `docker-compose.yml` tem um MinIO (só em dev, nunca no `docker-compose.prod.yml`): API em `http://localhost:9010`, consola em `http://localhost:9011` (minioadmin / minioadmin) e o bucket privado `xplendor-media`, criado pelo serviço `minio-setup`.
- O Docker Hub deixou de servir as versões fixas do MinIO: o compose usa `minio/minio:latest` e `minio/mc:latest` da cache local (`pull_policy: missing`).
- No `.env` de dev, as variáveis `R2_*` apontam para o MinIO; os discos continuam locais.
- Testes automáticos: `server/tests/Feature/R2StorageTest.php`, com um disco simulado em memória (sem caminho local, como o R2). Nenhum pedido real ao R2.
