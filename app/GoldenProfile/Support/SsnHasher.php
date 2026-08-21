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

    /** Memoised key lookup: null = not resolved yet, false = resolved as unavailable. */
    private string|false|null $keyCache = null;

    private ?string $unavailableReason = null;

    /** Hash a plaintext SSN into its ssn_hash, or null if no key is available. */
    public function hash(string $ssn): ?string
    {
        $key = $this->plaintextKey();

        return $key === null ? null : hash('sha512', $ssn.$key);
    }

    /**
     * Every ssn_hash a caller-supplied SSN could legitimately be stored under.
     *
     * CAMI hashes whatever string sits in employees.social_security_num, and that
     * column is not format-normalised — the same person may be stored as
     * "123456789" in one row and "123-45-6789" in another. Normalising the input
     * to one shape would therefore trade one silent false negative for another,
     * so instead every plausible format is hashed and the caller matches on any
     * of them. Hash collisions across formats are not a practical concern.
     *
     * @return list<string> empty when no key is available
     */
    public function candidateHashes(string $ssn): array
    {
        if ($this->plaintextKey() === null) {
            return [];
        }

        $digits = preg_replace('/\D+/', '', $ssn) ?? '';
        $forms = [$ssn];

        if ($digits !== '') {
            $forms[] = $digits;
            if (strlen($digits) === 9) {
                $forms[] = substr($digits, 0, 3).'-'.substr($digits, 3, 2).'-'.substr($digits, 5);
            }
        }

        return array_values(array_unique(array_map(fn ($f) => $this->hash($f), array_unique($forms))));
    }

    public function available(): bool
    {
        return $this->plaintextKey() !== null;
    }

    /**
     * Why SSN matching is unavailable, or null when it is available. Callers use
     * this to fail loudly instead of silently dropping the SSN filter — see
     * CredentialSearchController::resolveIdentity().
     */
    public function unavailableReason(): ?string
    {
        $this->plaintextKey();

        return $this->unavailableReason;
    }

    private function plaintextKey(): ?string
    {
        if ($this->keyCache !== null) {
            return $this->keyCache === false ? null : $this->keyCache;
        }

        $key = $this->resolveKey();
        $this->keyCache = $key ?? false;

        return $key;
    }

    private function resolveKey(): ?string
    {
        // Prefer an explicitly configured key (prod: shared with CAMI).
        $configured = config('golden_profile.ssn.plaintext_key');
        if ($configured) {
            $this->unavailableReason = null;

            return $configured;
        }

        // Otherwise resolve from the source encryption_keys registry (local dev).
        //
        // This lookup must never propagate its exception. Callers treat "no key"
        // as a condition to report (a 503 explaining SSN matching is off); an
        // escaping connection error turns that into a 500 instead, and would also
        // abort SsnHashGuard::buildBlocklistTable() and with it the whole
        // resolveDeterministic() run. Unreachable registry == no key available.
        try {
            $row = DB::connection('streamline_local')->table('encryption_keys')
                ->where('subject_attribute', $this->subjectAttribute)
                ->where('status', 1)
                ->first();
        } catch (\Throwable $e) {
            $this->unavailableReason = 'the source key registry is unreachable ('
                .class_basename($e).'), and golden_profile.ssn.plaintext_key is unset';

            return null;
        }

        if (! $row) {
            $this->unavailableReason = 'no active encryption key for '.$this->subjectAttribute
                .' in the source registry, and golden_profile.ssn.plaintext_key is unset';

            return null;
        }

        if (($row->manager ?? null) === 'local') {
            try {
                $key = (new LocalStrategy)->getKey($row->encrypted_key ?? null);
            } catch (\Throwable $e) {
                $this->unavailableReason = 'LocalStrategy could not unwrap the key for '
                    .$this->subjectAttribute.' ('.class_basename($e).')';

                return null;
            }
            if ($key === null || $key === '') {
                $this->unavailableReason = 'LocalStrategy returned no key for '.$this->subjectAttribute;

                return null;
            }
            $this->unavailableReason = null;

            return $key;
        }

        // Non-local (e.g. AWS KMS) keys need the shared master; not derivable here.
        $this->unavailableReason = 'active key uses the "'.$row->manager
            .'" key manager, which is not derivable here; set GP_SSN_PLAINTEXT_KEY';

        return null;
    }
}
