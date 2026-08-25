<?php

declare(strict_types=1);

/**
 * RoadRunner adapter: translates the PSR-7 request/response pair the RR
 * worker protocol hands us into the shared Request/Response DTOs and
 * dispatches through the shared Router.
 */

require __DIR__ . '/vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use RuntimeLab\Http\Request;
use RuntimeLab\Routing\RouteRegistry;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;

const RUNTIME_NAME = 'roadrunner';

/**
 * RoadRunner's worker protocol is a binary frame written to STDOUT; any
 * stray PHP warning/notice printed there (display_errors defaults to
 * stdout in CLI) corrupts the frame and crashes the worker with a CRC
 * error. Errors are redirected to stderr instead, which RR reads
 * separately and logs normally.
 */
ini_set('display_errors', 'stderr');

$psr17Factory = new Psr17Factory();
$roadRunnerWorker = Worker::create();
$psr7Worker = new PSR7Worker($roadRunnerWorker, $psr17Factory, $psr17Factory, $psr17Factory);

$router = RouteRegistry::build();

while (true) {
    try {
        $psrRequest = $psr7Worker->waitRequest();
    } catch (Throwable $exception) {
        // Reported, not swallowed. This is the failure path of the fragile
        // half of this file -- the binary frame described above -- so a
        // silent 500 here would hide exactly the class of problem the
        // stderr redirect exists to make visible.
        //
        // And no respond() call: waitRequest() threw, so no request arrived.
        // Answering one that does not exist writes an unpaired frame into
        // that same protocol, which is how a desync starts rather than how
        // one is recovered from.
        $psr7Worker->getWorker()->error((string) $exception);

        continue;
    }

    $hasNoMoreRequests = $psrRequest === null;

    if ($hasNoMoreRequests) {
        break;
    }

    try {
        $path = $psrRequest->getUri()->getPath();

        $request = new Request($path);
        $response = $router->dispatch($request, RUNTIME_NAME);

        $responseStream = $psr17Factory->createStream($response->toJson());

        $psrResponse = $psr17Factory
            ->createResponse($response->statusCode->value, $response->statusCode->reasonPhrase())
            ->withHeader('Content-Type', 'application/json')
            ->withBody($responseStream);

        $psr7Worker->respond($psrResponse);
    } catch (Throwable $exception) {
        $psr7Worker->getWorker()->error((string) $exception);
    }
}
