<?php

declare(strict_types=1);

namespace App\Services\Restaurant\Transfers;

/** Uma guarda recusou a escrita no PingWin (o controlador responde 403 com a explicação). */
final class TransferBlocked extends \RuntimeException {}
