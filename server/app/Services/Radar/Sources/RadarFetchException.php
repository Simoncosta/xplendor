<?php

declare(strict_types=1);

namespace App\Services\Radar\Sources;

use RuntimeException;

/** Radar: uma fonte que não respondeu como devia. A mensagem vai para radar_runs.error (sem conteúdo do feed). */
final class RadarFetchException extends RuntimeException {}
