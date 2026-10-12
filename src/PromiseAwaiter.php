<?php

declare(strict_types=1);

namespace DiscordMcp;

use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

/** Sequential MCP callback boundary; the SDK's fibers do not run ReactPHP's loop. */
final class PromiseAwaiter
{
    public static function wait(PromiseInterface $promise): mixed
    {
        $settled = false;
        $value = null;
        $error = null;
        $running = false;
        $promise->then(
            static function ($result) use (&$settled, &$value, &$running): void {
                $settled = true;
                $value = $result;
                if ($running) {
                    Loop::stop();
                }
            },
            static function (\Throwable $exception) use (&$settled, &$error, &$running): void {
                $settled = true;
                $error = $exception;
                if ($running) {
                    Loop::stop();
                }
            }
        );
        if (!$settled) {
            $running = true;
            Loop::run();
        }
        if ($error !== null) {
            throw $error;
        }
        if (!$settled) {
            throw new \RuntimeException('Discord promise did not settle.');
        }

        return $value;
    }
}
