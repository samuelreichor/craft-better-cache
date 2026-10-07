<?php

namespace samuelreichor\betterCache\tests;

use Craft;
use samuelreichor\betterCache\Constants;
use samuelreichor\betterCache\services\DynamicContent;
use yii\base\InvalidArgumentException;

class DynamicContentTest extends TestCase
{
    private DynamicContent $dynamicContent;
    private string $tokenName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dynamicContent = new DynamicContent();
        $this->tokenName = Craft::$app->getConfig()->getGeneral()->csrfTokenName;
    }

    public function testCsrfTokensBecomePlaceholders(): void
    {
        $html = $this->dynamicContent->prepareForStorage(
            "<html><head><meta name=\"csrf-token\" content=\"secret\"></head><body>" .
            "<form><input type=\"hidden\" name=\"$this->tokenName\" value=\"secret\"></form>" .
            "<form><input value='secret' name='$this->tokenName' type='hidden'/></form>" .
            "<input type=\"hidden\" name=\"other\" value=\"keep\"></body></html>"
        );

        $this->assertStringNotContainsString('secret', $html);
        $this->assertSame(3, substr_count($html, Constants::CSRF_PLACEHOLDER_ATTRIBUTE . '='));
        $this->assertStringContainsString('value="keep"', $html);
        $this->assertSame(1, substr_count($html, Constants::SCRIPT_MARKER_ATTRIBUTE));
        $this->assertStringEndsWith('</script></body></html>', $html);
    }

    public function testPagesWithoutPlaceholdersStayUntouched(): void
    {
        $html = '<html><body><p>static</p></body></html>';

        $this->assertSame($html, $this->dynamicContent->prepareForStorage($html));
    }

    public function testScriptIsNotAddedTwice(): void
    {
        $html = $this->dynamicContent->getIncludeHtml('bc/_now') . "<input name=\"$this->tokenName\" value=\"x\">";

        $prepared = $this->dynamicContent->prepareForStorage($html);

        $this->assertSame(1, substr_count($prepared, Constants::SCRIPT_MARKER_ATTRIBUTE));
    }

    public function testIncludeIsSignedAndRendersScriptOnce(): void
    {
        $first = $this->dynamicContent->getIncludeHtml('bc/_now', ['label' => 'a', 'list' => [1, 2]]);
        $second = $this->dynamicContent->getIncludeHtml('bc/_now');

        $this->assertStringContainsString(Constants::SCRIPT_MARKER_ATTRIBUTE, $first);
        $this->assertStringNotContainsString(Constants::SCRIPT_MARKER_ATTRIBUTE, $second);

        $include = $this->dynamicContent->resolveIncludePayload($this->payloadOf($first));
        $this->assertSame([
            'template' => 'bc/_now',
            'variables' => ['label' => 'a', 'list' => [1, 2]],
            'siteId' => Craft::$app->getSites()->getCurrentSite()->id,
        ], $include);
    }

    public function testTamperedPayloadIsRefused(): void
    {
        $payload = $this->payloadOf($this->dynamicContent->getIncludeHtml('bc/_now'));

        $tampered = str_replace('bc\/_now', '_private\/admin', $payload);

        $this->assertNotSame($payload, $tampered);
        $this->assertNull($this->dynamicContent->resolveIncludePayload($tampered));
        $this->assertNull($this->dynamicContent->resolveIncludePayload('garbage'));
    }

    public function testObjectVariablesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->dynamicContent->getIncludeHtml('bc/_now', ['site' => Craft::$app->getSites()->getCurrentSite()]);
    }

    private function payloadOf(string $html): string
    {
        preg_match('/' . Constants::INCLUDE_PLACEHOLDER_ATTRIBUTE . '="([^"]*)"/', $html, $match);

        return html_entity_decode($match[1]);
    }
}
