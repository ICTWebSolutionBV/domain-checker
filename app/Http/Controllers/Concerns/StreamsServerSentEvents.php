<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Shared plumbing for the Server-Sent Events endpoints.
 *
 * The contract the front end relies on: a stream always ends with a done
 * event. Before this existed, an exception raised inside the stream body --
 * after the headers were already sent -- simply truncated the response, and
 * the UI kept spinning on rows that would never arrive.
 */
trait StreamsServerSentEvents
{
    private function sseStream(callable $work, string $errorMessage = 'The check stopped early. Please try again.'): StreamedResponse
    {
        return response()->stream(function () use ($work, $errorMessage) {
            set_time_limit(0);      // SSE streams outlive the default 30s limit
            ignore_user_abort(false); // let PHP kill us once the client is gone

            // Open the pipe before any slow work so a buffering proxy does not
            // sit on the response until the first result lands.
            $this->push(': ping');

            try {
                $work();
            } catch (Throwable $e) {
                Log::error('SSE stream failed', ['exception' => $e]);

                $this->push('data: '.json_encode(['error' => $errorMessage]));
            } finally {
                $this->push('data: '.json_encode(['done' => true]));
            }
        }, 200, $this->sseHeaders());
    }

    /**
     * Write one frame and get it onto the wire.
     *
     * ob_flush() only works when an output buffer exists -- the CLI SAPI has
     * none and hard-codes output_buffering=0 -- while flush() always pushes to
     * the SAPI.
     */
    private function push(string $line): void
    {
        echo $line."\n\n";

        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }

    /**
     * @return array<string, string>
     */
    private function sseHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ];
    }
}
