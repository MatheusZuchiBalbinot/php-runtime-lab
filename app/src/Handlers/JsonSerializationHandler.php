<?php

declare(strict_types=1);

namespace RuntimeLab\Handlers;

use RuntimeLab\Config\PerformanceConfig;
use RuntimeLab\Http\HttpStatusCode;
use RuntimeLab\Http\Request;
use RuntimeLab\Http\Response;
use RuntimeLab\Http\ResponseEnvelope;
use RuntimeLab\Routing\RouteHandlerInterface;

/**
 * Builds and json_encode()s a moderately large nested structure.
 *
 * The response carries only the encoded payload's size and hash, not the
 * payload, so what is measured is the CPU and allocation cost of serialising
 * rather than the time to put bytes on the wire.
 *
 * Much of that cost is inside json_encode(), which is C. CpuBoundHandler is the
 * route that deliberately avoids C and stays in the VM.
 */
final class JsonSerializationHandler implements RouteHandlerInterface
{
    public function handle(Request $request, string $runtime): Response
    {
        $recordCount = PerformanceConfig::jsonPayloadRecordCount();
        $encodedPayload = json_encode($this->buildPayload($recordCount), JSON_THROW_ON_ERROR);
        $encodedHash = hash('sha256', $encodedPayload);

        $previewLength = PerformanceConfig::jsonEncodedHashPreviewLength();

        $responseFields = [
            'record_count' => $recordCount,
            'encoded_bytes' => strlen($encodedPayload),
            'encoded_hash' => substr($encodedHash, 0, $previewLength),
        ];

        return new Response(HttpStatusCode::OK, ResponseEnvelope::ok($runtime, $responseFields));
    }

    /**
     * @return list<array{id: int, name: string, tags: list<string>, active: bool}>
     */
    private function buildPayload(int $recordCount): array
    {
        $records = [];

        for ($id = 0; $id < $recordCount; $id++) {
            $isActiveRecord = $id % 2 === 0;

            $records[] = [
                'id' => $id,
                'name' => "record-{$id}",
                'tags' => ['bench', 'json', "batch-{$id}"],
                'active' => $isActiveRecord,
            ];
        }

        return $records;
    }
}
