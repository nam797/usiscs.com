<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Mail\HtmlToText;
use PHPUnit\Framework\TestCase;

final class HtmlToTextTest extends TestCase
{
    public function testKeepsTheWordsLinksAndLineBreaks(): void
    {
        $html = <<<'HTML'
            <html><head><style>p { color: red; }</style></head><body>
            <div style="display: none; max-height: 0;">Preview line</div>
            <h1>Thanks for your order, Jane!</h1>
            <p>Order <strong>000000042</strong> &amp; more.</p>
            <p><a href="https://usiscs.com/order/abc?x=1&amp;y=2" style="color: #fff;">View your order</a></p>
            <table><tr><td>Hard hat</td><td>Qty 2</td></tr></table>
            <p><a href="https://usiscs.com/"><img src="https://usiscs.com/email/logo.png" alt="US Industrial Supply"></a></p>
            <p><a href="https://usiscs.com/">https://usiscs.com/</a></p>
            </body></html>
            HTML;

        self::assertSame(
            "Thanks for your order, Jane!\n\nOrder 000000042 & more.\n\nView your order: https://usiscs.com/order/abc?x=1&y=2\n\nHard hat Qty 2\n\nhttps://usiscs.com/\n",
            HtmlToText::convert($html),
        );
    }
}
