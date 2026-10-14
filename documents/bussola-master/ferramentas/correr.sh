#!/bin/sh
# Bússola Master, R4: corre um dos scripts PHP desta pasta dentro do contentor, com a aplicação arrancada
# (sem o tinker, que importa nomes sozinho). O programa fica num ficheiro temporário em storage/ e é
# apagado no fim. Uso: documents/bussola-master/ferramentas/correr.sh demo-criar.php
DIR=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$DIR/../../.." && pwd)
TMP="$ROOT/server/storage/app/ferramenta-bussola-master.php"
{ printf '%s\n' '<?php' "require '/var/www/html/vendor/autoload.php';" "\$app = require '/var/www/html/bootstrap/app.php';" "\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"; cat "$DIR/$1"; } > "$TMP"
docker exec -w /var/www/html xplendor-php php storage/app/ferramenta-bussola-master.php
RC=$?
rm -f "$TMP"
exit $RC
