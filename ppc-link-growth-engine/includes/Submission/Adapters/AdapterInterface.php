<?php
/**
 * Contract for submission adapters (manual package, generic listing form, configured partner API).
 *
 * Adapters only ever act inside the authorization gates enforced by Submission\Policy:
 * the automation master switch, per-prospect authorization, no CAPTCHA / login / robots bypass.
 *
 * @package PPC\LinkGrowth
 */

declare(strict_types=1);

namespace PPC\LinkGrowth\Submission\Adapters;

interface AdapterInterface {

    public const STATUS_SUBMITTED    = 'submitted';
    public const STATUS_FAILED       = 'failed';
    public const STATUS_NEEDS_MANUAL = 'needs_manual';

    /** Stable identifier, e.g. "generic_form". */
    public function id(): string;

    /** Human-readable label for the admin UI. */
    public function label(): string;

    /**
     * Whether this adapter can technically handle the prospect/package (authorization is Policy's job).
     *
     * @param array<string,mixed> $prospect
     * @param array<string,mixed> $payload
     */
    public function supports( array $prospect, array $payload ): bool;

    /**
     * Perform (or decline) the submission.
     *
     * @param array<string,mixed> $submission Submission row.
     * @param array<string,mixed> $prospect   Prospect row.
     * @param array<string,mixed> $payload    Package built by PackageBuilder.
     * @return array{status:string,response:array<string,mixed>,confirmation_ref:string,listing_url:string,error:string,retryable:bool}
     */
    public function submit( array $submission, array $prospect, array $payload ): array;
}
