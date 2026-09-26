<?php

namespace App\Support;

use Sentry\Event;
use Sentry\EventHint;

class SentryEventSanitizer
{
    public static function sanitize(Event $event, ?EventHint $hint = null): Event
    {
        $event->setRequest([]);
        $event->setUser(null);
        $event->setExtra([]);
        $event->setBreadcrumb([]);
        $event->setLogs([]);
        $event->setMetrics([]);
        $event->setMessage('Unhandled application exception.');
        $event->setTransaction(null);
        $event->setTags(array_intersect_key($event->getTags(), ['request_id' => true]));

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue('Unhandled application exception.');

            foreach ($exception->getStacktrace()?->getFrames() ?? [] as $frame) {
                $frame->setVars([]);
            }
        }

        return $event;
    }
}
