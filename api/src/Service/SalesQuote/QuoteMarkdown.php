<?php

declare(strict_types=1);

namespace MyInvoice\Service\SalesQuote;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

final class QuoteMarkdown
{
    /** @param array<int,string> $images */
    public function converter(array $images): MarkdownConverter
    {
        $env = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 30,
            'max_delimiters_per_line' => 400,
        ]);
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new StrikethroughExtension());
        $env->addExtension(new TableExtension());
        $env->addRenderer(Image::class, new class($images) implements NodeRendererInterface {
            /** @param array<int,string> $images */
            public function __construct(private readonly array $images) {}

            public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable|string
            {
                Image::assertInstanceOf($node);
                $alt = '';
                foreach ($node->iterator() as $child) {
                    if ($child instanceof StringContainerInterface) {
                        $alt .= $child->getLiteral();
                    } elseif ($child instanceof Newline) {
                        $alt .= ' ';
                    }
                }

                if (preg_match('/\Aquote-file:([1-9][0-9]{0,18})\z/D', $node->getUrl(), $match) !== 1
                    || (string) (int) $match[1] !== $match[1]
                    || !isset($this->images[(int) $match[1]])) {
                    return htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }

                $path = $this->images[(int) $match[1]];
                $dimensions = @getimagesize($path);
                if ($dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1) {
                    throw new \InvalidArgumentException('Obrázek v textu nabídky nelze vykreslit.');
                }
                $width = min($dimensions[0] * 0.265, 175.0, 130.0 * $dimensions[0] / $dimensions[1]);
                return new HtmlElement('img', [
                    'src' => $path,
                    'alt' => $alt,
                    'style' => 'width:' . round($width, 2) . 'mm',
                ], '', true);
            }
        }, 10);

        return new MarkdownConverter($env);
    }
}
