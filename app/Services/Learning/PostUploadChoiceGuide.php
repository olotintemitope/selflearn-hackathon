<?php

namespace App\Services\Learning;

use InvalidArgumentException;

/** Gives a simple next-step suggestion from the learner's stated intent and available material. */
final class PostUploadChoiceGuide
{
    public const INTENT_PRACTISE = 'practise';

    public const INTENT_LEARN_FIRST = 'learn_first';

    public const INTENT_UNSURE = 'unsure';

    /**
     * @return array{route: 'practise'|'learn_first'|null, reason: string}
     */
    public function recommend(string $intent, int $readyQuestionCount, int $heldQuestionCount, int $relatedConceptCount): array
    {
        if (! in_array($intent, [self::INTENT_PRACTISE, self::INTENT_LEARN_FIRST, self::INTENT_UNSURE], true)) {
            throw new InvalidArgumentException('Choose practise, learn_first, or unsure.');
        }

        if ($readyQuestionCount < 0 || $heldQuestionCount < 0 || $relatedConceptCount < 0) {
            throw new InvalidArgumentException('Availability counts cannot be negative.');
        }

        $practiceAvailable = $readyQuestionCount > 0;
        $learningAvailable = $relatedConceptCount > 0;

        if ($intent === self::INTENT_PRACTISE) {
            if ($practiceAvailable) {
                return [
                    'route' => self::INTENT_PRACTISE,
                    'reason' => 'You can practise now. '.self::countAvailability($readyQuestionCount, 'question').' to try.',
                ];
            }

            return [
                'route' => null,
                'reason' => $heldQuestionCount > 0
                    ? 'Some practice questions need a quick review before you can try them.'
                    : self::unavailableRouteReason($learningAvailable, $relatedConceptCount),
            ];
        }

        if ($intent === self::INTENT_LEARN_FIRST) {
            if ($learningAvailable) {
                return [
                    'route' => self::INTENT_LEARN_FIRST,
                    'reason' => 'You can start by learning these ideas. '.self::countAvailability($relatedConceptCount, 'related idea').' to explore.',
                ];
            }

            return [
                'route' => null,
                'reason' => $practiceAvailable
                    ? 'There are no related ideas ready to learn yet. You can practise the available questions instead.'
                    : 'There are no related ideas ready to learn yet.',
            ];
        }

        if ($practiceAvailable && ! $learningAvailable) {
            return [
                'route' => self::INTENT_PRACTISE,
                'reason' => self::countAvailability($readyQuestionCount, 'practice question').', so you could try a few now.',
            ];
        }

        if ($learningAvailable && ! $practiceAvailable) {
            return [
                'route' => self::INTENT_LEARN_FIRST,
                'reason' => self::countAvailability($relatedConceptCount, 'related idea').', so you could start by learning them.',
            ];
        }

        if ($practiceAvailable && $learningAvailable) {
            return [
                'route' => self::INTENT_LEARN_FIRST,
                'reason' => 'You have '.self::countLabel($relatedConceptCount, 'related idea').' to learn first. You can also practise '.self::countLabel($readyQuestionCount, 'question').' now if you prefer.',
            ];
        }

        return [
            'route' => null,
            'reason' => $heldQuestionCount > 0
                ? 'Some practice questions need a quick review. No related ideas are ready to learn yet.'
                : 'There are no practice questions or related ideas ready yet.',
        ];
    }

    private static function unavailableRouteReason(bool $learningAvailable, int $relatedConceptCount): string
    {
        return $learningAvailable
            ? 'There are no practice questions ready yet. You can learn '.self::countLabel($relatedConceptCount, 'related idea').' first.'
            : 'There are no practice questions ready yet.';
    }

    private static function countLabel(int $count, string $singular): string
    {
        return $count.' '.($count === 1 ? $singular : $singular.'s');
    }

    private static function countAvailability(int $count, string $singular): string
    {
        $verb = $count === 1 ? 'is' : 'are';

        return 'There '.$verb.' '.self::countLabel($count, $singular).' ready';
    }
}
