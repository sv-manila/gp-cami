-- Read-only. Run against the production/staging golden_profile hub before the
-- SCD-2 migration (plan 3, task 1). Answers four questions:
--   A. how expensive are the unavoidable ALGORITHM=COPY rebuilds?
--   B. which indexes actually exist? (the hub carries at least one added by hand
--      and absent from every migration: idx_identity_registry_match, referenced
--      in CredentialSearchController's measurement comment)
--   C. would the new single-current unique index collide with existing data?
--   D. what are the real row counts this plan's growth applies to?

-- A. Rebuild sizing. Only gp_identity, gp_identity_credential and
--    gp_identity_exclusion need a PRIMARY KEY change, and a PK change in MySQL 8
--    is ALGORITHM=COPY: a full table rebuild that blocks writes. Everything else
--    in the migration is INSTANT or INPLACE/LOCK=NONE.
SELECT table_name,
       table_rows                                        AS approx_rows,
       ROUND((data_length + index_length) / 1024 / 1024) AS total_mb
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('gp_identity', 'gp_identity_credential', 'gp_identity_exclusion')
ORDER BY data_length + index_length DESC;

-- B. Real index inventory for every table the migration touches. The migration
--    drops indexes by name; a name that is not here must not be dropped, and a
--    name here the migration does not know about has to gain a `current` column
--    of its own or it stops being usable.
SELECT table_name, index_name, non_unique,
       GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name IN ('gp_identity', 'gp_license', 'gp_address', 'gp_identity_identifier',
                     'gp_identity_credential', 'gp_identity_exclusion')
GROUP BY table_name, index_name, non_unique
ORDER BY table_name, index_name;

-- C. Collision check. The new uq_*_current indexes are unique over a
--    NULL-propagating expression, so they constrain exactly the rows today's
--    multi-column uniques constrain and no more. These counts must all be 0; a
--    non-zero row means the existing unique is not doing what it looks like it is
--    doing and the migration WILL fail on that table.
SELECT 'gp_license' AS tbl, COUNT(*) AS duplicate_natural_keys FROM (
    SELECT identity_id, license_number, certification_state, certification_board
    FROM gp_license
    GROUP BY identity_id, license_number, certification_state, certification_board
    HAVING COUNT(*) > 1
) d
UNION ALL
SELECT 'gp_address', COUNT(*) FROM (
    SELECT identity_id, address1, city, state, zip
    FROM gp_address
    GROUP BY identity_id, address1, city, state, zip
    HAVING COUNT(*) > 1
) d
UNION ALL
SELECT 'gp_identity_identifier', COUNT(*) FROM (
    SELECT identity_id, id_type, id_value
    FROM gp_identity_identifier
    GROUP BY identity_id, id_type, id_value
    HAVING COUNT(*) > 1
) d;

-- D. Row counts on the tables whose growth this plan controls, so plan 3b and any
--    capacity conversation start from a measured number rather than the 13.4M
--    figure quoted from a docblock.
SELECT 'gp_identity' AS tbl, COUNT(*) AS rows_now FROM gp_identity
UNION ALL SELECT 'gp_license',             COUNT(*) FROM gp_license
UNION ALL SELECT 'gp_address',             COUNT(*) FROM gp_address
UNION ALL SELECT 'gp_identity_identifier', COUNT(*) FROM gp_identity_identifier
UNION ALL SELECT 'gp_source_link',         COUNT(*) FROM gp_source_link;
