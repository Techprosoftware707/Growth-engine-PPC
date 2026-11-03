<?php
/**
 * Fallback adapter: hands the package to a person with step-by-step instructions.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Submission\Adapters;

final class ManualPackageAdapter implements AdapterInterface {

    public function id(): string {
        return 'manual_package';
    }

    public function label(): string {
        return 'Manual package (a person submits)';
    }

    /** @inheritDoc */
    public function supports( array $prospect, array $payload ): bool {
        return true;
    }

    /** @inheritDoc */
    public function submit( array $submission, array $prospect, array $payload ): array {
        $instructions = [];
        foreach ( (array) ( $payload['instructions'] ?? [] ) as $step ) {
            if ( is_scalar( $step ) && '' !== trim( (string) $step ) ) {
                $instructions[] = trim( (string) $step );
            }
        }
        if ( [] === $instructions ) {
            $instructions[] = 'Open the target page and complete the listing using the business details and generated content in this package.';
            $instructions[] = 'After submitting, use "Mark submitted" to record the confirmation reference and listing URL.';
        }

        return [
            'status'           => self::STATUS_NEEDS_MANUAL,
            'response'         => [
                'adapter'      => $this->id(),
                'target_url'   => (string) ( $submission['target_url'] ?? '' ),
                'instructions' => $instructions,
                'at'           => gmdate( 'c' ),
            ],
            'confirmation_ref' => '',
            'listing_url'      => '',
            'error'            => 'Manual submission required: this package must be submitted by a person.',
            'retryable'        => false,
        ];
    }
}
