<?php

namespace justinholtweb\speculatr\models;

use craft\base\Model;

/**
 * One reason a link is never speculated.
 *
 * An exclusion knows two things: the URL pattern or CSS selector that expresses it to the browser,
 * and how to answer the same question in PHP so the control panel can explain a URL without
 * shipping a URLPattern implementation nobody asked for.
 *
 * Those two answers are written from the same value, deliberately. A pattern that is emitted but
 * cannot be evaluated is a rule nobody can check, and a check that does not correspond to the
 * emitted pattern is worse than no check at all.
 */
class Exclusion extends Model
{
    /** A path pattern: `/admin/*`. Matches the pathname, whatever the query string. */
    public const KIND_PATH = 'path';

    /** A query parameter by name: any URL carrying `?token=…`. */
    public const KIND_PARAM = 'param';

    /**
     * File extensions: `.pdf`, `.zip`, whatever the query string.
     *
     * One exclusion carries the whole list rather than one per extension. Thirty-odd separate
     * predicates were most of the bytes of the rules document, and this document is sent on every
     * page — a performance feature has no business being two kilobytes of its own overhead.
     */
    public const KIND_EXTENSION = 'extension';

    /**
     * A query parameter whose value starts with something: `?p=actions/…`.
     *
     * For installs that keep `index.php` in their URLs and route by `pathParam`, where the logout
     * URL is `/index.php?p=logout` and no path pattern can see it. Excluding the parameter outright
     * would exclude every page on such a site, so it is the value that is matched. `value` holds
     * `name=prefix`.
     */
    public const KIND_PARAM_VALUE = 'paramValue';

    /** Any query string at all. */
    public const KIND_QUERY = 'query';

    /** A CSS selector matched against the anchor, not the URL. */
    public const KIND_SELECTOR = 'selector';

    /** Craft's own configuration said so. */
    public const SOURCE_CRAFT = 'craft';

    /** Speculatr ships it. */
    public const SOURCE_BUILTIN = 'builtin';

    /** Somebody typed it into the settings. */
    public const SOURCE_SETTINGS = 'settings';

    /** A template added it for this request. */
    public const SOURCE_TEMPLATE = 'template';

    public string $kind = self::KIND_PATH;

    /** @var string The raw value — a path, a parameter name, an extension, or a selector. */
    public string $value = '';

    /** @var string Why this is here, in words, for the control panel. */
    public string $reason = '';

    public string $source = self::SOURCE_BUILTIN;

    public static function path(string $value, string $reason, string $source = self::SOURCE_BUILTIN): self
    {
        return new self([
            'kind' => self::KIND_PATH,
            'value' => '/' . ltrim(trim($value), '/'),
            'reason' => $reason,
            'source' => $source,
        ]);
    }

    public static function param(string $value, string $reason, string $source = self::SOURCE_BUILTIN): self
    {
        return new self(['kind' => self::KIND_PARAM, 'value' => trim($value), 'reason' => $reason, 'source' => $source]);
    }

    public static function paramValue(string $name, string $prefix, string $reason, string $source = self::SOURCE_BUILTIN): self
    {
        return new self([
            'kind' => self::KIND_PARAM_VALUE,
            'value' => trim($name) . '=' . trim($prefix, '/ '),
            'reason' => $reason,
            'source' => $source,
        ]);
    }

    /** @param string[] $extensions */
    public static function extensions(array $extensions, string $reason, string $source = self::SOURCE_BUILTIN): self
    {
        $clean = [];

        foreach ($extensions as $extension) {
            $extension = strtolower(ltrim(trim((string)$extension), '.'));

            if ($extension !== '') {
                $clean[] = $extension;
            }
        }

        return new self([
            'kind' => self::KIND_EXTENSION,
            'value' => implode(' ', array_unique($clean)),
            'reason' => $reason,
            'source' => $source,
        ]);
    }

    public static function query(string $reason, string $source = self::SOURCE_SETTINGS): self
    {
        return new self(['kind' => self::KIND_QUERY, 'value' => '?', 'reason' => $reason, 'source' => $source]);
    }

    public static function selector(string $value, string $reason, string $source = self::SOURCE_BUILTIN): self
    {
        return new self(['kind' => self::KIND_SELECTOR, 'value' => trim($value), 'reason' => $reason, 'source' => $source]);
    }

    /** Whether this exclusion is expressed as a CSS selector rather than a URL pattern. */
    public function isSelector(): bool
    {
        return $this->kind === self::KIND_SELECTOR;
    }

    /**
     * The `href_matches` patterns this exclusion emits.
     *
     * Plural, because a path exclusion is genuinely two patterns: `/admin/*` does not match
     * `/admin` itself, so the bare form has to be emitted alongside it or the one URL most worth
     * excluding is the one that gets through.
     *
     * Every URL pattern form here was checked against a real `URLPattern` before it was written
     * down, because the failure mode of a wrong one is silent: the browser accepts the rules,
     * matches nothing, and the exclusion simply never applies.
     *
     * @return string[]
     */
    public function patterns(): array
    {
        return match ($this->kind) {
            self::KIND_PATH => $this->pathPatterns(),

            // An escaped `?` ends the pathname component and starts the search component, so this
            // reads as "any path, whose query has `name=` at the start or after an `&`". The
            // `(^|&)` is a regex group, which is why `?other_name=` does not match.
            self::KIND_PARAM => ['/*\\?*(^|&)' . $this->value . '=*'],

            // The same shape with the value's start pinned. No `/` after the prefix: Craft may
            // write `p=actions/users/logout` or `p=actions%2Fusers%2Flogout` depending on how the
            // URL was built, and the shorter prefix covers both.
            self::KIND_PARAM_VALUE => ['/*\\?*(^|&)' . $this->paramName() . '=' . self::literal($this->paramPrefix()) . '*'],

            // One alternation group over every extension. A character class per letter, because
            // URL patterns have no case-insensitive flag and `/*.pdf` does not match
            // `/brochure.PDF`.
            self::KIND_EXTENSION => $this->extensionPatterns(),

            // `(.+)` rather than `*`, which would also match the empty search of a URL with no
            // query string at all — i.e. everything.
            self::KIND_QUERY => ['/*\\?(.+)'],

            default => [],
        };
    }

    /**
     * An exact path is emitted as `/logout{/}?`, because Craft trims slashes before routing:
     * `/logout/` signs a reader out exactly as `/logout` does, and `addTrailingSlashesToUrls`
     * makes it the form Craft itself writes. A bare `/logout` pattern does not match it.
     *
     * @return string[]
     */
    private function pathPatterns(): array
    {
        if (str_ends_with($this->value, '/*')) {
            $base = rtrim(substr($this->value, 0, -2), '/');

            return $base === '' ? ['/*'] : [self::literal($base), self::literal($this->value)];
        }

        if (str_contains($this->value, '*')) {
            return [self::literal($this->value)];
        }

        $exact = rtrim($this->value, '/');

        return $exact === '' ? ['/'] : [self::literal($exact) . '{/}?'];
    }

    /**
     * A typed path made safe to put in a URL pattern, with `*` left as the wildcard.
     *
     * URL pattern syntax is not inert: `/cart(` throws, which makes Chrome reject the whole
     * ruleset — speculation silently stops everywhere — and `:name`, `{}`, `+` and `?` all mean
     * something. A backslash makes most of them literal. A colon is the exception: `\:` throws in
     * the string form (the constructor reads it as the end of a protocol), and `{\:}` does not.
     */
    public static function literal(string $value): string
    {
        $out = '';

        foreach (str_split($value) as $character) {
            $out .= match ($character) {
                ':' => '{\\:}',
                '\\', '(', ')', '{', '}', '+', '?' => '\\' . $character,
                default => $character,
            };
        }

        return $out;
    }

    private function paramName(): string
    {
        return explode('=', $this->value, 2)[0];
    }

    private function paramPrefix(): string
    {
        return explode('=', $this->value, 2)[1] ?? '';
    }

    /** The `selector_matches` value, or null for a URL pattern. */
    public function selectorPattern(): ?string
    {
        return $this->kind === self::KIND_SELECTOR ? $this->value : null;
    }

    /**
     * Whether this exclusion covers a URL, answered the same way the emitted pattern would answer.
     *
     * Selector exclusions always return false: they are a fact about an anchor element, and a URL
     * on its own does not carry one. The control panel says so rather than pretending.
     *
     * @param array<string, mixed> $params The parsed query string.
     */
    public function matchesUrl(string $path, array $params): bool
    {
        $path = '/' . ltrim($path, '/');

        return match ($this->kind) {
            self::KIND_PATH => $this->matchesPath($path),
            self::KIND_PARAM => array_key_exists($this->value, $params),
            self::KIND_PARAM_VALUE => is_string($params[$this->paramName()] ?? null)
                && str_starts_with(ltrim($params[$this->paramName()], '/'), $this->paramPrefix()),
            self::KIND_EXTENSION => $this->matchesExtension($path),
            self::KIND_QUERY => $params !== [],
            default => false,
        };
    }

    /**
     * The same question the emitted patterns answer.
     *
     * `fnmatch` without `FNM_PATHNAME`, so `*` crosses `/` — which is what a URL pattern's `*`
     * does too. The `/admin/*` case is special-cased rather than left to `fnmatch`, because the
     * pair of patterns it emits also covers the bare `/admin`.
     */
    private function matchesPath(string $path): bool
    {
        if (str_ends_with($this->value, '/*')) {
            $base = rtrim(substr($this->value, 0, -2), '/');

            return $base === '' || $path === $base || str_starts_with($path, $base . '/');
        }

        if (str_contains($this->value, '*')) {
            return fnmatch($this->value, $path);
        }

        // Either side may carry the trailing slash; the emitted `{/}?` accepts both.
        return rtrim($path, '/') === rtrim($this->value, '/');
    }

    /** @return string[] */
    private function extensionPatterns(): array
    {
        $extensions = $this->extensionList();

        if ($extensions === []) {
            return [];
        }

        return ['/*.(' . implode('|', array_map($this->caseInsensitive(...), $extensions)) . ')'];
    }

    private function matchesExtension(string $path): bool
    {
        $path = strtolower($path);

        foreach ($this->extensionList() as $extension) {
            if (str_ends_with($path, '.' . $extension)) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    public function extensionList(): array
    {
        return array_values(array_filter(explode(' ', $this->value), static fn($e) => $e !== ''));
    }

    /** `pdf` becomes `[pP][dD][fF]` — matches every casing, with no flag to ask for. */
    private function caseInsensitive(string $value): string
    {
        $out = '';

        foreach (str_split($value) as $character) {
            $lower = strtolower($character);
            $upper = strtoupper($character);
            $out .= $lower === $upper ? $lower : '[' . $lower . $upper . ']';
        }

        return $out;
    }
}
