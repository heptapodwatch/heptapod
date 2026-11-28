-- Run before or after the fold. Counts candidates for review, not URLs whose
-- behavior is known to change: a ? may already be a query delimiter.
-- Ignored URLs already used *-only matching and are not part of this report.
SELECT fields.key AS setting,
       count(entry) AS stored_patterns,
       count(*) FILTER (WHERE entry ~ $regex$(^|[^\\])(\\\\)*(\?|\[[^][]+\])$regex$) AS fnmatch_wildcard_candidates
FROM (VALUES ('exclude_patterns'), ('do_not_crawl_urls'), ('force_crawl_urls')) AS fields(key)
LEFT JOIN checks ON checks.type = 'broken_links' AND checks.config ? fields.key
LEFT JOIN LATERAL jsonb_array_elements_text(checks.config->fields.key) AS patterns(entry) ON true
GROUP BY fields.key
ORDER BY fields.key;
