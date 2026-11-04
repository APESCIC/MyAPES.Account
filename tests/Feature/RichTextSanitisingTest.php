<?php

namespace Tests\Feature;

use App\Core\Content\RichText;
use App\Core\Content\SanitisedHtml;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Server-side HTML sanitising for rich text (#484).
 */
class RichTextSanitisingTest extends TestCase
{
    public function test_scripts_and_event_handlers_are_stripped(): void
    {
        $clean = app(RichText::class)->clean(
            '<p onclick="steal()">Hello <strong>there</strong></p>'
            .'<script>alert(1)</script>'
            .'<img src="https://example.org/a.png" onerror="alert(2)" alt="pic">'
            .'<a href="javascript:alert(3)">bad link</a>'
            .'<iframe src="https://evil.example"></iframe>'
            .'<p style="color:red">styled</p>',
        );

        $this->assertStringNotContainsStringIgnoringCase('<script', $clean);
        $this->assertStringNotContainsStringIgnoringCase('alert(1)', $clean);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $clean);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $clean);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $clean);
        $this->assertStringNotContainsStringIgnoringCase('<iframe', $clean);
        $this->assertStringNotContainsStringIgnoringCase('style=', $clean);
        $this->assertStringContainsString('<strong>there</strong>', $clean);
        $this->assertStringContainsString('<img src="https://example.org/a.png" alt="pic"', $clean);
    }

    public function test_safe_formatting_and_links_survive(): void
    {
        $clean = app(RichText::class)->clean(
            '<h2>Title</h2><ul><li>One</li></ul><a href="https://www.apes.org.uk" target="_blank">APES</a>'
            .'<table><tr><td>Cell</td></tr></table><img src="data:image/png;base64,AAAA" alt="x">',
        );

        $this->assertStringContainsString('<h2>Title</h2>', $clean);
        $this->assertStringContainsString('<li>One</li>', $clean);
        $this->assertMatchesRegularExpression('/<a href="https:\/\/www\.apes\.org\.uk"[^>]*rel="[^"]*noopener/', $clean);
        $this->assertStringContainsString('<td>Cell</td>', $clean);
        $this->assertStringNotContainsString('data:image', $clean);
    }

    public function test_the_cast_sanitises_on_save(): void
    {
        $model = new class extends Model
        {
            protected $guarded = [];

            protected function casts(): array
            {
                return ['description' => SanitisedHtml::class];
            }
        };

        $model->description = '<p>Fine</p><script>alert(1)</script><img src="x" onerror="alert(2)">';

        $stored = $model->getAttributes()['description'];
        $this->assertStringContainsString('<p>Fine</p>', $stored);
        $this->assertStringNotContainsStringIgnoringCase('<script', $stored);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $stored);
    }

    public function test_render_escapes_plain_text_and_re_sanitises_html(): void
    {
        $plain = Blade::render('<x-rich-text :value="$v" />', ['v' => "Line one\nLine 2 < 3 & three"]);
        $this->assertStringContainsString('Line one<br />', $plain);
        $this->assertStringContainsString('Line 2 &lt; 3 &amp; three', $plain);

        $html = Blade::render('<x-rich-text :value="$v" />', ['v' => '<p>Safe</p><script>alert(1)</script>']);
        $this->assertStringContainsString('<p>Safe</p>', $html);
        $this->assertStringNotContainsString('<script', $html);
    }
}
