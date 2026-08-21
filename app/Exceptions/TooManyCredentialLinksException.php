<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Thrown when credential-search resolves an identity carrying so many credential
 * links for one registry that picking the qualifying one cannot be done inside a
 * request.
 *
 * This is a data-quality signal, not a transient fault. Choosing the winner needs
 * date columns that live on the CAMI source server, so every link requires a
 * lookup there; identity 3 ("John Smith", 12,463 source rows merged together) has
 * 364,439 qualifying links for NYEMED and takes ~390s to resolve. Scanning only
 * part of the set would return a confidently wrong credential, which is worse than
 * no answer in a compliance product.
 *
 * An over-merge is the most common cause, but NOT the only one: identity 226159
 * carries 16,000 links with record_count=1 — one real person whose single employee
 * record accumulated that many credential checks. So this reports the symptom and
 * declines to diagnose it. Asserting "this identity must be split" would be wrong
 * advice for that case.
 */
class TooManyCredentialLinksException extends RuntimeException
{
    public function __construct(
        public readonly int $linkCount,
        public readonly int $maxLinks,
    ) {
        parent::__construct(
            "Identity has $linkCount credential links for this registry (limit $maxLinks)."
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'too_many_credential_links',
            'message' => 'This identity carries more credential links for the requested registry than '
                .'a single qualifying credential can be determined from within a request. This is '
                .'usually an over-merged identity, but can also be one person with an unusually long '
                .'check history — it needs review either way.',
            'link_count' => $this->linkCount,
            'max_links' => $this->maxLinks,
        ], 409);
    }
}
