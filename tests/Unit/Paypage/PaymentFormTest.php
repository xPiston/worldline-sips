<?php

declare(strict_types=1);

namespace Piston\Sips\Tests\Unit\Paypage;

use PHPUnit\Framework\TestCase;
use Piston\Sips\Paypage\PaymentForm;

final class PaymentFormTest extends TestCase
{
    public function test_it_exposes_the_three_fields_sips_expects(): void
    {
        $form = $this->form();

        $this->assertSame('amount=2500|currencyCode=978', $form->data());
        $this->assertSame(str_repeat('a', 64), $form->seal());
        $this->assertSame('HP_2.57', $form->interfaceVersion());
        $this->assertSame('https://payment-webinit.simu.sips-services.com/paymentInit', $form->action());
    }

    public function test_it_renders_hidden_inputs(): void
    {
        $this->assertSame(
            '<input type="hidden" name="Data" value="amount=2500|currencyCode=978">'."\n"
            .'<input type="hidden" name="InterfaceVersion" value="HP_2.57">'."\n"
            .'<input type="hidden" name="Seal" value="'.str_repeat('a', 64).'">',
            $this->form()->toInputs(),
        );
    }

    /**
     * The escaping happens here and nowhere earlier: the seal was computed
     * over the raw Data string, and a `returnContext` with an apostrophe has
     * to reach the browser inside an attribute without ending it.
     */
    public function test_it_escapes_for_html_without_touching_what_was_sealed(): void
    {
        $data = 'returnContext=l\'Église & <co> "x"';
        $form = new PaymentForm(['Data' => $data, 'Seal' => str_repeat('b', 64)], 'https://p.test/paymentInit');

        $html = $form->toInputs();

        $this->assertStringContainsString('value="returnContext=l&#039;Église &amp; &lt;co&gt; &quot;x&quot;"', $html);
        $this->assertSame($data, $form->data());
    }

    public function test_it_escapes_the_action(): void
    {
        $form = new PaymentForm([], 'https://p.test/paymentInit?a=1&b=2');

        $this->assertStringContainsString('action="https://p.test/paymentInit?a=1&amp;b=2"', $form->toHtml());
    }

    public function test_it_renders_a_whole_form(): void
    {
        $html = $this->form()->toHtml('Payer 25,00 €');

        $this->assertStringStartsWith('<form action="https://payment-webinit.simu.sips-services.com/paymentInit" method="post">', $html);
        $this->assertStringContainsString('<button type="submit">Payer 25,00 €</button>', $html);
        $this->assertStringEndsWith('</form>', $html);
    }

    public function test_the_form_attributes_can_be_replaced(): void
    {
        $this->assertStringContainsString(
            'method="post" target="_top" class="checkout"',
            $this->form()->toHtml('Pay', 'method="post" target="_top" class="checkout"'),
        );
    }

    public function test_a_form_without_a_seal_says_so_rather_than_breaking(): void
    {
        $form = new PaymentForm([], 'https://p.test/paymentInit');

        $this->assertSame('', $form->seal());
        $this->assertSame('', $form->data());
        $this->assertSame('', $form->interfaceVersion());
        $this->assertSame([], $form->fields());
    }

    private function form(): PaymentForm
    {
        return new PaymentForm([
            'Data' => 'amount=2500|currencyCode=978',
            'InterfaceVersion' => 'HP_2.57',
            'Seal' => str_repeat('a', 64),
        ], 'https://payment-webinit.simu.sips-services.com/paymentInit');
    }
}
