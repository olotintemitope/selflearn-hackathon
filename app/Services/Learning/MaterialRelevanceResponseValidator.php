<?php

namespace App\Services\Learning;

use App\Support\LearnerText;

final class MaterialRelevanceResponseValidator
{
    /** @return array<int, array{chunk_id:int, status:string, confidence:float, reason:string}> */
    public function validate(array $data, array $knownChunkIds): array
    {
        $items = $data['classifications'] ?? null;
        if (! is_array($items) || count($items) !== count($knownChunkIds) || $knownChunkIds === []) {
            throw new MaterialRelevanceException('Material relevance output was incomplete.');
        }

        $known = array_fill_keys($knownChunkIds, true);
        $seen = [];
        $result = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new MaterialRelevanceException('Material relevance output contained an invalid page.');
            }
            $id = $item['chunk_id'] ?? null;
            $status = $item['status'] ?? null;
            $confidence = $item['confidence'] ?? null;
            $reason = LearnerText::withoutEmDash((string) ($item['reason'] ?? ''));
            if (! is_int($id) || ! isset($known[$id]) || isset($seen[$id])
                || ! in_array($status, ['related', 'possibly_related', 'separate'], true)
                || (! is_float($confidence) && ! is_int($confidence)) || $confidence < 0 || $confidence > 1
                || $reason === '' || mb_strlen($reason) > 400) {
                throw new MaterialRelevanceException('Material relevance output failed validation.');
            }

            $seen[$id] = true;
            $result[] = [
                'chunk_id' => $id,
                'status' => $status,
                'confidence' => (float) $confidence,
                'reason' => $reason,
            ];
        }
        if (array_diff_key($known, $seen) !== []) {
            throw new MaterialRelevanceException('Material relevance output omitted a source page.');
        }

        return $result;
    }
}
