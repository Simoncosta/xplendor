<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ConsultantSite\YoutubeId;
use PHPUnit\Framework\TestCase;

/** Site do consultor: o ID do YouTube a partir do link (vídeo, Short, youtu.be, embed) ou do próprio ID. */
class YoutubeIdTest extends TestCase
{
    public function test_links_and_ids(): void
    {
        foreach ([
            'AbCdEfGhIjK', 'https://www.youtube.com/watch?v=AbCdEfGhIjK', 'https://youtube.com/watch?v=AbCdEfGhIjK&t=30s', 'https://m.youtube.com/watch?v=AbCdEfGhIjK',
            'https://youtu.be/AbCdEfGhIjK?si=abc', 'https://www.youtube.com/shorts/AbCdEfGhIjK', 'youtube.com/shorts/AbCdEfGhIjK?feature=share',
            'https://www.youtube-nocookie.com/embed/AbCdEfGhIjK', 'https://www.youtube.com/live/AbCdEfGhIjK',
        ] as $input) {
            $this->assertSame('AbCdEfGhIjK', YoutubeId::parse($input), $input);
        }
        foreach (['', 'https://vimeo.com/12345678901', 'https://www.youtube.com/watch?v=curto', 'https://evil.example/watch?v=AbCdEfGhIjK', 'https://youtube.com.evil.example/shorts/AbCdEfGhIjK'] as $bad) {
            $this->assertNull(YoutubeId::parse($bad), $bad);
        }
    }
}
