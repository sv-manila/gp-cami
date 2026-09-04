<?php

namespace App\GoldenProfile\Support;

use Illuminate\Support\Facades\DB;

/**
 * Reproduces CAMI's SSN match key: ssn_hash = sha512(plaintext_ssn + plaintext_key),
 * exactly as CAMI's Crypter::encrypt() (streamlineverify/security) emits it.
 *
 * Encryption itself is non-deterministic (AES-256-CBC, random IV), so matching is
 * only ever on this hash — never on ciphertext. Requires the SAME plaintext key
 * CAMI uses; locally that is the manager = 'local' key (see
 * golden_profile.ssn.local_manager_key), in prod it comes from the shared
 * encryption_keys registry behind KMS (manager = 'aws').
 *
 * Key resolution replicates CAMI's own KeyManager/EncryptionKey::scopeForDataPoint()
 * lookup (from the streamlineverify/security package) rather than depending on
 * that package, which gp-cami used for exactly this one lookup.
 */
class SsnHasher
{
    public function __construct(
        private string $subjectAttribute = 'social_security_num',
        private string $subject = 'employees',
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

        // Otherwise resolve from the source encryption_keys registry (local dev),
        // replicating EncryptionKey::scopeForDataPoint()'s filter exactly: subject
        // + subject_attribute + subject_id IS NULL (the global datapoint key, not
        // a per-account row) + status. Matching only on subject_attribute would
        // risk picking a different row than CAMI does — e.g. a per-account key or
        // another subject reusing this attribute name — which would silently
        // produce a ssn_hash that never matches CAMI's.
        //
        // This lookup must never propagate its exception. Callers treat "no key"
        // as a condition to report (a 503 explaining SSN matching is off); an
        // escaping connection error turns that into a 500 instead, and would also
        // abort SsnHashGuard::buildBlocklistTable() and with it the whole
        // resolveDeterministic() run. Unreachable registry == no key available.
        try {
            $row = DB::connection('streamline_local')->table('encryption_keys')
                ->where('subject', $this->subject)
                ->where('subject_attribute', $this->subjectAttribute)
                ->whereNull('subject_id')
                ->where('status', '1')
                ->first();
        } catch (\Throwable $e) {
            $this->unavailableReason = 'the source key registry is unreachable ('
                .class_basename($e).'), and golden_profile.ssn.plaintext_key is unset';

            return null;
        }

        if (! $row) {
            $this->unavailableReason = 'no active encryption key for '.$this->subject.'.'.$this->subjectAttribute
                .' in the source registry, and golden_profile.ssn.plaintext_key is unset';

            return null;
        }

        if (($row->manager ?? null) === 'local') {
            // Mirrors LocalStrategy::getKey(), which ignores its argument and
            // returns a hardcoded constant — never derived from encrypted_key.
            $key = config('golden_profile.ssn.local_manager_key');
            if (! $key) {
                $this->unavailableReason = 'active key uses the "local" manager but '
                    .'GP_SSN_LOCAL_MANAGER_KEY is unset (see golden_profile.ssn.local_manager_key)';

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
