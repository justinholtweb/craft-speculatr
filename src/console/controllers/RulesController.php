<?php

namespace justinholtweb\speculatr\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\speculatr\models\Exclusion;
use justinholtweb\speculatr\Plugin;
use yii\console\ExitCode;

/**
 * `craft speculatr/rules/…` — the same two answers the control panel gives, for anybody who would
 * rather diff them in CI than look at them.
 */
class RulesController extends Controller
{
    /** @var bool Answer as a signed-in user rather than a guest. */
    public bool $loggedIn = false;

    public function options($actionID): array
    {
        return match ($actionID) {
            'show', 'check' => [...parent::options($actionID), 'loggedIn'],
            default => parent::options($actionID),
        };
    }

    /**
     * Prints the speculation rules document.
     */
    public function actionShow(): int
    {
        $plugin = Plugin::getInstance();
        $json = $plugin->rules->json($this->user(), true);

        if ($json === '') {
            $this->stderr("No rules are served to this audience.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout($json . "\n");

        return ExitCode::OK;
    }

    /**
     * Lists everything that is never speculated, and why.
     */
    public function actionExclusions(): int
    {
        $plugin = Plugin::getInstance();

        $labels = [
            Exclusion::SOURCE_CRAFT => 'Craft',
            Exclusion::SOURCE_BUILTIN => 'Speculatr',
            Exclusion::SOURCE_SETTINGS => 'Settings',
            Exclusion::SOURCE_TEMPLATE => 'Template',
        ];

        $this->stdout("\nExclusions\n", Console::BOLD);
        $this->stdout(str_repeat('─', 78) . "\n");

        foreach ($plugin->exclusions->all() as $exclusion) {
            $patterns = $exclusion->isSelector()
                ? [(string)$exclusion->selectorPattern()]
                : $exclusion->patterns();

            $this->stdout(sprintf(
                "  %-10s %-34s %s\n",
                $labels[$exclusion->source] ?? $exclusion->source,
                implode('  ', $patterns),
                strip_tags($exclusion->reason) . ($exclusion->signedInOnly ? ' (signed-in only)' : ''),
            ));
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    /**
     * Says what would happen to a link to one URL.
     */
    public function actionCheck(string $url): int
    {
        $verdict = Plugin::getInstance()->rules->explain($url, $this->user());

        $this->stdout("\n  " . $url . "\n");
        $this->stdout('  ' . str_repeat('─', max(10, strlen($url))) . "\n");

        if ($verdict->speculated) {
            $this->stdout('  ' . $verdict->summary() . "\n", Console::FG_GREEN);

            foreach ($verdict->caveats as $caveat) {
                $this->stdout('  ' . strip_tags($caveat) . "\n", Console::FG_GREY);
            }
        } else {
            $this->stdout("  never speculated\n", Console::FG_YELLOW);
            $this->stdout('  ' . strip_tags($verdict->reason) . "\n", Console::FG_GREY);
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }

    private function user(): ?\craft\elements\User
    {
        if (!$this->loggedIn) {
            return null;
        }

        // Any non-admin will do: the question is only which audience switch applies.
        return \craft\elements\User::find()->admin(false)->one()
            ?? \craft\elements\User::find()->one();
    }
}
