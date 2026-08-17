<?php

namespace Tests\Unit;

use App\Support\OpenRunner;
use PHPUnit\Framework\TestCase;

// Whitelist OpenRunner (PRD §4.13.1).
class OpenRunnerTest extends TestCase
{
    public function test_accepts_valid_embed_url(): void
    {
        $this->assertTrue(OpenRunner::validEmbedUrl('https://www.openrunner.com/embed.html?code=AbC123xyz'));
    }

    public function test_rejects_embed_without_code(): void
    {
        $this->assertFalse(OpenRunner::validEmbedUrl('https://www.openrunner.com/embed.html'));
        $this->assertFalse(OpenRunner::validEmbedUrl('https://www.openrunner.com/embed.html?code='));
    }

    public function test_rejects_embed_wrong_host_scheme_or_path(): void
    {
        $this->assertFalse(OpenRunner::validEmbedUrl('http://www.openrunner.com/embed.html?code=x')); // http
        $this->assertFalse(OpenRunner::validEmbedUrl('https://openrunner.com/embed.html?code=x'));    // host sans www
        $this->assertFalse(OpenRunner::validEmbedUrl('https://evil.com/embed.html?code=x'));          // autre hôte
        $this->assertFalse(OpenRunner::validEmbedUrl('https://www.openrunner.com.evil.com/embed.html?code=x'));
        $this->assertFalse(OpenRunner::validEmbedUrl('https://www.openrunner.com/route-details/1/embed?code=x')); // path ≠ /embed.html
        $this->assertFalse(OpenRunner::validEmbedUrl('javascript:alert(1)'));
        $this->assertFalse(OpenRunner::validEmbedUrl(''));
        $this->assertFalse(OpenRunner::validEmbedUrl(null));
    }

    public function test_public_url_any_path_on_host(): void
    {
        $this->assertTrue(OpenRunner::validPublicUrl('https://www.openrunner.com/r/18234210'));
        $this->assertTrue(OpenRunner::validPublicUrl('https://www.openrunner.com/route-details/18234210'));
        $this->assertFalse(OpenRunner::validPublicUrl('https://openrunner.com/r/1'));
        $this->assertFalse(OpenRunner::validPublicUrl('http://www.openrunner.com/r/1'));
    }
}
