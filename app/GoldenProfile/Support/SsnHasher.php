<?php

namespace App\GoldenProfile\Support;

use Illuminate\Support\Facades\DB;
use Streamlineverify\Security\Encryption\KeyManager\LocalStrategy;

/**
 * Reproduces CAMI's SSN match key: ssn_hash = sha512(plaintext_ssn + plaintext_key),
 * exactly as Streamlineverify\Security\Encryption\Crypter::encrypt() emits it.
 *
 * Encryption itself is non-deterministic (AES-256-CBC, random IV), so matching is
 * only ever on this hash — never on ciphertext. Requires the SAME plaintext key
 * CAMI uses; locally that is the LocalStrategy key, in prod it comes from the
 * shared encryption_keys / KeyManager.
 */
class SsnHasher
{
    public function __construct(
        private string $subjectAttribute = 'social_security_num',
    ) {}

    /** Hash a plaintext SSN into its ssn_hash, or null if no key is available. */
    public function hash(string $ssn): ?string
    {
        $key = $this->plaintextKey();

        return $key === null ? null : hash('sha512', $ssn.$key);
    }

    public function available(): bool
    {
        return $this->plaintextKey() !== null;
    }

    private function plaintextKey(): ?string
    {
        // Prefer an explicitly configured key (prod: shared with CAMI).
        $configured = config('golden_profile.ssn.plaintext_key');
        if ($configured) {
            return $configured;
        }

        // Otherwise resolve from the source encryption_keys registry (local dev).
        $row = DB::connection('streamline_local')->table('encryption_keys')
            ->where('subject_attribute', $this->subjectAttribute)
            ->where('status', 1)
            ->first();

        if (! $row) {
            return null;
        }

        if (($row->manager ?? null) === 'local') {
            return (new LocalStrategy)->getKey($row->encrypted_key ?? null);
        }

        // Non-local (e.g. AWS KMS) keys need the shared master; not derivable here.
        return null;
    }
}
