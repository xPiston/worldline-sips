<?php

declare(strict_types=1);

namespace Piston\Sips\Paypage;

/**
 * A sealed Paypage request: three fields, and two ways of sending a customer
 * to them.
 *
 * Only three, whatever the size of the request - `Data` carries everything,
 * `InterfaceVersion` says how to read it, `Seal` proves who wrote it (and
 * `Encode` joins them when the Data element is encoded).
 *
 * The Data element is already built and already sealed by the time it gets
 * here. This class only escapes it for HTML, once, at the last moment.
 */
final class PaymentForm
{
    /** @param array<string, string> $fields */
    public function __construct(
        private readonly array $fields,
        private readonly string $action,
    ) {}

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function data(): string
    {
        return $this->fields['Data'] ?? '';
    }

    public function seal(): string
    {
        return $this->fields['Seal'] ?? '';
    }

    public function interfaceVersion(): string
    {
        return $this->fields['InterfaceVersion'] ?? '';
    }

    /** The hidden inputs, HTML-escaped, for a template that owns its form. */
    public function toInputs(): string
    {
        $inputs = [];

        foreach ($this->fields as $name => $value) {
            $inputs[] = sprintf('<input type="hidden" name="%s" value="%s">', $this->escape($name), $this->escape($value));
        }

        return implode("\n", $inputs);
    }

    public function toHtml(string $submitLabel = 'Payer', string $attributes = 'method="post"'): string
    {
        return sprintf(
            '<form action="%s" %s>%s<button type="submit">%s</button></form>',
            $this->escape($this->action),
            $attributes,
            "\n".$this->toInputs()."\n",
            $this->escape($submitLabel),
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
