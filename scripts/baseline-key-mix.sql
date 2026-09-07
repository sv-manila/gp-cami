-- Read-only. Run against the production/staging golden_profile hub.
-- Baseline for the GPP conformance programme (plan 1, task 1).

-- 1. How many source links were bound by each deterministic key?
SELECT match_key, match_method, match_state, COUNT(*) AS links
FROM gp_source_link
GROUP BY match_key, match_method, match_state
ORDER BY links DESC;

-- 2. How many identities exist, and how many carry an ssn_hash?
SELECT COUNT(*)                                     AS identities_total,
       SUM(ssn_hash IS NOT NULL AND ssn_hash <> '') AS identities_with_ssn_hash
FROM gp_identity
WHERE status = 'active';

-- 3. The number that matters: identities whose links were bound ONLY by
--    ssn_hash. Removing the tier fragments exactly these.
SELECT COUNT(*) AS identities_bound_only_by_ssn
FROM (
    SELECT identity_id
    FROM gp_source_link
    GROUP BY identity_id
    HAVING SUM(match_key <> 'ssn_hash') = 0
       AND SUM(match_key =  'ssn_hash') > 0
) t;

-- 4. Multi-row identities that would lose their only cross-record evidence.
SELECT COUNT(*) AS at_risk_identities
FROM gp_identity i
WHERE i.status = 'active'
  AND i.ssn_hash IS NOT NULL AND i.ssn_hash <> ''
  AND i.npi IS NULL AND i.upin IS NULL AND i.dea_number IS NULL
  AND (i.canonical_dob IS NULL OR i.canonical_last IS NULL)
  AND i.record_count > 1;

-- 5. Filler-hash exposure, against the 17 hashes / 9,164 people figure
--    recorded in config/golden_profile.php.
SELECT COUNT(*) AS filler_hashes, COALESCE(SUM(distinct_people), 0) AS people_affected
FROM gp_ssn_hash_blocklist;

-- 6. The number that predicts the future: how many EXTRA identities a
--    from-scratch rebuild produces once the ssn_hash tier is gone.
--
--    Plan 2 retains existing links (see the plan's design decision 3), so
--    removing the tier fragments nothing at the moment it lands. The cost is
--    deferred to the next full rebuild, which will mint one identity per source
--    row for every identity that had no other binding evidence. That delta is
--    (rows on ssn-only identities) - (count of those identities).
--
--    Read alongside query 3: query 3 says how many identities change shape,
--    this says by how much. An identity bound only by ssn_hash but holding a
--    single source row contributes 0 — it was already effectively a singleton.
SELECT COUNT(*)               AS ssn_only_identities,
       COALESCE(SUM(links),0) AS ssn_only_links,
       COALESCE(SUM(links),0) - COUNT(*) AS projected_extra_identities
FROM (
    SELECT identity_id, COUNT(*) AS links
    FROM gp_source_link
    GROUP BY identity_id
    HAVING SUM(match_key <> 'ssn_hash') = 0
       AND SUM(match_key =  'ssn_hash') > 0
) t;
