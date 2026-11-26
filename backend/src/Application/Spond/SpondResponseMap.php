<?php

declare(strict_types=1);

namespace App\Application\Spond;

use App\Domain\Spond\SpondReply;

/** Maps Spond response id lists onto accepted, declined, and unanswered. */
final class SpondResponseMap
{
    /**
     * The first list wins when an id appears more than once.
     *
     * @param array<string, mixed> $responses
     * @return list<array{spondId: string, answer: SpondReply}>
     */
    public function answers(array $responses): array
    {
        $order = [
            'acceptedIds' => SpondReply::Accepted,
            'declinedIds' => SpondReply::Declined,
            'unansweredIds' => SpondReply::Unanswered,
        ];
        $seen = [];
        $answers = [];
        foreach ($order as $key => $reply) {
            $ids = $responses[$key] ?? [];
            if (!is_array($ids)) {
                continue;
            }
            foreach ($ids as $id) {
                if (!is_string($id)) {
                    continue;
                }
                $id = trim($id);
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $answers[] = ['spondId' => $id, 'answer' => $reply];
            }
        }

        return $answers;
    }
}
