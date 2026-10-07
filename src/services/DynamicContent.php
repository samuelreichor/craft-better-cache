<?php

namespace samuelreichor\betterCache\services;

use Craft;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use samuelreichor\betterCache\Constants;
use yii\base\Component;
use yii\base\InvalidArgumentException;

class DynamicContent extends Component
{
    private bool $scriptRendered = false;

    /**
     * @param array<string, mixed> $variables scalars and arrays only, since they are signed into the page
     */
    public function getIncludeHtml(string $template, array $variables = []): string
    {
        $this->assertSerializable($variables);
        $payload = Craft::$app->getSecurity()->hashData(Json::encode([
            'template' => $template,
            'variables' => $variables,
            'siteId' => Craft::$app->getSites()->getCurrentSite()->id,
        ]));

        $html = Html::tag('span', '', [Constants::INCLUDE_PLACEHOLDER_ATTRIBUTE => $payload]);
        if (!$this->scriptRendered) {
            $html .= $this->getScriptTag();
            $this->scriptRendered = true;
        }

        return $html;
    }

    /**
     * @return array{template: string, variables: array<string, mixed>, siteId: int}|null null if the payload was tampered with
     */
    public function resolveIncludePayload(string $payload): ?array
    {
        $data = Craft::$app->getSecurity()->validateData($payload);

        return $data === false ? null : Json::decode($data);
    }

    /**
     * Swaps the visitor's CSRF tokens for placeholders, since a stored page is served to everyone.
     */
    public function prepareForStorage(string $html): string
    {
        $tokenName = preg_quote(Craft::$app->getConfig()->getGeneral()->csrfTokenName, '/');
        $html = (string)preg_replace_callback(
            "/<input\\b[^>]*\\bname=([\"'])$tokenName\\1[^>]*>/i",
            fn(array $match) => $this->toPlaceholder($match[0], 'value'),
            $html,
        );
        $metaName = preg_quote(Constants::CSRF_META_NAME, '/');
        $html = (string)preg_replace_callback(
            "/<meta\\b[^>]*\\bname=([\"'])$metaName\\1[^>]*>/i",
            fn(array $match) => $this->toPlaceholder($match[0], 'content'),
            $html,
        );

        $hasPlaceholders = str_contains($html, Constants::CSRF_PLACEHOLDER_ATTRIBUTE)
            || str_contains($html, Constants::INCLUDE_PLACEHOLDER_ATTRIBUTE);
        if (!$hasPlaceholders || str_contains($html, Constants::SCRIPT_MARKER_ATTRIBUTE)) {
            return $html;
        }

        $script = $this->getScriptTag();
        $bodyEnd = strripos($html, '</body>');

        return $bodyEnd === false ? $html . $script : substr_replace($html, $script, $bodyEnd, 0);
    }

    public function getScriptTag(): string
    {
        $config = [
            'csrfUrl' => UrlHelper::actionUrl(Constants::CSRF_ACTION),
            'includeUrl' => UrlHelper::actionUrl(Constants::INCLUDE_ACTION),
            'includeParam' => Constants::INCLUDE_PAYLOAD_PARAM,
            'csrfAttribute' => Constants::CSRF_PLACEHOLDER_ATTRIBUTE,
            'includeAttribute' => Constants::INCLUDE_PLACEHOLDER_ATTRIBUTE,
        ];

        $js = trim((string)file_get_contents(dirname(__DIR__) . '/web/dynamic.js'));

        return Html::tag('script', $js . '(' . Json::encode($config) . ');', [
            Constants::SCRIPT_MARKER_ATTRIBUTE => true,
        ]);
    }

    /**
     * The placeholder attribute names the attribute the script fills with the token.
     */
    private function toPlaceholder(string $tag, string $valueAttribute): string
    {
        $tag = (string)preg_replace("/\\s$valueAttribute=([\"']).*?\\1/i", " $valueAttribute=\"\"", $tag);
        $placeholder = ' ' . Constants::CSRF_PLACEHOLDER_ATTRIBUTE . "=\"$valueAttribute\"";

        return (string)preg_replace('/\s*\/?>$/', $placeholder . '$0', $tag, 1);
    }

    private function assertSerializable(mixed $value): void
    {
        if (is_array($value)) {
            array_walk($value, fn($item) => $this->assertSerializable($item));
        } elseif ($value !== null && !is_scalar($value)) {
            throw new InvalidArgumentException('Dynamic include variables must be scalars or arrays.');
        }
    }
}
