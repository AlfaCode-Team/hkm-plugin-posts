<?php

declare(strict_types=1);

namespace Plugins\Posts\Application\Contracts;

use Plugins\Posts\Domain\ValueObjects\BodyFormat;
use Plugins\Posts\Domain\ValueObjects\RenderedBody;

/**
 * Turns a stored body into SAFE HTML plus a table of contents.
 *
 * The service depends on this interface; the vendor-backed implementation
 * lives in Infrastructure/Rendering — the Service → Gateway edge of the access
 * rules, kept behind an interface so the service can be tested without the
 * libraries and so a project can bind its own renderer.
 *
 * Contract every implementation must keep: the returned `html` is safe to
 * print into a page as-is. No script, no event-handler attribute, no
 * `javascript:` URL survives, whatever the input.
 */
interface BodyRendererContract
{
    public function render(string $body, BodyFormat $format): RenderedBody;
}
