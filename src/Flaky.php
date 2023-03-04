<?php
/**
 * @author Aaron Francis <aaron@hammerstone.dev|https://twitter.com/aarondfrancis>
 */

namespace Hammerstone\Flaky;

use Illuminate\Support\Traits\Macroable;
use Throwable;

class Flaky
{
    use Macroable;

    protected static $disabledGlobally = false;

    protected $arbiter;

    protected $retry = [];

    protected $throw = true;

    protected $flakyProtectionDisabled = false;

    public static function make($id)
    {
        return new static($id);
    }

    public static function globallyDisable()
    {
        static::$disabledGlobally = true;
    }

    public static function globallyEnable()
    {
        static::$disabledGlobally = false;
    }

    public function __construct($id)
    {
        $this->retry();
        $this->arbiter = new Arbiter($id);
    }

    public function run(callable $callable)
    {
        $failed = false;
        $exception = null;
        $value = null;

        try {
            $value = retry($this->retry['times'], $callable, $this->retry['sleep'], $this->retry['when']);
        } catch (Throwable $e) {
            $failed = true;
            $exception = $e;
        }

        if ($this->protectionsBypassed() && $failed) {
            throw $exception;
        }

        $this->arbiter->handle($exception);

        return new Result($value, $exception);
    }

    public function disableFlakyProtection($disabled = true)
    {
        $this->flakyProtectionDisabled = $disabled;

        return $this;
    }

    public function retry($times = 0, $sleepMilliseconds = 0, $when = null)
    {
        // We just store these for now and then use them in the `run` method.
        $this->retry = [
            'times' => $times,
            'sleep' => $sleepMilliseconds,
            'when' => $this->normalizeRetryWhen($when),
        ];

        return $this;
    }

    public function reportFailures()
    {
        $this->arbiter->throw = false;

        return $this;
    }

    public function throwFailures()
    {
        $this->arbiter->throw = true;

        return $this;
    }

    public function allowFailuresFor($seconds = 0, $minutes = 0, $hours = 0, $days = 0)
    {
        return $this->allowFailuresForSeconds(
            $seconds + (60 * $minutes) + (60 * 60 * $hours) + (60 * 60 * 24 * $days)
        );
    }

    public function allowFailuresForSeconds($seconds)
    {
        $this->arbiter->failuresAllowedForSeconds = $seconds;

        return $this;
    }

    public function allowFailuresForAMinute()
    {
        return $this->allowFailuresForMinutes(1);
    }

    public function allowFailuresForMinutes($minutes)
    {
        return $this->allowFailuresForSeconds(60 * $minutes);
    }

    public function allowFailuresForAnHour()
    {
        return $this->allowFailuresForHours(1);
    }

    public function allowFailuresForHours($hours)
    {
        return $this->allowFailuresForSeconds(60 * 60 * $hours);
    }

    public function allowFailuresForADay()
    {
        return $this->allowFailuresForDays(1);
    }

    public function allowFailuresForDays($days)
    {
        return $this->allowFailuresForSeconds(60 * 60 * 24 * $days);
    }

    public function allowConsecutiveFailures($failures)
    {
        $this->arbiter->consecutiveFailuresAllowed = $failures;

        return $this;
    }

    public function allowTotalFailures($failures)
    {
        $this->arbiter->totalFailuresAllowed = $failures;

        return $this;
    }

    protected function protectionsBypassed()
    {
        return static::$disabledGlobally || $this->flakyProtectionDisabled;
    }

    protected function normalizeRetryWhen($when = null)
    {
        // Support for a single exception
        if (is_string($when)) {
            $when = [$when];
        }

        // Support for an array of exception types
        if (is_array($when)) {
            $when = function ($thrown) use ($when) {
                foreach ($when as $exception) {
                    if ($thrown instanceof $exception) {
                        return true;
                    }
                }

                return false;
            };
        }

        return $when;
    }
}
