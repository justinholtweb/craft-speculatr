<?php

namespace justinholtweb\speculatr\models;

use craft\base\Model;

/**
 * What would happen to a link to one URL.
 *
 * The answer to the only question anybody actually asks about this plugin, which is not "what are
 * my rules" but "is it going to prerender the thing that logs me out".
 */
class Verdict extends Model
{
    public string $url = '';

    /** @var bool Whether the URL is speculated at all. */
    public bool $speculated = false;

    public bool $prefetch = false;

    public bool $prerender = false;

    /** @var string The eagerness the prefetch half uses. */
    public string $eagerness = '';

    /** @var string The eagerness the prerender half uses, which `both` holds a step back. */
    public string $prerenderEagerness = '';

    /** @var Exclusion|null The exclusion that stopped it, when one did. */
    public ?Exclusion $blockedBy = null;

    /** @var string Why, in words. */
    public string $reason = '';

    /**
     * @var string[] Things true of the answer that a URL alone cannot settle — chiefly that a
     *               selector exclusion may still apply to the anchor.
     */
    public array $caveats = [];

    /** A one-line summary, for the console and the control panel's table. */
    public function summary(): string
    {
        if (!$this->speculated) {
            return 'excluded';
        }

        $parts = [];

        if ($this->prerender) {
            $parts[] = 'prerender (' . $this->prerenderEagerness . ')';
        }

        if ($this->prefetch) {
            $parts[] = 'prefetch (' . $this->eagerness . ')';
        }

        return implode(' + ', $parts);
    }
}
