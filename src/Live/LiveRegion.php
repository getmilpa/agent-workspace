<?php

/**
 * This file is part of milpa/agent-workspace — the agent's workspace inside a Milpa app.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/agent-workspace
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Live;

/**
 * A region of the page that can be re-read from the house without a reload (greenhouse decisions/0563).
 *
 * What the house decides is DERIVED here, on the server: a seat's frontier from the refused calls, the
 * enrollment ledger and the authoring policy; the verdict from the whole stream. A pushed fact is narrower
 * than any of them, so the page does not build these from a push — `desktop-regions.js` requests the page it
 * is on and swaps in the element this marks. Measured (evidence/1095): with 1,209 pushes received, the
 * frontier card, the question's card and «Verified by the house» were painted only after a reload.
 *
 * A region holds the WHOLE of what a re-read must replace — a list and its empty line, the verdict and its
 * cards — and never a signed state envelope: that one was signed for the page that was served.
 */
final class LiveRegion
{
    /** The questions sessions parked, with the buttons that answer them. */
    public const string DECISIONS_PENDING = 'decisions.pending';

    /** The refusals a human who answers for a seat can decide (greenhouse decisions/0493). */
    public const string DECISIONS_FRONTIER = 'decisions.frontier';

    /** The session's work and the house's verdict on it (greenhouse decisions/0509 §7). */
    public const string WORK = 'work';

    /** The session's figures, re-seeded as signals — a region with no markup of its own. */
    public const string SIGNALS = 'signals';

    /** The attribute `desktop-regions.js` finds a region by. */
    public const string ATTRIBUTE = 'data-live-region';

    /**
     * Every region name a client module may ask for.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::DECISIONS_PENDING, self::DECISIONS_FRONTIER, self::WORK, self::SIGNALS];
    }

    /**
     * Mark `$html` as one region. The wrapper is `display: contents` (each surface's stylesheet says so), so
     * the layout its children had is the layout they keep.
     */
    public static function of(string $name, string $html): string
    {
        return '<div class="milpa-live-region" ' . self::ATTRIBUTE . '="' . htmlspecialchars($name, \ENT_QUOTES) . '">' . $html . '</div>';
    }
}
