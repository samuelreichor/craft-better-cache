<?php

namespace samuelreichor\betterCache\variables;

use samuelreichor\betterCache\BetterCache;
use Twig\Markup;

class BetterCacheVariable
{
    /**
     * Renders the template in the browser after load, fresh for every visitor.
     *
     * @param array<string, mixed> $variables
     */
    public function include(string $template, array $variables = []): Markup
    {
        return new Markup(BetterCache::getInstance()->dynamicContent->getIncludeHtml($template, $variables), 'UTF-8');
    }
}
